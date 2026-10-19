<?php
/**
 * Database write failure.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * A write that must not be lost failed in the database.
 *
 * Callers in request handlers turn it into an error response, so the sender
 * or the user can try again, instead of reading the failure as "nothing to do".
 */
final class Storage_Error extends \RuntimeException {
}
