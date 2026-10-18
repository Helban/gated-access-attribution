<?php
/**
 * Keyed digests.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-SHA256 keyed with the site's auth salt.
 *
 * Each caller passes its own purpose label, so a digest made for one use
 * (say, a session cookie) can never be replayed as another (a code hash).
 */
final class Hmac {

	/**
	 * Returns a hex digest of the value for the given purpose.
	 *
	 * @param string $purpose Short label naming what the digest is for.
	 * @param string $value   Data to digest.
	 */
	public function digest( string $purpose, string $value ): string {
		return hash_hmac( 'sha256', $purpose . '|' . $value, wp_salt( 'auth' ) );
	}
}
