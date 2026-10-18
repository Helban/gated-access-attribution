<?php
/**
 * Class autoloader for the GatedAccess namespace.
 *
 * Maps GatedAccess\Sub\Class_Name to includes/sub/class-class-name.php so the
 * plugin runs straight from a checkout, without a Composer build step.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$segments   = explode( '\\', strtolower( substr( $class_name, strlen( $prefix ) ) ) );
		$file_stem  = str_replace( '_', '-', array_pop( $segments ) );
		$class_path = __DIR__ . '/' . implode( '/', array_merge( $segments, array( "class-{$file_stem}.php" ) ) );

		if ( is_readable( $class_path ) ) {
			require_once $class_path;
		}
	}
);
