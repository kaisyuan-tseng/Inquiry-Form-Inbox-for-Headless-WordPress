<?php
/**
 * Capabilities: the meta-cap mapping and the grant-once behaviour.
 *
 * Two things are worth locking down here. The meta caps decide whether an editor can read
 * an inquiry at all, and the grant path used to write `user_roles` on every single admin
 * request — a cost that only shows up on a busy site, and only after the fact.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Integration;

use Ifi\Tests\IfiTestCase;
use Ifi\Tests\Scratch;
use IFI_Migration;

/**
 * Covers map_meta_cap() and init_capabilities().
 */
final class CapabilityTest extends IfiTestCase {

	/**
	 * Create an inquiry record directly, without going through the endpoint.
	 *
	 * @param string $post_type Post type to create.
	 * @return int
	 */
	private function make_post( string $post_type = 'ifi_inquiry' ): int {
		return (int) wp_insert_post(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_title'  => 'Capability fixture',
			)
		);
	}

	public function test_the_view_meta_cap_maps_to_the_view_capability(): void {
		$id = $this->make_post();

		$caps = apply_filters( 'map_meta_cap', array(), 'ifi_view_inquiry', $this->admin_id, array( $id ) );

		$this->assertSame( array( 'ifi_view_inquiries' ), $caps );
	}

	public function test_the_edit_meta_cap_maps_to_the_edit_capability(): void {
		$id = $this->make_post();

		$caps = apply_filters( 'map_meta_cap', array(), 'ifi_edit_inquiry', $this->admin_id, array( $id ) );

		$this->assertSame( array( 'ifi_edit_inquiries' ), $caps );
	}

	public function test_the_delete_meta_cap_maps_to_the_delete_capability(): void {
		$id = $this->make_post();

		$caps = apply_filters( 'map_meta_cap', array(), 'ifi_delete_inquiry', $this->admin_id, array( $id ) );

		$this->assertSame( array( 'ifi_delete_inquiries' ), $caps );
	}

	public function test_a_meta_cap_on_another_post_type_is_denied(): void {
		$id = $this->make_post( 'post' );

		$caps = apply_filters( 'map_meta_cap', array(), 'ifi_view_inquiry', $this->admin_id, array( $id ) );

		$this->assertSame( array( 'do_not_allow' ), $caps );
	}

	public function test_a_meta_cap_without_a_post_id_is_denied(): void {
		$caps = apply_filters( 'map_meta_cap', array(), 'ifi_view_inquiry', $this->admin_id, array() );

		$this->assertSame( array( 'do_not_allow' ), $caps );
	}

	public function test_unrelated_capabilities_pass_through_untouched(): void {
		$caps = apply_filters( 'map_meta_cap', array( 'manage_options' ), 'manage_options', $this->admin_id, array() );

		$this->assertSame( array( 'manage_options' ), $caps );
	}

	public function test_the_administrator_holds_all_three_capabilities(): void {
		$role = get_role( 'administrator' );

		$this->assertTrue( $role->has_cap( 'ifi_view_inquiries' ) );
		$this->assertTrue( $role->has_cap( 'ifi_edit_inquiries' ) );
		$this->assertTrue( $role->has_cap( 'ifi_delete_inquiries' ) );
	}

	public function test_the_editor_holds_all_three_capabilities(): void {
		$role = get_role( 'editor' );

		$this->assertTrue( $role->has_cap( 'ifi_view_inquiries' ) );
		$this->assertTrue( $role->has_cap( 'ifi_edit_inquiries' ) );
		$this->assertTrue( $role->has_cap( 'ifi_delete_inquiries' ) );
	}

	public function test_a_subscriber_holds_none_of_the_capabilities(): void {
		$role = get_role( 'subscriber' );

		$this->assertFalse( $role->has_cap( 'ifi_view_inquiries' ) );
		$this->assertFalse( $role->has_cap( 'ifi_edit_inquiries' ) );
		$this->assertFalse( $role->has_cap( 'ifi_delete_inquiries' ) );
	}

	public function test_granting_capabilities_writes_nothing_when_they_are_already_present(): void {
		// Regression lock: WP_Role::add_cap() calls update_option() unconditionally, so an
		// ungated grant put six update_option() calls on every admin request.
		$writes = 0;
		$option = $GLOBALS['wpdb']->prefix . 'user_roles';
		add_filter(
			'pre_update_option_' . $option,
			static function ( $value ) use ( &$writes ) {
				$writes++;
				return $value;
			}
		);

		IFI_Migration::init_capabilities();
		IFI_Migration::init_capabilities();

		$this->assertSame( 0, $writes );
	}

	public function test_granting_capabilities_still_repairs_a_missing_one(): void {
		$role = get_role( 'editor' );
		$role->remove_cap( 'ifi_delete_inquiries' );

		IFI_Migration::init_capabilities();

		$this->assertTrue( get_role( 'editor' )->has_cap( 'ifi_delete_inquiries' ) );
	}

	public function test_an_administrator_can_edit_and_delete_an_inquiry(): void {
		$id = $this->make_post();
		wp_set_current_user( $this->admin_id );

		$this->assertTrue( current_user_can( 'ifi_view_inquiry', $id ) );
		$this->assertTrue( current_user_can( 'ifi_edit_inquiry', $id ) );
		$this->assertTrue( current_user_can( 'ifi_delete_inquiry', $id ) );
	}

	public function test_a_subscriber_cannot_view_an_inquiry(): void {
		$id   = $this->make_post();
		$user = get_user_by( 'login', 'ifi_subscriber' );
		if ( ! $user ) {
			$created = wp_insert_user(
				array(
					'user_login' => 'ifi_subscriber',
					'user_pass'  => 'ifi-test-password',
					'user_email' => 'ifi_subscriber@example.test',
					'role'       => 'subscriber',
				)
			);
			$this->assertIsInt( $created );
			$user = get_user_by( 'id', $created );
		}

		wp_set_current_user( (int) $user->ID );

		$this->assertFalse( current_user_can( 'ifi_view_inquiry', $id ) );
		$this->assertFalse( current_user_can( 'ifi_edit_inquiry', $id ) );
		$this->assertFalse( current_user_can( 'ifi_delete_inquiry', $id ) );
	}

	public function test_the_capability_is_not_granted_for_an_ordinary_post(): void {
		$id = $this->make_post( 'post' );
		wp_set_current_user( $this->admin_id );

		// Even an administrator must not pass the inquiry meta cap on a regular post: the
		// mapping returns do_not_allow, which nobody holds.
		$this->assertFalse( current_user_can( 'ifi_delete_inquiry', $id ) );
	}

	public function test_the_scratch_schema_is_still_the_one_under_test(): void {
		// Cheap guard against a misconfigured run reaching the real site's tables.
		$this->assertSame( 'wptests_', $GLOBALS['wpdb']->prefix );
		$this->assertStringContainsString( '127.0.0.1:8099', home_url() );
	}
}
