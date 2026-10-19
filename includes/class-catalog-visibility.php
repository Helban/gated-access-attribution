<?php
/**
 * Catalog visibility outside the gated pages.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Hides products wherever they could render outside the gated pages: post
 * queries, WooCommerce shortcodes and blocks, oEmbed and sitemaps.
 *
 * The gate covers the obvious doors. This covers a "Products" block on the
 * home page, a site search that matches a product title, and the like.
 */
final class Catalog_Visibility {

	public const PRODUCT_POST_TYPES  = array( 'product', 'product_variation' );
	private const PRODUCT_TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' );

	/**
	 * WooCommerce shortcodes that print products or prices.
	 */
	private const PRODUCT_SHORTCODES = array(
		'add_to_cart',
		'add_to_cart_url',
		'best_selling_products',
		'featured_products',
		'product',
		'product_attribute',
		'product_categories',
		'product_category',
		'product_page',
		'products',
		'recent_products',
		'related_products',
		'sale_products',
		'top_rated_products',
	);

	/**
	 * WooCommerce blocks that stay visible before sign-in.
	 */
	private const BLOCKS_ALLOWED_BEFORE_SIGN_IN = array(
		'woocommerce/customer-account',
		'woocommerce/store-notices',
	);

	/**
	 * Sets up visibility rules.
	 *
	 * @param Catalog_Access $access Access policy.
	 */
	public function __construct( private readonly Catalog_Access $access ) {
	}

	/**
	 * Registers the filters.
	 */
	public function register_hooks(): void {
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
		add_filter( 'pre_do_shortcode_tag', array( $this, 'filter_shortcode' ), 10, 2 );
		add_filter( 'pre_render_block', array( $this, 'filter_block' ), 10, 2 );
		add_filter( 'oembed_response_data', array( $this, 'filter_embed' ), 10, 2 );
		add_filter( 'wp_sitemaps_post_types', array( $this, 'remove_sitemap_post_types' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( $this, 'remove_sitemap_taxonomies' ) );
	}

	/**
	 * Takes products out of front-end and REST queries for visitors without access.
	 *
	 * Main queries for product pages are left alone: the gate redirects those,
	 * and emptying them first would turn the redirect into a 404.
	 *
	 * @param \WP_Query $query Query about to run.
	 */
	public function filter_query( $query ): void {
		// is_admin() is also true for admin-ajax.php, which logged-out visitors can reach through nopriv actions.
		if ( ! $query instanceof \WP_Query || ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( $query->is_main_query() && ! $query->is_search() ) {
			return;
		}

		$post_types = $query->get( 'post_type' );
		if ( 'any' === $post_types || ( '' === $post_types && $query->is_search() ) ) {
			$post_types = array_values( get_post_types( array( 'exclude_from_search' => false ) ) );
		}
		$post_types = array_filter( (array) $post_types );

		if ( array() === array_intersect( $post_types, self::PRODUCT_POST_TYPES ) ) {
			return;
		}

		// Results now depend on who is asking.
		Page_Cache::forbid();

		if ( $this->access->is_granted() ) {
			return;
		}

		$remaining = array_values( array_diff( $post_types, self::PRODUCT_POST_TYPES ) );

		if ( array() === $remaining ) {
			$query->set( 'post__in', array( 0 ) );
			return;
		}

		$query->set( 'post_type', $remaining );
	}

	/**
	 * Replaces WooCommerce product shortcodes with nothing for visitors without access.
	 *
	 * Needed on top of the query filter: [products] caches its results in a
	 * transient shared by every visitor.
	 *
	 * @param false|string $output Output from an earlier filter, or false.
	 * @param string       $tag    Shortcode name.
	 * @return false|string
	 */
	public function filter_shortcode( $output, $tag ) {
		if ( false !== $output || ! in_array( $tag, self::PRODUCT_SHORTCODES, true ) ) {
			return $output;
		}

		Page_Cache::forbid();

		return $this->access->is_granted() ? $output : '';
	}

	/**
	 * Skips rendering WooCommerce blocks for visitors without access.
	 *
	 * Runs before the block renders, not after: blocks such as single-product
	 * fetch the product from the Store API controller directly (bypassing the
	 * REST gate) and put its name and prices into interactivity state, which
	 * is printed into the page even when the block's HTML is dropped. WordPress
	 * applies this filter to inner blocks too.
	 *
	 * @param string|null $pre_render   Output from an earlier filter, or null to render normally.
	 * @param array       $parsed_block Block about to render.
	 * @return string|null
	 */
	public function filter_block( $pre_render, $parsed_block ) {
		$name = is_array( $parsed_block ) ? (string) ( $parsed_block['blockName'] ?? '' ) : '';

		if ( ! str_starts_with( $name, 'woocommerce/' ) || in_array( $name, self::BLOCKS_ALLOWED_BEFORE_SIGN_IN, true ) ) {
			return $pre_render;
		}

		Page_Cache::forbid();

		return $this->access->is_granted() ? $pre_render : '';
	}

	/**
	 * Refuses oEmbed data for products, which would include title and excerpt.
	 *
	 * @param array|false $embed_data Embed data.
	 * @param \WP_Post    $post       Embedded post.
	 * @return array|false
	 */
	public function filter_embed( $embed_data, $post ) {
		if ( $post instanceof \WP_Post && in_array( $post->post_type, self::PRODUCT_POST_TYPES, true ) && ! $this->access->is_granted() ) {
			return false;
		}

		return $embed_data;
	}

	/**
	 * Leaves products out of the XML sitemap. A members-only catalog has
	 * nothing to tell search engines.
	 *
	 * @param array<string, \WP_Post_Type> $post_types Post types in the sitemap.
	 * @return array<string, \WP_Post_Type>
	 */
	public function remove_sitemap_post_types( $post_types ): array {
		return array_diff_key( (array) $post_types, array_flip( self::PRODUCT_POST_TYPES ) );
	}

	/**
	 * Leaves product categories, tags and brands out of the XML sitemap.
	 *
	 * @param array<string, \WP_Taxonomy> $taxonomies Taxonomies in the sitemap.
	 * @return array<string, \WP_Taxonomy>
	 */
	public function remove_sitemap_taxonomies( $taxonomies ): array {
		return array_diff_key( (array) $taxonomies, array_flip( self::PRODUCT_TAXONOMIES ) );
	}
}
