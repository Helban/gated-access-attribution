<?php
/**
 * Exception used to stop a request at its redirect.
 *
 * @package GatedAccess
 */

/**
 * Thrown from the wp_redirect filter so the code under test never reaches exit.
 */
class GAA_Redirect_Intercepted extends Exception {

	/**
	 * Builds the exception.
	 *
	 * @param string $location Redirect target.
	 * @param int    $status   HTTP status.
	 */
	public function __construct( public readonly string $location, public readonly int $status ) {
		parent::__construct( "Redirect to {$location} ({$status})" );
	}
}
