<?php
/**
 * Checkout email prefill.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Offers the verified address as the billing email while the customer has none.
 *
 * Both checkouts read the billing email from the WooCommerce customer: the
 * block checkout through the Store API cart response, the shortcode checkout
 * through WC_Checkout::get_value(). Filtering the getter covers both without
 * writing into the customer session, so signing out leaves nothing behind. An
 * address the buyer typed is never replaced.
 */
final class Checkout_Prefill {

	/**
	 * Sets up the prefill.
	 *
	 * @param Session $session Signed-in session.
	 */
	public function __construct( private readonly Session $session ) {
	}

	/**
	 * Hooks into the customer's billing email getter.
	 */
	public function register_hooks(): void {
		add_filter( 'woocommerce_customer_get_billing_email', array( $this, 'default_billing_email' ), 10, 2 );
	}

	/**
	 * Returns the verified address when the customer's billing email is empty.
	 *
	 * @param mixed        $billing_email Billing email as stored.
	 * @param \WC_Customer $customer      Customer being read.
	 * @return mixed
	 */
	public function default_billing_email( $billing_email, $customer ) {
		if ( '' !== $billing_email || ! $this->is_checkout_customer( $customer ) ) {
			return $billing_email;
		}

		return $this->session->current_identity()?->email ?? $billing_email;
	}

	/**
	 * Whether this is the customer the current visitor is checking out as.
	 *
	 * Other customer objects, such as one an admin edits, must not pick up the
	 * visitor's address. A guest session and a guest-shaped lookup are both ID 0.
	 *
	 * @param mixed $customer Customer being read.
	 */
	private function is_checkout_customer( $customer ): bool {
		return $customer instanceof \WC_Customer
			&& WC()->customer instanceof \WC_Customer
			&& $customer->get_id() === WC()->customer->get_id();
	}
}
