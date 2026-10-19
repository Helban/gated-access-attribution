<?php
/**
 * Tests for the inbound webhook.
 *
 * @package GatedAccess
 */

use GatedAccess\Email_Address;
use GatedAccess\Identities;
use GatedAccess\Processed_Events;
use GatedAccess\Rep_Codes;
use GatedAccess\Schema;
use GatedAccess\Settings;
use GatedAccess\Storage_Error;
use GatedAccess\Webhook_Controller;

/**
 * Signature checks, idempotency and the rep reassignment itself.
 */
class Test_Webhook extends WP_UnitTestCase {

	use GAA_Test_Helpers;

	private const SECRET = 'test-secret-0123456789abcdef0123456789abcdef';

	/**
	 * Identity repository.
	 *
	 * @var Identities
	 */
	private Identities $identities;

	/**
	 * Buyer the events are about.
	 *
	 * @var int
	 */
	private int $identity_id;

	public function set_up(): void {
		parent::set_up();

		update_option( Webhook_Controller::SECRET_OPTION, self::SECRET );
		$this->configure_reps();

		$this->identities  = new Identities();
		$this->identity_id = $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) )->id;
		$this->identities->assign_rep( $this->identity_id, 'anna' );
	}

	public function tear_down(): void {
		$this->reset_rest_server();
		parent::tear_down();
	}

	public function test_signed_event_moves_the_buyer_to_the_new_rep(): void {
		$reply = $this->post_signed_event( $this->reassign_event( 'evt_1', 'ben' ) );

		$this->assertSame( 200, $reply->get_status() );
		$this->assertSame( 'processed', $reply->get_data()['status'] );
		$this->assertSame( 'ben', $this->current_rep() );
	}

	public function test_same_event_twice_changes_nothing_the_second_time(): void {
		$event = $this->reassign_event( 'evt_1', 'ben' );
		$this->post_signed_event( $event );
		// Someone moves the buyer back by hand; a replay of the old event must not undo that.
		$this->identities->assign_rep( $this->identity_id, 'anna' );

		$reply = $this->post_signed_event( $event );

		$this->assertSame( 200, $reply->get_status() );
		$this->assertSame( 'duplicate', $reply->get_data()['status'] );
		$this->assertSame( 'anna', $this->current_rep() );
		$this->assertSame( 1, $this->audit_entries( 'rep.reassigned' ) );
	}

	public function test_a_database_error_while_claiming_answers_503_so_the_sender_retries(): void {
		global $wpdb;

		$event = $this->reassign_event( 'evt_1', 'ben' );
		$wpdb->suppress_errors( true );
		$break = static fn ( string $sql ): string => str_starts_with( $sql, 'INSERT IGNORE' ) ? 'SELECT * FROM gaa_table_that_does_not_exist' : $sql;
		add_filter( 'query', $break );

		$failed        = $this->post_signed_event( $event );
		$rep_after_503 = $this->current_rep();

		remove_filter( 'query', $break );
		$wpdb->suppress_errors( false );
		$retry = $this->post_signed_event( $event );

		$this->assertSame( 503, $failed->get_status() );
		$this->assertSame( 'anna', $rep_after_503 );
		$this->assertSame( 'processed', $retry->get_data()['status'] );
		$this->assertSame( 'ben', $this->current_rep() );
	}

	public function test_an_event_id_of_the_longest_allowed_length_is_audited_intact(): void {
		global $wpdb;

		$event_id = str_repeat( 'e', Webhook_Controller::EVENT_ID_MAX_LENGTH );

		$reply = $this->post_signed_event( $this->reassign_event( $event_id, 'ben' ) );

		$this->assertSame( 200, $reply->get_status() );
		$this->assertSame(
			'webhook:' . $event_id,
			$wpdb->get_var( $wpdb->prepare( "SELECT actor FROM %i WHERE identity_id = %d AND event = 'rep.reassigned'", Schema::table( Schema::AUDIT_LOG ), $this->identity_id ) )
		);
	}

	public function test_an_event_id_too_long_for_the_audit_actor_is_rejected(): void {
		$reply = $this->post_signed_event( $this->reassign_event( str_repeat( 'e', Webhook_Controller::EVENT_ID_MAX_LENGTH + 1 ), 'ben' ) );

		$this->assertSame( 400, $reply->get_status() );
		$this->assertSame( 'anna', $this->current_rep() );
	}

	public function test_a_failed_audit_write_answers_503_and_leaves_the_rep_alone(): void {
		global $wpdb;

		$event = $this->reassign_event( 'evt_1', 'ben' );
		$wpdb->suppress_errors( true );
		$break = static fn ( string $sql ): string => str_starts_with( $sql, 'INSERT INTO `' . Schema::table( Schema::AUDIT_LOG ) ) ? 'SELECT * FROM gaa_table_that_does_not_exist' : $sql;
		add_filter( 'query', $break );

		$failed        = $this->post_signed_event( $event );
		$rep_after_503 = $this->current_rep();

		remove_filter( 'query', $break );
		$wpdb->suppress_errors( false );
		$retry = $this->post_signed_event( $event );

		$this->assertSame( 503, $failed->get_status() );
		$this->assertSame( 'anna', $rep_after_503 );
		$this->assertSame( 'processed', $retry->get_data()['status'] );
		$this->assertSame( 1, $this->audit_entries( 'rep.reassigned' ) );
	}

	public function test_claiming_raises_when_the_database_fails(): void {
		global $wpdb;

		$wpdb->suppress_errors( true );
		add_filter( 'query', static fn ( string $sql ): string => str_starts_with( $sql, 'INSERT IGNORE' ) ? 'SELECT * FROM gaa_table_that_does_not_exist' : $sql );

		try {
			( new Processed_Events() )->claim( 'evt_1', Webhook_Controller::EVENT_REP_REASSIGNED );
			$this->fail( 'Expected a Storage_Error.' );
		} catch ( Storage_Error $error ) {
			$this->assertInstanceOf( Storage_Error::class, $error );
		} finally {
			$wpdb->suppress_errors( false );
		}
	}

	public function test_a_new_event_id_with_the_same_payload_is_applied(): void {
		$this->post_signed_event( $this->reassign_event( 'evt_1', 'ben' ) );
		$this->identities->assign_rep( $this->identity_id, 'anna' );

		$reply = $this->post_signed_event( $this->reassign_event( 'evt_2', 'ben' ) );

		$this->assertSame( 'processed', $reply->get_data()['status'] );
		$this->assertSame( 'ben', $this->current_rep() );
	}

	public function test_reassignment_is_written_to_the_audit_log(): void {
		global $wpdb;

		$this->post_signed_event( $this->reassign_event( 'evt_1', 'ben' ) );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT actor, context FROM %i WHERE identity_id = %d AND event = 'rep.reassigned'", Schema::table( Schema::AUDIT_LOG ), $this->identity_id )
		);
		$this->assertSame( 'webhook:evt_1', $row->actor );
		$this->assertSame(
			array(
				'from' => 'anna',
				'to'   => 'ben',
			),
			json_decode( $row->context, true )
		);
	}

	public function test_request_without_a_signature_is_refused(): void {
		$reply = $this->post_unsigned_event( $this->reassign_event( 'evt_1', 'ben' ) );

		$this->assertSame( 401, $reply->get_status() );
		$this->assertSame( 'anna', $this->current_rep() );
	}

	public function test_request_signed_with_another_secret_is_refused(): void {
		$body = $this->reassign_event( 'evt_1', 'ben' );

		$reply = $this->post_event_with_signature( $body, $this->sign( $body, 'some-other-secret-0123456789abcdef0123' ) );

		$this->assertSame( 401, $reply->get_status() );
		$this->assertSame( 'anna', $this->current_rep() );
	}

	public function test_body_changed_after_signing_is_refused(): void {
		$signature = $this->sign( $this->reassign_event( 'evt_1', 'ben' ), self::SECRET );

		$reply = $this->post_event_with_signature( $this->reassign_event( 'evt_1', 'anna' ), $signature );

		$this->assertSame( 401, $reply->get_status() );
	}

	public function test_every_request_is_refused_while_no_secret_is_configured(): void {
		update_option( Webhook_Controller::SECRET_OPTION, '' );
		$body = $this->reassign_event( 'evt_1', 'ben' );

		$reply = $this->post_event_with_signature( $body, $this->sign( $body, '' ) );

		$this->assertSame( 401, $reply->get_status() );
		$this->assertSame( 'anna', $this->current_rep() );
	}

	public function test_a_secret_shorter_than_the_minimum_is_not_accepted(): void {
		update_option( Webhook_Controller::SECRET_OPTION, 'short' );
		$body = $this->reassign_event( 'evt_1', 'ben' );

		$reply = $this->post_event_with_signature( $body, $this->sign( $body, 'short' ) );

		$this->assertSame( 401, $reply->get_status() );
	}

	public function test_unknown_buyer_is_refused_and_the_event_can_be_retried(): void {
		$event = $this->reassign_event( 'evt_1', 'ben', 'newcomer@example.com' );

		$first = $this->post_signed_event( $event );
		$this->identities->record_verification( Email_Address::parse( 'newcomer@example.com' ) );
		$retry = $this->post_signed_event( $event );

		$this->assertSame( 404, $first->get_status() );
		$this->assertSame( 'processed', $retry->get_data()['status'] );
	}

	public function test_unknown_rep_is_refused(): void {
		$reply = $this->post_signed_event( $this->reassign_event( 'evt_1', 'carl' ) );

		$this->assertSame( 422, $reply->get_status() );
		$this->assertSame( 'anna', $this->current_rep() );
	}

	public function test_payload_without_an_event_id_is_rejected(): void {
		$body = (string) wp_json_encode(
			array(
				'type' => Webhook_Controller::EVENT_REP_REASSIGNED,
				'data' => array(
					'email'    => 'buyer@example.com',
					'rep_code' => 'ben',
				),
			)
		);

		$reply = $this->post_signed_event( $body );

		$this->assertSame( 400, $reply->get_status() );
		$this->assertSame( 'anna', $this->current_rep() );
	}

	public function test_signed_body_cannot_be_reused_with_parameters_in_the_query_string(): void {
		$captured = $this->reassign_event( 'evt_1', 'anna' );
		$request  = new WP_REST_Request( 'POST', '/gated-access/v1/events' );
		$request->set_header( 'Content-Type', 'text/plain' );
		$request->set_header( Webhook_Controller::SIGNATURE_HEADER, $this->sign( $captured, self::SECRET ) );
		$request->set_body( $captured );
		$request->set_query_params(
			array(
				'event_id' => 'evt_forged',
				'type'     => Webhook_Controller::EVENT_REP_REASSIGNED,
				'data'     => array(
					'email'    => 'buyer@example.com',
					'rep_code' => 'ben',
				),
			)
		);

		$reply = rest_do_request( $request );

		$this->assertSame( 400, $reply->get_status() );
		$this->assertSame( 'anna', $this->current_rep() );
	}

	public function test_json_body_missing_a_field_is_not_completed_from_the_query_string(): void {
		$body    = (string) wp_json_encode(
			array(
				'event_id' => 'evt_1',
				'type'     => Webhook_Controller::EVENT_REP_REASSIGNED,
			)
		);
		$request = new WP_REST_Request( 'POST', '/gated-access/v1/events' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( Webhook_Controller::SIGNATURE_HEADER, $this->sign( $body, self::SECRET ) );
		$request->set_body( $body );
		$request->set_query_params(
			array(
				'data' => array(
					'email'    => 'buyer@example.com',
					'rep_code' => 'ben',
				),
			)
		);

		$reply = rest_do_request( $request );

		$this->assertSame( 400, $reply->get_status() );
		$this->assertSame( 'anna', $this->current_rep() );
	}

	public function test_settings_keep_the_old_secret_when_the_new_one_is_too_short(): void {
		include_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';

		$kept = ( new Settings( new Rep_Codes() ) )->sanitize_webhook_secret( 'too-short' );

		$this->assertSame( self::SECRET, $kept );
	}

	/**
	 * JSON body of a rep reassignment event.
	 *
	 * @param string $event_id Event ID.
	 * @param string $rep_code New rep.
	 * @param string $email    Buyer address.
	 */
	private function reassign_event( string $event_id, string $rep_code, string $email = 'buyer@example.com' ): string {
		return (string) wp_json_encode(
			array(
				'event_id' => $event_id,
				'type'     => Webhook_Controller::EVENT_REP_REASSIGNED,
				'data'     => array(
					'email'    => $email,
					'rep_code' => $rep_code,
				),
			)
		);
	}

	/**
	 * Header value a correctly configured sender would send.
	 *
	 * @param string $body   Exact request body.
	 * @param string $secret Shared secret.
	 */
	private function sign( string $body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * Posts a body signed with the configured secret, as a correctly set up sender would.
	 *
	 * @param string $body Exact request body.
	 */
	private function post_signed_event( string $body ): WP_REST_Response {
		return $this->post_event_with_signature( $body, $this->sign( $body, self::SECRET ) );
	}

	/**
	 * Posts a body with no signature header.
	 *
	 * @param string $body Exact request body.
	 */
	private function post_unsigned_event( string $body ): WP_REST_Response {
		return rest_do_request( $this->event_request( $body ) );
	}

	/**
	 * Posts a body with the given signature header value.
	 *
	 * @param string $body      Exact request body.
	 * @param string $signature Header value.
	 */
	private function post_event_with_signature( string $body, string $signature ): WP_REST_Response {
		$request = $this->event_request( $body );
		$request->set_header( Webhook_Controller::SIGNATURE_HEADER, $signature );

		return rest_do_request( $request );
	}

	/**
	 * A JSON POST to the webhook route, without a signature.
	 *
	 * @param string $body Exact request body.
	 */
	private function event_request( string $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/gated-access/v1/events' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );

		return $request;
	}

	/**
	 * Rep currently stored for the test buyer.
	 */
	private function current_rep(): ?string {
		return $this->identities->find( $this->identity_id )->rep_code;
	}

	/**
	 * Number of audit entries of one kind for the test buyer.
	 *
	 * @param string $event Audit event name.
	 */
	private function audit_entries( string $event ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE identity_id = %d AND event = %s', Schema::table( Schema::AUDIT_LOG ), $this->identity_id, $event )
		);
	}
}
