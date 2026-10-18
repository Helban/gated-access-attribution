<?php
/**
 * Tests for issuing and checking sign-in codes.
 *
 * @package GatedAccess
 */

use GatedAccess\Email_Address;
use GatedAccess\Hmac;
use GatedAccess\Identities;
use GatedAccess\Otp_Service;
use GatedAccess\Schema;
use GatedAccess\Sign_In_Error;

/**
 * OTP rules: storage, expiry, attempt limit, single use, rate limits.
 */
class Test_Otp_Service extends WP_UnitTestCase {

	use GAA_Test_Helpers;

	private const IP = '203.0.113.10';

	/**
	 * Service under test.
	 *
	 * @var Otp_Service
	 */
	private Otp_Service $otp;

	/**
	 * Prefixed gaa_otp_requests table.
	 *
	 * @var string
	 */
	private string $table;

	public function set_up(): void {
		global $wpdb;

		parent::set_up();
		reset_phpmailer_instance();

		$this->otp   = new Otp_Service( new Hmac() );
		$this->table = $wpdb->prefix . Schema::OTP_REQUESTS;
	}

	public function test_mails_a_six_digit_code_to_the_address(): void {
		$this->otp->request_code( Email_Address::parse( 'buyer@example.com' ), self::IP );

		$sent = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertSame( 'buyer@example.com', $sent->to[0][0] );
		$this->assertMatchesRegularExpression( '/\b\d{6}\b/', $sent->body );
	}

	public function test_stores_neither_the_code_nor_the_address_nor_the_ip(): void {
		$this->otp->request_code( Email_Address::parse( 'buyer@example.com' ), self::IP );
		$code = $this->last_mailed_code();

		$stored = implode( '|', $this->latest_request() );

		$this->assertStringNotContainsString( $code, $stored );
		$this->assertStringNotContainsString( 'buyer@example.com', $stored );
		$this->assertStringNotContainsString( self::IP, $stored );
	}

	public function test_correct_code_is_accepted_and_consumed(): void {
		$email = Email_Address::parse( 'buyer@example.com' );
		$this->otp->request_code( $email, self::IP );

		$this->otp->verify_code( $email, $this->last_mailed_code() );

		$this->assertNotNull( $this->latest_request()['consumed_at'] );
	}

	public function test_spaces_typed_inside_the_code_are_ignored(): void {
		$email = Email_Address::parse( 'buyer@example.com' );
		$this->otp->request_code( $email, self::IP );
		$code = $this->last_mailed_code();

		$this->otp->verify_code( $email, substr( $code, 0, 3 ) . ' ' . substr( $code, 3 ) );

		$this->assertNotNull( $this->latest_request()['consumed_at'] );
	}

	public function test_wrong_code_is_rejected_and_counts_as_an_attempt(): void {
		$email = Email_Address::parse( 'buyer@example.com' );
		$this->otp->request_code( $email, self::IP );
		$wrong = $this->other_code( $this->last_mailed_code() );

		$this->assert_sign_in_error( Sign_In_Error::INVALID_CODE, fn () => $this->otp->verify_code( $email, $wrong ) );
		$this->assertSame( '1', $this->latest_request()['attempts'] );
	}

	public function test_correct_code_still_works_on_the_last_allowed_attempt(): void {
		$email = Email_Address::parse( 'buyer@example.com' );
		$this->otp->request_code( $email, self::IP );
		$code = $this->last_mailed_code();

		for ( $attempt = 1; $attempt < Otp_Service::MAX_ATTEMPTS; $attempt++ ) {
			$this->assert_sign_in_error( Sign_In_Error::INVALID_CODE, fn () => $this->otp->verify_code( $email, $this->other_code( $code ) ) );
		}
		$this->otp->verify_code( $email, $code );

		$this->assertNotNull( $this->latest_request()['consumed_at'] );
	}

	public function test_correct_code_is_rejected_once_the_attempts_are_used_up(): void {
		$email = Email_Address::parse( 'buyer@example.com' );
		$this->otp->request_code( $email, self::IP );
		$code = $this->last_mailed_code();

		for ( $attempt = 1; $attempt <= Otp_Service::MAX_ATTEMPTS; $attempt++ ) {
			$this->assert_sign_in_error( Sign_In_Error::INVALID_CODE, fn () => $this->otp->verify_code( $email, $this->other_code( $code ) ) );
		}

		$this->assert_sign_in_error( Sign_In_Error::INVALID_CODE, fn () => $this->otp->verify_code( $email, $code ) );
		$this->assertSame( (string) Otp_Service::MAX_ATTEMPTS, $this->latest_request()['attempts'] );
	}

	public function test_expired_code_is_rejected(): void {
		global $wpdb;

		$email = Email_Address::parse( 'buyer@example.com' );
		$this->otp->request_code( $email, self::IP );
		$code = $this->last_mailed_code();
		$wpdb->update( $this->table, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $this->latest_request()['id'] ) );

		$this->assert_sign_in_error( Sign_In_Error::INVALID_CODE, fn () => $this->otp->verify_code( $email, $code ) );
	}

	public function test_code_works_only_once(): void {
		$email = Email_Address::parse( 'buyer@example.com' );
		$this->otp->request_code( $email, self::IP );
		$code = $this->last_mailed_code();
		$this->otp->verify_code( $email, $code );

		$this->assert_sign_in_error( Sign_In_Error::INVALID_CODE, fn () => $this->otp->verify_code( $email, $code ) );
	}

	public function test_new_request_retires_the_previous_code(): void {
		$email = Email_Address::parse( 'buyer@example.com' );
		$this->otp->request_code( $email, self::IP );
		$first = $this->last_mailed_code();
		$this->otp->request_code( $email, self::IP );
		$second = $this->last_mailed_code();

		if ( $first === $second ) {
			$this->markTestSkipped( 'Both random codes came out equal (one in a million).' );
		}

		$this->assert_sign_in_error( Sign_In_Error::INVALID_CODE, fn () => $this->otp->verify_code( $email, $first ) );
		$this->otp->verify_code( $email, $second );
		$this->assertNotNull( $this->latest_request()['consumed_at'] );
	}

	public function test_code_is_bound_to_the_address_it_was_sent_to(): void {
		$first  = Email_Address::parse( 'first@example.com' );
		$second = Email_Address::parse( 'second@example.com' );
		$this->otp->request_code( $first, self::IP );
		$first_code = $this->last_mailed_code();
		$this->otp->request_code( $second, self::IP );

		$this->assert_sign_in_error( Sign_In_Error::INVALID_CODE, fn () => $this->otp->verify_code( $second, $first_code ) );
	}

	public function test_address_is_rate_limited_after_three_requests_in_fifteen_minutes(): void {
		$email = Email_Address::parse( 'buyer@example.com' );

		for ( $request = 1; $request <= Otp_Service::EMAIL_LIMIT; $request++ ) {
			$this->otp->request_code( $email, "198.51.100.{$request}" );
		}

		$this->assert_sign_in_error( Sign_In_Error::RATE_LIMITED, fn () => $this->otp->request_code( $email, '198.51.100.99' ) );
		$this->assertCount( Otp_Service::EMAIL_LIMIT, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_address_limit_resets_once_the_window_has_passed(): void {
		global $wpdb;

		$email = Email_Address::parse( 'buyer@example.com' );
		for ( $request = 1; $request <= Otp_Service::EMAIL_LIMIT; $request++ ) {
			$this->otp->request_code( $email, self::IP );
		}
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET created_at = %s', $this->table, gmdate( 'Y-m-d H:i:s', time() - Otp_Service::EMAIL_WINDOW - 1 ) )
		);

		$this->otp->request_code( $email, self::IP );

		$this->assertCount( Otp_Service::EMAIL_LIMIT + 1, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_address_is_capped_at_ten_codes_a_day_even_when_spread_out(): void {
		global $wpdb;

		$email = Email_Address::parse( 'buyer@example.com' );
		for ( $request = 1; $request <= Otp_Service::EMAIL_DAILY_LIMIT; $request++ ) {
			$this->otp->request_code( $email, "198.51.100.{$request}" );
			// Move every earlier request out of the 15-minute window, but keep it inside the day.
			$wpdb->query(
				$wpdb->prepare( 'UPDATE %i SET created_at = %s', $this->table, gmdate( 'Y-m-d H:i:s', time() - Otp_Service::EMAIL_WINDOW - 1 ) )
			);
		}

		$this->assert_sign_in_error( Sign_In_Error::RATE_LIMITED, fn () => $this->otp->request_code( $email, '198.51.100.99' ) );
		$this->assertCount( Otp_Service::EMAIL_DAILY_LIMIT, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_ip_is_rate_limited_after_ten_requests_in_an_hour(): void {
		for ( $request = 1; $request <= Otp_Service::IP_LIMIT; $request++ ) {
			$this->otp->request_code( Email_Address::parse( "buyer{$request}@example.com" ), self::IP );
		}

		$this->assert_sign_in_error(
			Sign_In_Error::RATE_LIMITED,
			fn () => $this->otp->request_code( Email_Address::parse( 'one-more@example.com' ), self::IP )
		);
	}

	public function test_known_and_unknown_addresses_are_treated_the_same(): void {
		( new Identities() )->record_verification( Email_Address::parse( 'known@example.com' ) );

		$this->otp->request_code( Email_Address::parse( 'known@example.com' ), self::IP );
		$known_mail = tests_retrieve_phpmailer_instance()->get_sent( 0 );
		$this->otp->request_code( Email_Address::parse( 'unknown@example.com' ), self::IP );
		$unknown_mail = tests_retrieve_phpmailer_instance()->get_sent( 1 );

		$this->assertSame( $known_mail->subject, $unknown_mail->subject );
		$this->assertSame(
			preg_replace( '/\d{6}/', 'CODE', $known_mail->body ),
			preg_replace( '/\d{6}/', 'CODE', $unknown_mail->body )
		);
	}

	public function test_failed_mail_is_reported_as_unavailable(): void {
		add_filter( 'pre_wp_mail', '__return_false' );

		$this->assert_sign_in_error(
			Sign_In_Error::UNAVAILABLE,
			fn () => $this->otp->request_code( Email_Address::parse( 'buyer@example.com' ), self::IP )
		);
	}

	public function test_prune_deletes_requests_past_the_retention_period(): void {
		global $wpdb;

		$this->otp->request_code( Email_Address::parse( 'old@example.com' ), self::IP );
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET created_at = %s', $this->table, gmdate( 'Y-m-d H:i:s', time() - Otp_Service::RETENTION - 1 ) )
		);
		$this->otp->request_code( Email_Address::parse( 'new@example.com' ), self::IP );

		$this->otp->prune();

		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table ) ) );
	}

	/**
	 * Most recent gaa_otp_requests row.
	 *
	 * @return array<string, string|null>
	 */
	private function latest_request(): array {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', $this->table ), ARRAY_A );
	}

	/**
	 * A six-digit code guaranteed to differ from the given one.
	 *
	 * @param string $code Real code.
	 */
	private function other_code( string $code ): string {
		return sprintf( '%06d', ( (int) $code + 1 ) % 1000000 );
	}

	/**
	 * Asserts that the callback throws a Sign_In_Error with the given reason.
	 *
	 * @param string   $reason  Expected Sign_In_Error reason.
	 * @param callable $attempt Code expected to fail.
	 */
	private function assert_sign_in_error( string $reason, callable $attempt ): void {
		try {
			$attempt();
		} catch ( Sign_In_Error $error ) {
			$this->assertSame( $reason, $error->reason );
			return;
		}

		$this->fail( "Expected a Sign_In_Error with reason {$reason}." );
	}
}
