<?php
/**
 * Tests that the plugin loads next to WooCommerce.
 *
 * @package GatedAccess
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Plugin bootstrap.
 */
class Test_Plugin_Bootstrap extends WP_UnitTestCase {

	public function test_plugin_boots_alongside_woocommerce(): void {
		$this->assertTrue( class_exists( WooCommerce::class ), 'WooCommerce is not loaded.' );
		$this->assertInstanceOf( GatedAccess\Plugin::class, GatedAccess\plugin() );
	}

	public function test_declares_hpos_compatibility(): void {
		$plugins = FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );

		$this->assertContains( 'gated-access-attribution/gated-access-attribution.php', $plugins['compatible'] );
	}
}
