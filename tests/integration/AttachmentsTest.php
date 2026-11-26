<?php
/**
 * Attachments: validation order, archiving, and cleanup on delete.
 *
 * The one thing that cannot be tested here is a real upload: move_uploaded_file() only works
 * for a genuine upload request, so stage_attachments() always fails at that step in-process.
 * Those cases live in tests/e2e/, which posts over HTTP. What is covered here is everything
 * around it — which input is rejected before a single byte is moved, and what happens to the
 * file afterwards.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Integration;

use Ifi\Tests\IfiTestCase;
use Ifi\Tests\Reflect;
use Ifi\Tests\Scratch;
use RuntimeException;

/**
 * Covers stage_attachments(), persist_attachments() and purge_record_files().
 */
final class AttachmentsTest extends IfiTestCase {

	/**
	 * Build a $_FILES-shaped array for one group.
	 *
	 * @param string $group  files|images.
	 * @param string $name   Original file name.
	 * @param string $bytes  File contents.
	 * @return array
	 */
	private function files( string $group, string $name, string $bytes = '%PDF-1.4 test' ): array {
		$tmp = tempnam( get_temp_dir(), 'ifi-upload-' );
		file_put_contents( $tmp, $bytes );

		return array(
			$group => array(
				'name'     => array( $name ),
				'tmp_name' => array( $tmp ),
				'size'     => array( strlen( $bytes ) ),
				'error'    => array( UPLOAD_ERR_OK ),
			),
		);
	}

	/**
	 * Remove a temp file, ignoring failure.
	 *
	 * @param string $path Path.
	 */
	private function drop( string $path ): void {
		if ( is_file( $path ) ) {
			@unlink( $path );
		}
	}

	public function test_no_files_produces_an_empty_list(): void {
		$this->assertSame( array(), Reflect::call( 'stage_attachments', array( array() ) ) );
	}

	public function test_a_missing_file_entry_is_skipped_rather_than_rejected(): void {
		$files = array(
			'files' => array(
				'name'     => array( '' ),
				'tmp_name' => array( '' ),
				'size'     => array( 0 ),
				'error'    => array( UPLOAD_ERR_NO_FILE ),
			),
		);

		$this->assertSame( array(), Reflect::call( 'stage_attachments', array( $files ) ) );
	}

	public function test_an_oversize_file_is_rejected(): void {
		// 21 MB is over the default 20 MB cap for the files group; the bytes are generated
		// here rather than stored in the repository.
		$files = $this->files( 'files', 'huge.pdf', str_repeat( 'a', 21 * 1024 * 1024 ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'limit' );

		try {
			Reflect::call( 'stage_attachments', array( $files ) );
		} finally {
			$this->drop( $files['files']['tmp_name'][0] );
		}
	}

	public function test_a_declared_size_that_does_not_match_the_file_is_rejected(): void {
		$files = $this->files( 'files', 'liar.pdf' );
		$files['files']['size'][0] = 999999;

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'declared size' );

		try {
			Reflect::call( 'stage_attachments', array( $files ) );
		} finally {
			$this->drop( $files['files']['tmp_name'][0] );
		}
	}

	public function test_more_files_than_the_limit_is_rejected(): void {
		// The cap is reached per-iteration, and every accepted file ends at
		// move_uploaded_file() — which cannot succeed in-process. Setting the cap to 0 makes
		// the branch reachable on the first file, so the rejection itself is still tested
		// here; the multi-file count is covered over HTTP in tests/e2e/.
		add_filter(
			'ifi_max_files',
			static function () {
				return 0;
			}
		);

		$files = $this->files( 'files', 'a.pdf' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Too many files' );

		try {
			Reflect::call( 'stage_attachments', array( $files ) );
		} finally {
			$this->drop( $files['files']['tmp_name'][0] );
			Scratch::clean_staging_dirs();
		}
	}

	public function test_the_file_groups_filter_can_tighten_one_group(): void {
		// 分组上限是配置，不是源码里的死值：站点可用 ifi_file_groups 按字段调整。
		add_filter(
			'ifi_file_groups',
			static function ( $groups ) {
				$groups['files'] = 1;
				return $groups;
			}
		);

		$files = $this->files( 'files', 'two-bytes.pdf', 'ab' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'limit' );

		try {
			Reflect::call( 'stage_attachments', array( $files ) );
		} finally {
			$this->drop( $files['files']['tmp_name'][0] );
		}
	}

	public function test_any_extension_reaches_the_upload_step(): void {
		// File types are the frontend's contract now; the server must accept an arbitrary
		// extension and only fail at move_uploaded_file() in this in-process test.
		$files = $this->files( 'files', 'payload.exe' );

		try {
			Reflect::call( 'stage_attachments', array( $files ) );
			$this->fail( 'Expected the in-process upload to fail at move_uploaded_file().' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'stored', $e->getMessage() );
		} finally {
			$this->drop( $files['files']['tmp_name'][0] );
			Scratch::clean_staging_dirs();
		}
	}

	public function test_the_attachment_row_has_no_hard_coded_header(): void {
		// 附件清单行的表头来自字段标签里的 attachments；没配置就不该出现固定文案。
		$fields = array(
			'fullName'    => 'Test Buyer',
			'fieldLabels' => array(),
		);
		$staged = array(
			array(
				'name' => 'a.pdf',
				'size' => 1024,
			),
		);

		$html = (string) Reflect::call( 'build_rows', array( $fields, $staged, array() ) );

		$this->assertStringContainsString( 'a.pdf', $html );
		$this->assertStringNotContainsString( 'Attachments', $html );
		$this->assertStringContainsString( 'colspan="2"', $html );
	}

	public function test_the_attachment_row_uses_the_attachments_label(): void {
		$fields = array(
			'fullName'    => 'Test Buyer',
			'fieldLabels' => array(),
		);
		$staged = array(
			array(
				'name' => 'a.pdf',
				'size' => 1024,
			),
		);

		$html = (string) Reflect::call( 'build_rows', array( $fields, $staged, array( 'attachments' => 'Files' ) ) );

		$this->assertStringContainsString( '>Files</th>', $html );
		$this->assertStringNotContainsString( 'colspan="2"', $html );
	}

	public function test_the_attachment_row_falls_back_to_the_upload_field_label(): void {
		$fields = array(
			'fullName'    => 'Test Buyer',
			'fieldLabels' => array(),
		);
		$staged = array(
			array(
				'name' => 'a.pdf',
				'size' => 1024,
			),
		);

		$html = (string) Reflect::call( 'build_rows', array( $fields, $staged, array( 'files' => 'Drawings' ) ) );

		$this->assertStringContainsString( '>Drawings</th>', $html );
		$this->assertStringNotContainsString( 'colspan="2"', $html );
	}

	public function test_persisting_moves_the_file_into_the_record_directory(): void {
		$post_id = (int) wp_insert_post(
			array(
				'post_type'   => 'ifi_inquiry',
				'post_status' => 'publish',
				'post_title'  => 'Attachment fixture',
			)
		);

		$source = tempnam( get_temp_dir(), 'ifi-stage-' );
		file_put_contents( $source, '%PDF-1.4 archived' );
		$staged = array(
			array(
				'name'     => 'drawing.pdf',
				'tmp_path' => wp_normalize_path( $source ),
				'size'     => 17,
			),
		);

		$stored = Reflect::call( 'persist_attachments', array( $staged, $post_id ) );

		$this->assertCount( 1, $stored );
		$this->assertSame( 'drawing.pdf', $stored[0]['name'] );
		$this->assertSame( 17, $stored[0]['size'] );
		$this->assertFileExists( $stored[0]['path'] );
		$this->assertStringContainsString( 'ifi-inquiries', $stored[0]['path'] );
		$this->assertStringContainsString( '/media/ifi-inquiries/', $stored[0]['url'] );
		$this->assertFileDoesNotExist( $source );
		$this->assertFileExists( Scratch::uploads_base() . '/ifi-inquiries/index.php' );
	}

	public function test_the_stored_url_is_relative_so_both_hosts_can_serve_it(): void {
		$post_id = (int) wp_insert_post(
			array(
				'post_type'   => 'ifi_inquiry',
				'post_status' => 'publish',
				'post_title'  => 'Attachment fixture',
			)
		);

		$source = tempnam( get_temp_dir(), 'ifi-stage-' );
		file_put_contents( $source, 'x' );
		$stored = Reflect::call(
			'persist_attachments',
			array(
				array(
					array(
						'name'     => 'a.pdf',
						'tmp_path' => wp_normalize_path( $source ),
						'size'     => 1,
					),
				),
				$post_id,
			)
		);

		$this->assertStringStartsWith( '/', $stored[0]['url'] );
	}

	public function test_deleting_a_record_purges_its_files(): void {
		$post_id = (int) wp_insert_post(
			array(
				'post_type'   => 'ifi_inquiry',
				'post_status' => 'publish',
				'post_title'  => 'Attachment fixture',
			)
		);

		$dir = Scratch::uploads_base() . '/ifi-inquiries/202601/' . $post_id . '-abc';
		wp_mkdir_p( $dir );
		$path = $dir . '/a.pdf';
		file_put_contents( $path, 'x' );

		update_post_meta(
			$post_id,
			'ifi_inquiry_attachments',
			array(
				array(
					'name' => 'a.pdf',
					'path' => wp_normalize_path( $path ),
					'url'  => '/media/ifi-inquiries/202601/' . $post_id . '-abc/a.pdf',
					'size' => 1,
				),
			)
		);

		wp_delete_post( $post_id, true );

		$this->assertFileDoesNotExist( $path );
		$this->assertDirectoryDoesNotExist( $dir );
		// 记录本身也要干净：文章行没了，meta 也跟着没了
		$this->assertNull( get_post( $post_id ) );
		$this->assertSame( array(), get_post_meta( $post_id, 'ifi_inquiry_attachments', false ) );
	}

	/**
	 * 归档失败退回暂存目录的附件也要清掉。
	 *
	 * persist_attachments() 在 move_file() 失败时把 path 留在 get_temp_dir()/ifi-stage-*；
	 * 这些文件不在 uploads 下，早期实现只认 uploads 前缀，永久删除记录后文件一直留在磁盘上。
	 */
	public function test_deleting_a_record_purges_files_left_in_the_stage_dir(): void {
		$post_id = (int) wp_insert_post(
			array(
				'post_type'   => 'ifi_inquiry',
				'post_status' => 'publish',
				'post_title'  => 'Staged attachment fixture',
			)
		);

		$stage = wp_normalize_path( get_temp_dir() ) . 'ifi-stage-test-' . $post_id;
		wp_mkdir_p( $stage );
		$path = $stage . '/b.pdf';
		file_put_contents( $path, 'x' );

		update_post_meta(
			$post_id,
			'ifi_inquiry_attachments',
			array(
				array(
					'name' => 'b.pdf',
					'path' => wp_normalize_path( $path ),
					'url'  => '',
					'size' => 1,
				),
			)
		);

		wp_delete_post( $post_id, true );

		$this->assertFileDoesNotExist( $path );
		if ( is_dir( $stage ) ) {
			@rmdir( $stage );
		}
	}

	public function test_purging_ignores_a_file_outside_the_attachment_root(): void {
		$post_id = (int) wp_insert_post(
			array(
				'post_type'   => 'ifi_inquiry',
				'post_status' => 'publish',
				'post_title'  => 'Attachment fixture',
			)
		);
		update_option( 'unrelated_setting', 'x', false );

		$outside = Scratch::uploads_base() . '/not-ours/keep.txt';
		wp_mkdir_p( dirname( $outside ) );
		file_put_contents( $outside, 'keep' );

		update_post_meta(
			$post_id,
			'ifi_inquiry_attachments',
			array(
				array(
					'name' => 'keep.txt',
					'path' => wp_normalize_path( $outside ),
					'url'  => '',
					'size' => 4,
				),
			)
		);

		wp_delete_post( $post_id, true );

		// A path outside uploads/ifi-inquiries/ must never be deleted: a corrupted meta row
		// should not be able to remove arbitrary files.
		$this->assertFileExists( $outside );
	}

	public function test_purging_refuses_a_traversal_path_that_starts_inside_the_attachment_root(): void {
		$post_id = (int) wp_insert_post(
			array(
				'post_type'   => 'ifi_inquiry',
				'post_status' => 'publish',
				'post_title'  => 'Attachment fixture',
			)
		);

		$base = Scratch::uploads_base();
		wp_mkdir_p( $base . '/ifi-inquiries' );

		$outside = $base . '/secret.txt';
		file_put_contents( $outside, 'keep' );

		update_post_meta(
			$post_id,
			'ifi_inquiry_attachments',
			array(
				array(
					'name' => 'secret.txt',
					// Starts with the storage prefix but resolves outside it.
					'path' => wp_normalize_path( $base . '/ifi-inquiries/../secret.txt' ),
					'url'  => '',
					'size' => 4,
				),
			)
		);

		wp_delete_post( $post_id, true );

		$this->assertFileExists( $outside );
		$this->drop( $outside );
	}

	public function test_the_cleanup_keeps_files_that_are_still_referenced(): void {
		$keep   = tempnam( get_temp_dir(), 'ifi-keep-' );
		$remove = tempnam( get_temp_dir(), 'ifi-drop-' );
		file_put_contents( $keep, 'keep' );
		file_put_contents( $remove, 'drop' );

		// The stage directory is per-process and per-submission; mirror what handle() does
		// by pointing cleanup at the real staging dir.
		$stage = Reflect::call( 'stage_dir' );
		wp_mkdir_p( $stage );
		$in_stage_keep = $stage . '/keep.pdf';
		$in_stage_drop = $stage . '/drop.pdf';
		copy( $keep, $in_stage_keep );
		copy( $remove, $in_stage_drop );

		Reflect::call( 'cleanup_stage', array( array( wp_normalize_path( $in_stage_keep ) ) ) );

		$this->assertFileExists( $in_stage_keep );
		$this->assertFileDoesNotExist( $in_stage_drop );

		$this->drop( $keep );
		$this->drop( $remove );
		Scratch::clean_staging_dirs();
	}
}
