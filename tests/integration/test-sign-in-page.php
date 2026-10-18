<?php
/**
 * Tests for the sign-in page.
 *
 * @package GatedAccess
 */

use GatedAccess\Sign_In_Page;

/**
 * Page creation and the uninstall cleanup.
 */
class Test_Sign_In_Page extends WP_UnitTestCase {

	public function test_activation_creates_a_published_page_with_the_shortcode(): void {
		$page = new Sign_In_Page();

		$page->ensure_exists();

		$this->assertGreaterThan( 0, $page->page_id() );
		$this->assertStringContainsString( '[gaa_sign_in]', get_post( $page->page_id() )->post_content );
	}

	public function test_uninstall_cleanup_deletes_the_created_page(): void {
		$page = new Sign_In_Page();
		$page->ensure_exists();
		$created_id = $page->page_id();

		$page->delete_created_pages();

		$this->assertNull( get_post( $created_id ) );
	}

	public function test_uninstall_cleanup_keeps_a_page_the_admin_chose(): void {
		$chosen_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Members',
				'post_content' => '<p>Welcome back.</p>[gaa_sign_in]',
			)
		);
		update_option( Sign_In_Page::OPTION, $chosen_id );

		( new Sign_In_Page() )->delete_created_pages();

		$this->assertNotNull( get_post( $chosen_id ) );
	}
}
