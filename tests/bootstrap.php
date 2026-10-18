<?php
/**
 * PHPUnit bootstrap: loads WooCommerce and this plugin into the WordPress test suite.
 *
 * WP_TESTS_DIR points at the WordPress PHPUnit library. wp-env sets it in
 * every container, so `composer test` needs no extra configuration.
 *
 * @package GatedAccess
 */

$gaa_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $gaa_tests_dir || '' === $gaa_tests_dir ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run the suite with `composer test`, which runs it inside wp-env.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded yet.
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $gaa_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$plugin_file = WP_PLUGIN_DIR . '/gated-access-attribution/gated-access-attribution.php';

		// Keeps plugin_basename() correct when the plugin directory is a symlink.
		wp_register_plugin_realpath( $plugin_file );

		require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require_once $plugin_file;
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		WC_Install::install();

		// WooCommerce adds roles during install; rebuild them so capability checks see them.
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Same reset WooCommerce's own test bootstrap does.
		wp_roles();
	}
);

require_once $gaa_tests_dir . '/includes/bootstrap.php';
