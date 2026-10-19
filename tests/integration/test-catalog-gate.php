<?php
/**
 * Tests for the catalog gate.
 *
 * @package GatedAccess
 */

use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;
use GatedAccess\Catalog_Access;
use GatedAccess\Catalog_Gate;
use GatedAccess\Cookies;
use GatedAccess\Hmac;
use GatedAccess\Identities;
use GatedAccess\Session;
use GatedAccess\Sign_In_Page;

/**
 * Pages, Store API, REST, wc-ajax, cart and checkout, for visitors with and without access.
 */
class Test_Catalog_Gate extends WP_UnitTestCase {

	use GAA_Test_Helpers;

	private const PRODUCT_NAME  = 'Gated widget';
	private const PRODUCT_PRICE = '49.17';

	/**
	 * Product every test can look for in responses.
	 *
	 * @var WC_Product_Simple
	 */
	private WC_Product_Simple $product;

	/**
	 * Category the product is filed under.
	 *
	 * @var int
	 */
	private int $category_id;

	/**
	 * Gate under test, for the page checks.
	 *
	 * @var Catalog_Gate
	 */
	private Catalog_Gate $gate;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'gaa_send_cookies', '__return_false' );

		// The WordPress test bootstrap deletes every post after loading, including the pages activation created.
		WC_Install::create_pages();
		( new Sign_In_Page() )->ensure_exists();

		$this->category_id = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Gated category',
			)
		);

		$this->product = new WC_Product_Simple();
		$this->product->set_name( self::PRODUCT_NAME );
		$this->product->set_regular_price( self::PRODUCT_PRICE );
		$this->product->set_category_ids( array( $this->category_id ) );
		$this->product->save();

		$identities = new Identities();
		$this->gate = new Catalog_Gate(
			new Catalog_Access( new Session( $identities, new Hmac(), new Cookies() ) ),
			new Sign_In_Page()
		);
	}

	public function tear_down(): void {
		unset( $_COOKIE[ Session::COOKIE ], $_GET['wc-ajax'], $_REQUEST['add-to-cart'], $_REQUEST['action'] );
		$this->reset_rest_server();
		parent::tear_down();
	}

	/**
	 * Every kind of catalog page.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function catalog_pages(): array {
		return array(
			'shop'             => array( 'shop' ),
			'product'          => array( 'product' ),
			'product category' => array( 'category' ),
			'cart'             => array( 'cart' ),
			'checkout'         => array( 'checkout' ),
		);
	}

	/**
	 * Unverified visitors are sent to sign in.
	 *
	 * @dataProvider catalog_pages
	 *
	 * @param string $page Kind of catalog page.
	 */
	public function test_catalog_page_sends_an_anonymous_visitor_to_sign_in( string $page ): void {
		$url = $this->catalog_url( $page );
		$this->visit( $url );

		$redirect = $this->guard_page_expecting_redirect();

		$this->assertSame( ( new Sign_In_Page() )->url( $url ), $redirect->location );
	}

	/**
	 * Verified buyers get through.
	 *
	 * @dataProvider catalog_pages
	 *
	 * @param string $page Kind of catalog page.
	 */
	public function test_catalog_page_lets_a_verified_buyer_through( string $page ): void {
		$this->sign_in_as( 'buyer@example.com' );
		$this->visit( $this->catalog_url( $page ) );

		$this->assertNoRedirect();
	}

	public function test_catalog_page_lets_store_staff_through(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->visit( $this->catalog_url( 'product' ) );

		$this->assertNoRedirect();
	}

	public function test_product_post_type_array_on_the_home_page_is_gated(): void {
		$sign_in = ( new Sign_In_Page() )->url();

		$this->visit( home_url( '/?post_type[]=product' ) );
		$this->assertStringStartsWith( $sign_in, $this->guard_page_expecting_redirect()->location );

		$this->visit( home_url( '/?post_type[]=product&feed=rss2' ) );
		$this->assertStringStartsWith( $sign_in, $this->guard_page_expecting_redirect()->location );
	}

	public function test_sign_in_page_and_home_page_stay_open(): void {
		$this->visit( ( new Sign_In_Page() )->url() );
		$this->assertNoRedirect();

		$this->visit( home_url( '/' ) );
		$this->assertNoRedirect();
	}

	/**
	 * Every REST route that could expose the catalog.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function catalog_rest_routes(): array {
		return array(
			'Store API products'             => array( '/wc/store/v1/products' ),
			'Store API products, no version' => array( '/wc/store/products' ),
			'Store API single product'       => array( '/wc/store/v1/products/{id}' ),
			'Store API categories'           => array( '/wc/store/v1/products/categories' ),
			'Store API cart'                 => array( '/wc/store/v1/cart' ),
			'Store API, upper case path'     => array( '/WC/STORE/V1/PRODUCTS' ),
			'WC REST v3 products'            => array( '/wc/v3/products' ),
			'WC REST v3 single product'      => array( '/wc/v3/products/{id}' ),
			'WC REST v2 products'            => array( '/wc/v2/products' ),
			'WP REST product'                => array( '/wp/v2/product' ),
			'WP REST single product'         => array( '/wp/v2/product/{id}' ),
			'WP REST product categories'     => array( '/wp/v2/product_cat' ),
		);
	}

	/**
	 * No catalog data reaches an anonymous visitor through JSON.
	 *
	 * @dataProvider catalog_rest_routes
	 *
	 * @param string $route Route, with {id} standing for the product ID.
	 */
	public function test_rest_route_returns_no_products_to_an_anonymous_visitor( string $route ): void {
		$rest_reply = $this->rest_get( $route );

		$this->assertContains( $rest_reply->get_status(), array( 401, 403 ) );
		$this->assertCatalogAbsent( $rest_reply );
	}

	public function test_store_api_serves_products_to_a_verified_buyer(): void {
		$this->sign_in_as( 'buyer@example.com' );

		$rest_reply = $this->rest_get( '/wc/store/v1/products' );

		$this->assertSame( 200, $rest_reply->get_status() );
		$this->assertStringContainsString( self::PRODUCT_NAME, wp_json_encode( $rest_reply->get_data() ) );
	}

	public function test_woocommerce_rest_api_still_serves_store_staff(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$rest_reply = $this->rest_get( '/wc/v3/products/{id}' );

		$this->assertSame( 200, $rest_reply->get_status() );
		$this->assertSame( self::PRODUCT_NAME, $rest_reply->get_data()['name'] );
	}

	public function test_verified_buyer_alone_does_not_unlock_the_woocommerce_rest_api(): void {
		$this->sign_in_as( 'buyer@example.com' );

		$rest_reply = $this->rest_get( '/wc/v3/products' );

		$this->assertSame( 401, $rest_reply->get_status() );
		$this->assertCatalogAbsent( $rest_reply );
	}

	public function test_wc_ajax_request_is_refused_before_woocommerce_handles_it(): void {
		$_GET['wc-ajax'] = 'get_variation';
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static fn () => static function (): void {
				throw new WPDieException( 'wc-ajax stopped' );
			}
		);

		ob_start();
		try {
			$this->gate->guard_wc_ajax();
			$this->fail( 'The wc-ajax request was not stopped.' );
		} catch ( WPDieException $stopped ) {
			$ajax_reply = json_decode( (string) ob_get_clean(), true );
		}

		$this->assertFalse( $ajax_reply['success'] );
	}

	public function test_add_to_cart_link_does_nothing_for_an_anonymous_visitor(): void {
		wc_load_cart();
		WC()->cart->empty_cart();
		$_REQUEST['add-to-cart'] = (string) $this->product->get_id();
		$this->intercept_redirects();

		WC_Form_Handler::add_to_cart_action();

		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( 1, wc_notice_count( 'error' ) );
		wc_clear_notices();
	}

	public function test_woocommerce_admin_ajax_action_is_refused_to_an_anonymous_visitor(): void {
		$ajax_reply = $this->call_admin_ajax( 'woocommerce_get_variation' );

		$this->assertFalse( $ajax_reply['success'] );
	}

	public function test_other_admin_ajax_actions_are_left_alone(): void {
		$this->assertNull( $this->call_admin_ajax( 'heartbeat' ) );
	}

	public function test_woocommerce_admin_ajax_action_is_allowed_for_a_verified_buyer(): void {
		$this->sign_in_as( 'buyer@example.com' );

		$this->assertNull( $this->call_admin_ajax( 'woocommerce_get_variation' ) );
	}

	public function test_classic_checkout_is_refused_once_the_session_is_gone(): void {
		$errors = new WP_Error();

		$this->gate->guard_checkout( array(), $errors );

		$this->assertContains( 'gaa_sign_in_required', $errors->get_error_codes() );
	}

	/**
	 * Loads a URL as the main query.
	 *
	 * WooCommerce caches "is this the cart/checkout page" in static properties
	 * for the life of the process. Production serves one page per process;
	 * these tests visit several, so the cache is cleared each time.
	 *
	 * @param string $url URL to load.
	 */
	private function visit( string $url ): void {
		$this->go_to( $url );

		foreach ( array( 'is_cart_page', 'is_checkout_page' ) as $property ) {
			( new ReflectionProperty( CartCheckoutUtils::class, $property ) )->setValue( null, null );
		}
	}

	/**
	 * Runs the admin-ajax guard as admin-ajax.php would, at admin_init.
	 *
	 * @param string $action Requested ajax action.
	 * @return array<string, mixed>|null The JSON the guard answered with, or null if it let the request through.
	 */
	private function call_admin_ajax( string $action ): ?array {
		$_REQUEST['action'] = $action;
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static fn () => static function (): void {
				throw new WPDieException( 'admin-ajax stopped' );
			}
		);

		ob_start();
		try {
			$this->gate->guard_admin_ajax();
		} catch ( WPDieException $stopped ) {
			return json_decode( (string) ob_get_clean(), true );
		}
		ob_end_clean();

		return null;
	}

	/**
	 * Runs the page gate and returns the redirect it ends in.
	 */
	private function guard_page_expecting_redirect(): GAA_Redirect_Intercepted {
		$this->intercept_redirects();

		try {
			$this->gate->guard_page();
		} catch ( GAA_Redirect_Intercepted $redirect ) {
			return $redirect;
		}

		$this->fail( 'The page gate did not redirect.' );
	}

	/**
	 * Asserts that the page gate lets the current request through.
	 */
	private function assertNoRedirect(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Reads as a PHPUnit assertion.
		$this->intercept_redirects();

		try {
			$this->gate->guard_page();
		} catch ( GAA_Redirect_Intercepted $redirect ) {
			$this->fail( "Unexpected redirect to {$redirect->location}" );
		}

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Asserts that neither the product's name nor its price is anywhere in the response.
	 *
	 * @param WP_REST_Response $rest_reply REST response.
	 */
	private function assertCatalogAbsent( WP_REST_Response $rest_reply ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Reads as a PHPUnit assertion.
		$body = wp_json_encode( $rest_reply->get_data() );

		$this->assertStringNotContainsString( self::PRODUCT_NAME, $body );
		$this->assertStringNotContainsString( '4917', $body );
		$this->assertStringNotContainsString( self::PRODUCT_PRICE, $body );
	}

	/**
	 * Dispatches a GET request through the REST server, as a browser request would.
	 *
	 * @param string $route Route, with {id} standing for the product ID.
	 */
	private function rest_get( string $route ): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'GET', str_replace( '{id}', (string) $this->product->get_id(), $route ) ) );
	}

	/**
	 * URL of one kind of catalog page.
	 *
	 * @param string $page shop, product, category, cart or checkout.
	 */
	private function catalog_url( string $page ): string {
		return match ( $page ) {
			'shop'     => wc_get_page_permalink( 'shop' ),
			'product'  => get_permalink( $this->product->get_id() ),
			'category' => get_term_link( $this->category_id, 'product_cat' ),
			'cart'     => wc_get_page_permalink( 'cart' ),
			'checkout' => wc_get_page_permalink( 'checkout' ),
		};
	}
}
