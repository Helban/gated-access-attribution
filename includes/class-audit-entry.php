<?php
/**
 * Audit log entry value object.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * One row of gaa_audit_log.
 */
final class Audit_Entry {

	/**
	 * Builds an entry.
	 *
	 * @param string               $event      Dotted event name, such as "rep.reassigned".
	 * @param string               $actor      Who caused it: "buyer", "checkout", "user:12", "webhook:evt_1".
	 * @param array<string, mixed> $context    Event details.
	 * @param \DateTimeImmutable   $created_at When it happened (UTC).
	 */
	public function __construct(
		public readonly string $event,
		public readonly string $actor,
		public readonly array $context,
		public readonly \DateTimeImmutable $created_at,
	) {
	}

	/**
	 * Builds an entry from a gaa_audit_log row.
	 *
	 * @param object $row Row as returned by wpdb::get_results().
	 */
	public static function from_row( object $row ): self {
		$context = json_decode( (string) $row->context, true );

		return new self(
			(string) $row->event,
			(string) $row->actor,
			is_array( $context ) ? $context : array(),
			new \DateTimeImmutable( $row->created_at, new \DateTimeZone( 'UTC' ) ),
		);
	}
}
