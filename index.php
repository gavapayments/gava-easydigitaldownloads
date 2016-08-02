<?php

/*
Plugin Name: Gava For Easy Digital Downloads
Plugin URI: http://github.com/gava-easydigitaldownloads
Description: Gava Payment Gateway Plugin For Easy Digital Downloads
Version: 0.1
Author: Sam Takunda
Author URI: http://github.com/ihatehandles
*/

//Register the gateway
function gava_edd_register_gateway($gateways)
{
	$gateways['gava_edd'] = array(
		'admin_label' => 'Gava',
		'checkout_label' => __('EcoCash or TeleCash', 'gava_edd')
	);

	return $gateways;
}

add_filter('edd_payment_gateways', 'gava_edd_register_gateway');

function gava_edd_gateway_cc_form()
{
	return;
}

add_action('edd_gava_edd_cc_form', 'gava_edd_gateway_cc_form');

function gava_edd_settings($settings)
{
	$s = array(
			array(
				'id' 	=> 'gava_edd_settings_h',
				'name' 	=> '<strong>' . __('Gava Settings', 'gava_edd') . '</strong>',
				'desc' 	=> __('Configure the gateway settings', 'gava_edd'),
				'type'	=> 'header'
				),
			array(
				'id'	=> 'gava_checkout_url',
				'name'	=> __('Checkout URL', 'gava_edd'),
				'desc'	=> __('The URL to the root of your Gava installation e.g example.com/checkout'),
				'type'	=> 'text',
				'size'	=> 'regular'
				),
			array(
				'id'	=> 'gava_secret_key',
				'name'	=> __('Gava Secret Key', 'gava_edd'),
				'desc'	=> __('Your Secret Key as configured in your Gava installation'),
				'type'	=> 'text',
				'size' 	=> 'regular'
				)
			);
	return array_merge($settings, $s);
}

add_filter('edd_settings_gateways', 'gava_edd_settings');


function gava_process_payment($purchase_data)
{
	global $edd_options;

	$gava_checkout_url = $edd_options['gava_checkout_url'];

	$purchase_summary = edd_get_purchase_summary($purchase_data);

	$payment = array(
		'price' 		=> $purchase_data['price'],
		'date' 			=> $purchase_data['date'],
		'user_email' 	=> $purchase_data['user_email'],
		'purchase_key' 	=> $purchase_data['purchase_key'],
		'currency' 		=> $edd_options['currency'],
		'downloads' 	=> $purchase_data['downloads'],
		'cart_details' 	=> $purchase_data['cart_details'],
		'user_info' 	=> $purchase_data['user_info'],
		'status' 		=> 'pending',
		'gateway' 		=> 'gava_edd'
	);

	$payment = edd_insert_payment($payment);

	//Check payment
	if (!$payment)
	{
		edd_record_gateway_error(
			__( 'Payment error', 'edd' ),
			sprintf( __( 'Payment creation failed before sending buyer to Gava. Payment data: %s', 'edd' ),
			json_encode($payment_data) ),
			$payment
		);

		//Redirect the buyer again to checkout
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );

	} else {

		$return_url = get_permalink( $edd_options['success_page'] );

		$payload = array(
			'reference' => $payment,
			'amount' => number_format($purchase_data['price'], 2, '.', null),
			'return_url' => $return_url,
			'cancel_url' => $return_url,
		);

		$payload['signature'] = gava_sign($payload);

		//create checkout, redirect to it
		$endpoint = rtrim($edd_options['gava_checkout_url'], '/') . '/create?return_checkout_url';
		$response = wp_remote_post($endpoint, array('body' => $payload));

		if (is_wp_error($response)) {
			gava_exit_with_error($response);
		}

		$redirect = wp_remote_retrieve_body($response);

		$responseCode = wp_remote_retrieve_response_code($response);

		if ($responseCode !== 200) {
			gava_exit_with_error($response);
		}

		//Clear cart
		edd_empty_cart();

		wp_redirect($redirect);
		exit();
	}

}

add_action('edd_gateway_gava_edd', 'gava_process_payment');

/**
 * Exit with error during checkout
 *
 * @param mixed $error Error
 * @return void
 */
function gava_exit_with_error($error)
{
	global $edd_options;

	$cartURL = get_permalink($edd_options['purchase_page']);
	echo "<p>Failed to create checkout. Please contact support, or <a href='".$cartURL."'>click here</a> ".
		"to return to the website and try again</p>";

	/*
	//Just gonna leave this here
	echo '<p>Error details: </p><pre>';
	print_r($error);
	echo '</pre>';
	*/

	exit();
}

/**
 * Checks if two numerics are equal
 *
 * @param mixed $a First one
 * @param mixed $b First one
 * @return bool
 */
function gava_equal_floats($a, $b)
{
	$a = (float)$a;
	$b = (float)$b;

	if (abs(($a-$b)/$b) < 0.00001) {
		return TRUE;
	}

	return FALSE;
}

/**
 * Exit with error
 *
 * @todo 	HTTP Status
 * @param 	string $error Error
 * @return 	void
 */
function gava_callback_error($error)
{
	echo $error;
	die();
}

/**
 * Fetches checkout with given hash.
 * A return of false generally means the checkout is not valid to us
 * Will exit with error for the ither scenarios
 *
 * @param string $hash Checkout hash
 * return object|false
 */
function gava_fetch_checkout($hash)
{
	global $edd_options;

	//Get checkout, confirm sig

	$endpoint = rtrim($edd_options['gava_checkout_url'], '/') . '/checkout/details/' . $hash;

	$response = wp_remote_get($endpoint);

	if (is_wp_error($response)) {
		return false;
	}

	$responseCode = wp_remote_retrieve_response_code($response);

	if ($responseCode !== 200) {
		gava_callback_error('Non-200 status during checkout fetch');
	}

	$checkout = json_decode(wp_remote_retrieve_body($response));

	if (!$checkout) return false;

	$expectedProperties = array(
		'checkoutId',
		'checkoutHash',
		'reference',
		'paid',
		'amount',
		'phone',
		'transactionCode',
		'paymentMethod',
		'note',
		'signature'
	);

	foreach ($expectedProperties as $property) {

		if (!property_exists($checkout, $property)) return false;

	}

	if (!gava_validate_signature($checkout)) return false;

	return $checkout;
}


/**
 * Given a iterable $payload, it signs it with the secret key
 *
 * @param mixed $payload Object or array
 * @return mixed
 */
function gava_sign($payload)
{
	global $edd_options;

	$string = '';

	foreach ($payload as $key => $value) {
		if ($key === 'signature') continue;

		$string .= $value;
	}

	return hash('sha512', $string . $edd_options['gava_secret_key']);
}


/**
 * Given a iterable $payload, it signs it with the secret key
 *
 * @param mixed $payload Object or array
 * @return mixed
 */
function gava_validate_signature($request)
{
	global $edd_options;

	$string = '';

	foreach ($request as $key => $value) {
		if ($key === 'signature') continue;

		$string .= $value;
	}

	$signature = hash('sha512', $string . $edd_options['gava_secret_key']);
	return ($signature === $request->signature);
}

/**
 * Exit the script with (optional) $message
 *
 * @param string|null $message Message
 * @return void
 */
function gava_exit($message = null)
{
	//if ($message) echo $message;
	exit();
}

/**
 * Listens for and processes Gava callabacks
 *
 * @return void
 */
function gava_edd_listen_for_callback()
{
	global $edd_options;

	if (!isset($_GET['gava_callback'])) return;
	
	//Listen for callback, validate with server, close checkout
	$callback = json_decode(file_get_contents('php://input'));

	if (!$callback) gava_callback_error('Missing parameters');

	$expectedProperties = array(
		'checkoutId',
		'checkoutHash',
		'reference',
		'paid',
		'amount',
		'phone',
		'transactionCode',
		'paymentMethod',
		'note',
		'signature'
	);

	foreach ($expectedProperties as $property) {

		if (!property_exists($callback, $property)) gava_callback_error('Missing parameters');

	}

	if (!gava_validate_signature($callback))
		gava_callback_error('Callback signature validation failed');

	if (!$checkout = gava_fetch_checkout($callback->checkoutHash))
		gava_callback_error('Checkout fetch failed');

	//Defense: Gava doesn't yet have automated status changes from paid to not paid
	if (!$checkout->paid) gava_exit('Checkout not paid on Gava');

	//Return silently if the payment cannot be found
	if (!$payment = get_post($checkout->reference)) gava_exit('Payment not found');
	if ($payment->post_type !== 'edd_payment') gava_exit('Post type not edd_payment');
	if (edd_get_payment_gateway($checkout->reference) !== 'gava_edd') gava_exit('Payment gateway not gava_edd');

	//Already published
	if (get_post_status($checkout->reference) == 'publish') gava_exit('Already set to publish');

	//I'm not sure under what conditions this would occur considering Gava checks that, but 
	if (!gava_equal_floats($checkout->amount, edd_get_payment_amount($checkout->reference))) {

		edd_record_gateway_error(
			__( 'Amount mismatch error', 'gava_edd' ),
			sprintf(
				__( 'The amount paid (%s) and the amount expected are different', 'gava_edd'),
				$checkout->amount
			),
			$checkout->reference
		);

		//Email admin
		$body = "Customer paid $".$checkout->amount.". The order required ".$edd_get_payment_amount($checkout->reference);
		$subject = '[Gava Easy Digital Downloads] Wrong settlement amount for order: '.$checkout->reference;
		wp_mail(get_option('admin_email'), $subject, $body);
		gava_exit('Amount mismatch');
	}

	//We get this far, we can complete the order
	edd_insert_payment_note(
		$checkout->reference,
		sprintf(
			__('Gava Checkout ID: %s, Paid by: %s, transaction code: %s', 'gava_edd'),
			$checkout->id,
			$checkout->phone,
			$checkout->transactionCode
		)
	);

	edd_update_payment_status($checkout->reference, 'publish');
	gava_exit('Successfully processed');
}

add_action('init', 'gava_edd_listen_for_callback');
