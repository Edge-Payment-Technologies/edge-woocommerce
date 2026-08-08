/**
 * Edge Payments — block checkout integration.
 *
 * The server creates an unconfirmed payment demand, Edge's hosted iframe
 * collects and verifies the card against it, and only the opaque demand id
 * crosses back into WooCommerce. No card data touches this bundle.
 */

import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { CART_STORE_KEY } from '@woocommerce/block-data';
import apiFetch from '@wordpress/api-fetch';

const settings = getSetting( 'edge_data', {} );

const label = decodeEntities( settings.title ) || __( 'Credit Card', 'edge-gateway' );

/**
 * How long to wait for a verification outcome before giving up.
 *
 * The SDK's own default is 180s. This is shorter so a shopper is not left
 * staring at a spinner, but still generous enough for a 3DS challenge.
 */
const VERIFY_TIMEOUT_MS = 120000;

/**
 * Pause to wait for before preparing, so a burst of address updates results in
 * one demand rather than one per field.
 */
const PREPARE_DEBOUNCE_MS = 700;

/**
 * The one Edge client on the page.
 *
 * edge.js registers global `window` message handlers in its constructor and
 * exposes no `destroy()`, so every additional instance leaks a handler that
 * outlives the component. Keeping exactly one, reused across remounts, is the
 * mitigation. Reported upstream.
 */
let sharedClient = null;

const getEdgeClient = ( publishableKey, host ) => {
	if ( sharedClient ) {
		return sharedClient;
	}

	if ( typeof window.Edge !== 'function' ) {
		return null;
	}

	sharedClient = new window.Edge( publishableKey, {
		formFactor: 'inputs',
		host,
	} );

	return sharedClient;
};

/**
 * Ask the server for a payment demand to mount against.
 *
 * No cart details are sent. The server reads the cart, total, currency and mode
 * from the session; the browser is not a source of truth for any of them.
 */
const prepareIntent = () =>
	apiFetch( {
		path: '/edge/v1/checkout-intent',
		method: 'POST',
	} );

const EdgePaymentForm = ( { eventRegistration, emitResponse } ) => {
	const { onPaymentSetup } = eventRegistration;

	// Stable, unique container id: edge.js looks the element up by id, so two
	// mounted instances must not collide.
	const containerId = useRef(
		`edge-payment-form-${ Math.random().toString( 36 ).slice( 2, 10 ) }`
	).current;

	const [ demandId, setDemandId ] = useState( null );
	const [ failure, setFailure ] = useState( null );

	// Which demand, if any, currently holds a verified card. Held in a ref
	// because the payment-setup callback reads it outside React's render cycle.
	const verifiedFor = useRef( null );
	const pending = useRef( null );
	const mountedDemand = useRef( null );

	// Everything the server fingerprints a demand against. Re-preparing when any
	// of it changes is what keeps the mounted demand from going stale: Edge
	// silently returns the original resource for a reused idempotency key, so a
	// changed cart or address needs a genuinely new demand.
	//
	// These come from the cart store rather than the form inputs, so they update
	// when Blocks has synced them to the server - not on every keystroke.
	const paymentFacts = useSelect( ( select ) => {
		const store = select( CART_STORE_KEY );
		const totals = store.getCartTotals();
		const customer = store.getCustomerData();

		return JSON.stringify( {
			total: totals?.total_price,
			currency: totals?.currency_code,
			billing: customer?.billingAddress,
			shipping: customer?.shippingAddress,
		} );
	}, [] );

	const settle = useCallback( ( outcome ) => {
		if ( pending.current ) {
			const { resolve, timer } = pending.current;
			pending.current = null;
			window.clearTimeout( timer );
			resolve( outcome );
		}
	}, [] );

	// --- Obtain a demand -----------------------------------------------------
	useEffect( () => {
		let cancelled = false;

		// Address fields settle in bursts as Blocks syncs them, so wait for a
		// pause rather than preparing against every intermediate state.
		const timer = window.setTimeout( () => {
			setFailure( null );
			verifiedFor.current = null;

			prepareIntent()
				.then( ( response ) => {
					if ( ! cancelled ) {
						setDemandId( response.demandId );
					}
				} )
				.catch( ( error ) => {
					if ( ! cancelled ) {
						setDemandId( null );
						setFailure(
							error?.message ||
								__(
									'We could not start your card payment. Please try again.',
									'edge-gateway'
								)
						);
					}
				} );
		}, PREPARE_DEBOUNCE_MS );

		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
	}, [ paymentFacts ] );

	// --- Mount the hosted form and listen ------------------------------------
	useEffect( () => {
		if ( ! demandId ) {
			return undefined;
		}

		const client = getEdgeClient( settings.publishableKey, settings.iframeHost );

		if ( ! client ) {
			setFailure(
				__(
					'The secure payment form could not be loaded. Please refresh and try again.',
					'edge-gateway'
				)
			);

			return undefined;
		}

		// Listeners go on before anything can emit, so no outcome is missed.
		const unsubscribers = [
			client.on( 'payment_method_verified', () => {
				verifiedFor.current = demandId;
				settle( { ok: true } );
			} ),

			client.on( 'payment_method_error', () => {
				verifiedFor.current = null;
				settle( {
					ok: false,
					message: __(
						'Your bank could not verify that card. Please check the details or try another card.',
						'edge-gateway'
					),
				} );
			} ),

			client.on( 'payment_method_failed', () => {
				verifiedFor.current = null;
				settle( {
					ok: false,
					message: __(
						'That card could not be authenticated. Please try another card.',
						'edge-gateway'
					),
				} );
			} ),

			// Fires whenever the fields change, including after a successful
			// verification — an edited card invalidates the previous result.
			client.on( 'payment_method_changed', () => {
				verifiedFor.current = null;
			} ),
		];

		try {
			client.mountPaymentForm( containerId, demandId );
			mountedDemand.current = demandId;
		} catch ( error ) {
			setFailure(
				__(
					'The secure payment form could not be displayed. Please refresh and try again.',
					'edge-gateway'
				)
			);
		}

		return () => {
			unsubscribers.forEach( ( off ) => {
				if ( typeof off === 'function' ) {
					off();
				}
			} );

			settle( {
				ok: false,
				message: __( 'Payment was interrupted. Please try again.', 'edge-gateway' ),
			} );

			mountedDemand.current = null;

			// The shared client has no teardown, so remove the iframe directly.
			const container = document.getElementById( containerId );

			if ( container ) {
				container.innerHTML = '';
			}
		};
	}, [ demandId, settle, containerId ] );

	// --- Verify on "Place order" ---------------------------------------------
	useEffect( () => {
		const unsubscribe = onPaymentSetup( async () => {
			const fail = ( message ) => ( {
				type: emitResponse.responseTypes.ERROR,
				message,
			} );

			if ( failure ) {
				return fail( failure );
			}

			if ( ! demandId || mountedDemand.current !== demandId ) {
				return fail(
					__(
						'The payment form is not ready yet. Please wait a moment and try again.',
						'edge-gateway'
					)
				);
			}

			// A second submit must never start a parallel verification; it joins
			// the one already running.
			if ( ! pending.current ) {
				const client = getEdgeClient( settings.publishableKey, settings.iframeHost );

				if ( ! client ) {
					return fail(
						__( 'The secure payment form is unavailable.', 'edge-gateway' )
					);
				}

				const outcome = new Promise( ( resolve ) => {
					const timer = window.setTimeout( () => {
						pending.current = null;
						resolve( {
							ok: false,
							message: __(
								'Verifying your card took too long. Please try again.',
								'edge-gateway'
							),
						} );
					}, VERIFY_TIMEOUT_MS );

					pending.current = { resolve, timer };
				} );

				// The returned promise is deliberately ignored: it never rejects,
				// and it resolves on `payment_method_changed`, which fires as soon
				// as the fields are merely filled in. The event handlers above are
				// the only trustworthy signal.
				try {
					client.verifyPaymentMethod();
				} catch ( error ) {
					settle( {
						ok: false,
						message: __( 'Your card could not be verified.', 'edge-gateway' ),
					} );
				}

				pending.current.outcome = outcome;
			}

			const result = await pending.current.outcome;

			if ( ! result.ok ) {
				return fail( result.message );
			}

			// Guard against a card edited between verification and submission.
			if ( verifiedFor.current !== demandId ) {
				return fail(
					__(
						'Your card details changed. Please submit again to verify them.',
						'edge-gateway'
					)
				);
			}

			return {
				type: emitResponse.responseTypes.SUCCESS,
				meta: {
					paymentMethodData: {
						edge_demand_id: demandId,
					},
				},
			};
		} );

		return () => unsubscribe();
	}, [
		onPaymentSetup,
		emitResponse.responseTypes.ERROR,
		emitResponse.responseTypes.SUCCESS,
		demandId,
		failure,
		settle,
	] );

	return (
		<div className="wc-edge-payment">
			{ settings.description && (
				<div
					className="wc-edge-payment__description"
					dangerouslySetInnerHTML={ { __html: settings.description } }
				/>
			) }

			{ failure && (
				<div className="wc-edge-payment__error" role="alert">
					{ failure }
				</div>
			) }

			{ /* Kept as wide as the theme allows: the 3DS challenge is sized from
			     this element's offsetWidth, and a narrow container forces the
			     smallest, least usable challenge layout. */ }
			<div id={ containerId } style={ { width: '100%' } } />
		</div>
	);
};

/**
 * Editor preview.
 *
 * Deliberately static: the editor must not call the prepare endpoint or mount a
 * live payment iframe.
 */
const EdgeEditorPreview = () => (
	<div className="wc-edge-payment">
		{ settings.description && (
			<div dangerouslySetInnerHTML={ { __html: settings.description } } />
		) }
		<p>
			{ __(
				'The secure card form from Edge appears here at checkout.',
				'edge-gateway'
			) }
		</p>
	</div>
);

const EdgeLabel = ( { components } ) => {
	const { PaymentMethodLabel } = components;

	return <PaymentMethodLabel text={ label } />;
};

registerPaymentMethod( {
	name: 'edge',
	label: <EdgeLabel />,
	content: <EdgePaymentForm />,
	edit: <EdgeEditorPreview />,
	ariaLabel: label,
	canMakePayment: () => Boolean( settings.publishableKey ),
	supports: {
		features: settings.supports || [],
	},
} );
