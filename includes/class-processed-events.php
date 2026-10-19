<?php
/**
 * Processed webhook events.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers which webhook events have been applied, so a resent event is a no-op.
 */
final class Processed_Events {

	/**
	 * Records the event as processed unless it already is.
	 *
	 * The unique key on event_id makes this atomic: of two identical
	 * deliveries arriving together, exactly one claims the event.
	 *
	 * @param string $event_id   Sender's event ID.
	 * @param string $event_type Event type, kept for the record.
	 * @return bool True when this call claimed the event, false when it was claimed before.
	 * @throws Storage_Error When the database failed, so "claimed before" cannot be told from "unknown".
	 */
	public function claim( string $event_id, string $event_type ): bool {
		global $wpdb;

		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (event_id, event_type, processed_at) VALUES (%s, %s, %s)',
				$this->table(),
				$event_id,
				$event_type,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		if ( false === $inserted ) {
			throw new Storage_Error( 'Could not claim the webhook event.' );
		}

		return 1 === $inserted;
	}

	/**
	 * Forgets a claimed event, so the sender can deliver it again later.
	 *
	 * @param string $event_id Sender's event ID.
	 */
	public function release( string $event_id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'event_id' => $event_id ), array( '%s' ) );
	}

	/**
	 * Prefixed table name.
	 */
	private function table(): string {
		global $wpdb;

		return Schema::table( Schema::PROCESSED_EVENTS );
	}
}
