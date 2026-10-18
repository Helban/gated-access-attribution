<?php
/**
 * Referral link capture.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers which rep's link a visitor arrived through, then binds that rep to
 * the identity when the visitor verifies.
 *
 * The cookie is only a hint carried across the anonymous part of the visit.
 * From verification on, the rep lives in gaa_identities, and that row is what
 * checkout reads.
 */
final class Referral {

	public const COOKIE    = 'gaa_ref';
	public const QUERY_ARG = 'ref';
	public const LIFETIME  = 30 * DAY_IN_SECONDS;

	/**
	 * Sets up referral handling.
	 *
	 * @param Rep_Codes  $rep_codes  Configured reps.
	 * @param Identities $identities Identity repository.
	 * @param Audit_Log  $audit_log  Audit trail.
	 * @param Cookies    $cookies    Cookie access.
	 */
	public function __construct(
		private readonly Rep_Codes $rep_codes,
		private readonly Identities $identities,
		private readonly Audit_Log $audit_log,
		private readonly Cookies $cookies,
	) {
	}

	/**
	 * Registers capture on every front-end request and binding on verification.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'capture' ) );
		add_action( 'gaa_identity_verified', array( $this, 'bind' ) );
	}

	/**
	 * Stores the rep code from ?ref= when it names a configured rep.
	 *
	 * A later link from another rep replaces it: until the visitor verifies,
	 * the most recent link wins.
	 */
	public function capture(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A tracking parameter on a GET link; only configured codes are kept.
		if ( is_admin() || ! isset( $_GET[ self::QUERY_ARG ] ) ) {
			return;
		}

		$rep_code = $this->rep_codes->find( sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.

		if ( null !== $rep_code ) {
			$this->cookies->set( self::COOKIE, $rep_code, time() + self::LIFETIME );
		}
	}

	/**
	 * Gives a freshly verified identity the rep from its referral cookie.
	 *
	 * An identity that already has a rep keeps it. Reassignment is an explicit
	 * act, done from the admin screen or the webhook.
	 *
	 * @param Identity $identity The identity that just verified.
	 */
	public function bind( Identity $identity ): void {
		$rep_code = $this->rep_codes->find( $this->cookies->get( self::COOKIE ) );
		$this->cookies->delete( self::COOKIE );

		if ( null === $rep_code || null !== $identity->rep_code ) {
			return;
		}

		if ( $this->identities->assign_rep_if_unassigned( $identity->id, $rep_code ) ) {
			$this->audit_log->record( $identity->id, 'rep.assigned', 'referral-link', array( 'rep_code' => $rep_code ) );
		}
	}
}
