<?php
/**
 * Sign-in failure.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Why a code could not be sent or accepted.
 *
 * Carries a reason, never user-facing text: the form decides what to say, and
 * says the same thing for every reason that could hint at an address's history.
 */
final class Sign_In_Error extends \RuntimeException {

	public const RATE_LIMITED = 'rate_limited';
	public const INVALID_CODE = 'invalid_code';
	public const UNAVAILABLE  = 'unavailable';

	/**
	 * One of the class constants.
	 *
	 * @var string
	 */
	public readonly string $reason;

	/**
	 * Builds the error.
	 *
	 * @param string $reason One of the class constants.
	 */
	private function __construct( string $reason ) {
		parent::__construct( $reason );
		$this->reason = $reason;
	}

	/**
	 * Too many codes requested for the address or from the IP.
	 */
	public static function rate_limited(): self {
		return new self( self::RATE_LIMITED );
	}

	/**
	 * The code does not match an active code for the address, for any reason.
	 */
	public static function invalid_code(): self {
		return new self( self::INVALID_CODE );
	}

	/**
	 * The code could not be stored or mailed.
	 */
	public static function unavailable(): self {
		return new self( self::UNAVAILABLE );
	}
}
