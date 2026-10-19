<?php
/**
 * Helpers shared by the integration tests.
 *
 * @package GatedAccess
 */

use GatedAccess\Cookies;
use GatedAccess\Email_Address;
use GatedAccess\Hmac;
use GatedAccess\Identities;
use GatedAccess\Identity;
use GatedAccess\Rep_Codes;
use GatedAccess\Session;

/**
 * Sign-in, redirect and mail helpers.
 */
trait GAA_Test_Helpers {

	/**
	 * Verifies the address and signs the current request in as it.
	 *
	 * @param string $email Buyer email address.
	 */
	protected function sign_in_as( string $email ): Identity {
		add_filter( 'gaa_send_cookies', '__return_false' );

		$identities = new Identities();
		$identity   = $identities->record_verification( Email_Address::parse( $email ) );
		( new Session( $identities, new Hmac(), new Cookies() ) )->start( $identity );

		return $identity;
	}

	/**
	 * Turns every redirect into a GAA_Redirect_Intercepted exception.
	 */
	protected function intercept_redirects(): void {
		add_filter(
			'wp_redirect',
			static function ( string $location, int $status ): string {
				throw new GAA_Redirect_Intercepted( $location, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only; the message never reaches a browser.
			},
			PHP_INT_MAX,
			2
		);
	}

	/**
	 * Stores the two reps most tests assign buyers to.
	 */
	protected function configure_reps(): void {
		update_option( Rep_Codes::OPTION, "anna: Anna Kowalska\nben: Ben Ortiz" );
	}

	/**
	 * Drops the REST server so the next request builds fresh route objects, as WordPress's own REST test cases do.
	 */
	protected function reset_rest_server(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test-only reset of core's REST server singleton.
	}

	/**
	 * A six-digit code guaranteed to differ from the given one.
	 *
	 * @param string $code Real code.
	 */
	protected function other_code( string $code ): string {
		return sprintf( '%06d', ( (int) $code + 1 ) % 1000000 );
	}

	/**
	 * Pulls the six-digit code out of the most recent mail.
	 */
	protected function last_mailed_code(): string {
		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent( count( $mailer->mock_sent ) - 1 );

		$this->assertNotFalse( $sent, 'No mail was sent.' );
		$this->assertSame( 1, preg_match( '/\b(\d{6})\b/', $sent->body, $matches ), 'No code in the mail body.' );

		return $matches[1];
	}
}
