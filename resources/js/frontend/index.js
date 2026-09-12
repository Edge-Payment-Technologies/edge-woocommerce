import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
import apiFetch from '@wordpress/api-fetch';
import {
	createElement,
	Fragment,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';

const settings = getSetting( 'edge_data', {} );

const defaultLabel = __( 'Edge Payments', 'edge-gateway-for-woocommerce' );

const label = decodeEntities( settings.title ) || defaultLabel;

let edgeClient;
let edgePaymentDemandId = '';
let edgePaymentMethodReady = false;

/**
 * How the block waits for the card network's answer.
 *
 * Confirming a demand only means Edge accepted the payment for processing. The
 * acquirer's answer lands afterwards - a second or three live, longer in the
 * sandbox - so it has to be waited for rather than assumed. Almost every payment
 * settles inside the first twenty seconds, so poll briskly there and ease off
 * rather than hammering the site for two minutes.
 */
const STATUS_POLL_MS = 2000;
const STATUS_SLOW_POLL_MS = 4000;
const STATUS_SLOW_AFTER_MS = 20000;

/**
 * How long to wait for a terminal outcome before sending the shopper on.
 */
const OUTCOME_TIMEOUT_MS = 120000;

const readStatus = ( orderId ) =>
	apiFetch( {
		path: '/edge/v1/checkout-status',
		method: 'POST',
		data: { order_id: orderId },
	} );

const declineMessage = ( response ) =>
	typeof response?.message === 'string' && response.message
		? response.message
		: __(
				'Your payment was declined. Please check your card details or try another card.',
				'edge-gateway-for-woocommerce'
		  );

/**
 * Custom form field component
 */
const LoadEdgePaymentsForm = () => {
	// Why there is no card form. The site refuses a second demand while a previous
	// payment of this shopper's is still settling, and that refusal is the only
	// thing standing between a mid-payment reload and paying twice - so it has to
	// be readable, not thrown into an empty page.
	const [ activationError, setActivationError ] = useState( null );

	useEffect( () => {
		// Load the remote script here
		const script = document.createElement( 'script' );
		script.src =
			'https://assets.tryedge.io/assets/js/edge-0082aa6231d2034eb9d5c489d74c41bb.js?vsn=d';
		//script.async = true;
		script.onload = () => {
			ActivateEdgePayments().catch( ( error ) => {
				setActivationError( error?.message || '' );
			} );
		};
		document.body.appendChild( script );

		return () => {
			//document.body.removeChild(script);
		};
	}, [] ); // Empty dependency array ensures it only runs once during component mount

	return createElement(
		Fragment,
		null,
		activationError &&
			createElement(
				'div',
				{ className: 'wc-edge-payment__error', role: 'alert' },
				activationError
			),
		createElement( 'div', { id: 'card-fields' } )
	);
};

const ActivateEdgePayments = async () => {
	const response = await fetch( settings.payment_demand_url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/x-www-form-urlencoded',
		},
		body: new URLSearchParams( {
			action: 'edge_create_payment_demand',
			nonce: settings.payment_demand_nonce,
		} ),
	} );
	const result = await response.json();

	if (
		! response.ok ||
		! result.success ||
		! result.data?.payment_demand_id
	) {
		throw new Error(
			result.data?.message || 'Unable to initialize Edge Payments.'
		);
	}

	edgePaymentDemandId = result.data.payment_demand_id;
	edgeClient = new window.Edge( settings.publishable_key, {
		formFactor: 'inputs',
	} );

	edgeClient.on( 'payment_method_changed', ( event ) => {
		edgePaymentMethodReady = Boolean( event.detail?.ready );
	} );

	const paymentIframe = edgeClient.mountPaymentForm(
		'card-fields',
		edgePaymentDemandId
	);
	paymentIframe.style.border = '0';
	paymentIframe.style.boxShadow = 'none';
};

const PrepareEdgePaymentDemand = async ( billingAddress, shippingAddress ) => {
	const response = await fetch( settings.payment_demand_url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/x-www-form-urlencoded',
		},
		body: new URLSearchParams( {
			action: 'edge_prepare_payment_demand',
			nonce: settings.payment_demand_nonce,
			payment_demand_id: edgePaymentDemandId,
			billing_address: JSON.stringify( billingAddress ),
			shipping_address: JSON.stringify( shippingAddress ),
		} ),
	} );
	const result = await response.json();

	if ( ! response.ok || ! result.success ) {
		throw new Error(
			result.data?.message || 'Unable to prepare Edge Payments.'
		);
	}
};

/**
 * Content component
 */
const Content = ( props ) => {
	const { billing, shippingData, eventRegistration, emitResponse } = props;
	const { onPaymentSetup, onCheckoutSuccess } = eventRegistration;
	const billingAddress = billing?.billingAddress || {};
	const shippingAddress = shippingData?.shippingAddress || billingAddress;

	// Where the post-order wait has got to: null, 'waiting' or 'slow'.
	const [ waitStage, setWaitStage ] = useState( null );
	const abandonWait = useRef( null );

	useEffect( () => {
		const unsubscribe = onPaymentSetup( async () => {
			if ( edgeClient && edgePaymentDemandId && edgePaymentMethodReady ) {
				try {
					await PrepareEdgePaymentDemand(
						billingAddress,
						shippingAddress
					);
					await edgeClient.verifyPaymentMethod();
				} catch ( error ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message:
							'We could not verify your card. Please check your card details and try again.',
					};
				}

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							payment_demand_id: edgePaymentDemandId,
						},
					},
				};
			}

			return {
				type: emitResponse.responseTypes.ERROR,
				message: 'Please enter your card information.',
			};
		} );
		// Unsubscribes when this component is unmounted.
		return () => {
			unsubscribe();
		};
	}, [
		emitResponse.responseTypes.ERROR,
		emitResponse.responseTypes.SUCCESS,
		billingAddress,
		onPaymentSetup,
		shippingAddress,
	] );

	/**
	 * Poll the site until the payment settles.
	 *
	 * A wall-clock budget rather than a retry count, so a slow acquirer and a slow
	 * site are the same problem. `report( status, response )` is called exactly
	 * once with 'succeeded', 'failed', 'timeout' or 'abandoned'. Returns a cancel
	 * function.
	 *
	 * @param {number}   orderId  Order to ask about.
	 * @param {string}   demandId Demand the mounted iframe is showing.
	 * @param {Function} report   Called once with the outcome.
	 * @return {Function} Cancels the wait and reports 'abandoned'.
	 */
	const watchOutcome = useCallback( ( orderId, demandId, report ) => {
		const startedAt = Date.now();
		const unsubscribers = [];

		let settled = false;
		let inFlight = false;
		let pollAgain = false;
		let pollTimer = null;
		let slowTimer = null;
		let giveUpTimer = null;

		function stop() {
			settled = true;

			window.clearTimeout( pollTimer );
			window.clearTimeout( slowTimer );
			window.clearTimeout( giveUpTimer );

			unsubscribers.forEach( ( off ) => {
				if ( typeof off === 'function' ) {
					off();
				}
			} );

			setWaitStage( null );
		}

		function schedule() {
			if ( settled ) {
				return;
			}

			window.clearTimeout( pollTimer );

			pollTimer = window.setTimeout(
				poll,
				Date.now() - startedAt >= STATUS_SLOW_AFTER_MS
					? STATUS_SLOW_POLL_MS
					: STATUS_POLL_MS
			);
		}

		// Either the timer or an iframe hint got here first; both land in the same
		// place so there is only ever one request outstanding.
		function again() {
			if ( pollAgain ) {
				pollAgain = false;
				poll();

				return;
			}

			schedule();
		}

		function poll() {
			if ( settled ) {
				return;
			}

			if ( inFlight ) {
				pollAgain = true;

				return;
			}

			window.clearTimeout( pollTimer );
			inFlight = true;

			readStatus( orderId )
				.then( ( response ) => {
					inFlight = false;

					if ( settled ) {
						return;
					}

					if (
						response?.status === 'succeeded' ||
						response?.status === 'failed'
					) {
						stop();
						report( response.status, response );

						return;
					}

					again();
				} )
				.catch( () => {
					// A transport failure or an unreadable body says nothing about
					// the payment, so it is not an outcome - keep waiting, and
					// never repeat a server's own words to the shopper.
					inFlight = false;

					if ( ! settled ) {
						again();
					}
				} );
		}

		if ( demandId && edgeClient ) {
			// The iframe hears the acquirer before WordPress does, but a message
			// from it is not evidence that an order moved. The hint only brings the
			// next poll forward; the server's answer is still the only thing acted
			// on.
			const hint = ( event ) => {
				if ( event?.detail?.paymentId === demandId ) {
					poll();
				}
			};

			unsubscribers.push(
				edgeClient.on( 'payment_approved', hint ),
				edgeClient.on( 'payment_failed', hint )
			);
		}

		setWaitStage( 'waiting' );

		slowTimer = window.setTimeout( () => {
			setWaitStage( 'slow' );
		}, STATUS_SLOW_AFTER_MS );

		giveUpTimer = window.setTimeout( () => {
			if ( ! settled ) {
				stop();
				report( 'timeout', null );
			}
		}, OUTCOME_TIMEOUT_MS );

		schedule();

		return () => {
			if ( ! settled ) {
				stop();
				report( 'abandoned', null );
			}
		};
	}, [] );

	/**
	 * Hold the checkout open until the payment settles, and answer Blocks with
	 * what happened.
	 */
	const awaitOutcome = useCallback(
		( orderId, demandId ) =>
			new Promise( ( resolve ) => {
				// The thank-you page with the order left on-hold is what this
				// gateway did before it waited at all, so it is the safe way out of
				// a wait that has gone on too long - or of this component being
				// unmounted underneath one. A shopper is never left on a locked
				// form, and a decline nobody actually reported is never announced.
				const giveUp = { type: emitResponse.responseTypes.SUCCESS };

				abandonWait.current = watchOutcome(
					orderId,
					demandId,
					( status, response ) => {
						abandonWait.current = null;

						if ( status === 'succeeded' ) {
							const outcome = {
								type: emitResponse.responseTypes.SUCCESS,
							};

							// Left off rather than sent empty, so the store falls
							// back to the redirect it already holds.
							if (
								typeof response.redirectUrl === 'string' &&
								response.redirectUrl
							) {
								outcome.redirectUrl = response.redirectUrl;
							}

							resolve( outcome );

							return;
						}

						if ( status === 'failed' ) {
							// The card is what needs fixing and the iframe is still
							// mounted holding it - it is never torn down, because
							// the SDK cannot remount without stacking a second
							// iframe, and Edge accepts a fresh card on a failed
							// demand. The next Place order re-verifies against it.
							resolve( {
								type: emitResponse.responseTypes.ERROR,
								message: declineMessage( response ),
								messageContext:
									emitResponse.noticeContexts.PAYMENTS,
							} );

							return;
						}

						resolve( giveUp );
					}
				);
			} ),
		[
			watchOutcome,
			emitResponse.responseTypes.SUCCESS,
			emitResponse.responseTypes.ERROR,
			emitResponse.noticeContexts.PAYMENTS,
		]
	);

	useEffect( () => {
		const unsubscribe = onCheckoutSuccess(
			( { processingResponse, orderId } ) => {
				const outcomeDemandId =
					processingResponse?.paymentDetails?.edge_demand_id;

				// Blocks seeds the checkout store's orderId from the draft order in
				// the opening GET /wc/store/v1/checkout and never updates it from
				// the POST response, so on a fresh session it is still 0 here and
				// the wait below would be skipped entirely - the shopper redirected
				// to an order nobody had waited on. process_payment() therefore
				// sends the real id back in the payment details, and that is
				// preferred over the store's copy.
				const detailsOrderId = parseInt(
					processingResponse?.paymentDetails?.edge_order_id,
					10
				);
				const outcomeOrderId =
					Number.isInteger( detailsOrderId ) && detailsOrderId > 0
						? detailsOrderId
						: orderId;

				// Someone else's order, or a page that has since remounted against
				// a different demand. Returning nothing lets Blocks finish the
				// checkout exactly as it would without us.
				if (
					typeof outcomeDemandId !== 'string' ||
					! outcomeDemandId ||
					outcomeDemandId !== edgePaymentDemandId ||
					! outcomeOrderId
				) {
					return undefined;
				}

				return awaitOutcome( outcomeOrderId, outcomeDemandId );
			}
		);

		return () => unsubscribe();
	}, [ onCheckoutSuccess, awaitOutcome ] );

	// Never leave Blocks holding a promise nobody will resolve.
	useEffect(
		() => () => {
			if ( abandonWait.current ) {
				abandonWait.current();
			}
		},
		[]
	);

	return createElement(
		Fragment,
		null,
		createElement( 'div', {
			dangerouslySetInnerHTML: { __html: settings.description },
		} ),
		createElement( LoadEdgePaymentsForm ),
		// Announced rather than merely shown: the form is locked while this is up,
		// so a screen reader has to be told that the wait is the reason.
		waitStage &&
			createElement(
				'div',
				{ className: 'wc-edge-payment__status', role: 'status' },
				waitStage === 'slow'
					? __(
							'This is taking longer than usual. Please keep this page open.',
							'edge-gateway-for-woocommerce'
					  )
					: __(
							'Waiting for your bank to confirm your payment…',
							'edge-gateway-for-woocommerce'
					  )
			)
	);
};
/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = ( props ) => {
	const { PaymentMethodLabel } = props.components;
	return createElement( PaymentMethodLabel, { text: label } );
};

/**
 * Edge payment method config object.
 */
const WCEdge = {
	name: 'edge',
	label: createElement( Label ),
	content: createElement( Content ),
	edit: createElement( Content ),
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports,
	},
	currencies: [ 'USD' ],
	supportsRecurring: true,
};

registerPaymentMethod( WCEdge );
