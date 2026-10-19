<?php
/**
 * Order attribution.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the buyer's identity and rep onto each order placed at checkout.
 *
 * The rep comes from the identity row, resolved from the signed session, never
 * from anything the browser sends with the order. Order data goes through the
 * WC_Order CRUD API only, so it lands in the right place with HPOS on or off.
 */
final class Order_Attribution {

	public const REP_META      = '_gaa_rep_code';
	public const IDENTITY_META = '_gaa_identity_id';

	/**
	 * Sets up attribution.
	 *
	 * @param Session   $session   Signed-in session.
	 * @param Rep_Codes $rep_codes Configured reps, for display names.
	 * @param Audit_Log $audit_log Audit trail.
	 */
	public function __construct(
		private readonly Session $session,
		private readonly Rep_Codes $rep_codes,
		private readonly Audit_Log $audit_log,
	) {
	}

	/**
	 * Hooks into both checkouts and the order edit screen.
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'attribute_classic_checkout_order' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'attribute' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_order_details' ) );
	}

	/**
	 * Adapter for the classic (shortcode) checkout hook.
	 *
	 * @param int|string     $order_id    Order ID.
	 * @param array          $posted_data Checkout fields, unused.
	 * @param \WC_Order|null $order       The order, passed since WooCommerce 3.
	 */
	public function attribute_classic_checkout_order( $order_id, $posted_data = array(), $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			$this->attribute( $order );
		}
	}

	/**
	 * Records the signed-in identity and its rep on the order.
	 *
	 * The block checkout can process the same draft order more than once (a
	 * failed payment, then a retry). The first attribution is kept.
	 *
	 * @param \WC_Order $order Order just created by checkout.
	 */
	public function attribute( \WC_Order $order ): void {
		$identity = $this->session->current_identity();

		if ( null === $identity || '' !== (string) $order->get_meta( self::IDENTITY_META ) ) {
			return;
		}

		$order->update_meta_data( self::IDENTITY_META, (string) $identity->id );
		if ( null !== $identity->rep_code ) {
			$order->update_meta_data( self::REP_META, $identity->rep_code );
		}
		$order->save();

		try {
			$this->audit_log->record(
				$identity->id,
				Audit_Log::ORDER_ATTRIBUTED,
				'checkout',
				array(
					'order_id' => $order->get_id(),
					'rep_code' => $identity->rep_code,
				)
			);
		} catch ( Storage_Error $error ) {
			// The order is already placed and carries its rep; the buyer must not see a failed checkout over a log row.
			wc_get_logger()->error(
				sprintf( 'Could not write the audit entry for order %d.', $order->get_id() ),
				array( 'source' => 'gated-access-attribution' )
			);
		}
	}

	/**
	 * Shows the rep on the order edit screen, on both the HPOS and legacy screens.
	 *
	 * @param \WC_Order $order Order being edited.
	 */
	public function render_order_details( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$rep_code = (string) $order->get_meta( self::REP_META );

		if ( '' === $rep_code ) {
			return;
		}

		printf(
			'<p class="form-field form-field-wide"><strong>%1$s</strong><br>%2$s (%3$s)</p>',
			esc_html__( 'Sales rep:', 'gated-access-attribution' ),
			esc_html( $this->rep_codes->name( $rep_code ) ),
			esc_html( $rep_code )
		);
	}
}
