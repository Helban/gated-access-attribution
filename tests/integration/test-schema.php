<?php
/**
 * Tests for the plugin's custom tables.
 *
 * @package GatedAccess
 */

use GatedAccess\Schema;

/**
 * Schema install, upgrade and uninstall.
 */
class Test_Schema extends WP_UnitTestCase {

	/**
	 * Schema under test.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	public function set_up(): void {
		parent::set_up();

		$this->schema = new Schema();
	}

	public function test_every_table_exists_after_install(): void {
		foreach ( $this->schema->table_names() as $table ) {
			$this->assertSame( $table, $this->find_table( $table ) );
		}
	}

	public function test_identities_table_has_the_documented_columns(): void {
		global $wpdb;

		$columns = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}gaa_identities" );

		$this->assertSame( array( 'id', 'email', 'verified_at', 'rep_code', 'created_at' ), $columns );
	}

	public function test_identities_table_rejects_a_duplicate_email(): void {
		global $wpdb;

		$row = array(
			'email'      => 'buyer@example.com',
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$wpdb->insert( $wpdb->prefix . Schema::IDENTITIES, $row );

		$suppressed = $wpdb->suppress_errors( true );
		$second     = $wpdb->insert( $wpdb->prefix . Schema::IDENTITIES, $row );
		$wpdb->suppress_errors( $suppressed );

		$this->assertFalse( $second );
	}

	public function test_event_ids_differing_only_in_case_are_different_events(): void {
		global $wpdb;

		foreach ( array( 'evt_AbC', 'evt_abc' ) as $event_id ) {
			$inserted = $wpdb->insert(
				$wpdb->prefix . Schema::PROCESSED_EVENTS,
				array(
					'event_id'     => $event_id,
					'event_type'   => 'test',
					'processed_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$this->assertSame( 1, $inserted, "{$event_id} was refused." );
		}
	}

	public function test_install_records_the_schema_version(): void {
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}

	public function test_second_install_changes_nothing(): void {
		$schema_changes = $this->capture_schema_changes();

		$this->schema->install();

		$this->assertSame( array(), $schema_changes->getArrayCopy() );
	}

	public function test_upgrade_runs_when_the_stored_version_is_older(): void {
		update_option( Schema::VERSION_OPTION, Schema::VERSION - 1 );
		$dbdelta_runs = $this->count_dbdelta_runs();

		$this->schema->maybe_upgrade();

		$this->assertSame( 1, $dbdelta_runs->count() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}

	public function test_upgrade_is_skipped_when_the_stored_version_is_current(): void {
		$dbdelta_runs = $this->count_dbdelta_runs();

		$this->schema->maybe_upgrade();

		$this->assertSame( 0, $dbdelta_runs->count() );
	}

	public function test_uninstall_drops_every_table_and_the_version_option(): void {
		// Let DROP and CREATE reach the real tables instead of the test case's temporary-table rewrite.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );

		try {
			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', 'gated-access-attribution/gated-access-attribution.php' );
			}
			require dirname( __DIR__, 2 ) . '/uninstall.php';

			foreach ( $this->schema->table_names() as $table ) {
				$this->assertNull( $this->find_table( $table ), "{$table} survived uninstall." );
			}
			$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		} finally {
			$this->schema->install();
		}
	}

	/**
	 * Looks a table up by exact name.
	 *
	 * @param string $table Prefixed table name.
	 */
	private function find_table( string $table ): ?string {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Records every ALTER or CREATE statement sent to the database from now on.
	 */
	private function capture_schema_changes(): ArrayObject {
		$statements = new ArrayObject();

		add_filter(
			'query',
			static function ( string $query ) use ( $statements ): string {
				if ( preg_match( '/^\s*(ALTER|CREATE)\s/i', $query ) ) {
					$statements->append( $query );
				}
				return $query;
			}
		);

		return $statements;
	}

	/**
	 * Counts dbDelta() calls from now on.
	 */
	private function count_dbdelta_runs(): ArrayObject {
		$runs = new ArrayObject();

		add_filter(
			'dbdelta_queries',
			static function ( array $queries ) use ( $runs ): array {
				$runs->append( true );
				return $queries;
			}
		);

		return $runs;
	}
}
