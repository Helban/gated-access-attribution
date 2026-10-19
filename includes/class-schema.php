<?php
/**
 * Database schema.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, upgrades and drops the plugin's own tables.
 *
 * Every datetime column holds UTC.
 */
final class Schema {

	public const VERSION        = 1;
	public const VERSION_OPTION = 'gaa_db_version';

	public const IDENTITIES       = 'gaa_identities';
	public const OTP_REQUESTS     = 'gaa_otp_requests';
	public const PROCESSED_EVENTS = 'gaa_processed_events';
	public const AUDIT_LOG        = 'gaa_audit_log';

	/**
	 * Runs the installer when the stored schema version is older than this code's.
	 */
	public function maybe_upgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		$this->install();
	}

	/**
	 * Creates missing tables and columns, then records the schema version.
	 *
	 * The dbDelta() call only ever adds. A future version that renames or drops
	 * a column needs an explicit migration step here, keyed on the stored version.
	 */
	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $this->table_definitions() );
		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Drops every table the plugin owns.
	 */
	public function drop_tables(): void {
		global $wpdb;

		foreach ( $this->table_names() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables is the point of uninstalling.
		}
	}

	/**
	 * Returns the prefixed names of every table the plugin owns.
	 *
	 * @return string[]
	 */
	public function table_names(): array {
		return array_map(
			array( self::class, 'table' ),
			array( self::IDENTITIES, self::OTP_REQUESTS, self::PROCESSED_EVENTS, self::AUDIT_LOG )
		);
	}

	/**
	 * Prefixed name of one of the plugin's tables.
	 *
	 * @param string $name One of the table constants of this class.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . $name;
	}

	/**
	 * CREATE TABLE statements in the exact layout dbDelta() parses.
	 *
	 * Emails are capped at 190 characters so a unique index fits the 767-byte
	 * limit of older InnoDB row formats under utf8mb4. Webhook event IDs are
	 * compared byte for byte: under the default case-insensitive collation,
	 * "evt_A" and "evt_a" would count as one event.
	 *
	 * @return string[]
	 */
	private function table_definitions(): array {
		global $wpdb;

		$prefix          = $wpdb->prefix;
		$charset_collate = $wpdb->get_charset_collate();
		$rep_code_length = Rep_Codes::MAX_LENGTH;

		return array(
			"CREATE TABLE {$prefix}" . self::IDENTITIES . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(190) NOT NULL,
				verified_at datetime DEFAULT NULL,
				rep_code varchar({$rep_code_length}) DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY email (email),
				KEY rep_code (rep_code)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}" . self::OTP_REQUESTS . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email_hash char(64) NOT NULL,
				ip_hash char(64) NOT NULL,
				code_hash char(64) NOT NULL,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				consumed_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY email_hash_created (email_hash,created_at),
				KEY ip_hash_created (ip_hash,created_at)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}" . self::PROCESSED_EVENTS . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				event_type varchar(64) NOT NULL,
				processed_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_id (event_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}" . self::AUDIT_LOG . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				identity_id bigint(20) unsigned DEFAULT NULL,
				event varchar(64) NOT NULL,
				actor varchar(100) NOT NULL,
				context text DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY identity_created (identity_id,created_at)
			) {$charset_collate};",
		);
	}
}
