<?php
/**
 * Plugin Name: Woo - MainStem
 * Plugin URI:  https://www.mainstem.io/plugins/woocommerce
 * Description: This plugin allows MainStem to view products, inventory, create new orders, and check order statuses.
 * Author:      MainStem
 * Author URI:  https://www.mainstem.io
 * Version:     1.1.2
 * Text Domain: mainstem
 * Domain Path: /languages
 *
 * @package MainStem
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Plugin constants.
 */
define('MAINSTEN_MAIN_FILE', __FILE__);
define('MAINSTEN_API_KEY_OPTION', 'MainStemAPIKey');
define('MAINSTEM_REST_API_NS', 'mainstem/v1');

/**
 * Set the installation hook and includes.
 */
function mainstem_main_hooks()
{
    register_activation_hook(__FILE__, 'mainstem_activate');

    add_action('init', 'mainstem_load_textdomain');

    require_once __DIR__ . '/includes/admin.php';
    require_once __DIR__ . '/includes/rest-api.php';
}
add_action('plugins_loaded', 'mainstem_main_hooks', 9);

/**
 * Create the WP Option for the API Key.
 */
function mainstem_activate()
{
    add_option(MAINSTEN_API_KEY_OPTION, '');
}

/**
 * Load plugin textdomain.
 */
function mainstem_load_textdomain()
{
    load_plugin_textdomain('mainstem', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
