
import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
import { useEffect } from '@wordpress/element';
import { React, useState } from 'react';


const settings = getSetting('edge_data', {});

const defaultLabel = __(
  'Edge Payments',
  'woo-gutenberg-products-block'
);

const label = decodeEntities(settings.title) || defaultLabel;

var edgeCardData = {
  'number': '',
  'cvc': '',
  'expiryMonth': '',
  'expiryYear': ''
};

/**
 * Custom form field component
 */
const LoadEdgePaymentsForm = () => {
  const [value, setValue] = useState('');

  useEffect(() => {
    // Load the remote script here
    const script = document.createElement('script');
    script.src = 'https://assets.tryedge.com/assets/edge-a05367bf1a6ff58a0349a85462352e392f28e68664bb817062701c801cd4b0c7.js';
    //script.async = true;
    script.onload = ActivateEdgePayments;
    document.body.appendChild(script);

    return () => {
      //document.body.removeChild(script);
    };
  }, []); // Empty dependency array ensures it only runs once during component mount


  return (
    <div id="card-fields"></div>
  );
};


const ActivateEdgePayments = () => {


  // Initialize Edge with the publishable key from settings
  const edgeJS = new Edge(settings.publishable_key);

  // Initialize the form with Edge
  edgeJS.initializeForm().then(function (form) {

    // Configure the form inputs
    const inputs = form.inputs('card-fields', {
      theme: 'default',
      inputBorderColor: '#d0d5dd',
      inputTextColor: '#212529',
      primaryColor: '#155eef',
      inputBorderRadius: '8px',
      inputHeight: '40px',
      inputFontSize: '13px',
      inputBoxShadowString: '0px 1px 2px rgba(16, 24, 40, 0.05)'
    });

    // Listen for changes in the form inputs
    inputs.on('change', async (data) => {
      let cardData = data.encryptedCard;
      // Update the hidden fields with the encrypted card data
      edgeCardData.number = cardData.number;
      edgeCardData.cvc = cardData.cvc;
      edgeCardData.expiryMonth = cardData.expMonth;
      edgeCardData.expiryYear = cardData.expYear;

    });

  });
}
/**
 * Content component
 */
const Content = (props) => {
  const { eventRegistration, emitResponse } = props;
  const { onPaymentSetup } = eventRegistration;

  useEffect(() => {
    const unsubscribe = onPaymentSetup(async () => {

      const EdgePaymentData = edgeCardData;
      const customDataIsValid = !!EdgePaymentData.number.length;

      if (customDataIsValid) {
        return {
          type: emitResponse.responseTypes.SUCCESS,
          meta: {
            paymentMethodData: {
              'number': EdgePaymentData.number,
              'cvc': EdgePaymentData.cvc,
              'month': EdgePaymentData.expiryMonth,
              'year': EdgePaymentData.expiryYear,
            },
          },
        };
      }

      return {
        type: emitResponse.responseTypes.ERROR,
        message: 'Please enter your card information.',
      };
    });
    // Unsubscribes when this component is unmounted.
    return () => {
      unsubscribe();
    };
  }, [
    emitResponse.responseTypes.ERROR,
    emitResponse.responseTypes.SUCCESS,
    onPaymentSetup,
  ]);

  return (
    <>
      <div dangerouslySetInnerHTML={{ __html: settings.description }} />
      <LoadEdgePaymentsForm />
    </>
  );
};
/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = (props) => {
  const { PaymentMethodLabel } = props.components;
  return <PaymentMethodLabel text={label} />;
};



/**
 * Edge payment method config object.
 */
const WCEdge = {
  name: "edge",
  label: <Label />,
  content: <Content />,
  edit: <Content />,
  canMakePayment: () => true,
  ariaLabel: label,
  supports: {
    features: settings.supports,
  },
};


registerPaymentMethod(WCEdge);

const EventLogger = () => {
  useEffect(() => {
    // Get all event names from the window object
    const eventNames = Object.keys(window);

    // Log every event to the console
    eventNames.forEach(eventName => {
      window.addEventListener(eventName, event => {
        console.log(`Event: ${eventName}`, event);
      });
    });

    // Clean up event listeners when the component unmounts
    return () => {
      eventNames.forEach(eventName => {
        window.removeEventListener(eventName);
      });
    };
  }, []); // Empty dependency array ensures the effect runs only once on mount

  return null; // or your component JSX
};

export default EventLogger;
