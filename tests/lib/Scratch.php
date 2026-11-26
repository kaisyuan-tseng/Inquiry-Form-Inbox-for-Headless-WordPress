<?php
/**
 * Scratch-schema helpers: guards, teardown and the upload sandbox.
 *
 * Everything here exists to make one promise: a test can write whatever it likes and the
 * next run starts from the same clean state, without ever touching the real site's rows or
 * its uploads directory.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests;

use RuntimeException;
use WP_Post;

/**
 * State management for the integration suite.
 */
final class Scratch {

	/** Set once the upload_dir filter is attached. */
	private static bool $upload_filter_attached = false;

	/**
	 * Fail loudly if the suite is not pointed at the scratch schema.
	 *
	 * @throws RuntimeException When the prefix is wrong.
	 */
	public static function assert_schema(): void {
		global $wpdb;

		if ( 'wptests_' !== $wpdb->prefix ) {
			throw new RuntimeException(
				sprintf( 'Refusing to run: table prefix is "%s", expected "wptests_".', $wpdb->prefix )
			);
		}
		if ( ! is_blog_installed() ) {
			throw new RuntimeException( 'The scratch schema is missing. Run: php tests/bin/install-wp.php' );
		}
	}

	/**
	 * Absolute path of the sandboxed uploads directory.
	 *
	 * @return string
	 */
	public static function uploads_base(): string {
		return dirname( __DIR__ ) . '/runtime/uploads';
	}

	/**
	 * Public base URL that the sandboxed uploads are served under.
	 *
	 * @return string
	 */
	public static function uploads_url(): string {
		return 'http://127.0.0.1:8099/media';
	}

	/**
	 * Point wp_upload_dir() at the sandbox.
	 *
	 * Attached once per process. The plugin caches the resolved upload directory in a
	 * static, so this has to be in place before the first call — attaching it from the base
	 * class setUp() guarantees that.
	 */
	public static function attach_upload_filter(): void {
		if ( self::$upload_filter_attached ) {
			return;
		}
		self::$upload_filter_attached = true;

		add_filter(
			'upload_dir',
			static function ( array $dirs ): array {
				$base = self::uploads_base();
				$sub  = (string) ( $dirs['subdir'] ?? '' );

				$dirs['basedir'] = $base;
				$dirs['baseurl'] = self::uploads_url();
				$dirs['path']    = $base . $sub;
				$dirs['url']     = self::uploads_url() . $sub;

				return $dirs;
			},
			10,
			1
		);
	}

	/**
	 * Assert that the sandbox is where WordPress thinks uploads live.
	 *
	 * @throws RuntimeException When the filter is not in effect.
	 */
	public static function assert_upload_sandbox(): void {
		$dirs = wp_upload_dir();
		$base = wp_normalize_path( $dirs['basedir'] );
		$want = wp_normalize_path( self::uploads_base() );

		if ( 0 !== strpos( $base, $want ) ) {
			throw new RuntimeException(
				sprintf( 'Uploads are not sandboxed: %s is outside %s.', $base, $want )
			);
		}
	}

	/**
	 * Return every inquiry record in the scratch schema.
	 *
	 * @return WP_Post[]
	 */
	public static function inquiries(): array {
		return get_posts(
			array(
				'post_type'        => 'ifi_inquiry',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Delete every inquiry record, plus every option, transient and file the plugin wrote.
	 *
	 * Idempotent: calling it twice in a row leaves the same state.
	 */
	public static function reset_state(): void {
		global $wpdb;

		// Records first: deleting a post triggers before_delete_post, which is what removes
		// the attachment directory for that record.
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'ifi_inquiry' )
		);
		foreach ( (array) $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}

		// Options and transients. esc_like keeps the underscore literal.
		$patterns = array(
			$wpdb->esc_like( 'ifi_' ) . '%',
			$wpdb->esc_like( '_transient_ifi_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ifi_' ) . '%',
		);
		foreach ( $patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- scratch-schema teardown.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
		}

		// The plugin also writes one numbered option per day: ifi_seq_YYYYMMDD.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- scratch-schema teardown.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'ifi_seq_' ) . '%' ) );

		wp_cache_flush();

		self::rmdir( self::uploads_base() );
		self::clean_staging_dirs();

		// Plugin-level caches are per process, not per request.
		\Inquiry_Form_Inbox::flush_template_cache();
		\Inquiry_Form_Inbox::flush_string_cache();
	}

	/**
	 * Remove the staging directories a submission creates under the system temp dir.
	 */
	public static function clean_staging_dirs(): void {
		$temp = rtrim( wp_normalize_path( get_temp_dir() ), '/' );
		foreach ( (array) glob( $temp . '/ifi-stage-*' ) as $dir ) {
			self::rmdir( $dir );
		}
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Absolute path.
	 */
	public static function rmdir( string $dir ): void {
		$dir = wp_normalize_path( $dir );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}
		@rmdir( $dir );
	}
}
