<?php
/**
 * The page that hosts the sign-in form.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and locates the sign-in page.
 */
final class Sign_In_Page {

	public const OPTION    = 'gaa_sign_in_page_id';
	public const SHORTCODE = 'gaa_sign_in';

	/**
	 * Shortcode for a header or menu: a sign-in link, or the buyer's address and a sign-out button.
	 */
	public const SESSION_SHORTCODE = 'gaa_session';

	/**
	 * Post meta key on pages the plugin created, so uninstall never deletes a page an admin made.
	 */
	public const CREATED_META = '_gaa_created_by_plugin';

	/**
	 * Creates the page unless a published one is already configured.
	 */
	public function ensure_exists(): void {
		if ( $this->page_id() > 0 ) {
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => __( 'Sign in', 'gated-access-attribution' ),
				'post_name'      => 'sign-in',
				'post_content'   => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
				'comment_status' => 'closed',
				'meta_input'     => array( self::CREATED_META => '1' ),
			)
		);

		if ( $page_id > 0 ) {
			update_option( self::OPTION, $page_id );
		}
	}

	/**
	 * Permanently deletes the pages this plugin created, and only those.
	 *
	 * The sign-in page setting can point at any page; that page stays.
	 */
	public function delete_created_pages(): void {
		$page_ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => self::CREATED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs once, on uninstall.
			)
		);

		foreach ( $page_ids as $page_id ) {
			wp_delete_post( (int) $page_id, true );
		}
	}

	/**
	 * Whether the current request is a view of the sign-in page.
	 */
	public function is_current(): bool {
		$page_id = $this->page_id();

		return $page_id > 0 && is_page( $page_id );
	}

	/**
	 * ID of the configured page, or 0 when it is missing or not published.
	 */
	public function page_id(): int {
		$page_id = (int) get_option( self::OPTION, 0 );

		return $page_id > 0 && 'publish' === get_post_status( $page_id ) ? $page_id : 0;
	}

	/**
	 * URL of the sign-in page, carrying where to send the buyer afterwards.
	 *
	 * Falls back to the home page if the sign-in page was deleted, so a gated
	 * visitor is never sent to a 404.
	 *
	 * @param string $redirect_to Same-site URL to return to after sign-in.
	 */
	public function url( string $redirect_to = '' ): string {
		$page_id = $this->page_id();
		$url     = $page_id > 0 ? (string) get_permalink( $page_id ) : home_url( '/' );

		return '' === $redirect_to ? $url : add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $url );
	}
}
