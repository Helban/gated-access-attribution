<?php
/**
 * Buyer identities admin screen.
 *
 * @package GatedAccess
 */

namespace GatedAccess\Admin;

use GatedAccess\Audit_Entry;
use GatedAccess\Audit_Log;
use GatedAccess\Email_Address;
use GatedAccess\Identities;
use GatedAccess\Identity;
use GatedAccess\Order_Attribution;
use GatedAccess\Rep_Codes;
use GatedAccess\Settings;
use GatedAccess\Storage_Error;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce > Buyer identities: look a buyer up by email, see their rep,
 * orders and history, and move them to another rep.
 */
final class Identity_Screen {

	public const PAGE_SLUG       = 'gaa-identities';
	public const REASSIGN_ACTION = 'gaa_reassign_rep';

	private const CAPABILITY   = 'manage_woocommerce';
	private const RECENT_LIMIT = 20;
	private const ORDER_LIMIT  = 20;

	/**
	 * Sets up the screen.
	 *
	 * @param Identities $identities Identity repository.
	 * @param Rep_Codes  $rep_codes  Configured reps.
	 * @param Audit_Log  $audit_log  Audit trail.
	 */
	public function __construct(
		private readonly Identities $identities,
		private readonly Rep_Codes $rep_codes,
		private readonly Audit_Log $audit_log,
	) {
	}

	/**
	 * Registers the menu entry and the reassignment handler.
	 */
	public function register_hooks(): void {
		// After WooCommerce has added its own menu.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );
		add_action( 'admin_post_' . self::REASSIGN_ACTION, array( $this, 'handle_reassign' ) );
	}

	/**
	 * Adds the screen under the WooCommerce menu.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Buyer identities', 'gated-access-attribution' ),
			__( 'Buyer identities', 'gated-access-attribution' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * URL of the screen.
	 *
	 * @param array<string, string> $args Extra query arguments, already URL-encoded.
	 */
	public function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Moves an identity to another rep, then returns to the screen.
	 *
	 * Checks the capability and a nonce bound to the identity, and records
	 * the change in the audit log with the user who made it.
	 */
	public function handle_reassign(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change sales reps.', 'gated-access-attribution' ), '', array( 'response' => 403 ) );
		}

		$identity_id = isset( $_POST['identity_id'] ) ? absint( $_POST['identity_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is bound to this ID and checked on the next line.
		check_admin_referer( self::REASSIGN_ACTION . '_' . $identity_id );

		$identity = $this->identities->find( $identity_id );
		if ( null === $identity ) {
			wp_die( esc_html__( 'That identity no longer exists.', 'gated-access-attribution' ), '', array( 'response' => 404 ) );
		}

		$rep_code = $this->rep_codes->find( isset( $_POST['rep_code'] ) ? sanitize_text_field( wp_unslash( $_POST['rep_code'] ) ) : '' );
		$outcome  = null === $rep_code ? 'unknown_rep' : $this->reassign( $identity, $rep_code );

		wp_safe_redirect(
			$this->url(
				array(
					'email'      => rawurlencode( $identity->email ),
					'gaa_notice' => $outcome,
				)
			)
		);
		exit;
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to see buyer identities.', 'gated-access-attribution' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only lookup behind the menu capability.
		$search = isset( $_GET['email'] ) ? sanitize_text_field( wp_unslash( $_GET['email'] ) ) : '';

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Buyer identities', 'gated-access-attribution' ) );
		$this->render_notice();
		$this->render_search_form( $search );

		if ( '' === $search ) {
			$this->render_recent();
		} else {
			$this->render_lookup( $search );
		}

		echo '</div>';
	}

	/**
	 * Applies a reassignment and names the outcome for the notice.
	 *
	 * @param Identity $identity Identity to move.
	 * @param string   $rep_code Configured rep code.
	 * @return string "reassigned", "unchanged" or "failed".
	 */
	private function reassign( Identity $identity, string $rep_code ): string {
		if ( $rep_code === $identity->rep_code ) {
			return 'unchanged';
		}

		// Audit first: if the entry cannot be written, the rep is untouched.
		try {
			$this->audit_log->record(
				$identity->id,
				'rep.reassigned',
				'user:' . get_current_user_id(),
				array(
					'from' => $identity->rep_code,
					'to'   => $rep_code,
				)
			);
		} catch ( Storage_Error $error ) {
			return 'failed';
		}

		$this->identities->assign_rep( $identity->id, $rep_code );

		return 'reassigned';
	}

	/**
	 * Prints the outcome of the last reassignment, if there was one.
	 *
	 * Only known keys produce text, so nothing from the URL is echoed.
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selects one of four fixed messages.
		$notice = isset( $_GET['gaa_notice'] ) ? sanitize_key( wp_unslash( $_GET['gaa_notice'] ) ) : '';

		$messages = array(
			'reassigned'  => array( 'success', __( 'Sales rep changed.', 'gated-access-attribution' ) ),
			'unchanged'   => array( 'info', __( 'The buyer already had that rep.', 'gated-access-attribution' ) ),
			'unknown_rep' => array( 'error', __( 'That rep code is not configured.', 'gated-access-attribution' ) ),
			'failed'      => array( 'error', __( 'The change could not be saved. Try again.', 'gated-access-attribution' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $messages[ $notice ][0] ),
				esc_html( $messages[ $notice ][1] )
			);
		}
	}

	/**
	 * Prints the email lookup form.
	 *
	 * @param string $search Address searched for, if any.
	 */
	private function render_search_form( string $search ): void {
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<p>
				<label for="gaa-email-search"><?php esc_html_e( 'Email address', 'gated-access-attribution' ); ?></label>
				<input type="search" id="gaa-email-search" name="email" class="regular-text" value="<?php echo esc_attr( $search ); ?>">
				<?php submit_button( __( 'Look up', 'gated-access-attribution' ), 'secondary', '', false ); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Prints the most recently verified buyers.
	 */
	private function render_recent(): void {
		$identities = $this->identities->recently_verified( self::RECENT_LIMIT );

		printf( '<h2>%s</h2>', esc_html__( 'Recently verified', 'gated-access-attribution' ) );

		if ( array() === $identities ) {
			printf( '<p>%s</p>', esc_html__( 'No buyer has signed in yet.', 'gated-access-attribution' ) );
			return;
		}

		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Email address', 'gated-access-attribution' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sales rep', 'gated-access-attribution' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last verified', 'gated-access-attribution' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $identities as $identity ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $this->url( array( 'email' => rawurlencode( $identity->email ) ) ) ); ?>"><?php echo esc_html( $identity->email ); ?></a></td>
						<td><?php echo esc_html( $this->rep_label( $identity->rep_code ?? '' ) ); ?></td>
						<td><?php echo esc_html( $this->verified_label( $identity ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Prints the identity matching the searched address, or says there is none.
	 *
	 * @param string $search Address searched for.
	 */
	private function render_lookup( string $search ): void {
		try {
			$identity = $this->identities->find_by_email( Email_Address::parse( $search ) );
		} catch ( \InvalidArgumentException $error ) {
			printf( '<p>%s</p>', esc_html__( 'That is not a valid email address.', 'gated-access-attribution' ) );
			return;
		}

		if ( null === $identity ) {
			printf( '<p>%s</p>', esc_html__( 'No identity has that email address.', 'gated-access-attribution' ) );
			return;
		}

		$this->render_identity( $identity );
		$this->render_reassign_form( $identity );
		$this->render_orders( $identity );
		$this->render_history( $identity );
	}

	/**
	 * Prints the identity's details.
	 *
	 * @param Identity $identity Identity to show.
	 */
	private function render_identity( Identity $identity ): void {
		?>
		<h2><?php echo esc_html( $identity->email ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Identity ID', 'gated-access-attribution' ); ?></th>
				<td><?php echo esc_html( (string) $identity->id ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sales rep', 'gated-access-attribution' ); ?></th>
				<td><?php echo esc_html( $this->rep_label( $identity->rep_code ?? '' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Last verified', 'gated-access-attribution' ); ?></th>
				<td><?php echo esc_html( $this->verified_label( $identity ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'First verified', 'gated-access-attribution' ); ?></th>
				<td><?php echo esc_html( $this->format_time( $identity->created_at ) ); ?></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Prints the form that moves the identity to another rep.
	 *
	 * @param Identity $identity Identity to move.
	 */
	private function render_reassign_form( Identity $identity ): void {
		$reps = $this->rep_codes->all();

		printf( '<h2>%s</h2>', esc_html__( 'Change sales rep', 'gated-access-attribution' ) );

		if ( array() === $reps ) {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'admin.php?page=wc-settings&tab=' . Settings::TAB ) ),
				esc_html__( 'Add sales reps in the settings first.', 'gated-access-attribution' )
			);
			return;
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::REASSIGN_ACTION ); ?>">
			<input type="hidden" name="identity_id" value="<?php echo esc_attr( (string) $identity->id ); ?>">
			<?php wp_nonce_field( self::REASSIGN_ACTION . '_' . $identity->id ); ?>
			<p>
				<label for="gaa-rep-code"><?php esc_html_e( 'New sales rep', 'gated-access-attribution' ); ?></label>
				<select id="gaa-rep-code" name="rep_code">
					<?php foreach ( $reps as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $identity->rep_code ); ?>><?php echo esc_html( "{$name} ({$code})" ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Change rep', 'gated-access-attribution' ), 'secondary', 'submit', false ); ?>
			</p>
			<p class="description"><?php esc_html_e( 'Orders already placed keep the rep they were attributed to.', 'gated-access-attribution' ); ?></p>
		</form>
		<?php
	}

	/**
	 * Prints the identity's most recent orders.
	 *
	 * @param Identity $identity Identity whose orders to list.
	 */
	private function render_orders( Identity $identity ): void {
		$orders = array_filter(
			array_map( 'wc_get_order', $this->audit_log->attributed_order_ids( $identity->id, self::ORDER_LIMIT ) ),
			static fn ( $order ): bool => $order instanceof \WC_Order
		);

		printf( '<h2>%s</h2>', esc_html__( 'Orders', 'gated-access-attribution' ) );

		if ( array() === $orders ) {
			printf( '<p>%s</p>', esc_html__( 'No orders yet.', 'gated-access-attribution' ) );
			return;
		}

		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Order', 'gated-access-attribution' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'gated-access-attribution' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'gated-access-attribution' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Total', 'gated-access-attribution' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sales rep', 'gated-access-attribution' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $orders as $order ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
						<td><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
						<td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
						<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
						<td><?php echo esc_html( $this->rep_label( (string) $order->get_meta( Order_Attribution::REP_META ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Prints the identity's audit history, newest first.
	 *
	 * @param Identity $identity Identity whose history to show.
	 */
	private function render_history( Identity $identity ): void {
		$entries = $this->audit_log->for_identity( $identity->id );

		printf( '<h2>%s</h2>', esc_html__( 'History', 'gated-access-attribution' ) );

		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'gated-access-attribution' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What', 'gated-access-attribution' ); ?></th>
					<th scope="col"><?php esc_html_e( 'By', 'gated-access-attribution' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'gated-access-attribution' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $this->format_time( $entry->created_at ) ); ?></td>
						<td><?php echo esc_html( $this->event_label( $entry->event ) ); ?></td>
						<td><?php echo esc_html( $this->actor_label( $entry->actor ) ); ?></td>
						<td><?php $this->render_event_details( $entry ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Prints the detail cell for one history entry.
	 *
	 * @param Audit_Entry $entry History entry.
	 */
	private function render_event_details( Audit_Entry $entry ): void {
		$context = $entry->context;

		if ( Audit_Log::ORDER_ATTRIBUTED === $entry->event && isset( $context['order_id'] ) ) {
			$order = wc_get_order( (int) $context['order_id'] );
			if ( $order instanceof \WC_Order ) {
				printf( '<a href="%1$s">#%2$s</a>', esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ) );
				return;
			}
		}

		if ( 'rep.reassigned' === $entry->event ) {
			echo esc_html(
				sprintf(
					/* translators: 1: previous rep, 2: new rep. */
					__( '%1$s to %2$s', 'gated-access-attribution' ),
					$this->rep_label( (string) ( $context['from'] ?? '' ) ),
					$this->rep_label( (string) ( $context['to'] ?? '' ) )
				)
			);
			return;
		}

		if ( 'rep.assigned' === $entry->event ) {
			echo esc_html( $this->rep_label( (string) ( $context['rep_code'] ?? '' ) ) );
		}
	}

	/**
	 * Rep name and code, or "None" for an empty code.
	 *
	 * @param string $rep_code Rep code, empty when there is none.
	 */
	private function rep_label( string $rep_code ): string {
		if ( '' === $rep_code ) {
			return __( 'None', 'gated-access-attribution' );
		}

		return sprintf( '%s (%s)', $this->rep_codes->name( $rep_code ), $rep_code );
	}

	/**
	 * Human name for an audit event.
	 *
	 * @param string $event Dotted event name.
	 */
	private function event_label( string $event ): string {
		return match ( $event ) {
			'identity.verified'         => __( 'Signed in', 'gated-access-attribution' ),
			'rep.assigned'              => __( 'Rep assigned', 'gated-access-attribution' ),
			'rep.reassigned'            => __( 'Rep changed', 'gated-access-attribution' ),
			Audit_Log::ORDER_ATTRIBUTED => __( 'Order attributed', 'gated-access-attribution' ),
			default                     => $event,
		};
	}

	/**
	 * Human name for whoever caused an audit event.
	 *
	 * @param string $actor Stored actor, such as "user:12".
	 */
	private function actor_label( string $actor ): string {
		if ( str_starts_with( $actor, 'user:' ) ) {
			$user = get_userdata( (int) substr( $actor, 5 ) );
			return $user instanceof \WP_User ? $user->display_name : __( 'Deleted user', 'gated-access-attribution' );
		}

		if ( str_starts_with( $actor, 'webhook:' ) ) {
			/* translators: %s: webhook event ID. */
			return sprintf( __( 'Webhook, event %s', 'gated-access-attribution' ), substr( $actor, 8 ) );
		}

		return match ( $actor ) {
			'buyer'         => __( 'Buyer', 'gated-access-attribution' ),
			'referral-link' => __( 'Referral link', 'gated-access-attribution' ),
			'checkout'      => __( 'Checkout', 'gated-access-attribution' ),
			default         => $actor,
		};
	}

	/**
	 * When the buyer last verified, or "Never".
	 *
	 * @param Identity $identity Identity to describe.
	 */
	private function verified_label( Identity $identity ): string {
		return $identity->is_verified() ? $this->format_time( $identity->verified_at ) : __( 'Never', 'gated-access-attribution' );
	}

	/**
	 * Formats a UTC time in the site's time zone and formats.
	 *
	 * @param \DateTimeImmutable $time Time to format.
	 */
	private function format_time( \DateTimeImmutable $time ): string {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time->getTimestamp() );
	}
}
