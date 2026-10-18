<?php
/**
 * Tests for the identity repository.
 *
 * @package GatedAccess
 */

use GatedAccess\Email_Address;
use GatedAccess\Identities;

/**
 * Identity creation on verification.
 */
class Test_Identities extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var Identities
	 */
	private Identities $identities;

	public function set_up(): void {
		parent::set_up();
		$this->identities = new Identities();
	}

	public function test_first_verification_creates_a_verified_identity(): void {
		$identity = $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) );

		$this->assertSame( 'buyer@example.com', $identity->email );
		$this->assertTrue( $identity->is_verified() );
		$this->assertNull( $identity->rep_code );
	}

	public function test_verifying_again_keeps_the_same_identity(): void {
		$first  = $this->identities->record_verification( Email_Address::parse( 'buyer@example.com' ) );
		$second = $this->identities->record_verification( Email_Address::parse( '  Buyer@Example.COM ' ) );

		$this->assertSame( $first->id, $second->id );
	}

	public function test_unknown_address_has_no_identity(): void {
		$this->assertNull( $this->identities->find_by_email( Email_Address::parse( 'nobody@example.com' ) ) );
	}

	public function test_address_longer_than_the_index_allows_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Email_Address::parse( str_repeat( 'a', 180 ) . '@example.com' );
	}
}
