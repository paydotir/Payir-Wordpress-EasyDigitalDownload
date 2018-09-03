<?php

if (!function_exists('edd_rial')) {

	function edd_rial($formatted, $currency, $price)
	{
		return $price . ' ریال';
	}
}

add_filter('edd_rial_currency_filter_after', 'edd_rial', 10, 3);

@session_start();

function common($url, $params)
{
	$ch = curl_init();

	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

	$response = curl_exec($ch);
	$error    = curl_errno($ch);

	curl_close($ch);

	$output = $error ? false : json_decode($response);

	return $output;
}

function payir_edd_rial($formatted, $currency, $price)
{
	return $price . ' ریال';
}

function add_payir_gateway($gateways)
{
	$gateways['payir'] = array(
		'admin_label'    => 'درگاه پرداخت و کیف پول الکترونیک Pay.ir',
		'checkout_label' => 'درگاه پرداخت و کیف پول الکترونیک Pay.ir'
	);

	return $gateways;
}

add_filter('edd_payment_gateways', 'add_payir_gateway');

function payir_cc_form()
{
	return;
}

add_action('edd_payir_cc_form', 'payir_cc_form');

function payir_process($purchase_data)
{
	global $edd_options;

	$payment_data = array(
		'price'        => $purchase_data['price'],
		'date'         => $purchase_data['date'],
		'user_email'   => $purchase_data['post_data']['edd_email'],
		'purchase_key' => $purchase_data['purchase_key'],
		'currency'     => $edd_options['currency'],
		'downloads'    => $purchase_data['downloads'],
		'cart_details' => $purchase_data['cart_details'],
		'user_info'    => $purchase_data['user_info'],
		'status'       => 'pending'
	);

	$payment = edd_insert_payment($payment_data);

	if ($payment) {

		delete_transient('edd_payir_record');
		set_transient('edd_payir_record', $payment);

		$_SESSION['edd_payir_record'] = $payment;

		if (extension_loaded('curl')) {
			$api_key     = $edd_options['payir_api'];
			if($edd_options['currency'] == 'IRT' || $edd_options['currency'] == 'toman' || $edd_options['currency'] == 'irt'){
				$amount  = intval($payment_data['price']) * 10;
			}else{
				$amount  = intval($payment_data['price']);
			}			
			$callback    = add_query_arg('verify', 'payir', get_permalink($edd_options['success_page']));
			$description = 'پرداخت صورت حساب ' . $purchase_data['purchase_key'];

			$params = array(
				'api'          => $api_key,
				'amount'       => $amount,
				'redirect'     => urlencode($callback),
				'mobile'       => null,
				'factorNumber' => $payment,
				'description'  => $description
			);

			$result = common('https://pay.ir/payment/send', $params);

			if ($result && isset($result->status) && $result->status == 1) {
				$message = 'شماره تراکنش ' . $result->transId;
				edd_insert_payment_note($payment, $message);
				$gateway_url = 'https://pay.ir/payment/gateway/' . $result->transId;
				wp_redirect($gateway_url);
				exit;
			} else {
				$message = 'در ارتباط با وب سرویس Pay.ir خطایی رخ داده است';
				$message = isset($result->errorMessage) ? $result->errorMessage : $message;
				edd_insert_payment_note($payment, $message);
				wp_die($message);
				exit;
			}

		} else {
			$message = 'تابع cURL در سرور فعال نمی باشد';
			edd_insert_payment_note($payment, $message);
			wp_die($message);
			exit;
		}

	} else {
		edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
	}
}

add_action('edd_gateway_payir', 'payir_process');

function payir_verify()
{
	global $edd_options;

	$payment_id = isset($_SESSION['edd_payir_record']) ? $_SESSION['edd_payir_record'] : null;

	if ($payment_id != null && isset($_GET['verify']) && $_GET['verify'] == 'payir' && isset($_POST['status']) && isset($_POST['transId']) && isset($_POST['factorNumber'])) {

		$status        = sanitize_text_field($_POST['status']);
		$trans_id      = sanitize_text_field($_POST['transId']);
		$factor_number = sanitize_text_field($_POST['factorNumber']);
		$message       = sanitize_text_field($_POST['message']);

		if (isset($status) && $status == 1) {

			$api_key = $edd_options['payir_api'];

			$params = array (

				'api'     => $api_key,
				'transId' => $trans_id
			);

			$result = common('https://pay.ir/payment/verify', $params);

			if ($result && isset($result->status) && $result->status == 1) {

				$card_number = isset($_POST['cardNumber']) ? $_POST['cardNumber'] : null;

				if($edd_options['currency'] == 'IRT' || $edd_options['currency'] == 'toman' || $edd_options['currency'] == 'irt'){
					$amount = intval(edd_get_payment_amount($payment_id)) * 10;
				}else{
					$amount = intval(edd_get_payment_amount($payment_id));
				}	

				if ($amount == $result->amount) {
					$message = 'تراکنش شماره ' . $trans_id . ' با موفقیت انجام شد. شماره کارت پرداخت کننده ' . $card_number;
					edd_insert_payment_note($payment_id, $message);
					edd_update_payment_status($payment_id, 'publish');
					edd_empty_cart();
					edd_send_to_success_page();
				} else {
					$message = 'رقم تراكنش با رقم پرداخت شده مطابقت ندارد';
					edd_insert_payment_note($payment_id, $message);
					edd_update_payment_status($payment_id, 'failed');
					edd_empty_cart();
					wp_redirect(get_permalink($edd_options['failure_page']));
					exit;
				}

			} else {
				$message = 'در ارتباط با وب سرویس Pay.ir و بررسی تراکنش خطایی رخ داده است';
				$message = isset($result->errorMessage) ? $result->errorMessage : $message;
				edd_insert_payment_note($payment_id, $message);
				edd_update_payment_status($payment_id, 'failed');
				edd_empty_cart();
				wp_redirect(get_permalink($edd_options['failure_page']));
				exit;
			}
		} else {
			if ($message) {
				edd_insert_payment_note($payment_id, $message);
				edd_update_payment_status($payment_id, 'failed');					
			} else {
				$message = 'تراكنش با خطا مواجه شد و یا توسط پرداخت کننده کنسل شده است';
				edd_insert_payment_note($payment_id, $message);
				edd_update_payment_status($payment_id, 'failed');
			}
			edd_empty_cart();
			wp_redirect(get_permalink($edd_options['failure_page']));
			exit;
		}
	}
}

add_action('init', 'payir_verify');

function payir_settings($settings)
{
	$payir_options = array(
		array(

			'id'   => 'payir_settings',
			'type' => 'header',
			'name' => 'تنظیمات درگاه پرداخت Pay.ir'
		),
		array(

			'id'   => 'payir_api',
			'type' => 'text',
			'name' => 'کلید API',
			'desc' => null
		)
	);

	return array_merge($settings, $payir_options);
}

add_filter('edd_settings_gateways', 'payir_settings');
