<?php
/**
 * Identity value object.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * A buyer known by email address, and the sales rep they are attributed to.
 */
final class Identity {

	/**
	 * Builds an identity.
	 *
	 * @param int                     $id          Surrogate key.
	 * @param string                  $email       Normalised email address.
	 * @param string|null             $rep_code    Attributed sales rep, if any.
	 * @param \DateTimeImmutable|null $verified_at Last successful code verification (UTC).
	 * @param \DateTimeImmutable      $created_at  First verification (UTC).
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $email,
		public readonly ?string $rep_code,
		public readonly ?\DateTimeImmutable $verified_at,
		public readonly \DateTimeImmutable $created_at,
	) {
	}

	/**
	 * Builds an identity from a gaa_identities row.
	 *
	 * @param object $row Row as returned by wpdb::get_row().
	 */
	public static function from_row( object $row ): self {
		$utc = new \DateTimeZone( 'UTC' );

		return new self(
			(int) $row->id,
			(string) $row->email,
			null === $row->rep_code ? null : (string) $row->rep_code,
			null === $row->verified_at ? null : new \DateTimeImmutable( $row->verified_at, $utc ),
			new \DateTimeImmutable( $row->created_at, $utc ),
		);
	}

	/**
	 * Whether the buyer has proved control of the address at least once.
	 */
	public function is_verified(): bool {
		return null !== $this->verified_at;
	}
}
