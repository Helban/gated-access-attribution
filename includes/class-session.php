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
	 * Verified identity per cookie value, so repeated checks in one request cost one query.
	 *
	 * @var array<string, Identity|null>
	 */
	private array $resolved = array();

	/**
	 * Sets up the session.
	 *
	 * @param Identities $identities Identity repository.
	 * @param Hmac       $hmac       Keyed digests.
	 */
	public function __construct( private readonly Identities $identities, private readonly Hmac $hmac ) {
	}

	/**
	 * Signs the visitor in as the identity.
	 *
	 * @param Identity $identity A verified identity.
	 */
	public function start( Identity $identity ): void {
		$expires = time() + self::LIFETIME;
		$payload = $identity->id . '|' . $expires;

		$this->write_cookie( $payload . '|' . $this->hmac->digest( 'session', $payload ), $expires );
	}

	/**
	 * Signs the visitor out.
	 */
	public function end(): void {
		$this->write_cookie( '', time() - YEAR_IN_SECONDS );
	}

	/**
	 * Returns the signed-in identity, or null for an anonymous or invalid session.
	 */
	public function current_identity(): ?Identity {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';

		if ( '' === $cookie ) {
			return null;
		}

		if ( ! array_key_exists( $cookie, $this->resolved ) ) {
			$this->resolved[ $cookie ] = $this->resolve( $cookie );
		}

		return $this->resolved[ $cookie ];
	}

	/**
	 * Checks the cookie's signature and expiry, then loads its identity.
	 *
	 * @param string $cookie Raw cookie value.
	 */
	private function resolve( string $cookie ): ?Identity {
		$parts = explode( '|', $cookie );

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

	/**
	 * Sends the cookie and mirrors it into $_COOKIE for the rest of this request.
	 *
	 * @param string $value   Cookie value, empty to delete.
	 * @param int    $expires Unix expiry time.
	 */
	private function write_cookie( string $value, int $expires ): void {
		/**
		 * Filters whether the session cookie is sent to the browser.
		 *
		 * Same role as core's send_auth_cookies: code that runs after headers
		 * are out, such as tests, can turn the Set-Cookie header off.
		 *
		 * @param bool $send Whether to send the cookie.
		 */
		if ( apply_filters( 'gaa_send_session_cookie', true ) ) {
			setcookie(
				self::COOKIE,
				$value,
				array(
					'expires'  => $expires,
					'path'     => COOKIEPATH,
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		if ( '' === $value ) {
			unset( $_COOKIE[ self::COOKIE ] );
			return;
		}

		$_COOKIE[ self::COOKIE ] = $value;
	}
}
