<?php
/**
 * Request checks for the sign-in form.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and vets what the browser sent with a form post: where it came from,
 * which IP it came over, and where it asks to be sent back to.
 */
final class Request_Guard {

	/**
	 * Rejects form posts whose Origin header names another site.
	 *
	 * A second line of defence next to the nonce, which is shared by every
	 * logged-out visitor. Browsers that omit Origin fall back to the nonce alone.
	 */
	public function is_same_origin(): bool {
		if ( ! isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			return true;
		}

		$origin = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) );
		$home   = wp_parse_url( home_url() );

		return is_array( $origin ) && isset( $origin['host'], $home['host'] )
			&& strtolower( $origin['host'] ) === strtolower( $home['host'] )
			&& ( $origin['port'] ?? null ) === ( $home['port'] ?? null );
	}

	/**
	 * Client IP for per-IP rate limits.
	 */
	public function client_ip(): string {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the IP address used for per-IP rate limits.
		 *
		 * Only REMOTE_ADDR is trusted by default, because X-Forwarded-For and
		 * similar headers can be set by anyone. A site behind a reverse proxy it
		 * controls should return the forwarded client address here.
		 *
		 * @param string $remote_addr The connecting address.
		 */
		$client_ip = (string) apply_filters( 'gaa_client_ip', $remote_addr );

		return false === filter_var( $client_ip, FILTER_VALIDATE_IP ) ? '0.0.0.0' : $client_ip;
	}

	/**
	 * Same-site URL the visitor asked to return to, or an empty string.
	 */
	public function requested_redirect(): string {
		// phpcs:disable WordPress.Security.NonceVerification -- Read-only, and validated to a same-site URL before use.
		if ( isset( $_POST['redirect_to'] ) ) {
			$raw = esc_url_raw( wp_unslash( $_POST['redirect_to'] ) );
		} elseif ( isset( $_GET['redirect_to'] ) ) {
			$raw = esc_url_raw( wp_unslash( $_GET['redirect_to'] ) );
		} else {
			$raw = '';
		}
		// phpcs:enable

		return (string) wp_validate_redirect( $raw, '' );
	}
}
