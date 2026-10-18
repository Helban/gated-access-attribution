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
	 * Sign-in form and shortcode.
	 *
	 * @var Sign_In_Form
	 */
	private Sign_In_Form $sign_in_form;

	/**
	 * Builds the services.
	 */
	public function __construct() {
		$hmac       = new Hmac();
		$identities = new Identities();

		$this->schema       = new Schema();
		$this->otp          = new Otp_Service( $hmac );
		$this->sign_in_form = new Sign_In_Form(
			$this->otp,
			$identities,
			new Session( $identities, $hmac ),
			new Sign_In_Page()
		);
	}

	/**
	 * Registers every hook the plugin needs at runtime.
	 */
	public function register_hooks(): void {
		// Covers updates that replace the files without re-running activation.
		add_action( 'init', array( $this->schema, 'maybe_upgrade' ), 1 );
		add_action( 'init', array( $this, 'schedule_pruning' ) );
		add_action( self::PRUNE_HOOK, array( $this->otp, 'prune' ) );

		$this->sign_in_form->register_hooks();
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
