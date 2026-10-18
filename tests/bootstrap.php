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

// wp-env serves the test site from localhost, which makes WordPress's default
// sender "wordpress@localhost". is_email() rejects that, so wp_mail() would fail.
tests_add_filter(
	'wp_mail_from',
	static fn (): string => 'store@example.org'
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		WC_Install::install();

		// WooCommerce adds roles during install; rebuild them so capability checks see them.
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Same reset WooCommerce's own test bootstrap does.
		wp_roles();

		// Order storage is fixed for the whole run: WooCommerce does not switch data stores cleanly mid-process.
		// `composer test` runs the suite once per storage.
		update_option( 'woocommerce_custom_orders_table_enabled', 'posts' === getenv( 'GAA_TEST_ORDER_STORAGE' ) ? 'no' : 'yes' );

		// WordPress's installer recreates core tables on every run; do the same for the plugin's,
		// so they always match the definitions in Schema.
		( new GatedAccess\Schema() )->drop_tables();
		GatedAccess\activate();
	}
);

require_once $gaa_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/support/class-gaa-redirect-intercepted.php';
require_once __DIR__ . '/support/trait-gaa-test-helpers.php';
