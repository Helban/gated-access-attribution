<?php
/**
 * Who may see the catalog.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the current visitor may see products and prices.
 *
 * Store staff with manage_woocommerce always may; everyone else needs a
 * verified session.
 */
final class Catalog_Access {

	/**
	 * Sets up the policy.
	 *
	 * @param Session $session Signed-in session.
	 */
	public function __construct( private readonly Session $session ) {
	}

	/**
	 * Whether the current visitor may see the catalog.
	 *
	 * Not memoised: callers check cheap conditions first, so this runs a
	 * handful of times per request, and each run is at most one primary-key
	 * query.
	 */
	public function is_granted(): bool {
		$granted = current_user_can( 'manage_woocommerce' ) || null !== $this->session->current_identity();

		/**
		 * Filters whether the current visitor may see the catalog.
		 *
		 * Return true to let other visitors in, for example a wholesale role.
		 * Returning false locks out verified buyers too.
		 *
		 * @param bool $granted Whether the visitor is store staff or has a verified session.
		 */
		return (bool) apply_filters( 'gaa_catalog_access', $granted );
	}
}
