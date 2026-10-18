<?php
/**
 * Composition root.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the plugin's services once and registers their hooks.
 */
final class Plugin {

	public const PRUNE_HOOK = 'gaa_prune_otp_requests';

	/**
	 * Custom tables.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Sign-in codes.
	 *
	 * @var Otp_Service
	 */
	private Otp_Service $otp;

	/**
	 * Audit trail.
	 *
	 * @var Audit_Log
	 */
	private Audit_Log $audit_log;

	/**
	 * Sign-in form and shortcode.
	 *
	 * @var Sign_In_Form
	 */
	private Sign_In_Form $sign_in_form;

	/**
	 * Referral link capture and binding.
	 *
	 * @var Referral
	 */
	private Referral $referral;

	/**
	 * Order attribution at checkout.
	 *
	 * @var Order_Attribution
	 */
	private Order_Attribution $order_attribution;

	/**
	 * WooCommerce settings tab.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Gate on catalog pages, REST, wc-ajax, cart and checkout.
	 *
	 * @var Catalog_Gate
	 */
	private Catalog_Gate $catalog_gate;

	/**
	 * Product hiding outside the gated pages.
	 *
	 * @var Catalog_Visibility
	 */
	private Catalog_Visibility $catalog_visibility;

	/**
	 * Builds the services.
	 */
	public function __construct() {
		$hmac       = new Hmac();
		$cookies    = new Cookies();
		$identities = new Identities();
		$rep_codes  = new Rep_Codes();
		$session    = new Session( $identities, $hmac, $cookies );
		$page       = new Sign_In_Page();
		$access     = new Catalog_Access( $session );

		$this->schema             = new Schema();
		$this->otp                = new Otp_Service( $hmac );
		$this->audit_log          = new Audit_Log();
		$this->sign_in_form       = new Sign_In_Form( $this->otp, $identities, $session, $page );
		$this->referral           = new Referral( $rep_codes, $identities, $this->audit_log, $cookies );
		$this->order_attribution  = new Order_Attribution( $session, $rep_codes, $this->audit_log );
		$this->settings           = new Settings( $rep_codes );
		$this->catalog_gate       = new Catalog_Gate( $access, $page );
		$this->catalog_visibility = new Catalog_Visibility( $access );
	}

	/**
	 * Registers every hook the plugin needs at runtime.
	 */
	public function register_hooks(): void {
		// Covers updates that replace the files without re-running activation.
		add_action( 'init', array( $this->schema, 'maybe_upgrade' ), 1 );
		add_action( 'init', array( $this, 'schedule_pruning' ) );
		add_action( self::PRUNE_HOOK, array( $this->otp, 'prune' ) );
		// Before Referral::bind(), so the history reads "verified" then "rep assigned".
		add_action( 'gaa_identity_verified', array( $this->audit_log, 'record_verification' ), 5 );

		$this->sign_in_form->register_hooks();
		$this->referral->register_hooks();
		$this->order_attribution->register_hooks();
		$this->settings->register_hooks();
		$this->catalog_gate->register_hooks();
		$this->catalog_visibility->register_hooks();
	}

	/**
	 * Schedules the daily cleanup of old code requests if it is missing.
	 */
	public function schedule_pruning(): void {
		if ( false === wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::PRUNE_HOOK );
		}
	}
}
