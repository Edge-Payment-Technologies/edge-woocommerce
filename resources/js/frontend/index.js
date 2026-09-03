import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
import { createElement, Fragment, useEffect } from '@wordpress/element';

const settings = getSetting( 'edge_data', {} );

const defaultLabel = __( 'Edge Payments', 'edge-gateway-for-woocommerce' );

const label = decodeEntities( settings.title ) || defaultLabel;

let edgeClient;
let edgePaymentDemandId = '';
let edgePaymentMethodReady = false;

/**
 * Custom form field component
 */
const LoadEdgePaymentsForm = () => {
	useEffect( () => {
		// Load the remote script here
		const script = document.createElement( 'script' );
		script.src =
			'https://assets.tryedge.io/assets/js/edge-0082aa6231d2034eb9d5c489d74c41bb.js?vsn=d';
		//script.async = true;
		script.onload = ActivateEdgePayments;
		document.body.appendChild( script );

		return () => {
			//document.body.removeChild(script);
		};
	}, [] ); // Empty dependency array ensures it only runs once during component mount

	return createElement( 'div', { id: 'card-fields' } );
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
	edgeClient = new Edge( settings.publishable_key, {
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
	const { onPaymentSetup } = eventRegistration;
	const billingAddress = billing?.billingAddress || {};
	const shippingAddress = shippingData?.shippingAddress || billingAddress;

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

	return createElement(
		Fragment,
		null,
		createElement( 'div', {
			dangerouslySetInnerHTML: { __html: settings.description },
		} ),
		createElement( LoadEdgePaymentsForm )
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
