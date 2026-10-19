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

	public function test_partly_rejected_save_also_confirms_what_was_kept(): void {
		include_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
		$settings = new Settings( $this->rep_codes );

		$settings->sanitize_rep_codes( "anna: Anna Kowalska\nnot valid!\nben" );

		$this->assertStringContainsString( '2 valid lines were saved: anna, ben.', $this->render_settings( $settings ) );
		$this->assertStringContainsString( 'notice-success', $this->render_settings( $settings ) );
	}

	public function test_save_with_only_rejected_lines_shows_no_success_notice(): void {
		include_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
		$settings = new Settings( $this->rep_codes );

		$settings->sanitize_rep_codes( 'not valid!' );

		$this->assertStringNotContainsString( 'notice-success', $this->render_settings( $settings ) );
	}

	public function test_settings_tab_has_no_second_title_and_a_full_width_secret(): void {
		include_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';

		$html = $this->render_settings( new Settings( $this->rep_codes ) );

		$this->assertStringNotContainsString( '<h2>Gated access</h2>', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]+class="large-text code"[^>]+gaa_webhook_secret|<input[^>]+gaa_webhook_secret[^>]+class="large-text code"/', $html );
	}

	/**
	 * Output of the settings tab body.
	 *
	 * @param Settings $settings Tab under test.
	 */
	private function render_settings( Settings $settings ): string {
		ob_start();
		$settings->render();

		return (string) ob_get_clean();
	}
}
