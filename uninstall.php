<?php
/**
 * Removes the plugin's tables and options when it is deleted from the Plugins screen.
 *
 * Attribution already written to orders stays: it is part of the store's sales
 * records, not plugin state.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/autoload.php';

( new Schema() )->drop_tables();

// Without the plugin, the page it created would only show a raw shortcode.
( new Sign_In_Page() )->delete_created_pages();

foreach ( array( Schema::VERSION_OPTION, Sign_In_Page::OPTION ) as $gaa_option ) {
	delete_option( $gaa_option );
}

wp_clear_scheduled_hook( Plugin::PRUNE_HOOK );
