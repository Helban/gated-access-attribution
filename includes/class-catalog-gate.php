<?php
/**
 * Catalog gate.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;

defined( 'ABSPATH' ) || exit;

/**
 * Turns away visitors without catalog access at every request entry point
 * WooCommerce serves products, prices or a cart through.
 *
 * Pages redirect to the sign-in page. JSON entry points (REST and wc-ajax)
 * answer 401 or 403. Cart and checkout refuse to proceed.
 */
final class Catalog_Gate {

	/**
	 * Registered REST route patterns that expose products, prices or the cart.
	 *
	 * Matched against the pattern a route was registered with, not the raw
	 * request path, so casing and other path variations cannot slip past.
	 */
	private const REST_ROUTES = '#^/(?:wc/store|wc/v\d+/products|wp/v2/(?:product|product_cat|product_tag|product_brand))(?:/|$)#';

	/**
	 * Sets up the gate.
	 *
	 * @param Catalog_Access $access Access policy.
	 * @param Sign_In_Page   $page   Where to send visitors.
	 */
	public function __construct(
		private readonly Catalog_Access $access,
		private readonly Sign_In_Page $page,
	) {
	}

	/**
	 * Registers the gate on each entry point.
	 */
	public function register_hooks(): void {
		// Before WC_AJAX::do_wc_ajax(), which runs at priority 0 and exits.
		add_action( 'template_redirect', array( $this, 'guard_wc_ajax' ), -1 );
		add_action( 'admin_init', array( $this, 'guard_admin_ajax' ), 1 );
		add_action( 'template_redirect', array( $this, 'guard_page' ) );
		add_filter( 'rest_dispatch_request', array( $this, 'guard_rest_request' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'guard_add_to_cart' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'guard_checkout' ), 10, 2 );
	}

	/**
	 * Redirects shop, product, product taxonomy, cart and checkout pages to sign-in.
	 */
	public function guard_page(): void {
		if ( ! $this->is_catalog_request() ) {
			return;
		}

		// These pages differ per visitor, so no shared cache may keep a copy.
		Page_Cache::forbid();

		if ( $this->access->is_granted() ) {
			return;
		}

		wp_safe_redirect( $this->page->url( $this->current_url() ) );
		exit;
	}

	/**
	 * Answers every wc-ajax request with 401 for visitors without access.
	 *
	 * Those endpoints include get_variation, which returns any variation's
	 * price by ID, and checkout. None of them are needed before sign-in.
	 */
	public function guard_wc_ajax(): void {
		// WooCommerce dispatches on the query var, which $_GET['wc-ajax'] fills when present; check both.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only detects a wc-ajax request; nothing is read from it.
		$is_wc_ajax = ! empty( $_GET['wc-ajax'] ) || '' !== (string) get_query_var( 'wc-ajax' );

		if ( ! $is_wc_ajax || $this->access->is_granted() ) {
			return;
		}

		$this->refuse_json();
	}

	/**
	 * Answers WooCommerce's admin-ajax actions with 401 for visitors without access.
	 *
	 * WooCommerce registers its storefront events (get_variation, checkout and
	 * the rest) for logged-out admin-ajax.php calls as well as for wc-ajax, and
	 * admin-ajax.php never fires template_redirect.
	 */
	public function guard_admin_ajax(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which action is being called.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( ! wp_doing_ajax() || ! str_starts_with( $action, 'woocommerce_' ) || $this->access->is_granted() ) {
			return;
		}

		$this->refuse_json();
	}

	/**
	 * Refuses catalog REST routes to visitors without access.
	 *
	 * @param mixed            $result  Response from an earlier filter, or null.
	 * @param \WP_REST_Request $request Request being dispatched.
	 * @param string           $route   Pattern of the matched route.
	 * @return mixed The earlier result, or an error.
	 */
	public function guard_rest_request( $result, $request, $route ) {
		if ( null !== $result || ! is_string( $route ) || ! preg_match( self::REST_ROUTES, $route ) || $this->access->is_granted() ) {
			return $result;
		}

		return new \WP_Error(
			'gaa_sign_in_required',
			__( 'Sign in to see the catalog.', 'gated-access-attribution' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Keeps visitors without access from building a cart.
	 *
	 * Covers ?add-to-cart= links, the wc-ajax add_to_cart call and the Store
	 * API, which all run this validation.
	 *
	 * @param bool $passed Whether earlier validation passed.
	 */
	public function guard_add_to_cart( $passed ): bool {
		if ( ! $passed || $this->access->is_granted() ) {
			return (bool) $passed;
		}

		wc_add_notice( __( 'Sign in to add products to your cart.', 'gated-access-attribution' ), 'error' );

		return false;
	}

	/**
	 * Stops the classic checkout for a visitor whose session ended mid-visit.
	 *
	 * The cart can outlive the session, and the non-JavaScript checkout runs
	 * on wp_loaded, before any page redirect.
	 *
	 * @param array     $data   Posted checkout data, unused.
	 * @param \WP_Error $errors Validation errors so far.
	 */
	public function guard_checkout( $data, $errors ): void {
		if ( $errors instanceof \WP_Error && ! $this->access->is_granted() ) {
			$errors->add( 'gaa_sign_in_required', __( 'Sign in again to place this order.', 'gated-access-attribution' ) );
		}
	}

	/**
	 * Whether the main query is for a catalog page.
	 *
	 * Also catches ?post_type[]=product: WordPress accepts an array there,
	 * and then sets neither is_post_type_archive() nor anything is_woocommerce()
	 * looks at, so the home page or a feed would list products.
	 */
	private function is_catalog_request(): bool {
		// Not is_cart() or is_checkout(): those also return true whenever an AJAX constant is defined. This is about the page.
		return is_woocommerce()
			|| CartCheckoutUtils::is_cart_page()
			|| CartCheckoutUtils::is_checkout_page()
			|| array() !== array_intersect( (array) get_query_var( 'post_type' ), Catalog_Visibility::PRODUCT_POST_TYPES );
	}

	/**
	 * Ends a JSON request with 401.
	 */
	private function refuse_json(): void {
		wp_send_json_error(
			array( 'message' => __( 'Sign in to see the catalog.', 'gated-access-attribution' ) ),
			401
		);
	}

	/**
	 * Absolute URL of the current request, built on the site's own host.
	 *
	 * The Host header is not used: it comes from the client.
	 */
	private function current_url(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$home        = wp_parse_url( home_url() );
		$port        = isset( $home['port'] ) ? ':' . $home['port'] : '';

		return $home['scheme'] . '://' . $home['host'] . $port . $request_uri;
	}
}
