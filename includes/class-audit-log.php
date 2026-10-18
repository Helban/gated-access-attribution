<?php
/**
 * Audit trail.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Appends who-did-what records to gaa_audit_log.
 *
 * Rows are only ever inserted, never updated, so the table reads as a history.
 */
final class Audit_Log {

	/**
	 * Records one event.
	 *
	 * @param int|null             $identity_id Identity the event concerns, if any.
	 * @param string               $event       Dotted event name, such as "rep.assigned".
	 * @param string               $actor       Who caused it: "system", "checkout", "user:12", "webhook:evt_1".
	 * @param array<string, mixed> $context     Event details, stored as JSON.
	 */
	public function record( ?int $identity_id, string $event, string $actor, array $context = array() ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . Schema::AUDIT_LOG,
			array(
				'identity_id' => $identity_id,
				'event'       => $event,
				'actor'       => $actor,
				'context'     => wp_json_encode( $context ),
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * An identity's history, newest first.
	 *
	 * @param int $identity_id Identity ID.
	 * @param int $limit       Most entries to return.
	 * @return Audit_Entry[]
	 */
	public function for_identity( int $identity_id, int $limit = 100 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT event, actor, context, created_at FROM %i WHERE identity_id = %d ORDER BY id DESC LIMIT %d',
				$wpdb->prefix . Schema::AUDIT_LOG,
				$identity_id,
				$limit
			)
		);

		return array_map( array( Audit_Entry::class, 'from_row' ), $rows );
	}

	/**
	 * IDs of the orders attributed to an identity, newest first.
	 *
	 * Read from this log rather than from order meta, because wc_get_orders()
	 * only accepts meta_query under HPOS; this way the answer is the same on
	 * both order storages.
	 *
	 * @param int $identity_id Identity ID.
	 * @param int $limit       Most orders to return.
	 * @return int[]
	 */
	public function attributed_order_ids( int $identity_id, int $limit ): array {
		global $wpdb;

		$contexts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT context FROM %i WHERE identity_id = %d AND event = 'order.attributed' ORDER BY id DESC LIMIT %d",
				$wpdb->prefix . Schema::AUDIT_LOG,
				$identity_id,
				$limit
			)
		);

		$order_ids = array();
		foreach ( $contexts as $context ) {
			$decoded = json_decode( (string) $context, true );
			if ( is_array( $decoded ) && isset( $decoded['order_id'] ) ) {
				$order_ids[] = (int) $decoded['order_id'];
			}
		}

		return $order_ids;
	}

	/**
	 * Records a successful sign-in.
	 *
	 * @param Identity $identity The identity that just verified.
	 */
	public function record_verification( Identity $identity ): void {
		$this->record( $identity->id, 'identity.verified', 'buyer' );
	}
}
