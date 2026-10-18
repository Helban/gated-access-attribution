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
	 * Records a successful sign-in.
	 *
	 * @param Identity $identity The identity that just verified.
	 */
	public function record_verification( Identity $identity ): void {
		$this->record( $identity->id, 'identity.verified', 'buyer' );
	}
}
