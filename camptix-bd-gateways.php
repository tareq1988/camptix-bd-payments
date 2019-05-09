<?php
/**
 * Plugin Name: Camptix BD Payments
 * Description: Bangladeshi payment gateways for CampTix
 * Plugin URI: https://github.com/tareq1988/camptix-bd-payments
 * Author: Tareq Hasan
 * Author URI: https://tareq.co
 * Version: 1.0
 * License: GPL2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: camptix-bd-payments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main Class
 */
class CampTix_BD_Gateways {

    function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
    }

    public function init_plugin() {
        $this->includes();
        $this->init_classes();
    }

    public function includes() {
        require_once __DIR__ . '/includes/class-phone-field.php';
        require_once __DIR__ . '/includes/gateway/class-gateway-aamarpay.php';
    }

    public function init_classes() {
        new CamptixBD\Phone_Field();
        new CamptixBD\Gateway\AamarPay();
    }
}

new CampTix_BD_Gateways();
