<?php
/**
 * Tests for the verified email appearing in both checkouts.
 *
 * @package GatedAccess
 */

use GatedAccess\Session;

/**
 * The block checkout reads the Store API cart, the shortcode checkout reads WC_Checkout::get_value().
 */
class Test_Checkout_Prefill extends WP_UnitTestCase {

	use GAA_Test_Helpers;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'gaa_send_cookies', '__return_false' );
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->customer->set_billing_email( '' );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ Session::COOKIE ] );
		WC()->customer->set_billing_email( '' );
		WC()->cart->empty_cart();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Forces fresh route objects, as WordPress's REST test cases do.

		parent::tear_down();
	}

	public function test_classic_checkout_offers_the_verified_address(): void {
		$this->sign_in_as( 'buyer@example.com' );

		$this->assertSame( 'buyer@example.com', WC()->checkout()->get_value( 'billing_email' ) );
	}

	public function test_block_checkout_cart_carries_the_verified_address(): void {
		$this->sign_in_as( 'buyer@example.com' );

		$this->assertSame( 'buyer@example.com', $this->store_api_billing_email() );
	}

	public function test_an_address_the_buyer_typed_is_kept_in_both_checkouts(): void {
		$this->sign_in_as( 'buyer@example.com' );
		WC()->customer->set_billing_email( 'typed@example.com' );

		$this->assertSame( 'typed@example.com', WC()->checkout()->get_value( 'billing_email' ) );
		$this->assertSame( 'typed@example.com', $this->store_api_billing_email() );
	}

	public function test_visitor_without_a_session_gets_no_prefill(): void {
		$this->assertEmpty( WC()->checkout()->get_value( 'billing_email' ) );
		$this->assertSame( '', WC()->customer->get_billing_email() );
	}

	public function test_another_customer_object_does_not_pick_up_the_address(): void {
		$this->sign_in_as( 'buyer@example.com' );
		$customer = new WC_Customer( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$this->assertSame( '', $customer->get_billing_email() );
	}

	/**
	 * Billing email in the Store API cart response, which the checkout block loads its fields from.
	 */
	private function store_api_billing_email(): string {
		$cart = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) );

		$this->assertSame( 200, $cart->get_status(), wp_json_encode( $cart->get_data() ) );

		$body = json_decode( wp_json_encode( $cart->get_data() ), true );

		return $body['billing_address']['email'];
	}
}
