<?php
/**
 * Tests for the sign-in form.
 *
 * @package GatedAccess
 */

use GatedAccess\Cookies;
use GatedAccess\Email_Address;
use GatedAccess\Hmac;
use GatedAccess\Identities;
use GatedAccess\Otp_Service;
use GatedAccess\Session;
use GatedAccess\Sign_In_Form;
use GatedAccess\Sign_In_Page;

/**
 * Form submission, CSRF checks, redirects and response uniformity.
 */
class Test_Sign_In_Form extends WP_UnitTestCase {

	use GAA_Test_Helpers;

	/**
	 * Identity repository.
	 *
	 * @var Identities
	 */
	private Identities $identities;

	/**
	 * Session reader.
	 *
	 * @var Session
	 */
	private Session $session;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		add_filter( 'gaa_send_cookies', '__return_false' );

		$this->identities = new Identities();
		$this->session    = new Session( $this->identities, new Hmac(), new Cookies() );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REMOTE_ADDR']    = '203.0.113.10';
	}

	public function tear_down(): void {
		$_POST = array();
		unset( $_SERVER['HTTP_ORIGIN'], $_COOKIE[ Session::COOKIE ] );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		parent::tear_down();
	}

	public function test_first_visit_shows_the_email_step(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$html = $this->new_form()->render();

		$this->assertStringContainsString( 'name="email"', $html );
		$this->assertStringContainsString( 'name="gaa_nonce"', $html );
		$this->assertStringNotContainsString( 'name="code"', $html );
	}

	public function test_requesting_a_code_mails_it_and_shows_the_code_step(): void {
		$form = $this->submit( $this->new_form(), 'request_code', array( 'email' => 'buyer@example.com' ) );

		$html = $form->render();

		$this->assertStringContainsString( 'name="code"', $html );
		$this->assertStringContainsString( 'We sent a 6-digit code to buyer@example.com', $html );
		$this->assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_page_is_identical_for_known_and_unknown_addresses(): void {
		$this->identities->record_verification( Email_Address::parse( 'known@example.com' ) );

		$known   = $this->submit( $this->new_form(), 'request_code', array( 'email' => 'known@example.com' ) )->render();
		$unknown = $this->submit( $this->new_form(), 'request_code', array( 'email' => 'unknown@example.com' ) )->render();

		$this->assertSame(
			str_replace( 'known@example.com', 'ADDRESS', $known ),
			str_replace( 'unknown@example.com', 'ADDRESS', $unknown )
		);
	}

	public function test_valid_code_signs_in_and_returns_to_the_requested_page(): void {
		$this->submit( $this->new_form(), 'request_code', array( 'email' => 'buyer@example.com' ) );
		$return_to = home_url( '/product/widget/' );

		$redirect = $this->submit_expecting_redirect(
			'verify_code',
			array(
				'email'       => 'buyer@example.com',
				'code'        => $this->last_mailed_code(),
				'redirect_to' => $return_to,
			)
		);

		$this->assertSame( $return_to, $redirect->location );
		$this->assertSame( 'buyer@example.com', $this->session->current_identity()?->email );
	}

	public function test_redirect_to_another_site_falls_back_to_the_shop(): void {
		$this->submit( $this->new_form(), 'request_code', array( 'email' => 'buyer@example.com' ) );

		$redirect = $this->submit_expecting_redirect(
			'verify_code',
			array(
				'email'       => 'buyer@example.com',
				'code'        => $this->last_mailed_code(),
				'redirect_to' => 'https://attacker.example/phish',
			)
		);

		$this->assertSame( wc_get_page_permalink( 'shop' ), $redirect->location );
	}

	public function test_verification_announces_the_identity(): void {
		$this->submit( $this->new_form(), 'request_code', array( 'email' => 'buyer@example.com' ) );
		$announced = new ArrayObject();
		add_action( 'gaa_identity_verified', array( $announced, 'append' ) );

		$this->submit_expecting_redirect(
			'verify_code',
			array(
				'email' => 'buyer@example.com',
				'code'  => $this->last_mailed_code(),
			)
		);

		$this->assertCount( 1, $announced );
		$this->assertSame( 'buyer@example.com', $announced[0]->email );
	}

	public function test_wrong_code_keeps_the_visitor_on_the_code_step(): void {
		$this->submit( $this->new_form(), 'request_code', array( 'email' => 'buyer@example.com' ) );

		$html = $this->submit(
			$this->new_form(),
			'verify_code',
			array(
				'email' => 'buyer@example.com',
				'code'  => '000000' === $this->last_mailed_code() ? '111111' : '000000',
			)
		)->render();

		$this->assertStringContainsString( 'That code is not valid.', $html );
		$this->assertStringContainsString( 'name="code"', $html );
		$this->assertNull( $this->session->current_identity() );
	}

	public function test_post_without_a_valid_nonce_does_nothing(): void {
		$_POST = array(
			'gaa_action' => 'request_code',
			'gaa_nonce'  => 'forged',
			'email'      => 'buyer@example.com',
		);

		$form = $this->new_form();
		$form->handle_submission();

		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertStringContainsString( 'The form expired.', $form->render() );
	}

	public function test_post_from_another_origin_does_nothing(): void {
		$_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';

		$this->submit( $this->new_form(), 'request_code', array( 'email' => 'buyer@example.com' ) );

		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_post_from_this_origin_is_accepted(): void {
		$_SERVER['HTTP_ORIGIN'] = untrailingslashit( home_url() );

		$this->submit( $this->new_form(), 'request_code', array( 'email' => 'buyer@example.com' ) );

		$this->assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_malformed_address_is_rejected_before_anything_is_sent(): void {
		$html = $this->submit( $this->new_form(), 'request_code', array( 'email' => 'not-an-address' ) )->render();

		$this->assertStringContainsString( 'Enter a valid email address.', $html );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_rendering_the_form_marks_the_response_uncacheable(): void {
		$before = did_action( 'gaa_response_not_cacheable' );

		$this->new_form()->render();

		$this->assertSame( $before + 1, did_action( 'gaa_response_not_cacheable' ) );
	}

	public function test_only_the_sign_in_page_is_marked_uncacheable_before_rendering(): void {
		$page = new Sign_In_Page();
		$page->ensure_exists();
		$before = did_action( 'gaa_response_not_cacheable' );

		$this->go_to( home_url( '/' ) );
		$this->new_form()->prevent_caching();
		$this->assertSame( $before, did_action( 'gaa_response_not_cacheable' ) );

		$this->go_to( $page->url() );
		$this->new_form()->prevent_caching();
		$this->assertSame( $before + 1, did_action( 'gaa_response_not_cacheable' ) );
	}

	public function test_signed_in_visitor_can_sign_out(): void {
		$this->sign_in_as( 'buyer@example.com' );

		$redirect = $this->submit_expecting_redirect( 'sign_out', array() );

		$this->assertSame( ( new Sign_In_Page() )->url(), $redirect->location );
		$this->assertNull( $this->session->current_identity() );
	}

	/**
	 * A form with no state from earlier submissions.
	 */
	private function new_form(): Sign_In_Form {
		return new Sign_In_Form( new Otp_Service( new Hmac() ), $this->identities, $this->session, new Sign_In_Page() );
	}

	/**
	 * POSTs one form step with a valid nonce.
	 *
	 * @param Sign_In_Form          $form   Form to submit to.
	 * @param string                $action Form step.
	 * @param array<string, string> $fields Posted fields.
	 */
	private function submit( Sign_In_Form $form, string $action, array $fields ): Sign_In_Form {
		$_POST = array_merge(
			$fields,
			array(
				'gaa_action' => $action,
				'gaa_nonce'  => wp_create_nonce( 'gaa_sign_in' ),
			)
		);

		$form->handle_submission();

		return $form;
	}

	/**
	 * POSTs one form step and returns the redirect it ends with.
	 *
	 * @param string                $action Form step.
	 * @param array<string, string> $fields Posted fields.
	 */
	private function submit_expecting_redirect( string $action, array $fields ): GAA_Redirect_Intercepted {
		$this->intercept_redirects();

		try {
			$this->submit( $this->new_form(), $action, $fields );
		} catch ( GAA_Redirect_Intercepted $redirect ) {
			return $redirect;
		}

		$this->fail( "The {$action} step did not redirect." );
	}
}
