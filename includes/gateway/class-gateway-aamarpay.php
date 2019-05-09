<?php
namespace CamptixBD\Gateway;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AamarPay gateway
 */
class AamarPay extends \CampTix_Payment_Method {

    public $id                   = 'aamarpay';
    public $name                 = 'aamarPay';
    public $description          = 'aamarPay payment gateway for Bangladesh';
    public $supported_currencies = [ 'BDT' ];

    function camptix_init() {
        $this->options = array_merge( [
            'merchant_id'   => '',
            'signature_key' => '',
            'sandbox'       => true,
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
            wp_die( __( 'The selected currency is not supported by this payment method.', 'camptix-bd-payments' ) );
        }

        $url   = $this->options['sandbox'] ? 'http://sandbox.aamarpay.com' : 'http://secure.aamarpay.com';
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
            'tix_action' => 'payment_notify',
            'tix_payment_token' => $payment_token,
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

        foreach ( $attendees as $attendee ) {
            $email = $attendee->tix_email;
            $name  = $attendee->tix_first_name . ' ' . $attendee->tix_last_name;
        }

        $args = [
            'store_id'      => $this->options['merchant_id'],
            'tran_id'       => $payment_token,
            'success_url'   => $return_url,
            'fail_url'      => site_url(),
            'cancel_url'    => $cancel_url,
            'ipn_url'       => $notify_url,
            'amount'        => $order['total'],
            'currency'      => $this->camptix_options['currency'],
            'signature_key' => $this->options['signature_key'],
            'desc'          => 'Ticket',
            'cus_name'      => $name,
            'cus_email'     => $email,
            'cus_phone'     => get_post_meta( $order['attendee_id'], 'tix_phone', true ),
        ];

        $response = wp_remote_post( $url . '/request.php', [
            'body' => $args
        ] );

        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ) );

            wp_redirect( $url . $body );
            exit;
        } else {
            $body = wp_remote_retrieve_body( $response );
            echo __( 'Something went wrong', 'camptix-bd-payments' );
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
        $this->add_settings_field_helper( 'merchant_id', __( 'Merchant ID', 'camptix-bd-payments' ), [ $this, 'field_text' ] );
        $this->add_settings_field_helper( 'signature_key', __( 'aamarPay Signature Key', 'camptix-bd-payments' ), [ $this, 'field_text' ] );
        $this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode',  'camptix-bd-payments' ), [ $this, 'field_yesno' ] );
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

        if ( isset( $input['signature_key'] ) ) {
            $output['signature_key'] = $input['signature_key'];
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
        }
    }

    /**
     * Process payment return step
     *
     * @return void
     */
    function payment_notify() {
        global $camptix;

        $camptix->log( print_r( $_REQUEST, true ) );

        $payment_token = isset( $_REQUEST['tix_payment_token'] ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

        $status         = strtolower( $_REQUEST['pay_status'] );
        $risk_level     = $_REQUEST['pg_card_risklevel'];
        $transaction_id = $_REQUEST['pg_txnid'];

        if ( isset( $_POST['mer_txnid'] ) && isset( $_POST['store_id'] ) ) {
            $payment_data = [
                'transaction_id'      => $transaction_id,
                'transaction_details' => $_REQUEST,
            ];

            if ( 'successful' == $status && $this->verify_transaction( $payment_token ) ) {

                return $camptix->payment_result( $payment_token, \CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data );

            } else {

                return $camptix->payment_result( $payment_token, \CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
            }
        }
    }

    /**
     * Cancel the payment
     *
     * @return void
     */
    public function payment_cancel() {
        global $camptix;

        $payment_token = isset( $_REQUEST['tix_payment_token'] ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

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
     * Verify the transaction
     *
     * @param  string $payment_token
     *
     * @return boolean
     */
    public function verify_transaction( $payment_token ) {
        $url = 'http://secure.aamarpay.com/api/v1/trxcheck/request.php';
        $args = [
            'body' => [
                'request_id'    => $payment_token,
                'store_id'      => $this->options['merchant_id'],
                'signature_key' => $this->options['signature_key'],
                'type'          => 'json'
            ]
        ];

        $response = wp_remote_get( $url, $args );

        if ( ! is_wp_error( $response ) ) {
            $body  = json_decode( wp_remote_retrieve_body( $response ) );
            $order = $this->get_order( $payment_token );

            if ( $body->pay_status == 'Successful' && $order['total'] == $body->amount_bdt ) {
                return true;
            }
        }

        return false;
    }

}
