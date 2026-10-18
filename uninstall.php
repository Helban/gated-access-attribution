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

( new Schema( $GLOBALS['wpdb'] ) )->drop_tables();

foreach ( array( Schema::VERSION_OPTION ) as $gaa_option ) {
	delete_option( $gaa_option );
}
