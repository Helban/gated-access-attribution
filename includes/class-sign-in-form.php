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
	 * @param Otp_Service   $otp           Code issuing and checking.
	 * @param Identities    $identities    Identity repository.
	 * @param Session       $session       Signed-in session.
	 * @param Sign_In_Page  $page          The page hosting the form.
	 * @param Request_Guard $request_guard Origin, client IP and return URL checks.
	 */
	public function __construct(
		private readonly Otp_Service $otp,
		private readonly Identities $identities,
		private readonly Session $session,
		private readonly Sign_In_Page $page,
		private readonly Request_Guard $request_guard,
	) {
	}

	/**
	 * Registers the shortcode and the submission handler.
	 */
	public function register_hooks(): void {
		add_shortcode( Sign_In_Page::SHORTCODE, array( $this, 'render' ) );
		add_shortcode( Sign_In_Page::SESSION_SHORTCODE, array( $this, 'render_session' ) );
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

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! $this->request_guard->is_same_origin() ) {
			$this->error = __( 'The form expired. Please try again.', 'gated-access-attribution' );
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['gaa_action'] ) );

		if ( 'sign_out' === $action ) {
			$this->sign_out();
		}

		if ( 'request_code' !== $action && 'verify_code' !== $action ) {
			return;
		}

		try {
			$email = $this->posted_email();
		} catch ( \InvalidArgumentException $error ) {
			$this->error = __( 'Enter a valid email address.', 'gated-access-attribution' );
			return;
		}

		if ( 'request_code' === $action ) {
			$this->request_code( $email );
		} else {
			$this->verify_code( $email );
		}
	}

	/**
	 * Renders the step the visitor is on.
	 */
	public function render(): string {
		$this->prepare_personal_output();

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
	 * Renders the [gaa_session] shortcode: a sign-in link, or the address and a sign-out button.
	 */
	public function render_session(): string {
		$this->prepare_personal_output();

		$identity = $this->session->current_identity();

		if ( null === $identity ) {
			return sprintf(
				'<div class="gaa-session"><a href="%s">%s</a></div>',
				esc_url( $this->page->url() ),
				esc_html__( 'Sign in', 'gated-access-attribution' )
			);
		}

		ob_start();
		?>
		<div class="gaa-session">
			<span class="gaa-session__email"><?php echo esc_html( $identity->email ); ?></span>
			<span aria-hidden="true">&middot;</span>
			<form method="post" class="gaa-session__sign-out">
				<?php $this->render_hidden_fields( 'sign_out' ); ?>
				<button type="submit" class="gaa-link-button"><?php esc_html_e( 'Sign out', 'gated-access-attribution' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Marks the response uncacheable and loads the stylesheet for output that names the signed-in buyer.
	 */
	private function prepare_personal_output(): void {
		// Covers a shortcode placed on a page other than the configured one. Headers are out by now; the constant still works.
		Page_Cache::forbid();

		wp_enqueue_style( 'gaa-sign-in', plugins_url( 'assets/sign-in.css', PLUGIN_FILE ), array(), VERSION );
	}

	/**
	 * Step one: email a code.
	 *
	 * @param Email_Address $email Address from the form.
	 */
	private function request_code( Email_Address $email ): void {
		$this->step  = 'code';
		$this->email = $email->address;

		try {
			$this->otp->request_code( $email, $this->request_guard->client_ip() );
		} catch ( Sign_In_Error $error ) {
			$this->error = $this->message_for( $error );
			return;
		}

		$this->notice = sprintf(
			/* translators: 1: email address, 2: minutes until the code expires, 3: number of digits in the code. */
			__( 'We sent a %3$d-digit code to %1$s. It expires in %2$d minutes.', 'gated-access-attribution' ),
			$email->address,
			Otp_Service::CODE_TTL / MINUTE_IN_SECONDS,
			Otp_Service::CODE_LENGTH
		);
	}

	/**
	 * Step two: check the code and sign in.
	 *
	 * @param Email_Address $email Address from the form.
	 */
	private function verify_code( Email_Address $email ): void {
		$this->step  = 'code';
		$this->email = $email->address;
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
		try {
			do_action( 'gaa_identity_verified', $identity );
		} catch ( Storage_Error $error ) {
			// The audit trail is part of signing in; do not start a session it has no record of.
			$this->error = $this->message_for( Sign_In_Error::unavailable() );
			return;
		}

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
	 * The posted address.
	 *
	 * @throws \InvalidArgumentException When it is missing or malformed.
	 */
	private function posted_email(): Email_Address {
		$raw = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in handle_submission().

		return Email_Address::parse( $raw );
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
	 * Where to send the buyer after signing in.
	 */
	private function redirect_target(): string {
		$requested = $this->request_guard->requested_redirect();

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
			printf( '<div class="woocommerce-message" role="status">%s</div>', esc_html( $this->notice ) );
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
			<p><button type="submit" class="button wp-element-button"><?php esc_html_e( 'Email me a sign-in code', 'gated-access-attribution' ); ?></button></p>
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
				<label for="gaa-code">
					<?php
					/* translators: %d: number of digits in the code. */
					echo esc_html( sprintf( __( '%d-digit code', 'gated-access-attribution' ), Otp_Service::CODE_LENGTH ) );
					?>
				</label>
				<input type="text" class="input-text" name="code" id="gaa-code" inputmode="numeric" pattern="[0-9]{<?php echo esc_attr( (string) Otp_Service::CODE_LENGTH ); ?>}" maxlength="<?php echo esc_attr( (string) Otp_Service::CODE_LENGTH ); ?>" autocomplete="one-time-code" required>
			</p>
			<input type="hidden" name="email" value="<?php echo esc_attr( $this->email ); ?>">
			<?php $this->render_hidden_fields( 'verify_code' ); ?>
			<p><button type="submit" class="button wp-element-button"><?php esc_html_e( 'Sign in', 'gated-access-attribution' ); ?></button></p>
		</form>
		<form method="post" class="gaa-sign-in__resend gaa-sign-in__actions">
			<input type="hidden" name="email" value="<?php echo esc_attr( $this->email ); ?>">
			<?php $this->render_hidden_fields( 'request_code' ); ?>
			<button type="submit" class="gaa-link-button"><?php esc_html_e( 'Send a new code', 'gated-access-attribution' ); ?></button>
			<a class="gaa-link-button" href="<?php echo esc_url( $this->page->url( $this->request_guard->requested_redirect() ) ); ?>"><?php esc_html_e( 'Use a different email address', 'gated-access-attribution' ); ?></a>
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
				esc_html__( 'Signed in as %s', 'gated-access-attribution' ),
				'<strong>' . esc_html( $identity->email ) . '</strong>'
			);
			?>
		</p>
		<p><a class="button wp-element-button" href="<?php echo esc_url( $this->redirect_target() ); ?>"><?php esc_html_e( 'Continue to the shop', 'gated-access-attribution' ); ?></a></p>
		<form method="post" class="gaa-sign-in__sign-out">
			<?php $this->render_hidden_fields( 'sign_out' ); ?>
			<p><button type="submit" class="gaa-link-button"><?php esc_html_e( 'Sign out', 'gated-access-attribution' ); ?></button></p>
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
		printf( '<input type="hidden" name="redirect_to" value="%s">', esc_attr( $this->request_guard->requested_redirect() ) );
	}
}
