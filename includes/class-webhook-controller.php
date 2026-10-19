<?php
/**
 * Inbound webhook.
 *
 * @package GatedAccess
 */

namespace GatedAccess;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/gated-access/v1/events
 *
 * Lets another system (a CRM, say) move a buyer to another sales rep. Each
 * request is signed with HMAC-SHA256 over the raw body using a shared secret,
 * and each event ID is applied at most once.
 */
final class Webhook_Controller {

	public const ROUTE_NAMESPACE      = 'gated-access/v1';
	public const ROUTE                = '/events';
	public const SECRET_OPTION        = 'gaa_webhook_secret';
	public const MIN_SECRET_LENGTH    = 32;
	public const SIGNATURE_HEADER     = 'X-GAA-Signature';
	public const EVENT_REP_REASSIGNED = 'identity.rep_reassigned';
	public const ACTOR_PREFIX         = 'webhook:';

	/**
	 * Length of the secret generated on activation.
	 */
	public const GENERATED_SECRET_LENGTH = 64;

	/**
	 * Longest event ID that still fits the audit actor "webhook:<id>" (the prefix is 8 characters).
	 */
	public const EVENT_ID_MAX_LENGTH = Audit_Log::ACTOR_MAX_LENGTH - 8;

	/**
	 * Sets up the endpoint.
	 *
	 * @param Identities       $identities Identity repository.
	 * @param Rep_Codes        $rep_codes  Configured reps.
	 * @param Processed_Events $events     Event IDs already applied.
	 * @param Audit_Log        $audit_log  Audit trail.
	 */
	public function __construct(
		private readonly Identities $identities,
		private readonly Rep_Codes $rep_codes,
		private readonly Processed_Events $events,
		private readonly Audit_Log $audit_log,
	) {
	}

	/**
	 * Registers the route when the REST API starts.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers POST /gated-access/v1/events.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'verify_signature' ),
				'args'                => array(
					'event_id' => array(
						'description' => __( 'Sender-assigned ID. An ID already processed is acknowledged and ignored.', 'gated-access-attribution' ),
						'type'        => 'string',
						'pattern'     => sprintf( '^[A-Za-z0-9._:-]{1,%d}$', self::EVENT_ID_MAX_LENGTH ),
						'required'    => true,
					),
					'type'     => array(
						'type'     => 'string',
						'enum'     => array( self::EVENT_REP_REASSIGNED ),
						'required' => true,
					),
					'data'     => array(
						'type'       => 'object',
						'required'   => true,
						'properties' => array(
							'email'    => array(
								'type'     => 'string',
								'format'   => 'email',
								'required' => true,
							),
							'rep_code' => array(
								'type'     => 'string',
								'required' => true,
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Lets the request through only with a valid signature over its exact body.
	 *
	 * Fails closed: with no secret, or one shorter than MIN_SECRET_LENGTH,
	 * every request is refused.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return true|\WP_Error
	 */
	public function verify_signature( \WP_REST_Request $request ) {
		$secret    = (string) get_option( self::SECRET_OPTION, '' );
		$signature = (string) $request->get_header( self::SIGNATURE_HEADER );
		$expected  = 'sha256=' . hash_hmac( 'sha256', $request->get_body(), $secret );

		if ( strlen( $secret ) >= self::MIN_SECRET_LENGTH && hash_equals( $expected, $signature ) ) {
			return true;
		}

		wc_get_logger()->warning(
			'Rejected a webhook request: missing or wrong signature, or no webhook secret configured.',
			array( 'source' => 'gated-access-attribution' )
		);

		return new \WP_Error(
			'gaa_invalid_signature',
			__( 'Invalid or missing signature.', 'gated-access-attribution' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Applies the event once.
	 *
	 * The event ID is claimed before anything changes, so a second delivery
	 * of the same event, even one arriving at the same moment, does nothing.
	 * An event refused for a business reason releases its claim, so the sender
	 * can deliver it again once the reason is gone.
	 *
	 * Reads only the JSON body, which is what the signature covers. WordPress
	 * would otherwise fill missing parameters from the query string, and a
	 * request that is not JSON gets all of them from there, so one captured
	 * signed body could carry any event_id, email and rep in its URL.
	 *
	 * @param \WP_REST_Request $request Signed, schema-validated request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$event = $request->get_json_params();

		if ( ! is_array( $event ) || ! isset( $event['event_id'], $event['type'], $event['data'] ) || ! is_array( $event['data'] ) ) {
			return new \WP_Error(
				'gaa_invalid_body',
				__( 'The event must be a JSON body with event_id, type and data.', 'gated-access-attribution' ),
				array( 'status' => 400 )
			);
		}

		$event_id = (string) $event['event_id'];

		try {
			$claimed = $this->events->claim( $event_id, (string) $event['type'] );
		} catch ( Storage_Error $error ) {
			return $this->storage_unavailable();
		}

		if ( ! $claimed ) {
			return $this->acknowledge( $event_id, 'duplicate' );
		}

		$outcome = $this->reassign_rep( $event_id, $event['data'] );

		if ( is_wp_error( $outcome ) ) {
			$this->events->release( $event_id );
			return $outcome;
		}

		return $this->acknowledge( $event_id, $outcome );
	}

	/**
	 * Moves the identity named in the event to the rep named in the event.
	 *
	 * @param string               $event_id   Event ID, recorded as the actor.
	 * @param array<string, mixed> $rep_change The event's data object: email and rep_code.
	 * @return string|\WP_Error "processed", "unchanged", or why the event was refused.
	 */
	private function reassign_rep( string $event_id, array $rep_change ) {
		try {
			$email = Email_Address::parse( (string) $rep_change['email'] );
		} catch ( \InvalidArgumentException $error ) {
			return new \WP_Error( 'gaa_invalid_email', __( 'The email address is not valid.', 'gated-access-attribution' ), array( 'status' => 400 ) );
		}

		$identity = $this->identities->find_by_email( $email );
		if ( null === $identity ) {
			return new \WP_Error( 'gaa_unknown_identity', __( 'No identity has that email address.', 'gated-access-attribution' ), array( 'status' => 404 ) );
		}

		$rep_code = $this->rep_codes->find( (string) $rep_change['rep_code'] );
		if ( null === $rep_code ) {
			return new \WP_Error( 'gaa_unknown_rep', __( 'That rep code is not configured.', 'gated-access-attribution' ), array( 'status' => 422 ) );
		}

		if ( $identity->rep_code === $rep_code ) {
			return 'unchanged';
		}

		// Audit first: if the entry cannot be written, the rep is untouched and the retry starts clean.
		try {
			$this->audit_log->record(
				$identity->id,
				'rep.reassigned',
				self::ACTOR_PREFIX . $event_id,
				array(
					'from' => $identity->rep_code,
					'to'   => $rep_code,
				)
			);
		} catch ( Storage_Error $error ) {
			return $this->storage_unavailable();
		}

		$this->identities->assign_rep( $identity->id, $rep_code );

		return 'processed';
	}

	/**
	 * 503 for a database failure: the sender retries instead of treating the event as handled.
	 */
	private function storage_unavailable(): \WP_Error {
		return new \WP_Error(
			'gaa_storage_unavailable',
			__( 'The event could not be stored. Try again later.', 'gated-access-attribution' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Success response naming what happened to the event.
	 *
	 * @param string $event_id Event ID.
	 * @param string $status   "processed", "unchanged" or "duplicate".
	 */
	private function acknowledge( string $event_id, string $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'event_id' => $event_id,
				'status'   => $status,
			),
			200
		);
	}
}
