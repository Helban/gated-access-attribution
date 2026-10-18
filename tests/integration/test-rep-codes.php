<?php
/**
 * Tests for rep code configuration.
 *
 * @package GatedAccess
 */

use GatedAccess\Rep_Codes;
use GatedAccess\Settings;

/**
 * Parsing, lookup and the settings sanitiser.
 */
class Test_Rep_Codes extends WP_UnitTestCase {

	/**
	 * Registry under test.
	 *
	 * @var Rep_Codes
	 */
	private Rep_Codes $rep_codes;

	public function set_up(): void {
		parent::set_up();
		$this->rep_codes = new Rep_Codes();
	}

	public function test_parses_codes_with_and_without_names(): void {
		$parsed = $this->rep_codes->parse( "anna: Anna Kowalska\n\n  BEN  \nnorth-east_2: North East team" );

		$this->assertSame(
			array(
				'anna'         => 'Anna Kowalska',
				'ben'          => 'ben',
				'north-east_2' => 'North East team',
			),
			$parsed['reps']
		);
		$this->assertSame( array(), $parsed['rejected'] );
	}

	public function test_rejects_codes_outside_the_allowed_characters(): void {
		$parsed = $this->rep_codes->parse( "anna smith: Anna\n-dash-first\n" . str_repeat( 'x', 33 ) . "\nok" );

		$this->assertSame( array( 'ok' => 'ok' ), $parsed['reps'] );
		$this->assertCount( 3, $parsed['rejected'] );
	}

	public function test_find_normalises_case_and_rejects_unknown_codes(): void {
		update_option( Rep_Codes::OPTION, 'anna: Anna Kowalska' );

		$this->assertSame( 'anna', $this->rep_codes->find( ' Anna ' ) );
		$this->assertNull( $this->rep_codes->find( 'ben' ) );
	}

	public function test_settings_store_reps_in_canonical_form_and_report_rejected_lines(): void {
		include_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';

		$stored = ( new Settings( $this->rep_codes ) )->sanitize_rep_codes( "ANNA:  Anna Kowalska \nnot valid!\nben" );

		ob_start();
		WC_Admin_Settings::show_messages();
		$messages = (string) ob_get_clean();

		$this->assertSame( "anna: Anna Kowalska\nben", $stored );
		$this->assertStringContainsString( 'not valid!', $messages );
	}
}
