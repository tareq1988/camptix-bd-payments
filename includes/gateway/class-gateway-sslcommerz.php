<?php
namespace CamptixBD\Gateway;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AamarPay gateway
 */
class SSLCommerz extends \CampTix_Payment_Method {

	public $id                   = 'sslcommerz';
	public $name                 = 'SSLCommerz';
	public $description          = 'SSLCommerz payment gateway for Bangladesh';
	public $supported_currencies = [ 'BDT' ];

	function camptix_init() {
		$this->options = array_merge( [
			'merchant_id'    => '',
			'store_password' => '',
			'sandbox'        => true,
		], $this->get_payment_options() );

		if ( $this->gateway_enabled() ) {
			add_filter( 'camptix_form_register_complete_attendee_object', [ $this, 'add_attendee_info' ], 10, 3 );
			add_action( 'template_redirect', [ $this, 'template_redirect' ] );
		}
	}

	/**
	 * Check if the gateway is enabled
	 *
	 * @return boolean
	 */
	public function gateway_enabled() {
		return isset( $this->camptix_options['payment_methods'][ $this->id ] );
	}

	/**
	 * If the phone number is passed, add this to the attendee object
	 *
	 * @param [type] $attendee
	 * @param [type] $attendee_info
	 * @param [type] $current_count
	 */
	public function add_attendee_info( $attendee, $attendee_info, $current_count ) {
		if ( ! empty( $_POST['tix_attendee_info'][ $current_count ]['phone'] ) ) {
			$attendee->phone = trim( $_POST['tix_attendee_info'][ $current_count ]['phone'] );
		}

		return $attendee;
	}

	/**
	 * Process the payment
	 *
	 * @param  string $payment_token
	 *
	 * @return void
	 */
	public function payment_checkout( $payment_token ) {
		global $camptix;

		if ( ! $payment_token || empty( $payment_token ) ) {
			return false;
		}

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) ) {
			wp_die( __( 'The selected currency is not supported by this payment method.', 'bd-payments-camptix' ) );
		}

		$url   = $this->options['sandbox'] ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
		$order = $this->get_order( $payment_token );

		$return_url = add_query_arg( array(
			'tix_action'         => 'payment_return',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
		), $camptix->get_tickets_url() );

		$cancel_url = add_query_arg( array(
			'tix_action'         => 'payment_cancel',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
		), $camptix->get_tickets_url() );

		$notify_url = add_query_arg( array(
			'tix_action'         => 'payment_notify',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
		), $camptix->get_tickets_url() );

		$fail_url = add_query_arg( array(
			'tix_action'         => 'payment_failed',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
		), $camptix->get_tickets_url() );

		$attendees = get_posts(
			[
				'post_type'   => 'tix_attendee',
				'post_status' => 'any',
				'meta_query'  => [
					[
						'key'     => 'tix_payment_token',
						'compare' => '=',
						'value'   => $payment_token,
					],
				],
			]
		);

		// take the first attendee as the customer because
		// we need the name and phone number for the gateway
		$attendee = reset( $attendees );
		$email = $attendee->tix_email;
		$name  = $attendee->tix_first_name . ' ' . $attendee->tix_last_name;
		$phone = $attendee->tix_phone;

		// build the payment description wth sitename and
		// ticket names with quantity
		$description = get_bloginfo( 'description' ) . ' ' . __( 'ticket', 'bd-payments-camptix' );;

		foreach ( $order['items'] as $ticket ) {
			$description .= ' | ' . $ticket['name'] . ' x' . $ticket['quantity'];
		}

		$args = [
			'store_id'     => $this->options['merchant_id'],
			'tran_id'      => $payment_token,
			'success_url'  => $return_url,
			'fail_url'     => $fail_url,
			'emi_option'   => 0,
			'cancel_url'   => $cancel_url,
			'ipn_url'      => $notify_url,
			'total_amount' => $order['total'],
			'currency'     => $this->camptix_options['currency'],
			'store_passwd' => $this->options['store_password'],
			'desc'         => $description,
			'cus_name'     => $name,
			'cus_email'    => $email,
			'cus_phone'    => $phone,
		];

		$response = wp_remote_post( $url . '/gwprocess/v3/api.php', [
			'body' => $args
		] );

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['GatewayPageURL'] ) && $body['GatewayPageURL'] != '' ) {
				wp_redirect( $body['GatewayPageURL'] );
				exit;
			}
		} else {
			$body = wp_remote_retrieve_body( $response );
			echo __( 'Something went wrong', 'bd-payments-camptix' );
			return;
		}

		return;
	}

	/**
	 * Add payment settings field
	 *
	 * @return void
	 */
	function payment_settings_fields() {
		$this->add_settings_field_helper( 'merchant_id', __( 'Store ID', 'bd-payments-camptix' ), [ $this, 'field_text' ] );
		$this->add_settings_field_helper( 'store_password', __( 'Store Password', 'bd-payments-camptix' ), [ $this, 'field_text' ] );
		$this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode',  'bd-payments-camptix' ), [ $this, 'field_yesno' ] );
	}

	/**
	 * Validate payment settings fields
	 *
	 * @param  array $input
	 *
	 * @return array
	 */
	function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['merchant_id'] ) ) {
			$output['merchant_id'] = $input['merchant_id'];
		}

		if ( isset( $input['store_password'] ) ) {
			$output['store_password'] = $input['store_password'];
		}

		if ( isset( $input['sandbox'] ) ) {
			$output['sandbox'] = (bool) $input['sandbox'];
		}

		return $output;
	}

	/**
	 * Monitor for IPN and payment return
	 *
	 * @return void
	 */
	function template_redirect() {
		if ( ! isset( $_REQUEST['tix_payment_method'] ) || $this->id != $_REQUEST['tix_payment_method'] ) {
			return;
		}

		if ( isset( $_GET['tix_action'] ) ) {
			if ( 'payment_cancel' == $_GET['tix_action'] ) {
				$this->payment_cancel();
			}

			if ( 'payment_return' == $_GET['tix_action'] ) {
				$this->payment_notify();
			}

			if ( 'payment_notify' == $_GET['tix_action'] ) {
				$this->payment_notify();
			}

			if ( 'payment_failed' == $_GET['tix_action'] ) {
				$this->payment_failed();
			}
		}
	}

	/**
	 * Process payment return step
	 *
	 * @return mixed
	 */
	function payment_notify() {
		global $camptix;

		$payment_token  = isset( $_REQUEST['tix_payment_token'] ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		$transaction_id = isset( $_REQUEST['tran_id'] ) ? $_REQUEST['tran_id'] : '';
		$val_id         = isset( $_REQUEST['val_id'] ) ? $_REQUEST['val_id'] : '';

		$camptix->log( 'Payment validation from SSLCommerz', null, compact( 'payment_token', 'transaction_id', 'val_id' ) );

		if ( $this->_ipn_hash_varify( $this->options['store_password'] ) ) {

			$camptix->log('IPN hash verified');

			$payment_data = [
				'transaction_id'      => $transaction_id,
				'val_id'              => $val_id,
				'transaction_details' => $_REQUEST,
			];

			if ( $this->verify_transaction( $val_id, $payment_token ) ) {
				return $camptix->payment_result( $payment_token, \CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data );
			} else {
				$camptix->log( 'IPN Verification failed', null, $payment_data );
				return $camptix->payment_result( $payment_token, \CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
			}
		}

		return $camptix->payment_result( $payment_token, \CampTix_Plugin::PAYMENT_STATUS_FAILED );
	}

	/**
	 * Cancel the payment
	 *
	 * @return void
	 */
	public function payment_cancel() {
		global $camptix;

		$payment_token = isset( $_REQUEST['tix_payment_token'] ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		$camptix->log('Cancel token: ' . $payment_token );

		if ( ! $payment_token ) {
			wp_die( 'empty token' );
		}

		$order = $this->get_order( $payment_token );

		if ( ! $order ) {
			wp_die( 'could not find order' );
		}

		return $camptix->payment_result( $payment_token, \CampTix_Plugin::PAYMENT_STATUS_CANCELLED );
	}

	/**
	 * Fail the payment
	 *
	 * @return void
	 */
	public function payment_failed() {
		global $camptix;

		$payment_token = isset( $_REQUEST['tix_payment_token'] ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		$camptix->log('Fail token: ' . $payment_token );

		if ( ! $payment_token ) {
			wp_die( 'empty token' );
		}

		$order = $this->get_order( $payment_token );

		if ( ! $order ) {
			wp_die( 'could not find order' );
		}

		return $camptix->payment_result( $payment_token, \CampTix_Plugin::PAYMENT_STATUS_FAILED );
	}

	/**
	 * Verify the transaction
	 *
	 * @param  string $payment_token
	 *
	 * @return boolean
	 */
	public function verify_transaction( $val_id, $payment_token ) {
		global $camptix;

		$url  = $this->options['sandbox'] ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
		$url  = $url . '/validator/api/validationserverAPI.php';
		$args = [
			'body' => [
				'val_id'       => $val_id,
				'store_id'     => $this->options['merchant_id'],
				'store_passwd' => $this->options['store_password'],
				'format'       => 'json'
			]
		];

		$response = wp_remote_get( $url, $args );

		if ( ! is_wp_error( $response ) ) {
			$body  = json_decode( wp_remote_retrieve_body( $response ) );
			$order = $this->get_order( $payment_token );

			// $camptix->log( print_r( $body, true ) );

			if ( in_array( $body->status, ['VALID', 'VALIDATED'] ) && $order['total'] == $body->amount ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Verify IPN hash
	 *
	 * @param  string $store_passwd
	 *
	 * @return boolean
	 */
	function _ipn_hash_varify( $store_passwd ) {

		if ( isset( $_POST ) && isset( $_POST['verify_sign'] ) && isset( $_POST['verify_key'] ) ) {
			$pre_define_key = explode(',', $_POST['verify_key']);
			$new_data       = array();

			if ( !empty( $pre_define_key ) ) {
				foreach ( $pre_define_key as $value ) {
					if ( isset( $_POST[ $value ] ) ) {
						$new_data[ $value ] = $_POST[$value];
					}
				}
			}

			# ADD MD5 OF STORE PASSWORD
			$new_data['store_passwd'] = md5( $store_passwd );

			# SORT THE KEY AS BEFORE
			ksort( $new_data );

			$hash_string = '';
			foreach ( $new_data as $key => $value ) {
				$hash_string .= $key . '=' . $value .'&';
			}

			$hash_string = rtrim( $hash_string, '&' );

			if ( md5( $hash_string ) == $_POST['verify_sign'] ) {
				return true;
			} else {
				return false;
			}
		}

		return false;
	}

}
