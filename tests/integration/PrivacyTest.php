<?php
/**
 * Privacy exporter and eraser.
 *
 * Two behaviours here are regression locks rather than feature tests:
 *
 *   - a trashed inquiry is still personal data, and `'any'` does not include trash
 *   - both callbacks paginate, because core keeps calling them until `done` is true
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Integration;

use Ifi\Tests\IfiTestCase;
use Ifi\Tests\Scratch;
use IFI_Privacy;

/**
 * Covers IFI_Privacy::export() and ::erase().
 */
final class PrivacyTest extends IfiTestCase {

	/**
	 * Create one inquiry and return its post id.
	 *
	 * @param string $email Visitor address.
	 * @return int
	 */
	private function seed( string $email = 'subject@example.com' ): int {
		$this->submit(
			array(
				'email'           => $email,
				'fullName'        => 'Data Subject',
				'message'         => 'Please delete my data.',
				'browserLanguage' => 'en-US',
				'timezone'        => 'Asia/Shanghai',
				'formVariant'     => 'page',
			)
		);

		$post = $this->latest_inquiry();
		$this->assertNotNull( $post );

		return (int) $post->ID;
	}

	public function test_export_returns_one_item_per_inquiry(): void {
		$this->seed();

		$result = IFI_Privacy::export( 'subject@example.com' );

		$this->assertArrayHasKey( 'data', $result );
		$this->assertCount( 1, $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_export_uses_a_stable_group_and_item_id(): void {
		$id = $this->seed();

		$item = IFI_Privacy::export( 'subject@example.com' )['data'][0];

		$this->assertSame( 'ifi_inquiry', $item['group_id'] );
		$this->assertSame( 'ifi_inquiry_' . $id, $item['item_id'] );
	}

	public function test_export_includes_the_email_and_the_submitted_values(): void {
		$this->seed();

		$rows  = IFI_Privacy::export( 'subject@example.com' )['data'][0]['data'];
		$pairs = array();
		foreach ( $rows as $row ) {
			$pairs[ $row['name'] ] = $row['value'];
		}

		$this->assertSame( 'subject@example.com', $pairs['Email'] );
		$this->assertArrayHasKey( 'Inquiry number', $pairs );
		// The submitted form values are exported with their labels, not as a list of names.
		$this->assertArrayHasKey( 'fullName', $pairs );
		$this->assertSame( 'Data Subject', $pairs['fullName'] );
	}

	public function test_export_includes_the_ip_and_language(): void {
		$this->seed();

		$names = array_column( IFI_Privacy::export( 'subject@example.com' )['data'][0]['data'], 'name' );

		$this->assertContains( 'IP address', $names );
		$this->assertContains( 'Browser language', $names );
		$this->assertContains( 'Timezone', $names );
		$this->assertContains( 'Form type', $names );
	}

	public function test_export_finds_a_trashed_inquiry(): void {
		$id = $this->seed();
		wp_trash_post( $id );

		$result = IFI_Privacy::export( 'subject@example.com' );

		// Regression lock: `post_status => 'any'` silently excluded trash, so a data subject
		// whose record had been thrown away got nothing back.
		$this->assertCount( 1, $result['data'] );
	}

	public function test_export_returns_nothing_for_an_unknown_address(): void {
		$this->seed();

		$result = IFI_Privacy::export( 'someone-else@example.com' );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_export_pages_through_results(): void {
		$this->seed();

		$second = IFI_Privacy::export( 'subject@example.com', 2 );

		// A full page means "maybe more"; here the first page was short, so the second is
		// empty and done stays true.
		$this->assertSame( array(), $second['data'] );
		$this->assertTrue( $second['done'] );
	}

	public function test_erase_deletes_the_inquiry(): void {
		$id = $this->seed();

		$result = IFI_Privacy::erase( 'subject@example.com' );

		$this->assertSame( 1, $result['items_removed'] );
		$this->assertSame( 0, $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertNull( get_post( $id ) );
	}

	public function test_erase_deletes_a_trashed_inquiry(): void {
		$id = $this->seed();
		wp_trash_post( $id );

		$result = IFI_Privacy::erase( 'subject@example.com' );

		$this->assertSame( 1, $result['items_removed'] );
		$this->assertNull( get_post( $id, OBJECT, 'edit' ) );
	}

	public function test_erase_reports_nothing_removed_for_an_unknown_address(): void {
		$this->seed();

		$result = IFI_Privacy::erase( 'someone-else@example.com' );

		$this->assertSame( 0, $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_erase_removes_the_archived_attachments(): void {
		$id = $this->seed();

		// Simulate an archived file the way persist_attachments() stores it.
		$dir = Scratch::uploads_base() . '/ifi-inquiries/202601/' . $id . '-test';
		wp_mkdir_p( $dir );
		$path = $dir . '/drawing.pdf';
		file_put_contents( $path, '%PDF-1.4 test' );

		update_post_meta(
			$id,
			'ifi_inquiry_attachments',
			array(
				array(
					'name' => 'drawing.pdf',
					'path' => wp_normalize_path( $path ),
					'url'  => '/media/ifi-inquiries/202601/' . $id . '-test/drawing.pdf',
					'size' => 13,
				),
			)
		);

		IFI_Privacy::erase( 'subject@example.com' );

		$this->assertFileDoesNotExist( $path );
	}

	public function test_erase_ignores_the_page_argument_on_purpose(): void {
		// Rows are deleted as they are read, so re-reading page one walks the remaining set.
		// Asking for page three must still remove what is left.
		$this->seed();

		$result = IFI_Privacy::erase( 'subject@example.com', 3 );

		$this->assertSame( 1, $result['items_removed'] );
	}

	public function test_the_exporter_and_eraser_are_registered(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'inquiry-form-inbox', $exporters );
		$this->assertArrayHasKey( 'inquiry-form-inbox', $erasers );
		$this->assertIsCallable( $exporters['inquiry-form-inbox']['callback'] );
		$this->assertIsCallable( $erasers['inquiry-form-inbox']['callback'] );
	}
}
