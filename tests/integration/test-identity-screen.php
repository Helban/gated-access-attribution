<?php
/**
 * Tests for the Buyer identities admin screen.
 *
 * @package GatedAccess
 */

use GatedAccess\Admin\Identity_Screen;
use GatedAccess\Audit_Log;
use GatedAccess\Email_Address;
use GatedAccess\Identities;
use GatedAccess\Order_Attribution;
use GatedAccess\Rep_Codes;
use GatedAccess\Schema;

/**
 * Lookup, history, and reassignment with its nonce and capability checks.
 */
class Test_Identity_Screen extends WP_UnitTestCase {

	use GAA_Test_Helpers;

	/**
	 * Screen under test.
	 *
	 * @var Identity_Screen
	 */
	private Identity_Screen $screen;

	/**
	 * Identity repository.
	 *
	 * @var Identities
	 */
	private Identities $identities;

	/**
	 * Audit trail.
	 *
	 * @var Audit_Log
	 */
	private Audit_Log $audit_log;

	/**
	 * Administrator running the screen.
	 *
	 * @var int
	 */
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		$this->configure_reps();

		$this->identities = new Identities();
		$this->audit_log  = new Audit_Log();
		$this->screen     = new Identity_Screen( $this->identities, new Rep_Codes(), $this->audit_log );
		$this->admin_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['submenu']['woocommerce'] );

		parent::tear_down();
	}

	public function test_menu_entry_is_added_for_store_staff(): void {
		$this->screen->register_menu();

		$this->assertContains( Identity_Screen::PAGE_SLUG, wp_list_pluck( $GLOBALS['submenu']['woocommerce'], 2 ) );
	}

	public function test_menu_entry_is_not_added_for_other_users(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->screen->register_menu();

		$this->assertNotContains( Identity_Screen::PAGE_SLUG, wp_list_pluck( $GLOBALS['submenu']['woocommerce'] ?? array(), 2 ) );
	}

	public function test_lookup_shows_the_rep_the_orders_and_the_history(): void {
		$identity = $this->buyer_with_rep( 'anna' );
		$order    = wc_create_order();
		$order->update_meta_data( Order_Attribution::IDENTITY_META, (string) $identity->id );
		$order->update_meta_data( Order_Attribution::REP_META, 'anna' );
		$order->save();
		$this->audit_log->record_verification( $identity );
		$this->audit_log->record( $identity->id, Audit_Log::ORDER_ATTRIBUTED, 'checkout', array( 'order_id' => $order->get_id() ) );

		$html = $this->render_with_query( array( 'email' => 'Buyer@Example.com' ) );

		$this->assertStringContainsString( '<h2>buyer@example.com</h2>', $html );
		$this->assertStringContainsString( 'Anna Kowalska (anna)', $html );
		$this->assertStringContainsString( '#' . $order->get_order_number(), $html );
		$this->assertStringContainsString( 'Signed in', $html );
		$this->assertStringContainsString( 'Order attributed', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}

	public function test_lookup_of_an_unknown_address_says_so(): void {
		$html = $this->render_with_query( array( 'email' => 'nobody@example.com' ) );

		$this->assertStringContainsString( 'No identity has that email address.', $html );
	}

	public function test_without_a_search_the_screen_lists_recent_buyers(): void {
		$this->buyer_with_rep( 'anna' );

		$html = $this->render_with_query( array() );

		$this->assertStringContainsString( 'Recently verified', $html );
		$this->assertStringContainsString( 'buyer@example.com', $html );
	}

	public function test_reassigning_moves_the_buyer_and_records_who_did_it(): void {
		$identity = $this->buyer_with_rep( 'anna' );

		$redirect = $this->post_reassignment( $identity->id, 'ben', wp_create_nonce( Identity_Screen::REASSIGN_ACTION . '_' . $identity->id ) );

		$this->assertStringContainsString( 'gaa_notice=reassigned', $redirect->location );
		$this->assertSame( 'ben', $this->identities->find( $identity->id )->rep_code );

		$entry = $this->audit_log->for_identity( $identity->id )[0];
		$this->assertSame( 'rep.reassigned', $entry->event );
		$this->assertSame( 'user:' . $this->admin_id, $entry->actor );
		$this->assertSame(
			array(
				'from' => 'anna',
				'to'   => 'ben',
			),
			$entry->context
		);
	}

	public function test_reassignment_without_a_valid_nonce_is_refused(): void {
		$identity = $this->buyer_with_rep( 'anna' );

		$this->assert_reassignment_refused( $identity->id, 'forged' );

		$this->assertSame( 'anna', $this->identities->find( $identity->id )->rep_code );
	}

	public function test_nonce_for_another_identity_is_refused(): void {
		$identity = $this->buyer_with_rep( 'anna' );
		$other    = $this->identities->record_verification( Email_Address::parse( 'other@example.com' ) );

		$this->assert_reassignment_refused( $identity->id, wp_create_nonce( Identity_Screen::REASSIGN_ACTION . '_' . $other->id ) );

		$this->assertSame( 'anna', $this->identities->find( $identity->id )->rep_code );
	}

	public function test_reassignment_by_a_user_without_the_capability_is_refused(): void {
		$identity = $this->buyer_with_rep( 'anna' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assert_reassignment_refused( $identity->id, wp_create_nonce( Identity_Screen::REASSIGN_ACTION . '_' . $identity->id ) );

		$this->assertSame( 'anna', $this->identities->find( $identity->id )->rep_code );
	}

	public function test_reassignment_to_an_unknown_rep_changes_nothing(): void {
		$identity = $this->buyer_with_rep( 'anna' );

		$redirect = $this->post_reassignment( $identity->id, 'carl', wp_create_nonce( Identity_Screen::REASSIGN_ACTION . '_' . $identity->id ) );

		$this->assertStringContainsString( 'gaa_notice=unknown_rep', $redirect->location );
		$this->assertSame( 'anna', $this->identities->find( $identity->id )->rep_code );
		$this->assertSame( array(), $this->audit_log->for_identity( $identity->id ) );
	}

	public function test_notice_text_only_comes_from_known_keys(): void {
		$html = $this->render_with_query( array( 'gaa_notice' => '<script>alert(1)</script>' ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'class="notice', $html );
	}

	public function test_history_escapes_stored_values(): void {
		global $wpdb;

		$identity = $this->buyer_with_rep( 'anna' );
		$wpdb->insert(
			Schema::table( Schema::AUDIT_LOG ),
			array(
				'identity_id' => $identity->id,
				'event'       => 'rep.assigned',
				'actor'       => '<b>actor</b>',
				'context'     => wp_json_encode( array( 'rep_code' => '<img src=x onerror=alert(1)>' ) ),
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$html = $this->render_with_query( array( 'email' => 'buyer@example.com' ) );

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<b>actor</b>', $html );
		$this->assertStringContainsString( '&lt;img src=x', $html );
	}

	/**
	 * Creates the test buyer with a rep.
	 *
	 * @param string $rep_code Rep code.
	 */
	private function buyer_with_rep( string $rep_code ): GatedAccess\Identity {
		$identity = $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) );
		$this->identities->assign_rep( $identity->id, $rep_code );

		return $this->identities->find( $identity->id );
	}

	/**
	 * Renders the screen for a GET request with the given query.
	 *
	 * @param array<string, string> $query Query arguments.
	 */
	private function render_with_query( array $query ): string {
		$_GET = $query;

		ob_start();
		$this->screen->render();

		return (string) ob_get_clean();
	}

	/**
	 * Submits the reassignment form and returns where it redirects.
	 *
	 * @param int    $identity_id Identity to move.
	 * @param string $rep_code    Posted rep code.
	 * @param string $nonce       Posted nonce.
	 */
	private function post_reassignment( int $identity_id, string $rep_code, string $nonce ): GAA_Redirect_Intercepted {
		$_POST    = array(
			'identity_id' => (string) $identity_id,
			'rep_code'    => $rep_code,
			'_wpnonce'    => $nonce,
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Builds the request check_admin_referer() reads.
		$this->intercept_redirects();

		try {
			$this->screen->handle_reassign();
		} catch ( GAA_Redirect_Intercepted $redirect ) {
			return $redirect;
		}

		$this->fail( 'The reassignment did not redirect.' );
	}

	/**
	 * Asserts that the reassignment handler stops with wp_die().
	 *
	 * @param int    $identity_id Identity to move.
	 * @param string $nonce       Posted nonce.
	 */
	private function assert_reassignment_refused( int $identity_id, string $nonce ): void {
		try {
			$this->post_reassignment( $identity_id, 'ben', $nonce );
		} catch ( WPDieException $refused ) {
			$this->addToAssertionCount( 1 );
			return;
		}

		$this->fail( 'The reassignment was not refused.' );
	}
}
