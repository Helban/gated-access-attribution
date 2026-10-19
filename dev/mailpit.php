<?php
/**
 * Development only: delivers all WordPress mail to a local Mailpit container.
 *
 * .wp-env.json mounts this file into wp-content/mu-plugins. It is not part of
 * the plugin. In the PHPUnit run, WordPress swaps in a mock mailer that never
 * connects, so the SMTP settings below have no effect there.
 *
 * @package GatedAccess
 */

add_action(
	'phpmailer_init',
	static function ( PHPMailer\PHPMailer\PHPMailer $phpmailer ): void {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property names.
		$phpmailer->isSMTP();
		$phpmailer->Host        = 'host.docker.internal';
		$phpmailer->Port        = 1025;
		$phpmailer->SMTPAuth    = false;
		$phpmailer->SMTPAutoTLS = false;
		// phpcs:enable
	}
);

// wp-env serves the site from localhost, and is_email() rejects the default sender wordpress@localhost.
add_filter(
	'wp_mail_from',
	static fn (): string => 'store@example.test'
);

// Without this the sender shows up as "WordPress" in the mailbox.
add_filter(
	'wp_mail_from_name',
	static fn (): string => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
);
