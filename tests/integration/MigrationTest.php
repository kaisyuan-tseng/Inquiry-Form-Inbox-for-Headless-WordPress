<?php
/**
 * Migration from the 2.x (`eln_`) and 1.x (`inquiry`) identifiers.
 *
 * `maybe_migrate()` only runs inside wp-admin, so the individual steps are called directly
 * here. That is deliberate: the gate itself is one `is_admin()` check, and the risk lives in
 * the steps — renaming 20 meta keys, folding three sequence prefixes into one, and moving
 * an attachment directory without losing files.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Integration;

use Ifi\Tests\IfiTestCase;
use Ifi\Tests\Reflect;
use IFI_Migration;

/**
 * Covers the private migration steps in IFI_Migration.
 */
final class MigrationTest extends IfiTestCase {

	/**
	 * Create a record under a legacy post type.
	 *
	 * @param string $post_type Legacy post type.
	 * @param array  $meta      Meta to attach, keyed by legacy key.
	 * @return int
	 */
	private function seed_legacy( string $post_type, array $meta = array() ): int {
		$id = (int) wp_insert_post(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_title'  => 'Legacy inquiry',
				'post_content' => 'Legacy body',
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		wp_cache_flush();

		return $id;
	}

	public function test_the_legacy_post_type_is_renamed(): void {
		$id = $this->seed_legacy( 'eln_inquiry' );

		$this->assertTrue( Reflect::call( 'migrate_post_type', array(), IFI_Migration::class ) );
		wp_cache_flush();

		$this->assertSame( 'ifi_inquiry', get_post_type( $id ) );
	}

	public function test_the_first_generation_post_type_is_renamed(): void {
		$id = $this->seed_legacy( 'inquiry' );

		$this->assertTrue( Reflect::call( 'migrate_post_type', array(), IFI_Migration::class ) );
		wp_cache_flush();

		$this->assertSame( 'ifi_inquiry', get_post_type( $id ) );
	}

	public function test_legacy_meta_keys_are_renamed(): void {
		$id = $this->seed_legacy(
			'eln_inquiry',
			array(
				'eln_inquiry_email'  => 'legacy@example.com',
				'eln_inquiry_lang'   => 'zh-CN',
				'eln_inquiry_contacted' => '1700000000',
			)
		);

		$this->assertTrue( Reflect::call( 'migrate_meta_keys', array(), IFI_Migration::class ) );

		$this->assertSame( 'legacy@example.com', get_post_meta( $id, 'ifi_inquiry_email', true ) );
		$this->assertSame( 'zh-CN', get_post_meta( $id, 'ifi_inquiry_lang', true ) );
		$this->assertSame( '1700000000', get_post_meta( $id, 'ifi_inquiry_contacted', true ) );
		$this->assertSame( '', get_post_meta( $id, 'eln_inquiry_email', true ) );
	}

	public function test_first_generation_meta_keys_are_renamed(): void {
		$id = $this->seed_legacy(
			'inquiry',
			array( 'inquiry_email' => 'ancient@example.com' )
		);

		$this->assertTrue( Reflect::call( 'migrate_meta_keys', array(), IFI_Migration::class ) );

		$this->assertSame( 'ancient@example.com', get_post_meta( $id, 'ifi_inquiry_email', true ) );
	}

	public function test_legacy_options_are_renamed_and_the_old_key_removed(): void {
		update_option( 'eln_template', array( 'subject' => 'Legacy subject' ), false );

		$this->assertTrue( Reflect::call( 'migrate_options', array(), IFI_Migration::class ) );

		$this->assertSame( array( 'subject' => 'Legacy subject' ), get_option( 'ifi_template' ) );
		$this->assertFalse( get_option( 'eln_template' ) );
	}

	public function test_an_existing_new_option_wins_over_the_legacy_one(): void {
		update_option( 'ifi_template', array( 'subject' => 'New subject' ), false );
		update_option( 'eln_template', array( 'subject' => 'Legacy subject' ), false );

		Reflect::call( 'migrate_options', array(), IFI_Migration::class );

		$this->assertSame( array( 'subject' => 'New subject' ), get_option( 'ifi_template' ) );
		$this->assertFalse( get_option( 'eln_template' ) );
	}

	public function test_legacy_sequence_counters_are_folded_into_the_new_prefix(): void {
		update_option( 'eln_seq_20260101', 7, false );
		update_option( 'inquiry_seq_20260102', 3, false );

		$this->assertTrue( Reflect::call( 'migrate_options', array(), IFI_Migration::class ) );

		$this->assertSame( 7, (int) get_option( 'ifi_seq_20260101' ) );
		$this->assertSame( 3, (int) get_option( 'ifi_seq_20260102' ) );
		$this->assertFalse( get_option( 'eln_seq_20260101' ) );
	}

	public function test_legacy_rate_limit_transients_are_removed(): void {
		set_transient( 'eln_rl_min_abc123', array( 'count' => 2 ), 3600 );

		Reflect::call( 'migrate_options', array(), IFI_Migration::class );

		// The migration deletes the rows directly, which is correct for a one-shot cleanup;
		// the object cache still holds the value until the request ends, so drop it before
		// asserting, exactly as the next request would see it.
		wp_cache_flush();

		$this->assertFalse( get_transient( 'eln_rl_min_abc123' ) );
	}

	public function test_legacy_capabilities_are_removed_from_roles(): void {
		$role = get_role( 'administrator' );
		$role->add_cap( 'eln_view_inquiries' );

		$this->assertTrue( Reflect::call( 'migrate_capabilities', array(), IFI_Migration::class ) );

		$this->assertFalse( get_role( 'administrator' )->has_cap( 'eln_view_inquiries' ) );
	}

	public function test_running_the_steps_twice_is_harmless(): void {
		$id = $this->seed_legacy(
			'eln_inquiry',
			array( 'eln_inquiry_email' => 'legacy@example.com' )
		);
		update_option( 'eln_template', array( 'subject' => 'Legacy subject' ), false );

		Reflect::call( 'migrate_post_type', array(), IFI_Migration::class );
		Reflect::call( 'migrate_meta_keys', array(), IFI_Migration::class );
		Reflect::call( 'migrate_options', array(), IFI_Migration::class );

		$second = array(
			Reflect::call( 'migrate_post_type', array(), IFI_Migration::class ),
			Reflect::call( 'migrate_meta_keys', array(), IFI_Migration::class ),
			Reflect::call( 'migrate_options', array(), IFI_Migration::class ),
		);

		$this->assertSame( array( true, true, true ), $second );
		wp_cache_flush();
		$this->assertSame( 'ifi_inquiry', get_post_type( $id ) );
		$this->assertSame( 'legacy@example.com', get_post_meta( $id, 'ifi_inquiry_email', true ) );
	}

	public function test_the_migration_version_is_not_set_by_the_individual_steps(): void {
		// Only maybe_migrate() records completion, and only after every step reported
		// success — so a partially applied migration is retried on the next admin load.
		Reflect::call( 'migrate_post_type', array(), IFI_Migration::class );

		$this->assertFalse( get_option( 'ifi_db_version' ) );
	}
}
