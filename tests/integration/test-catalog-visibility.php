<?php
/**
 * Tests for hiding products outside the gated pages.
 *
 * @package GatedAccess
 */

use GatedAccess\Session;

/**
 * Queries, search, shortcodes, blocks, oEmbed and sitemaps.
 */
class Test_Catalog_Visibility extends WP_UnitTestCase {

	use GAA_Test_Helpers;

	private const PRODUCT_NAME = 'Gated widget';

	/**
	 * Product every test looks for.
	 *
	 * @var WC_Product_Simple
	 */
	private WC_Product_Simple $product;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'gaa_send_cookies', '__return_false' );

		$this->product = new WC_Product_Simple();
		$this->product->set_name( self::PRODUCT_NAME );
		$this->product->set_regular_price( '49.17' );
		$this->product->save();
	}

	public function tear_down(): void {
		unset( $_COOKIE[ Session::COOKIE ] );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh routes per test, as WordPress's REST test cases do.
		parent::tear_down();
	}

	public function test_product_query_finds_nothing_for_an_anonymous_visitor(): void {
		$query = new WP_Query( array( 'post_type' => 'product' ) );

		$this->assertSame( 0, $query->post_count );
	}

	public function test_product_query_finds_the_product_for_a_verified_buyer(): void {
		$this->sign_in_as( 'buyer@example.com' );

		$query = new WP_Query( array( 'post_type' => 'product' ) );

		$this->assertSame( array( $this->product->get_id() ), wp_list_pluck( $query->posts, 'ID' ) );
	}

	public function test_mixed_query_keeps_the_other_post_types(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Company news' ) );

		$query = new WP_Query( array( 'post_type' => array( 'post', 'product' ) ) );

		$this->assertSame( array( $post_id ), wp_list_pluck( $query->posts, 'ID' ) );
	}

	public function test_site_search_leaves_products_out_for_an_anonymous_visitor(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Gated widget launch notes' ) );

		$query = new WP_Query( array( 's' => 'Gated widget' ) );

		$this->assertSame( array( $post_id ), wp_list_pluck( $query->posts, 'ID' ) );
	}

	public function test_rest_search_leaves_products_out_for_an_anonymous_visitor(): void {
		$request = new WP_REST_Request( 'GET', '/wp/v2/search' );
		$request->set_param( 'search', self::PRODUCT_NAME );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( self::PRODUCT_NAME, wp_json_encode( $response->get_data() ) );
	}

	public function test_products_shortcode_prints_nothing_for_an_anonymous_visitor(): void {
		// Prime WooCommerce's shared result cache as a verified buyer first.
		$this->sign_in_as( 'buyer@example.com' );
		$this->assertStringContainsString( self::PRODUCT_NAME, do_shortcode( '[products]' ) );
		unset( $_COOKIE[ Session::COOKIE ] );

		$this->assertSame( '', do_shortcode( '[products]' ) );
		$this->assertSame( '', do_shortcode( '[product_page id="' . $this->product->get_id() . '"]' ) );
	}

	public function test_woocommerce_blocks_are_skipped_for_an_anonymous_visitor(): void {
		$this->assertSame( '', $this->pre_render( 'woocommerce/single-product' ) );
		$this->assertNull( $this->pre_render( 'woocommerce/customer-account' ) );
		$this->assertNull( $this->pre_render( 'core/paragraph' ) );
	}

	public function test_woocommerce_blocks_render_for_a_verified_buyer(): void {
		$this->sign_in_as( 'buyer@example.com' );

		$this->assertNull( $this->pre_render( 'woocommerce/single-product' ) );
	}

	public function test_single_product_block_leaks_nothing_into_interactivity_state(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Interactivity-state widget' );
		$product->set_regular_price( '61.23' );
		$product->save();

		$html  = render_block(
			array(
				'blockName'    => 'woocommerce/single-product',
				'attrs'        => array( 'productId' => $product->get_id() ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$state = (string) wp_json_encode( wp_interactivity_state( 'woocommerce/products' ) );

		$this->assertSame( '', $html );
		$this->assertStringNotContainsString( 'Interactivity-state widget', $state );
		$this->assertStringNotContainsString( '6123', $state );
	}

	public function test_product_output_is_marked_uncacheable_even_for_a_verified_buyer(): void {
		$this->sign_in_as( 'buyer@example.com' );

		$product_renders = array(
			'product query' => static fn () => new WP_Query( array( 'post_type' => 'product' ) ),
			// The filter alone: running [products] would also run a product query.
			'[products]'    => static fn () => apply_filters( 'pre_do_shortcode_tag', false, 'products', array(), array() ),
			'product block' => fn () => $this->pre_render( 'woocommerce/single-product' ),
		);

		foreach ( $product_renders as $label => $render ) {
			$before = did_action( 'gaa_response_not_cacheable' );
			$render();
			$this->assertGreaterThan( $before, did_action( 'gaa_response_not_cacheable' ), $label );
		}
	}

	public function test_output_without_products_leaves_caching_alone(): void {
		$before = did_action( 'gaa_response_not_cacheable' );

		new WP_Query( array( 'post_type' => 'post' ) );
		do_shortcode( '[caption]Company news[/caption]' );
		$this->pre_render( 'core/paragraph' );

		$this->assertSame( $before, did_action( 'gaa_response_not_cacheable' ) );
	}

	public function test_product_embed_is_refused_to_an_anonymous_visitor(): void {
		$this->assertFalse( get_oembed_response_data( $this->product->get_id(), 600 ) );
	}

	public function test_sitemap_leaves_out_products_and_product_taxonomies(): void {
		$post_types = apply_filters( 'wp_sitemaps_post_types', get_post_types( array( 'public' => true ), 'objects' ) );
		$taxonomies = apply_filters( 'wp_sitemaps_taxonomies', get_taxonomies( array( 'public' => true ), 'objects' ) );

		$this->assertArrayNotHasKey( 'product', $post_types );
		$this->assertArrayHasKey( 'post', $post_types );
		$this->assertArrayNotHasKey( 'product_cat', $taxonomies );
		$this->assertArrayNotHasKey( 'product_tag', $taxonomies );
	}

	/**
	 * What the pre_render_block filter decides for a block: null renders it normally.
	 *
	 * @param string $block_name Block name.
	 * @return string|null
	 */
	private function pre_render( string $block_name ): ?string {
		$parsed_block = array(
			'blockName'    => $block_name,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		return apply_filters( 'pre_render_block', null, $parsed_block, null );
	}
}
