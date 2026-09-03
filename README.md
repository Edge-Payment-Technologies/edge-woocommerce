# Edge Gateway for WooCommerce

This is the official Wordpress plugin for utilizing Edge Payment Technologies, Inc. as a payment gateway in WooCommerce stores.

## Dependencies

- WooCommerce

## Version Support Policy

We adopt the L-2 version support policy for WordPress core strictly, and a loose L-2 policy for WooCommerce.

## Installation

To install the Edge Gateway for WooCommerce plugin, follow these steps:

1. Download the plugin files from the Edge Gateway for WooCommerce repository.
2. Upload the plugin files to the `/wp-content/plugins/` directory.
3. Run `composer install` in the root directory of the plugin to install the required dependencies.

## Activation in WordPress and WooCommerce

After installing the Edge Gateway for WooCommerce plugin, follow these steps to activate it in WordPress and WooCommerce:

1. In WordPress, navigate to the 'Plugins' section.
2. Find 'Edge Gateway for WooCommerce' in the list of plugins and click 'Activate'.
3. In WooCommerce, go to 'WooCommerce' > 'Settings' > 'Payments'.
4. Enable 'Edge Gateway for WooCommerce' from the list of available payment methods.

Now, the Edge Gateway for WooCommerce is installed, activated, and ready to use for processing payments on your WooCommerce store.


### Development Building Instructions

To build the js in this project, run:

```
nvm use
npm install
npm run packages-update
npm run build
```
