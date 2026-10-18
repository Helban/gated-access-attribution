<?php
/**
 * Plugin Name:          Gated Access & Referral Attribution for WooCommerce
 * Plugin URI:           https://github.com/Helban/woo-gated-access
 * Description:          Shows the catalog only to buyers who verify their email with a one-time code, and records on every order which sales rep referred the buyer.
 * Version:              0.1.0
 * Requires at least:    7.1
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               Helban
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          gated-access-attribution
 * Domain Path:          /languages
 * WC requires at least: 11.2
 * WC tested up to:      11.2
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/includes/autoload.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );
add_action( 'before_woocommerce_init', __NAMESPACE__ . '\declare_woocommerce_compatibility' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\boot' );

/**
 * Creates the plugin's tables, its sign-in page and a webhook secret.
 */
function activate(): void {
	( new Schema() )->install();
	( new Sign_In_Page() )->ensure_exists();

	// add_option() leaves an existing secret alone. Not autoloaded: only the webhook reads it.
	add_option( Webhook_Controller::SECRET_OPTION, wp_generate_password( 64, false ), '', false );
}

/**
 * Stops scheduled cleanup while the plugin is inactive.
 */
function deactivate(): void {
	wp_clear_scheduled_hook( Plugin::PRUNE_HOOK );
}

/**
 * Tells WooCommerce which of its optional features this plugin supports.
 *
 * Order data is read and written only through the WC_Order CRUD API, so the
 * plugin works with both HPOS and the legacy posts table.
 */
function declare_woocommerce_compatibility(): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PLUGIN_FILE, true );
	}
}

/**
 * Wires the plugin into WordPress once WooCommerce has loaded.
 */
function boot(): void {
	if ( ! class_exists( \WooCommerce::class ) ) {
		return;
	}

	plugin()->register_hooks();
}

/**
 * Returns the plugin's composition root, building it on first use.
 */
function plugin(): Plugin {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new Plugin();
	}

	return $plugin;
}
