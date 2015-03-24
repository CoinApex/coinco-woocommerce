## CoinCo WooCommerce plugin

Coin.co makes it easy for merchants to accept payments in Bitcoin through WordPress. Using this plugin merchants can accept BTC yet receive USD deposited directly into their bank accounts via ACH transfers. Coin.co merchants pay zero processing fees, and no monthly fees of any kind. 

Please note that this plugin currently only works with USD.


#### Requirements

* WordPress `>= 3.9`
* WooCommerce `>= 2.2`


#### Installation Guide

1. Download the latest version of this plugin from `https://github.com/CoinApex/coinco-woocommerce/releases`
2. Go to `<your domain name here>/wp-admin/plugin-install.php` and click `Upload plugin`. Choose the previously downloaded file (you do not have to decompress it).

#### Settings

1. Go to WooCommerce's settings under `<your domain name here>/wp-admin`. Make sure currency is set to dollars under the 'General' settings tab. Coin.Co does not support other currencies.

2. Go to `https://coin.co/developers/authentication` for instructions on how to generate API keys for your merchant account.

3. Under the 'Checkout' tab with there should be a link to Coin.co's settings at the top. Click on it and paste the generated API Key from step (2) into the API Key field.

4. Sell your heart out!


#### How It Works
 
1. The customer selects and buys their product through the WordPress interface and eventually reaches the checkout page. The customer then selects the Bitcoin payment method and continues.

2. The customer is then directed to the Coin.co Bitcoin invoice page, where they are presented with the amount in Bits, the USD conversion rate, a wallet address, and a QR code (for mobile bitcoin wallets). As soon as the customer pays, they are redirected back to your WordPress site.

3. Upon a successful amount of confirmations from the Bitcoin network, the customer's order will be marked as 'processing' by this plugin, giving you a green flag to ship the product to the customer. This entire process generally takes only a few seconds to a few minutes.
 
