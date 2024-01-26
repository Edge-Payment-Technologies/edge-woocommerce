# WooCommerce Edge Payments Gateway

## Installation

To install the WooCommerce Edge Payments Gateway plugin, follow these steps:

1. Download the plugin files from the WooCommerce Edge Payments Gateway repository.
2. Upload the plugin files to the `/wp-content/plugins/` directory.
3. Run `composer install` in the root directory of the plugin to install the required dependencies.

## Activation in WordPress and WooCommerce

After installing the WooCommerce Edge Payments Gateway plugin, follow these steps to activate it in WordPress and WooCommerce:

1. In WordPress, navigate to the 'Plugins' section.
2. Find 'WooCommerce Edge Payments Gateway' in the list of plugins and click 'Activate'.
3. In WooCommerce, go to 'WooCommerce' > 'Settings' > 'Payments'.
4. Enable 'WooCommerce Edge Payments Gateway' from the list of available payment methods.

Now, the WooCommerce Edge Payments Gateway is installed, activated, and ready to use for processing payments on your WooCommerce store.


### Development Building Instructions

To build the js in this project, run: 

```
nvm use
npm install
npm run packages-update
npm run build
```
