<?php
/**
 * Cookie reading and writing.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Sets the plugin's cookies with one set of flags and mirrors them into $_COOKIE.
 *
 * Every cookie is HttpOnly, SameSite=Lax, scoped to the site path, and Secure
 * whenever the request came over HTTPS.
 */
final class Cookies {

	/**
	 * Returns a cookie's sanitised value, or an empty string when it is absent.
	 *
	 * @param string $name Cookie name.
	 */
	public function get( string $name ): string {
		return isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
	}

	/**
	 * Sets a cookie for this and later requests.
	 *
	 * @param string $name    Cookie name.
	 * @param string $value   Cookie value.
	 * @param int    $expires Unix expiry time.
	 */
	public function set( string $name, string $value, int $expires ): void {
		$this->send( $name, $value, $expires );
		$_COOKIE[ $name ] = $value;
	}

	/**
	 * Removes a cookie for this and later requests.
	 *
	 * @param string $name Cookie name.
	 */
	public function delete( string $name ): void {
		$this->send( $name, '', time() - YEAR_IN_SECONDS );
		unset( $_COOKIE[ $name ] );
	}

	/**
	 * Emits the Set-Cookie header.
	 *
	 * @param string $name    Cookie name.
	 * @param string $value   Cookie value.
	 * @param int    $expires Unix expiry time.
	 */
	private function send( string $name, string $value, int $expires ): void {
		/**
		 * Filters whether the plugin's cookies are sent to the browser.
		 *
		 * Same role as core's send_auth_cookies: code that runs after headers
		 * are out, such as tests, can turn the Set-Cookie header off.
		 *
		 * @param bool   $send Whether to send the cookie.
		 * @param string $name Cookie name.
		 */
		if ( ! apply_filters( 'gaa_send_cookies', true, $name ) ) {
			return;
		}

		setcookie(
			$name,
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
}
