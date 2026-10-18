<?php
/**
 * Sign-in form.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the two-step sign-in form and renders it through a shortcode.
 *
 * Step one takes an email address and sends a code. Step two takes the code,
 * marks the identity verified and starts the session. Both steps answer the
 * same way for known and unknown addresses.
 */
final class Sign_In_Form {

	private const NONCE_ACTION = 'gaa_sign_in';
	private const NONCE_FIELD  = 'gaa_nonce';

	/**
	 * Which step to render: "email" or "code".
	 *
	 * @var string
	 */
	private string $step = 'email';

	/**
	 * Address being signed in, echoed back into the code step.
	 *
	 * @var string
	 */
	private string $email = '';

	/**
	 * Error for the visitor, already translated.
	 *
	 * @var string
	 */
	private string $error = '';

	/**
	 * Confirmation for the visitor, already translated.
	 *
	 * @var string
	 */
	private string $notice = '';

	/**
	 * Sets up the form.
	 *
	 * @param Otp_Service  $otp        Code issuing and checking.
	 * @param Identities   $identities Identity repository.
	 * @param Session      $session    Signed-in session.
	 * @param Sign_In_Page $page       The page hosting the form.
	 */
	public function __construct(
		private readonly Otp_Service $otp,
		private readonly Identities $identities,
		private readonly Session $session,
		private readonly Sign_In_Page $page,
	) {
	}

	/**
	 * Registers the shortcode and the submission handler.
	 */
	public function register_hooks(): void {
		add_shortcode( Sign_In_Page::SHORTCODE, array( $this, 'render' ) );
		// Before the catalog gate, which runs at the default priority.
		add_action( 'template_redirect', array( $this, 'handle_submission' ), 5 );
		add_action( 'template_redirect', array( $this, 'prevent_caching' ), 5 );
	}

	/**
	 * Keeps the sign-in page out of shared caches: it shows the signed-in
	 * address, and the form's state, to whoever asked.
	 */
	public function prevent_caching(): void {
		if ( $this->page->is_current() ) {
			Page_Cache::forbid();
		}
	}

	/**
	 * Processes a POSTed form step, if there is one.
	 */
	public function handle_submission(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['gaa_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only detects the form; the nonce is checked next.
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! $this->is_same_origin() ) {
			$this->error = __( 'The form expired. Please try again.', 'gated-access-attribution' );
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['gaa_action'] ) );

		if ( 'request_code' === $action ) {
			$this->request_code();
		} elseif ( 'verify_code' === $action ) {
			$this->verify_code();
		} elseif ( 'sign_out' === $action ) {
			$this->sign_out();
		}
	}

	/**
	 * Renders the step the visitor is on.
	 */
	public function render(): string {
		// Covers the shortcode placed on a page other than the configured one. Headers are out by now; the constant still works.
		Page_Cache::forbid();

		ob_start();

		echo '<div class="woocommerce gaa-sign-in">';
		$this->render_messages();

		$identity = $this->session->current_identity();

		if ( 'code' === $this->step ) {
			$this->render_code_step();
		} elseif ( null !== $identity ) {
			$this->render_signed_in( $identity );
		} else {
			$this->render_email_step();
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Step one: email a code.
	 */
	private function request_code(): void {
		$email = $this->posted_email();

		if ( null === $email ) {
			$this->error = __( 'Enter a valid email address.', 'gated-access-attribution' );
			return;
		}

		$this->step  = 'code';
		$this->email = $email->value;

		try {
			$this->otp->request_code( $email, $this->client_ip() );
		} catch ( Sign_In_Error $error ) {
			$this->error = $this->message_for( $error );
			return;
		}

		$this->notice = sprintf(
			/* translators: 1: email address, 2: minutes until the code expires. */
			__( 'We sent a 6-digit code to %1$s. It expires in %2$d minutes.', 'gated-access-attribution' ),
			$email->value,
			Otp_Service::CODE_TTL / MINUTE_IN_SECONDS
		);
	}

	/**
	 * Step two: check the code and sign in.
	 */
	private function verify_code(): void {
		$email = $this->posted_email();

		if ( null === $email ) {
			$this->error = __( 'Enter a valid email address.', 'gated-access-attribution' );
			return;
		}

		$this->step  = 'code';
		$this->email = $email->value;
		$code        = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in handle_submission().

		try {
			$this->otp->verify_code( $email, $code );
		} catch ( Sign_In_Error $error ) {
			$this->error = $this->message_for( $error );
			return;
		}

		$identity = $this->identities->record_verification( $email );

		/**
		 * Fires after a buyer proves control of their email address.
		 *
		 * @param Identity $identity The verified identity, as stored before this hook.
		 */
		do_action( 'gaa_identity_verified', $identity );

		$this->session->start( $identity );
		wp_safe_redirect( $this->redirect_target() );
		exit;
	}

	/**
	 * Ends the session and returns to the sign-in page.
	 */
	private function sign_out(): void {
		$this->session->end();
		wp_safe_redirect( $this->page->url() );
		exit;
	}

	/**
	 * The posted address, or null when it is missing or malformed.
	 */
	private function posted_email(): ?Email_Address {
		$raw = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in handle_submission().

		try {
			return Email_Address::parse( $raw );
		} catch ( \InvalidArgumentException $error ) {
			return null;
		}
	}

	/**
	 * Visitor-facing text for a failure.
	 *
	 * @param Sign_In_Error $error What went wrong.
	 */
	private function message_for( Sign_In_Error $error ): string {
		return match ( $error->reason ) {
			Sign_In_Error::RATE_LIMITED => __( 'Too many codes were requested for this address or from your network. Use the latest code you received, or try again later.', 'gated-access-attribution' ),
			Sign_In_Error::INVALID_CODE => __( 'That code is not valid. Check the latest email from us, or ask for a new code.', 'gated-access-attribution' ),
			default                     => __( 'We could not send a code just now. Please try again in a few minutes.', 'gated-access-attribution' ),
		};
	}

	/**
	 * Rejects form posts whose Origin header names another site.
	 *
	 * A second line of defence next to the nonce, which is shared by every
	 * logged-out visitor. Browsers that omit Origin fall back to the nonce alone.
	 */
	private function is_same_origin(): bool {
		if ( ! isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			return true;
		}

		$origin = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) );
		$home   = wp_parse_url( home_url() );

		return is_array( $origin ) && isset( $origin['host'], $home['host'] )
			&& strtolower( $origin['host'] ) === strtolower( $home['host'] )
			&& ( $origin['port'] ?? null ) === ( $home['port'] ?? null );
	}

	/**
	 * Client IP for per-IP rate limits.
	 */
	private function client_ip(): string {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the IP address used for per-IP rate limits.
		 *
		 * Only REMOTE_ADDR is trusted by default, because X-Forwarded-For and
		 * similar headers can be set by anyone. A site behind a reverse proxy it
		 * controls should return the forwarded client address here.
		 *
		 * @param string $remote_addr The connecting address.
		 */
		$client_ip = (string) apply_filters( 'gaa_client_ip', $remote_addr );

		return false === filter_var( $client_ip, FILTER_VALIDATE_IP ) ? '0.0.0.0' : $client_ip;
	}

	/**
	 * Same-site URL the visitor asked to return to, or an empty string.
	 */
	private function requested_redirect(): string {
		// phpcs:disable WordPress.Security.NonceVerification -- Read-only, and validated to a same-site URL before use.
		if ( isset( $_POST['redirect_to'] ) ) {
			$raw = esc_url_raw( wp_unslash( $_POST['redirect_to'] ) );
		} elseif ( isset( $_GET['redirect_to'] ) ) {
			$raw = esc_url_raw( wp_unslash( $_GET['redirect_to'] ) );
		} else {
			$raw = '';
		}
		// phpcs:enable

		return (string) wp_validate_redirect( $raw, '' );
	}

	/**
	 * Where to send the buyer after signing in.
	 */
	private function redirect_target(): string {
		$requested = $this->requested_redirect();

		return '' !== $requested ? $requested : wc_get_page_permalink( 'shop' );
	}

	/**
	 * Prints the pending error or confirmation.
	 */
	private function render_messages(): void {
		if ( '' !== $this->error ) {
			printf( '<ul class="woocommerce-error" role="alert"><li>%s</li></ul>', esc_html( $this->error ) );
		}

		if ( '' !== $this->notice ) {
			printf( '<div class="woocommerce-info" role="status">%s</div>', esc_html( $this->notice ) );
		}
	}

	/**
	 * Prints the email form.
	 */
	private function render_email_step(): void {
		?>
		<form method="post" class="woocommerce-form gaa-sign-in__email">
			<p class="form-row form-row-wide">
				<label for="gaa-email"><?php esc_html_e( 'Email address', 'gated-access-attribution' ); ?></label>
				<input type="email" class="input-text" name="email" id="gaa-email" autocomplete="email" required value="<?php echo esc_attr( $this->email ); ?>">
			</p>
			<?php $this->render_hidden_fields( 'request_code' ); ?>
			<p><button type="submit" class="button"><?php esc_html_e( 'Email me a sign-in code', 'gated-access-attribution' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Prints the code form, plus a way to get a new code or change the address.
	 */
	private function render_code_step(): void {
		?>
		<form method="post" class="woocommerce-form gaa-sign-in__code">
			<p class="form-row form-row-wide">
				<label for="gaa-code"><?php esc_html_e( '6-digit code', 'gated-access-attribution' ); ?></label>
				<input type="text" class="input-text" name="code" id="gaa-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
			</p>
			<input type="hidden" name="email" value="<?php echo esc_attr( $this->email ); ?>">
			<?php $this->render_hidden_fields( 'verify_code' ); ?>
			<p><button type="submit" class="button"><?php esc_html_e( 'Sign in', 'gated-access-attribution' ); ?></button></p>
		</form>
		<form method="post" class="gaa-sign-in__resend">
			<input type="hidden" name="email" value="<?php echo esc_attr( $this->email ); ?>">
			<?php $this->render_hidden_fields( 'request_code' ); ?>
			<p>
				<button type="submit" class="button"><?php esc_html_e( 'Send a new code', 'gated-access-attribution' ); ?></button>
				<a href="<?php echo esc_url( $this->page->url( $this->requested_redirect() ) ); ?>"><?php esc_html_e( 'Use a different email address', 'gated-access-attribution' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Prints the signed-in state.
	 *
	 * @param Identity $identity The signed-in identity.
	 */
	private function render_signed_in( Identity $identity ): void {
		?>
		<p>
			<?php
			printf(
				/* translators: %s: email address. */
				esc_html__( 'You are signed in as %s.', 'gated-access-attribution' ),
				'<strong>' . esc_html( $identity->email ) . '</strong>'
			);
			?>
		</p>
		<p><a class="button" href="<?php echo esc_url( $this->redirect_target() ); ?>"><?php esc_html_e( 'Continue to the shop', 'gated-access-attribution' ); ?></a></p>
		<form method="post" class="gaa-sign-in__sign-out">
			<?php $this->render_hidden_fields( 'sign_out' ); ?>
			<p><button type="submit" class="button"><?php esc_html_e( 'Sign out', 'gated-access-attribution' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Prints the action, nonce and return URL fields shared by every form.
	 *
	 * @param string $action Form step to run on submit.
	 */
	private function render_hidden_fields( string $action ): void {
		printf( '<input type="hidden" name="gaa_action" value="%s">', esc_attr( $action ) );
		// Not wp_nonce_field(): it prints a fixed id, and the code step has two forms.
		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( self::NONCE_FIELD ), esc_attr( wp_create_nonce( self::NONCE_ACTION ) ) );
		printf( '<input type="hidden" name="redirect_to" value="%s">', esc_attr( $this->requested_redirect() ) );
	}
}
