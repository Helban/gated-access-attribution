<?php
/**
 * Sales rep codes.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * The configured sales reps, kept as one "code: Name" line each in an option.
 *
 * Only codes listed here are ever captured from a link or written to an
 * identity, so a made-up ?ref value is ignored.
 */
final class Rep_Codes {

	public const OPTION = 'gaa_rep_codes';

	/**
	 * Lower-case letters, digits, hyphen and underscore, up to 32 characters.
	 */
	private const PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

	/**
	 * Every configured rep, keyed by code.
	 *
	 * @return array<string, string> Display name per code; the code itself when no name was given.
	 */
	public function all(): array {
		return $this->parse( (string) get_option( self::OPTION, '' ) )['reps'];
	}

	/**
	 * Normalises a code and returns it if it is configured.
	 *
	 * @param string $code Code as typed or received.
	 */
	public function find( string $code ): ?string {
		$code = strtolower( trim( $code ) );

		return array_key_exists( $code, $this->all() ) ? $code : null;
	}

	/**
	 * Display name for a code, falling back to the code for reps removed since.
	 *
	 * @param string $code Rep code.
	 */
	public function name( string $code ): string {
		return $this->all()[ $code ] ?? $code;
	}

	/**
	 * Splits textarea input into valid reps and rejected lines.
	 *
	 * @param string $text One rep per line, "code" or "code: Name".
	 * @return array{reps: array<string, string>, rejected: string[]}
	 */
	public function parse( string $text ): array {
		$reps     = array();
		$rejected = array();

		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			list( $code, $name ) = array_map( 'trim', array_pad( explode( ':', $line, 2 ), 2, '' ) );
			$code                = strtolower( $code );

			if ( ! preg_match( self::PATTERN, $code ) ) {
				$rejected[] = $line;
				continue;
			}

			$name          = sanitize_text_field( $name );
			$reps[ $code ] = '' === $name ? $code : $name;
		}

		return array(
			'reps'     => $reps,
			'rejected' => $rejected,
		);
	}

	/**
	 * Renders reps back into the stored "code: Name" text.
	 *
	 * @param array<string, string> $reps Display name per code.
	 */
	public function format( array $reps ): string {
		$lines = array();

		foreach ( $reps as $code => $name ) {
			$lines[] = $code === $name ? $code : "{$code}: {$name}";
		}

		return implode( "\n", $lines );
	}
}
