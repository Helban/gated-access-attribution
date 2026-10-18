<?php
/**
 * Tests for the inbound webhook.
 *
 * @package GatedAccess
 */

use GatedAccess\Email_Address;
use GatedAccess\Identities;
use GatedAccess\Rep_Codes;
use GatedAccess\Settings;
use GatedAccess\Webhook_Controller;

/**
 * Signature checks, idempotency and the rep reassignment itself.
 */
class Test_Webhook extends WP_UnitTestCase {

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
		update_option( Rep_Codes::OPTION, "anna: Anna Kowalska\nben: Ben Ortiz" );

		$this->identities  = new Identities();
		$this->identity_id = $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) )->id;
		$this->identities->assign_rep( $this->identity_id, 'anna' );
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh routes per test, as WordPress's REST test cases do.
		parent::tear_down();
	}

	public function test_signed_event_moves_the_buyer_to_the_new_rep(): void {
		$response = $this->post_event( $this->reassign_event( 'evt_1', 'ben' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'processed', $response->get_data()['status'] );
		$this->assertSame( 'ben', $this->current_rep() );
	}

	public function test_same_event_twice_changes_nothing_the_second_time(): void {
		$event = $this->reassign_event( 'evt_1', 'ben' );
		$this->post_event( $event );
		// Someone moves the buyer back by hand; a replay of the old event must not undo that.
		$this->identities->assign_rep( $this->identity_id, 'anna' );

		$response = $this->post_event( $event );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'duplicate', $response->get_data()['status'] );
		$this->assertSame( 'anna', $this->current_rep() );
		$this->assertSame( 1, $this->audit_entries( 'rep.reassigned' ) );
	}

	public function test_a_new_event_id_with_the_same_payload_is_applied(): void {
		$this->post_event( $this->reassign_event( 'evt_1', 'ben' ) );
		$this->identities->assign_rep( $this->identity_id, 'anna' );

		$response = $this->post_event( $this->reassign_event( 'evt_2', 'ben' ) );

		$this->assertSame( 'processed', $response->get_data()['status'] );
		$this->assertSame( 'ben', $this->current_rep() );
	}

	public function test_reassignment_is_written_to_the_audit_log(): void {
		global $wpdb;

		$this->post_event( $this->reassign_event( 'evt_1', 'ben' ) );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT actor, context FROM %i WHERE identity_id = %d AND event = 'rep.reassigned'", $wpdb->prefix . 'gaa_audit_log', $this->identity_id )
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
		$response = $this->post_event( $this->reassign_event( 'evt_1', 'ben' ), null );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'anna', $this->current_rep() );
	}

	public function test_request_signed_with_another_secret_is_refused(): void {
		$body = $this->reassign_event( 'evt_1', 'ben' );

		$response = $this->post_event( $body, $this->sign( $body, 'some-other-secret-0123456789abcdef0123' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'anna', $this->current_rep() );
	}

	public function test_body_changed_after_signing_is_refused(): void {
		$signature = $this->sign( $this->reassign_event( 'evt_1', 'ben' ), self::SECRET );

		$response = $this->post_event( $this->reassign_event( 'evt_1', 'anna' ), $signature );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_every_request_is_refused_while_no_secret_is_configured(): void {
		update_option( Webhook_Controller::SECRET_OPTION, '' );
		$body = $this->reassign_event( 'evt_1', 'ben' );

		$response = $this->post_event( $body, $this->sign( $body, '' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'anna', $this->current_rep() );
	}

	public function test_a_secret_shorter_than_the_minimum_is_not_accepted(): void {
		update_option( Webhook_Controller::SECRET_OPTION, 'short' );
		$body = $this->reassign_event( 'evt_1', 'ben' );

		$response = $this->post_event( $body, $this->sign( $body, 'short' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_unknown_buyer_is_refused_and_the_event_can_be_retried(): void {
		$event = $this->reassign_event( 'evt_1', 'ben', 'newcomer@example.com' );

		$first = $this->post_event( $event );
		$this->identities->record_verification( Email_Address::parse( 'newcomer@example.com' ) );
		$retry = $this->post_event( $event );

		$this->assertSame( 404, $first->get_status() );
		$this->assertSame( 'processed', $retry->get_data()['status'] );
	}

	public function test_unknown_rep_is_refused(): void {
		$response = $this->post_event( $this->reassign_event( 'evt_1', 'carl' ) );

		$this->assertSame( 422, $response->get_status() );
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

		$response = $this->post_event( $body );

		$this->assertSame( 400, $response->get_status() );
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

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
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

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
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
	 * Posts a body to the webhook, signed with the configured secret unless told otherwise.
	 *
	 * @param string      $body      Exact request body.
	 * @param string|null $signature Signature header; null sends none; omitted signs correctly.
	 */
	private function post_event( string $body, ?string $signature = 'sign' ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/gated-access/v1/events' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );

		if ( null !== $signature ) {
			$request->set_header( Webhook_Controller::SIGNATURE_HEADER, 'sign' === $signature ? $this->sign( $body, self::SECRET ) : $signature );
		}

		return rest_do_request( $request );
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
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE identity_id = %d AND event = %s', $wpdb->prefix . 'gaa_audit_log', $this->identity_id, $event )
		);
	}
}
