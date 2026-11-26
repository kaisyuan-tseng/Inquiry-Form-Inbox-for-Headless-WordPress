<?php
/**
 * Uninstall cleanup for Inquiry Form Inbox.
 *
 * Multisite: WordPress calls uninstall.php once for the network, so the cleanup runs for
 * every site in the network — options, inquiries, uploads and capabilities are per-site.
 *
 * @package Inquiry_Form_Inbox
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove every piece of plugin data for the current site.
 *
 * Uses only literals so uninstall.php keeps working without loading the plugin classes.
 *
 * @return void
 */
function ifi_uninstall_site() {
	global $wpdb;

	$ifi_options = array(
		'ifi_template',
		'ifi_autoreply',
		'ifi_reply',
		'ifi_spam_rules',
	);

	foreach ( $ifi_options as $ifi_option ) {
		delete_option( $ifi_option );
	}

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'ifi_seq_' ) . '%'
		)
	);

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_ifi_rl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ifi_rl_' ) . '%',
			$wpdb->esc_like( '_transient_ifi_field_keys' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ifi_field_keys' ) . '%',
			$wpdb->esc_like( '_transient_ifi_dup_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ifi_dup_' ) . '%',
			$wpdb->esc_like( '_transient_ifi_activation_notice_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ifi_activation_notice_' ) . '%'
		)
	);

	$ifi_post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
			'ifi_inquiry'
		)
	);

	foreach ( (array) $ifi_post_ids as $ifi_post_id ) {
		wp_delete_post( (int) $ifi_post_id, true );
	}

	$ifi_uploads = wp_upload_dir();
	$ifi_base    = wp_normalize_path( $ifi_uploads['basedir'] );

	ifi_uninstall_remove_dir( $ifi_base . '/ifi-inquiries', $ifi_base );

	$ifi_capabilities = array(
		'ifi_view_inquiries',
		'ifi_edit_inquiries',
		'ifi_delete_inquiries',
	);

	foreach ( array( 'administrator', 'editor' ) as $ifi_role_name ) {
		$ifi_role = get_role( $ifi_role_name );
		if ( ! $ifi_role ) {
			continue;
		}
		foreach ( $ifi_capabilities as $ifi_capability ) {
			$ifi_role->remove_cap( $ifi_capability );
		}
	}
}

/**
 * Recursively remove a directory only when it lives inside the uploads base.
 *
 * @param string $dir     Directory to remove.
 * @param string $basedir Uploads base directory.
 * @return void
 */
function ifi_uninstall_remove_dir( $dir, $basedir ) {
	$dir = wp_normalize_path( $dir );
	if ( '' === $dir || strpos( $dir, trailingslashit( $basedir ) ) !== 0 || ! is_dir( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
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

if ( is_multisite() ) {
	$ifi_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $ifi_sites as $ifi_site_id ) {
		switch_to_blog( (int) $ifi_site_id );
		ifi_uninstall_site();
		restore_current_blog();
	}
} else {
	ifi_uninstall_site();
}
