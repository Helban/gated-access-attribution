<?php
/**
 * Identity repository.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the gaa_identities table.
 */
final class Identities {

	/**
	 * Looks an identity up by its key.
	 *
	 * @param int $id Identity ID.
	 */
	public function find( int $id ): ?Identity {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id )
		);

		return null === $row ? null : Identity::from_row( $row );
	}

	/**
	 * Looks an identity up by email address.
	 *
	 * @param Email_Address $email Normalised address.
	 */
	public function find_by_email( Email_Address $email ): ?Identity {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE email = %s', $this->table(), $email->value )
		);

		return null === $row ? null : Identity::from_row( $row );
	}

	/**
	 * Marks the address as verified now, creating its identity on first sign-in.
	 *
	 * A single upsert, so two verifications racing for a new address cannot
	 * create two identities.
	 *
	 * @param Email_Address $email Address that just passed code verification.
	 * @throws \RuntimeException When the database write fails.
	 */
	public function record_verification( Email_Address $email ): Identity {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (email, verified_at, created_at) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE verified_at = %s',
				$this->table(),
				$email->value,
				$now,
				$now,
				$now
			)
		);

		$identity = $this->find_by_email( $email );

		if ( null === $identity ) {
			throw new \RuntimeException( 'Could not record the identity.' );
		}

		return $identity;
	}

	/**
	 * Gives the identity a rep, but only if it has none yet: the first rep wins.
	 *
	 * The condition is in the UPDATE itself, so two racing requests cannot both
	 * assign a rep.
	 *
	 * @param int    $identity_id Identity ID.
	 * @param string $rep_code    A configured rep code.
	 * @return bool Whether the rep was assigned.
	 */
	public function assign_rep_if_unassigned( int $identity_id, string $rep_code ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET rep_code = %s WHERE id = %d AND rep_code IS NULL',
				$this->table(),
				$rep_code,
				$identity_id
			)
		);

		return 1 === $updated;
	}

	/**
	 * Moves the identity to another rep, whatever it had before.
	 *
	 * Only for explicit reassignment (admin screen, webhook). Orders already
	 * placed keep the rep they were attributed to.
	 *
	 * @param int    $identity_id Identity ID.
	 * @param string $rep_code    A configured rep code.
	 */
	public function assign_rep( int $identity_id, string $rep_code ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array( 'rep_code' => $rep_code ),
			array( 'id' => $identity_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Prefixed table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::IDENTITIES;
	}
}
