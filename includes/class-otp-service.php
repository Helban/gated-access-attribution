<?php
/**
 * One-time sign-in codes.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Issues and checks six-digit sign-in codes.
 *
 * A code is never stored, only its HMAC bound to the email address. Requests
 * are rate limited per address and per IP from the same table, and a new code
 * retires the previous one, so an attacker never has more than one live code
 * per address to guess against.
 */
final class Otp_Service {

	public const CODE_LENGTH        = 6;
	public const CODE_TTL           = 10 * MINUTE_IN_SECONDS;
	public const MAX_ATTEMPTS       = 5;
	public const EMAIL_LIMIT        = 3;
	public const EMAIL_WINDOW       = 15 * MINUTE_IN_SECONDS;
	public const EMAIL_DAILY_LIMIT  = 10;
	public const EMAIL_DAILY_WINDOW = DAY_IN_SECONDS;
	public const IP_LIMIT           = 10;
	public const IP_WINDOW          = HOUR_IN_SECONDS;

	/**
	 * How long request rows are kept. Must stay longer than every rate-limit window.
	 */
	public const RETENTION = 2 * DAY_IN_SECONDS;

	/**
	 * Sets up the service.
	 *
	 * @param Hmac $hmac Keyed digests.
	 */
	public function __construct( private readonly Hmac $hmac ) {
	}

	/**
	 * Emails a fresh code to the address, retiring any code sent before.
	 *
	 * Nothing here depends on whether the address belongs to an identity, so
	 * the outcome cannot be used to probe for buyers.
	 *
	 * @param Email_Address $email Address to send the code to.
	 * @param string        $ip    Client IP, used only for rate limiting.
	 * @throws Sign_In_Error When a rate limit is hit or the code cannot be stored or sent.
	 */
	public function request_code( Email_Address $email, string $ip ): void {
		$email_hash = $this->hmac->digest( 'otp-email', $email->address );
		$ip_hash    = $this->hmac->digest( 'otp-ip', $ip );

		if ( $this->is_rate_limited( $email_hash, $ip_hash ) ) {
			throw Sign_In_Error::rate_limited();
		}

		$code = str_pad( (string) random_int( 0, 10 ** self::CODE_LENGTH - 1 ), self::CODE_LENGTH, '0', STR_PAD_LEFT );

		$this->retire_active_codes( $email_hash );
		$this->store_code( $email_hash, $ip_hash, $code );
		$this->send_code( $email, $code );
	}

	/**
	 * Accepts the code once, if it matches the address's live code.
	 *
	 * No code, a wrong code, an expired code, a used code and a code past its
	 * attempt limit all raise the same error.
	 *
	 * @param Email_Address $email Address the code was sent to.
	 * @param string        $code  Code as typed.
	 * @throws Sign_In_Error With reason INVALID_CODE on any mismatch.
	 */
	public function verify_code( Email_Address $email, string $code ): void {
		global $wpdb;

		$email_hash = $this->hmac->digest( 'otp-email', $email->address );
		$code       = (string) preg_replace( '/\s+/', '', $code );

		if ( ! preg_match( '/^\d{' . self::CODE_LENGTH . '}$/', $code ) ) {
			throw Sign_In_Error::invalid_code();
		}

		$now     = gmdate( 'Y-m-d H:i:s' );
		$request = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, code_hash FROM %i WHERE email_hash = %s AND consumed_at IS NULL AND expires_at > %s AND attempts < %d ORDER BY id DESC LIMIT 1',
				$this->table(),
				$email_hash,
				$now,
				self::MAX_ATTEMPTS
			)
		);

		if ( null === $request ) {
			throw Sign_In_Error::invalid_code();
		}

		// Count the attempt before comparing, in one conditional UPDATE, so parallel guesses cannot exceed the limit.
		$counted = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET attempts = attempts + 1 WHERE id = %d AND attempts < %d',
				$this->table(),
				$request->id,
				self::MAX_ATTEMPTS
			)
		);

		if ( 1 !== $counted || ! hash_equals( $request->code_hash, $this->code_digest( $email_hash, $code ) ) ) {
			throw Sign_In_Error::invalid_code();
		}

		$consumed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL',
				$this->table(),
				$now,
				$request->id
			)
		);

		if ( 1 !== $consumed ) {
			throw Sign_In_Error::invalid_code();
		}
	}

	/**
	 * Deletes request rows older than the retention period.
	 *
	 * Full scan on created_at: fine for a table that only holds a day of
	 * sign-ins. Add an index on created_at if that stops being true.
	 */
	public function prune(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				$this->table(),
				gmdate( 'Y-m-d H:i:s', time() - self::RETENTION )
			)
		);
	}

	/**
	 * Whether the address or the IP has used up its request allowance.
	 *
	 * The daily cap per address bounds guessing: at most EMAIL_DAILY_LIMIT
	 * codes times MAX_ATTEMPTS tries a day, however many IPs ask.
	 *
	 * Check-then-insert without a lock: parallel requests can overshoot a limit
	 * by however many are in flight at once. Fine for mail-abuse limits; a
	 * GET_LOCK() per address hash would close the gap if it ever matters.
	 *
	 * @param string $email_hash Digest of the address.
	 * @param string $ip_hash    Digest of the client IP.
	 */
	private function is_rate_limited( string $email_hash, string $ip_hash ): bool {
		global $wpdb;

		$now = time();

		$email_requests = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS last_day, COALESCE(SUM(created_at >= %s), 0) AS last_window FROM %i WHERE email_hash = %s AND created_at >= %s',
				gmdate( 'Y-m-d H:i:s', $now - self::EMAIL_WINDOW ),
				$this->table(),
				$email_hash,
				gmdate( 'Y-m-d H:i:s', $now - self::EMAIL_DAILY_WINDOW )
			)
		);

		// A COUNT query always returns a row, so null means the query failed. Fail closed.
		if ( null === $email_requests || (int) $email_requests->last_window >= self::EMAIL_LIMIT || (int) $email_requests->last_day >= self::EMAIL_DAILY_LIMIT ) {
			return true;
		}

		$ip_requests = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE ip_hash = %s AND created_at >= %s',
				$this->table(),
				$ip_hash,
				gmdate( 'Y-m-d H:i:s', $now - self::IP_WINDOW )
			)
		);

		return null === $ip_requests || (int) $ip_requests >= self::IP_LIMIT;
	}

	/**
	 * Marks every live code for the address as used.
	 *
	 * @param string $email_hash Digest of the address.
	 */
	private function retire_active_codes( string $email_hash ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET consumed_at = %s WHERE email_hash = %s AND consumed_at IS NULL',
				$this->table(),
				gmdate( 'Y-m-d H:i:s' ),
				$email_hash
			)
		);
	}

	/**
	 * Saves the digest of a new code.
	 *
	 * @param string $email_hash Digest of the address.
	 * @param string $ip_hash    Digest of the client IP.
	 * @param string $code       The plain code, digested before it is written.
	 * @throws Sign_In_Error When the row cannot be written.
	 */
	private function store_code( string $email_hash, string $ip_hash, string $code ): void {
		global $wpdb;

		$now = time();

		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'email_hash' => $email_hash,
				'ip_hash'    => $ip_hash,
				'code_hash'  => $this->code_digest( $email_hash, $code ),
				'attempts'   => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + self::CODE_TTL ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw Sign_In_Error::unavailable();
		}
	}

	/**
	 * Mails the code to the address.
	 *
	 * @param Email_Address $email Recipient.
	 * @param string        $code  Plain code.
	 * @throws Sign_In_Error When wp_mail() reports a failure.
	 */
	private function send_code( Email_Address $email, string $code ): void {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Your sign-in code for %s', 'gated-access-attribution' ),
			$site_name
		);

		$message = sprintf(
			/* translators: 1: six-digit code, 2: minutes until the code expires. */
			__( "Your sign-in code is %1\$s\n\nIt works once and expires in %2\$d minutes. If you did not ask for it, you can ignore this email.", 'gated-access-attribution' ),
			$code,
			self::CODE_TTL / MINUTE_IN_SECONDS
		);

		if ( ! wp_mail( $email->address, $subject, $message ) ) {
			// No address in the message: PHPMailer errors can include the recipient.
			wc_get_logger()->error(
				'wp_mail() failed while sending a sign-in code. Check the site mail setup and its From address.',
				array( 'source' => 'gated-access-attribution' )
			);
			throw Sign_In_Error::unavailable();
		}
	}

	/**
	 * Prefixed table name.
	 */
	private function table(): string {
		global $wpdb;

		return Schema::table( Schema::OTP_REQUESTS );
	}

	/**
	 * Digest of a code, bound to the address it was sent to.
	 *
	 * @param string $email_hash Digest of the address.
	 * @param string $code       Plain code.
	 */
	private function code_digest( string $email_hash, string $code ): string {
		return $this->hmac->digest( 'otp-code', $email_hash . '|' . $code );
	}
}
