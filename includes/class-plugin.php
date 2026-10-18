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

	/**
	 * Custom tables.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Builds the services.
	 */
	public function __construct() {
		global $wpdb;

		$this->schema = new Schema( $wpdb );
	}

	/**
	 * Registers every hook the plugin needs at runtime.
	 */
	public function register_hooks(): void {
		// Covers updates that replace the files without re-running activation.
		add_action( 'init', array( $this->schema, 'maybe_upgrade' ), 1 );
	}
}
