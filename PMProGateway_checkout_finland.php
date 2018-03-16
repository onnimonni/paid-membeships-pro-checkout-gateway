<?php		

defined( 'ABSPATH' ) or die;

if ( ! class_exists( 'PMProGateway' ) ) { return; }

// Load init methods
add_action('init', array('PMProGateway_checkout_finland', 'init'), 50, 0);

// Load admin ajax
add_action('admin_init', array('PMProGateway_checkout_finland', 'init_webhooks'));
	
class PMProGateway_checkout_finland extends PMProGateway
{
	const CODE = 'checkout_finland';
	const WEBHOOK = 'checkout_webhook';

	function __construct($gateway = NULL)
	{
		$this->gateway = $gateway;
		return $this->gateway;
	}										
	
	/**
	 * Run on WP init
	 *		 
	 * @since 1.8
	 */
	static function init()
	{			
		//make sure PayPal Express is a gateway option
		add_filter('pmpro_gateways', array(__CLASS__, 'pmpro_gateways'));
		
		//add fields to payment settings
		add_filter('pmpro_payment_options', array(__CLASS__, 'pmpro_payment_options'));		
		add_filter('pmpro_payment_option_fields', array(__CLASS__, 'pmpro_payment_option_fields'), 10, 2);

		// Change the default country
		// FIXME: GARBAGE CODE WHICH DOESN'T WORK LIKE IT SHOULD
		global $pmpro_default_country;
		$pmpro_default_country = 'FI';
		add_filter('pmpro_international_addresses', '__return_true' );
		add_filter('pmpro_default_country', array(__CLASS__, 'pmpro_default_country'));

		//code to add at checkout
		$gateway = pmpro_getGateway();

		if($gateway == self::CODE)
		{				
			//add_filter('pmpro_include_billing_address_fields', '__return_false');
			add_filter('pmpro_include_payment_information_fields', '__return_false');
			add_filter('pmpro_required_billing_fields', array(__CLASS__, 'pmpro_required_billing_fields'));
			add_filter('pmpro_checkout_default_submit_button', array(__CLASS__, 'pmpro_checkout_default_submit_button'));
			add_filter('pmpro_checkout_before_change_membership_level', array(__CLASS__, 'pmpro_checkout_before_change_membership_level'), 10, 2);
		}
	}

	/**
	 * Handle the incoming return urls in admin ajax
	 * /wp-admin/admin-ajax.php?action=checkout_webhook&...
	 */
	static function init_webhooks() {
		add_action( "wp_ajax_nopriv_" . self::WEBHOOK, array(__CLASS__, 'incoming_webhook') );
		add_action( "wp_ajax_" . self::WEBHOOK, array(__CLASS__, 'incoming_webhook') );
	}

	/**
	 * Handle the return url for Checkout.fi, this is used for cancel, success, delayed and so on
	 */
	static function incoming_webhook()
	{
		// VALIDATE
		if ( ! self::getMerchant()->validate_return_url_params($_GET) ) {
			wp_die(__("MAC from return url does't validate against the merchant secret",'paid-memberships-pro'));
		}

		// FIND THE ORDER
		// Checkout should return the order in the reference field
		$ref = sanitize_text_field( $_GET['REFERENCE'] );

		// This field can be used to contact Checkout
		$transaction_id = sanitize_text_field( $_GET['STAMP'] );
		$morder = new MemberOrder( $ref );

		// If the MAC was correct but the order was not found
		if( empty ( $morder ) || empty ( $morder->status ) ) {
			error_log( "Order with reference (" . $ref . "). Was not found" );
			wp_die( sprintf( __( "Something went wrong! Order with reference ( %s ) can't be found. Please contact the support!" ), $ref ) );
		}

		// DO STUFF
		switch ($_GET['result']) {
			case 'success':
				// Update membership
				if ( $morder->status === 'success' ) {
					error_log( "Checkout was already processed (" . $morder->code . "). Ignoring this request." );
				} elseif ( self::handle_order( $transaction_id, $morder ) ) {
					error_log( "Checkout processed (" . $morder->code . ") success!" );
				} else {
					error_log( "ERROR: Couldn't change level for order (" . $morder->code . ")." );
				}
				break;
			case 'reject':
				# FIXME: Update the user status when after DELAYED we got REJECT
				echo "REJECT";
				break;
			case 'cancel':
				# FIXME: Show the user a cancellation page and do not update level
				echo "CANCEL";
				break;
			case 'delayed':
				# FIXME: Show the user a delayed page and for the let them read
				# until the order is completed?
				# Keep checking with wp_cron
				echo "DELAYED";
				break;
			
			default:
				# FIXME: Should trigger a warning?
				break;
		}

		// Redirect the user to the succeed page
		wp_redirect(
			pmpro_url("confirmation", "?level=" . $morder->getMembershipLevel()->id)
		);
		exit;
	}

	/**
	 * Updates the user according to the order which the user did
	 */
	static function handle_order(string $txn_id, \MemberOrder $morder) {
		// Change the membership level
		pmpro_changeMembershipLevel(
			$morder->getMembershipLevel()->id,
			$morder->user_id,
			'changed'
		);

		// Update order status and transaction ids
		$morder->status = "success";
		$morder->payment_transaction_id = $txn_id;
		$morder->saveOrder();
	}

	/**
	 * Change the default country
	 *		 
	 * @since 1.8
	 */
	static function pmpro_default_country($country)
	{
		return 'FI';
	}
	
	/**
	 * Make sure this gateway is in the gateways list
	 *		 
	 * @since 1.8
	 */
	static function pmpro_gateways($gateways)
	{
		if(empty($gateways[self::CODE]))
			$gateways[self::CODE] = __('Checkout.fi', 'paid-memberships-pro' );
	
		return $gateways;
	}
	
	/**
	 * Get a list of payment options that the this gateway needs/supports.
	 *		 
	 * @since 1.8
	 */
	static function getGatewayOptions()
	{			
		$options = array(
			'sslseal',
			'nuclear_HTTPS',
			'gateway_environment',			
			'checkout_fi_merchant_id',
			'checkout_fi_merchant_secret',
			'currency',
			'use_ssl',
			'tax_state',
			'tax_rate'
		);
		
		return $options;
	}
	
	/**
	 * Set payment options for payment settings page.
	 *		 
	 * @since 1.8
	 */
	static function pmpro_payment_options($options)
	{			
		//get stripe options
		$twocheckout_options = self::getGatewayOptions();
		
		//merge with others.
		$options = array_merge($twocheckout_options, $options);
		
		return $options;
	}
	
	/**
	 * Display fields for this gateway's options.
	 *		 
	 * @since 1.8
	 */
	static function pmpro_payment_option_fields($values, $gateway)
	{
	?>
	<tr class="pmpro_settings_divider gateway gateway_checkout_fi" <?php if($gateway != self::CODE) { ?>style="display: none;"<?php } ?>>
		<td colspan="2">
			<?php _e('Checkout.fi Settings', 'paid-memberships-pro' ); ?>
		</td>
	</tr>
	<tr class="gateway gateway_checkout_fi" <?php if($gateway != self::CODE) { ?>style="display: none;"<?php } ?>>
		<th scope="row" valign="top">
			<label for="checkout_fi_merchant_id"><?php _e('Merchant ID', 'paid-memberships-pro' );?>:</label>
		</th>
		<td>
			<input type="text" id="checkout_fi_merchant_id" name="checkout_fi_merchant_id" size="60" value="<?php echo esc_attr($values['checkout_fi_merchant_id'])?>" placeholder="375917" />
			<br /><small><?php _e('Merchant id which you received from Checkout.fi');?></small>
		</td>
	</tr>
	<tr class="gateway gateway_checkout_fi" <?php if($gateway != self::CODE) { ?>style="display: none;"<?php } ?>>
		<th scope="row" valign="top">
			<label for="checkout_fi_merchant_secret"><?php _e('Merchant Secret', 'paid-memberships-pro' );?>:</label>
		</th>
		<td>
			<input type="text" id="checkout_fi_merchant_secret" name="checkout_fi_merchant_secret" size="60" value="<?php echo esc_attr($values['checkout_fi_merchant_secret'])?>" placeholder="SAIPPUAKAUPPIAS" />
			<br /><small><?php _e('Merchant secret which you received from Checkout.fi');?></small>
		</td>
	</tr>	
	<?php
	}
	
	/**
	 * Remove required billing fields
	 *		 
	 * @since 1.8
	 */
	static function pmpro_required_billing_fields($fields)
	{
		//unset($fields['bfirstname']);
		//unset($fields['blastname']);
		//unset($fields['baddress1']);
		//unset($fields['bcity']);
		unset($fields['bstate']);
		//unset($fields['bzipcode']);
		//unset($fields['bphone']);
		//unset($fields['bemail']);
		//unset($fields['bcountry']);
		unset($fields['CardType']);
		unset($fields['AccountNumber']);
		unset($fields['ExpirationMonth']);
		unset($fields['ExpirationYear']);
		unset($fields['CVV']);
		
		return $fields;
	}
	
	/**
	 * Swap in our submit buttons.
	 *
	 * @since 1.8
	 */
	static function pmpro_checkout_default_submit_button($show)
	{
		global $gateway, $pmpro_requirebilling;
		
		//show our submit buttons
		?>			
		<span id="pmpro_submit_span">
			<input type="hidden" name="submit-checkout" value="1" />		
			<input type="submit" class="pmpro_btn pmpro_btn-submit-checkout" value="<?php if($pmpro_requirebilling) { _e('Pay with Checkout', 'paid-memberships-pro' ); } else { _e('Submit and Confirm', 'paid-memberships-pro' );}?> &raquo;" />		
		</span>
		<?php
	
		//don't show the default
		return false;
	}
	
	/**
	 * Instead of change membership levels, send users to 2Checkout to pay.
	 *
	 * @since 1.8
	 */
	static function pmpro_checkout_before_change_membership_level($user_id, $morder)
	{
		global $wpdb, $discount_code_id;
		
		//if no order, no need to pay
		if(empty($morder))
			return;
		
		$morder->user_id = $user_id;				
		$morder->saveOrder();
		
		//save discount code use
		if(!empty($discount_code_id))
			$wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . $discount_code_id . "', '" . $user_id . "', '" . $morder->id . "', now())");	
		
		do_action("pmpro_before_send_to_twocheckout", $user_id, $morder);
		
		$morder->Gateway->sendToTwocheckout($morder);
	}

	/**
	 * Creates a Merchant object from checkout/sdk
	 */
	static function getMerchant() {
		return new Checkout\v1\Merchant(
			pmpro_getOption('checkout_fi_merchant_id'),
			pmpro_getOption('checkout_fi_merchant_secret')
		);
	}
	
	/**
	 * Process checkout.
	 */
	function process(&$order)
	{						
		if(empty($order->code))
			$order->code = $order->getRandomCode();			
		
		//clean up a couple values
		$order->payment_type = "Checkout.fi";
		$order->CardType = "";
		$order->cardtype = "";
		
		//just save, the user will go to Checkout.fi to pay
		$order->status = "review";														
		$order->saveOrder();

		return true;			
	}
	
	function sendToTwocheckout(&$order)
	{
		$admin_hook_url = admin_url("admin-ajax.php") . "?action=" . self::WEBHOOK;
		// Add the order data
		$checkoutOrderData = [
		  'REFERENCE'     => substr($order->code, 0, 20), // Order reference
		  'MESSAGE'       => get_bloginfo('name') . ' - ' . substr($order->membership_level->name, 0, 127),
		  'STAMP'         => substr($order->code.(string)time(), 0, 20), // ID+Unique timestamp
		  'RETURN'        => $admin_hook_url . "&result=success",
		  'CANCEL'        => $admin_hook_url . "&result=cancel",
		  'REJECT'        => $admin_hook_url . "&result=reject",
		  'DELAYED'       => $admin_hook_url . "&result=delayed",
		  'DELIVERY_DATE' => date('Ymd'),
		  'COUNTRY'				=> $order->billing->country.'N', // FIXME: Needs to be 3 letter code, fuck my life. Happily Checkout doesn't really check that this field is correct country
		  'FIRSTNAME'     => $order->FirstName,
		  'FAMILYNAME'    => $order->LastName,
		  'ADDRESS'       => trim($order->Address1."\n".$order->Address2),
		  'POSTCODE'      => $order->billing->zip,
		  'POSTOFFICE'    => $order->billing->city,
		  'EMAIL'         => $order->Email,
		  'PHONE'         => $order->billing->phone,
		  'AMOUNT'        => ceil($order->InitialPayment*100) // Price in cents
		];
		
		$merchant = $this->getMerchant();

		$payment = $merchant->create_payment($checkoutOrderData);

		// Use the Checkout hosted payment wall for this
		try {
			$co_url = $payment->payment_wall_url();
			// redirect to Checout
			wp_redirect( $co_url );
			exit;
		} catch (Exception $e) {
			wp_die("Could'nt pay with Checkout:".$e->getMessage());
		}
	}

	function cancel(&$order) {
		//no matter what happens below, we're going to cancel the order in our system
		$order->updateStatus("cancelled");

		//require a payment transaction id
		if(empty($order->payment_transaction_id))
			return false;

		//build api params
		$params = array();
		$params['sale_id'] = $order->payment_transaction_id;
		
		// Demo mode?
		if(empty($order->gateway_environment))
			$gateway_environment = pmpro_getOption("gateway_environment");
		else
			$gateway_environment = $order->gateway_environment;
		
		if("sandbox" === $gateway_environment || "beta-sandbox" === $gateway_environment)
		{
			Twocheckout::sandbox(true);
			$params['demo'] = 'Y';
		}
		else
			Twocheckout::sandbox(false);

		$result = Twocheckout_Sale::stop( $params ); // Stop the recurring billing

		// Successfully cancelled
		if (isset($result['response_code']) && $result['response_code'] === 'OK') {
			$order->updateStatus("cancelled");	
			return true;
		}
		// Failed
		else {
			$order->status = "error";
			$order->errorcode = $result->getCode();
			$order->error = $result->getMessage();
							
			return false;
		}
		
		return $order;
	}
}
