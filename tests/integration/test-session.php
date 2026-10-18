<?php
/**
 * Tests for the signed session cookie.
 *
 * @package GatedAccess
 */

use GatedAccess\Cookies;
use GatedAccess\Email_Address;
use GatedAccess\Hmac;
use GatedAccess\Identities;
use GatedAccess\Session;

/**
 * Session cookie signing, expiry and revocation.
 */
class Test_Session extends WP_UnitTestCase {

	/**
	 * Session under test.
	 *
	 * @var Session
	 */
	private Session $session;

	/**
	 * Identity repository.
	 *
	 * @var Identities
	 */
	private Identities $identities;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'gaa_send_cookies', '__return_false' );

		$this->identities = new Identities();
		$this->session    = new Session( $this->identities, new Hmac(), new Cookies() );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ Session::COOKIE ] );
		parent::tear_down();
	}

	public function test_started_session_resolves_to_its_identity(): void {
		$identity = $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) );

		$this->session->start( $identity );

		$this->assertSame( $identity->id, $this->session->current_identity()?->id );
	}

	public function test_no_cookie_means_no_identity(): void {
		$this->assertNull( $this->session->current_identity() );
	}

	public function test_cookie_with_an_edited_identity_id_is_rejected(): void {
		$buyer = $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) );
		$other = $this->identities->record_verification( Email_Address::parse( 'other@example.com' ) );
		$this->session->start( $buyer );

		$issued                        = sanitize_text_field( wp_unslash( $_COOKIE[ Session::COOKIE ] ?? '' ) );
		list( , $expires, $signature ) = explode( '|', $issued );
		$_COOKIE[ Session::COOKIE ]    = "{$other->id}|{$expires}|{$signature}";

		$this->assertNull( $this->session->current_identity() );
	}

	public function test_expired_cookie_is_rejected_even_with_a_valid_signature(): void {
		$identity = $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) );
		$payload  = $identity->id . '|' . ( time() - 1 );

		$_COOKIE[ Session::COOKIE ] = $payload . '|' . ( new Hmac() )->digest( 'session', $payload );

		$this->assertNull( $this->session->current_identity() );
	}

	public function test_deleting_the_identity_ends_its_session(): void {
		global $wpdb;

		$identity = $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) );
		$this->session->start( $identity );
		$wpdb->delete( $wpdb->prefix . 'gaa_identities', array( 'id' => $identity->id ) );

		$this->assertNull( $this->session->current_identity() );
	}

	public function test_malformed_cookie_is_rejected(): void {
		$_COOKIE[ Session::COOKIE ] = 'not-a-session';

		$this->assertNull( $this->session->current_identity() );
	}

	public function test_end_signs_the_visitor_out(): void {
		$this->session->start( $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) ) );

		$this->session->end();

		$this->assertNull( $this->session->current_identity() );
	}
}
