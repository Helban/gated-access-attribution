<?php
/**
 * Email address value object.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * A syntactically valid, lower-cased email address.
 *
 * Lower-casing means Buyer@Example.com and buyer@example.com are one identity
 * and share one rate limit.
 */
final class Email_Address {

	/**
	 * Longest address the identities table can index.
	 */
	public const MAX_LENGTH = 190;

	/**
	 * Wraps an already normalised address.
	 *
	 * @param string $value Normalised address.
	 */
	private function __construct( public readonly string $value ) {
	}

	/**
	 * Normalises and validates raw input.
	 *
	 * @param string $raw Address as typed or received.
	 * @throws \InvalidArgumentException When the input is not a usable address.
	 */
	public static function parse( string $raw ): self {
		$value = strtolower( trim( $raw ) );

		if ( strlen( $value ) > self::MAX_LENGTH || ! is_email( $value ) ) {
			throw new \InvalidArgumentException( 'Not a valid email address.' );
		}

		return new self( $value );
	}
}
