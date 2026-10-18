<?php
/**
 * WooCommerce settings tab.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Adds WooCommerce > Settings > Gated access.
 *
 * Saving goes through WooCommerce's settings API, which checks the
 * "woocommerce-settings" nonce and the manage_woocommerce capability.
 */
final class Settings {

	public const TAB = 'gated_access';

	/**
	 * Sets up the tab.
	 *
	 * @param Rep_Codes $rep_codes Configured reps.
	 */
	public function __construct( private readonly Rep_Codes $rep_codes ) {
	}

	/**
	 * Registers the tab and its sanitisers.
	 */
	public function register_hooks(): void {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_tab' ), 50 );
		add_action( 'woocommerce_settings_' . self::TAB, array( $this, 'render' ) );
		add_action( 'woocommerce_update_options_' . self::TAB, array( $this, 'save' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Rep_Codes::OPTION, array( $this, 'sanitize_rep_codes' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Webhook_Controller::SECRET_OPTION, array( $this, 'sanitize_webhook_secret' ) );
	}

	/**
	 * Adds the tab to WooCommerce's list.
	 *
	 * @param array<string, string> $tabs Tab labels keyed by ID.
	 * @return array<string, string>
	 */
	public function add_tab( $tabs ): array {
		$tabs              = is_array( $tabs ) ? $tabs : array();
		$tabs[ self::TAB ] = __( 'Gated access', 'gated-access-attribution' );

		return $tabs;
	}

	/**
	 * Prints the fields.
	 */
	public function render(): void {
		woocommerce_admin_fields( $this->fields() );
	}

	/**
	 * Saves the fields.
	 */
	public function save(): void {
		woocommerce_update_options( $this->fields() );
	}

	/**
	 * Keeps valid rep lines in canonical form and reports the rest.
	 *
	 * @param mixed $value Textarea content after WooCommerce's own sanitising.
	 */
	public function sanitize_rep_codes( $value ): string {
		$parsed = $this->rep_codes->parse( is_string( $value ) ? $value : '' );

		if ( array() !== $parsed['rejected'] && class_exists( \WC_Admin_Settings::class ) ) {
			\WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: comma-separated list of rejected lines. */
					__( 'These rep lines were not saved: %s. A code may use a-z, 0-9, hyphen and underscore, up to 32 characters.', 'gated-access-attribution' ),
					implode( ', ', $parsed['rejected'] )
				)
			);
		}

		return $this->rep_codes->format( $parsed['reps'] );
	}

	/**
	 * Keeps the old secret when the new one is too short to be worth anything.
	 *
	 * @param mixed $value Field content after WooCommerce's own sanitising.
	 */
	public function sanitize_webhook_secret( $value ): string {
		$secret = trim( is_string( $value ) ? $value : '' );

		if ( strlen( $secret ) >= Webhook_Controller::MIN_SECRET_LENGTH ) {
			return $secret;
		}

		if ( class_exists( \WC_Admin_Settings::class ) ) {
			\WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %d: minimum number of characters. */
					__( 'The webhook secret was not changed: it must be at least %d characters.', 'gated-access-attribution' ),
					Webhook_Controller::MIN_SECRET_LENGTH
				)
			);
		}

		return (string) get_option( Webhook_Controller::SECRET_OPTION, '' );
	}

	/**
	 * Field definitions in WooCommerce settings API format.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fields(): array {
		return array(
			array(
				'title' => __( 'Gated access', 'gated-access-attribution' ),
				'type'  => 'title',
				'id'    => 'gaa_settings',
			),
			array(
				'title'    => __( 'Sign-in page', 'gated-access-attribution' ),
				'desc'     => __( 'Page containing the [gaa_sign_in] shortcode. Visitors who have not signed in are sent here.', 'gated-access-attribution' ),
				'id'       => Sign_In_Page::OPTION,
				'type'     => 'single_select_page',
				'class'    => 'wc-enhanced-select-nostd',
				'desc_tip' => true,
			),
			array(
				'title'   => __( 'Sales reps', 'gated-access-attribution' ),
				'desc'    => sprintf(
					/* translators: %s: example referral URL. */
					__( 'One rep per line: the code, a colon, then the name, for example "anna: Anna Kowalska". That rep\'s referral link is %s', 'gated-access-attribution' ),
					esc_url( add_query_arg( Referral::QUERY_ARG, 'anna', home_url( '/' ) ) )
				),
				'id'      => Rep_Codes::OPTION,
				'type'    => 'textarea',
				'css'     => 'min-height: 10em; max-width: 40em;',
				'default' => '',
			),
			array(
				'title'             => __( 'Webhook secret', 'gated-access-attribution' ),
				'desc'              => sprintf(
					/* translators: %s: webhook URL. */
					__( 'Shared with the system that posts events to %s. It signs each request body with HMAC-SHA256 using this value. At least 32 characters. Changing it stops the old value working at once.', 'gated-access-attribution' ),
					esc_url( rest_url( Webhook_Controller::ROUTE_NAMESPACE . Webhook_Controller::ROUTE ) )
				),
				'id'                => Webhook_Controller::SECRET_OPTION,
				'type'              => 'text',
				'autoload'          => false,
				'css'               => 'max-width: 40em;',
				'custom_attributes' => array(
					'autocomplete' => 'off',
					'spellcheck'   => 'false',
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'gaa_settings',
			),
		);
	}
}
