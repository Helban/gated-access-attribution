<?php
/**
 * Page cache control.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Marks the current response as personal, so no shared cache keeps it.
 */
final class Page_Cache {

	/**
	 * Sends no-cache headers (while headers can still be sent) and sets the
	 * DONOTCACHEPAGE constant that page caching plugins check.
	 */
	public static function forbid(): void {
		nocache_headers();
		wc_maybe_define_constant( 'DONOTCACHEPAGE', true );

		/**
		 * Fires when the plugin marks a response as personal.
		 *
		 * For hosts whose page cache needs its own signal, such as a cookie or
		 * header that the cache layer is configured to respect.
		 */
		do_action( 'gaa_response_not_cacheable' );
	}
}
