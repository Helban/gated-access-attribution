<?php
/**
 * Tests for writing the rep onto orders at checkout.
 *
 * @package GatedAccess
 */

use Automattic\WooCommerce\Utilities\OrderUtil;
use GatedAccess\Identities;
use GatedAccess\Order_Attribution;
use GatedAccess\Referral;
use GatedAccess\Rep_Codes;
use GatedAccess\Session;

/**
 * Attribution through the classic checkout and the block (Store API) checkout.
 *
 * `composer test` runs this once with HPOS and once with posts storage.
 */
class Test_Order_Attribution extends WP_UnitTestCase {

	use GAA_Test_Helpers;

	/**
	 * Product placed in every test order.
	 *
	 * @var WC_Product_Simple
	 */
	private WC_Product_Simple $product;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'gaa_send_cookies', '__return_false' );

		update_option( Rep_Codes::OPTION, "anna: Anna Kowalska\nben: Ben Ortiz" );
		update_option(
			'woocommerce_cod_settings',
			array(
				'enabled'            => 'yes',
				'enable_for_virtual' => 'yes',
			)
		);
		WC()->payment_gateways()->init();

		$this->product = new WC_Product_Simple();
		$this->product->set_name( 'Gated widget' );
		$this->product->set_regular_price( '25' );
		$this->product->set_virtual( true );
		$this->product->save();

		wc_load_cart();
		WC()->cart->empty_cart();
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		unset( $_COOKIE[ Referral::COOKIE ], $_COOKIE[ Session::COOKIE ] );
		WC()->cart->empty_cart();
		wc_clear_notices();

		// Production serves one request per process. Tests make several, and the Store API's
		// Checkout route keeps its order object in a property, and the session keeps the draft
		// order ID; both would leak this test's order into the next one.
		WC()->session->set( 'store_api_draft_order', null );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Forces fresh route objects, as WordPress's REST test cases do.

		parent::tear_down();
	}

	public function test_suite_runs_against_the_requested_order_storage(): void {
		$this->assertSame( 'posts' !== getenv( 'GAA_TEST_ORDER_STORAGE' ), OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	public function test_classic_checkout_records_the_identity_and_its_rep(): void {
		$identity = $this->sign_in_as( 'buyer@example.com' );
		( new Identities() )->assign_rep_if_unassigned( $identity->id, 'anna' );

		$order = $this->place_classic_checkout_order();

		$this->assertSame( 'anna', $order->get_meta( Order_Attribution::REP_META ) );
		$this->assertSame( (string) $identity->id, $order->get_meta( Order_Attribution::IDENTITY_META ) );
	}

	public function test_block_checkout_records_the_identity_and_its_rep(): void {
		$identity = $this->sign_in_as( 'buyer@example.com' );
		( new Identities() )->assign_rep_if_unassigned( $identity->id, 'anna' );

		$order = $this->place_block_checkout_order();

		$this->assertSame( 'anna', $order->get_meta( Order_Attribution::REP_META ) );
		$this->assertSame( (string) $identity->id, $order->get_meta( Order_Attribution::IDENTITY_META ) );
	}

	public function test_referral_cookie_does_not_override_the_identitys_rep(): void {
		$identity = $this->sign_in_as( 'buyer@example.com' );
		( new Identities() )->assign_rep_if_unassigned( $identity->id, 'anna' );
		$_COOKIE[ Referral::COOKIE ] = 'ben';

		$order = $this->place_block_checkout_order();

		$this->assertSame( 'anna', $order->get_meta( Order_Attribution::REP_META ) );
	}

	public function test_referral_cookie_alone_attributes_nothing(): void {
		$this->sign_in_as( 'buyer@example.com' );
		$_COOKIE[ Referral::COOKIE ] = 'ben';

		$order = $this->place_classic_checkout_order();

		$this->assertSame( '', $order->get_meta( Order_Attribution::REP_META ) );
	}

	public function test_order_without_a_session_is_left_alone(): void {
		$order = $this->place_classic_checkout_order();

		$this->assertSame( '', $order->get_meta( Order_Attribution::IDENTITY_META ) );
		$this->assertSame( '', $order->get_meta( Order_Attribution::REP_META ) );
	}

	public function test_reprocessing_an_order_keeps_the_first_attribution(): void {
		$identities = new Identities();
		$identity   = $this->sign_in_as( 'buyer@example.com' );
		$identities->assign_rep_if_unassigned( $identity->id, 'anna' );
		$order = $this->place_block_checkout_order();

		$this->sign_in_as( 'someone-else@example.com' );
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$this->assertSame( (string) $identity->id, wc_get_order( $order->get_id() )->get_meta( Order_Attribution::IDENTITY_META ) );
	}

	public function test_attribution_is_written_to_the_audit_log(): void {
		global $wpdb;

		$identity = $this->sign_in_as( 'buyer@example.com' );
		( new Identities() )->assign_rep_if_unassigned( $identity->id, 'anna' );

		$order = $this->place_classic_checkout_order();

		$context = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT context FROM %i WHERE identity_id = %d AND event = 'order.attributed'",
				$wpdb->prefix . 'gaa_audit_log',
				$identity->id
			)
		);
		$this->assertSame(
			array(
				'order_id' => $order->get_id(),
				'rep_code' => 'anna',
			),
			json_decode( $context, true )
		);
	}

	/**
	 * Places an order through WC_Checkout::process_checkout(), the shortcode checkout.
	 *
	 * The successful payment ends in a redirect that WooCommerce's own
	 * exception handler swallows; the order is picked up from the hook.
	 */
	private function place_classic_checkout_order(): WC_Order {
		WC()->cart->add_to_cart( $this->product->get_id() );

		$_POST    = array_merge(
			$this->billing_fields( 'billing_' ),
			array(
				'payment_method'                     => 'cod',
				'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
			)
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Builds the request WooCommerce reads; the nonce is inside it.

		$placed = new ArrayObject();
		add_action( 'woocommerce_checkout_order_processed', array( $placed, 'append' ), PHP_INT_MAX );
		$this->intercept_redirects();

		WC()->checkout()->process_checkout();

		$this->assertCount( 1, $placed, 'Classic checkout created no order: ' . wp_strip_all_tags( wc_print_notices( true ) ) );

		return wc_get_order( $placed[0] );
	}

	/**
	 * Places an order through the Store API, the block checkout's back end.
	 */
	private function place_block_checkout_order(): WC_Order {
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

		$add_item = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
		$add_item->set_param( 'id', $this->product->get_id() );
		$add_item->set_param( 'quantity', 1 );
		$added = rest_do_request( $add_item );
		$this->assertSame( 201, $added->get_status(), wp_json_encode( $added->get_data() ) );

		$address  = $this->billing_fields( '' );
		$checkout = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$checkout->set_header( 'Content-Type', 'application/json' );
		$checkout->set_body(
			wp_json_encode(
				array(
					'billing_address'  => $address,
					'shipping_address' => array_diff_key( $address, array_flip( array( 'email' ) ) ),
					'payment_method'   => 'cod',
				)
			)
		);
		$placed = rest_do_request( $checkout );
		$this->assertSame( 200, $placed->get_status(), wp_json_encode( $placed->get_data() ) );

		return wc_get_order( $placed->get_data()['order_id'] );
	}

	/**
	 * A valid US address.
	 *
	 * @param string $prefix "billing_" for the classic form, "" for the Store API.
	 * @return array<string, string>
	 */
	private function billing_fields( string $prefix ): array {
		$fields = array(
			'first_name' => 'Ada',
			'last_name'  => 'Buyer',
			'company'    => '',
			'address_1'  => '1 Main Street',
			'address_2'  => '',
			'city'       => 'Los Angeles',
			'state'      => 'CA',
			'postcode'   => '90001',
			'country'    => 'US',
			'email'      => 'buyer@example.com',
			'phone'      => '5555550100',
		);

		$prefixed = array();
		foreach ( $fields as $key => $value ) {
			$prefixed[ $prefix . $key ] = $value;
		}

		return $prefixed;
	}
}
