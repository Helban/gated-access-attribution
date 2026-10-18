<?php
/**
 * Signed-in buyer session.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers a verified identity in a signed cookie.
 *
 * The cookie holds "identity id|expiry|HMAC". The signature stops a visitor
 * from editing the id, and the identity is re-read from the database on every
 * request, so deleting an identity ends its sessions.
 */
final class Session {

	public const COOKIE   = 'gaa_session';
	public const LIFETIME = 14 * DAY_IN_SECONDS;

	/**
	 * Sets up the session.
	 *
	 * @param Identities $identities Identity repository.
	 * @param Hmac       $hmac       Keyed digests.
	 * @param Cookies    $cookies    Cookie access.
	 */
	public function __construct(
		private readonly Identities $identities,
		private readonly Hmac $hmac,
		private readonly Cookies $cookies,
	) {
	}

	/**
	 * Signs the visitor in as the identity.
	 *
	 * @param Identity $identity A verified identity.
	 */
	public function start( Identity $identity ): void {
		$expires = time() + self::LIFETIME;
		$payload = $identity->id . '|' . $expires;

		$this->cookies->set( self::COOKIE, $payload . '|' . $this->hmac->digest( 'session', $payload ), $expires );
	}

	/**
	 * Signs the visitor out.
	 */
	public function end(): void {
		$this->cookies->delete( self::COOKIE );
	}

	/**
	 * Returns the signed-in identity, or null for an anonymous or invalid session.
	 *
	 * Not memoised: it is one primary-key query, and a cached identity could
	 * miss a rep assigned earlier in the same request.
	 */
	public function current_identity(): ?Identity {
		$parts = explode( '|', $this->cookies->get( self::COOKIE ) );

		if ( 3 !== count( $parts ) ) {
			return null;
		}

		list( $identity_id, $expires, $signature ) = $parts;

		if ( ! ctype_digit( $identity_id ) || ! ctype_digit( $expires ) || (int) $expires < time() ) {
			return null;
		}

		if ( ! hash_equals( $this->hmac->digest( 'session', $identity_id . '|' . $expires ), $signature ) ) {
			return null;
		}

		$identity = $this->identities->find( (int) $identity_id );

		return null !== $identity && $identity->is_verified() ? $identity : null;
	}
}
