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
	 * Registers every hook the plugin needs at runtime.
	 */
	public function register_hooks(): void {
	}
}
