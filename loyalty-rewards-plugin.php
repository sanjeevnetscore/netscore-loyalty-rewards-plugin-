<?php
/*
 * Plugin Name: Loyalty Rewards Plugin
 * Description: A plugin to manage loyalty points for WooCommerce.
 * Version: 1.0
 * Author: NetScore Technologies
 * Text Domain: loyalty-rewards-plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LRP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LRP_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once LRP_PLUGIN_DIR . 'includes/class-lrp-activator.php';
require_once LRP_PLUGIN_DIR . 'includes/class-lrp-admin.php';
require_once LRP_PLUGIN_DIR . 'includes/class-lrp-frontend.php';
require_once LRP_PLUGIN_DIR . 'includes/lrp-functions.php';
require_once LRP_PLUGIN_DIR . 'includes/class-lrp-api.php';
require_once LRP_PLUGIN_DIR . 'includes/class-lrp-utils.php';



function run_lrp() {
    $activator = new LRP_Activator();
    register_activation_hook(__FILE__, array($activator, 'activate'));
    register_deactivation_hook(__FILE__, array($activator, 'deactivate'));

    $admin = new LRP_Admin();
    $frontend = new LRP_Frontend();
    $api = new LRP_API();

}

run_lrp();
?>