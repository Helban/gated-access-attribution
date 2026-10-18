<?php
/**
 * Tests for referral capture and binding.
 *
 * @package GatedAccess
 */

use GatedAccess\Audit_Log;
use GatedAccess\Cookies;
use GatedAccess\Email_Address;
use GatedAccess\Hmac;
use GatedAccess\Identities;
use GatedAccess\Otp_Service;
use GatedAccess\Referral;
use GatedAccess\Rep_Codes;
use GatedAccess\Session;
use GatedAccess\Sign_In_Form;
use GatedAccess\Sign_In_Page;

/**
 * ?ref= capture, binding at verification, first rep wins.
 */
class Test_Referral extends WP_UnitTestCase {

	use GAA_Test_Helpers;

	/**
	 * Referral handling under test.
	 *
	 * @var Referral
	 */
	private Referral $referral;

	/**
	 * Identity repository.
	 *
	 * @var Identities
	 */
	private Identities $identities;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'gaa_send_cookies', '__return_false' );
		update_option( Rep_Codes::OPTION, "anna: Anna Kowalska\nben: Ben Ortiz" );

		$this->identities = new Identities();
		$this->referral   = new Referral( new Rep_Codes(), $this->identities, new Audit_Log(), new Cookies() );
	}

	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();
		unset( $_COOKIE[ Referral::COOKIE ], $_COOKIE[ Session::COOKIE ] );

		parent::tear_down();
	}

	public function test_link_with_a_configured_code_is_remembered(): void {
		$_GET['ref'] = 'ANNA';

		$this->referral->capture();

		$this->assertSame( 'anna', ( new Cookies() )->get( Referral::COOKIE ) );
	}

	public function test_link_with_an_unknown_code_is_ignored(): void {
		$_GET['ref'] = 'made-up';

		$this->referral->capture();

		$this->assertArrayNotHasKey( Referral::COOKIE, $_COOKIE );
	}

	public function test_verification_binds_the_remembered_rep(): void {
		$identity                    = $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) );
		$_COOKIE[ Referral::COOKIE ] = 'ben';

		$this->referral->bind( $identity );

		$this->assertSame( 'ben', $this->identities->find( $identity->id )->rep_code );
		$this->assertArrayNotHasKey( Referral::COOKIE, $_COOKIE );
	}

	public function test_first_rep_wins(): void {
		$identity = $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) );
		$this->identities->assign_rep_if_unassigned( $identity->id, 'anna' );
		$_COOKIE[ Referral::COOKIE ] = 'ben';

		$this->referral->bind( $this->identities->find( $identity->id ) );

		$this->assertSame( 'anna', $this->identities->find( $identity->id )->rep_code );
	}

	public function test_rep_removed_from_settings_since_the_click_is_not_bound(): void {
		$identity                    = $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) );
		$_COOKIE[ Referral::COOKIE ] = 'carl';

		$this->referral->bind( $identity );

		$this->assertNull( $this->identities->find( $identity->id )->rep_code );
	}

	public function test_binding_is_written_to_the_audit_log(): void {
		global $wpdb;

		$identity                    = $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) );
		$_COOKIE[ Referral::COOKIE ] = 'ben';

		$this->referral->bind( $identity );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT actor, context FROM %i WHERE identity_id = %d AND event = 'rep.assigned'", $wpdb->prefix . 'gaa_audit_log', $identity->id )
		);
		$this->assertSame( 'referral-link', $row->actor );
		$this->assertSame( array( 'rep_code' => 'ben' ), json_decode( $row->context, true ) );
	}

	public function test_signing_in_after_a_referral_link_attributes_the_identity(): void {
		$_GET['ref'] = 'anna';
		$this->referral->capture();
		$_GET = array();

		$identity = $this->sign_in_through_the_form( 'buyer@example.com' );

		$this->assertSame( 'anna', $identity->rep_code );
	}

	/**
	 * Runs both form steps with the plugin's real hooks attached, as a browser would.
	 *
	 * @param string $email Buyer email address.
	 */
	private function sign_in_through_the_form( string $email ): GatedAccess\Identity {
		reset_phpmailer_instance();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REMOTE_ADDR']    = '203.0.113.10';

		$form = new Sign_In_Form(
			new Otp_Service( new Hmac() ),
			$this->identities,
			new Session( $this->identities, new Hmac(), new Cookies() ),
			new Sign_In_Page()
		);

		$_POST = array(
			'gaa_action' => 'request_code',
			'gaa_nonce'  => wp_create_nonce( 'gaa_sign_in' ),
			'email'      => $email,
		);
		$form->handle_submission();

		$_POST = array(
			'gaa_action' => 'verify_code',
			'gaa_nonce'  => wp_create_nonce( 'gaa_sign_in' ),
			'email'      => $email,
			'code'       => $this->last_mailed_code(),
		);
		$this->intercept_redirects();
		try {
			$form->handle_submission();
			$this->fail( 'Verification did not redirect.' );
		} catch ( GAA_Redirect_Intercepted $redirect ) {
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}

		return $this->identities->find_by_email( Email_Address::parse( $email ) );
	}
}
