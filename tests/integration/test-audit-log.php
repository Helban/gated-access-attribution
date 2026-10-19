<?php
/**
 * Tests for the audit log writer.
 *
 * @package GatedAccess
 */

use GatedAccess\Audit_Log;
use GatedAccess\Schema;
use GatedAccess\Storage_Error;

/**
 * Rows are written intact or the write raises.
 */
class Test_Audit_Log extends WP_UnitTestCase {

	public function tear_down(): void {
		global $wpdb;

		$wpdb->suppress_errors( false );
		parent::tear_down();
	}

	public function test_an_actor_of_the_full_column_width_is_stored_intact(): void {
		global $wpdb;

		$actor = str_repeat( 'a', Audit_Log::ACTOR_MAX_LENGTH );

		( new Audit_Log() )->record( null, 'rep.reassigned', $actor );

		$this->assertSame( $actor, $wpdb->get_var( $wpdb->prepare( 'SELECT actor FROM %i ORDER BY id DESC LIMIT 1', Schema::table( Schema::AUDIT_LOG ) ) ) );
	}

	public function test_an_actor_longer_than_the_column_is_refused_instead_of_cut_short(): void {
		$this->expectException( InvalidArgumentException::class );

		( new Audit_Log() )->record( null, 'rep.reassigned', str_repeat( 'a', Audit_Log::ACTOR_MAX_LENGTH + 1 ) );
	}

	public function test_a_failed_insert_raises(): void {
		global $wpdb;

		$wpdb->suppress_errors( true );
		add_filter(
			'query',
			static fn ( string $sql ): string => str_starts_with( $sql, 'INSERT INTO `' . Schema::table( Schema::AUDIT_LOG ) ) ? 'SELECT * FROM gaa_table_that_does_not_exist' : $sql
		);

		$this->expectException( Storage_Error::class );

		( new Audit_Log() )->record( null, 'identity.verified', 'buyer' );
	}
}
