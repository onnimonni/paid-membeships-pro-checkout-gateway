<?php
/**
 * Plugin Name: Paid Memberships Pro Checkout Finland Gateway
 * Plugin URI: https://github.com/checkoutfinland/paid-memberships-pro-checkout-gateway
 * Description: Enable Checkout.fi payment methods in your Paid Memberships Pro site
 * Author: Onni Hakala / Checkout Finland Oy
 * Author URI: https://checkout.fi
 * License: GPLv2
 * License URI: https://github.com/checkoutfinland/paid-memberships-pro-checkout-gateway/blob/master/LICENSE
 * Version: 1.0.0
 */


// load payment gateway class
require_once(__DIR__ . "/PMProGateway_checkout_finland.php");

// load classes init method
//add_action('init', array('PMProGateway_Checkout', 'init'));