<?php
/**
 * Privacy policy content, personal data exporter and eraser.
 *
 * Two rules drive the shape of this class:
 *
 *   1. A trashed inquiry is still personal data. WordPress's `'any'` post status does not
 *      include trash, so relying on it silently skipped every record an admin had thrown
 *      away — both for export and for erase.
 *   2. Both callbacks are paged. Core calls them repeatedly with an incrementing `$page`
 *      until `done` is true; returning everything at once ties the request to the size of
 *      the inbox and times out on a busy site.
 *
 * The eraser ignores `$page` on purpose: it deletes the rows it just read, so re-querying
 * page 1 each round walks the remaining set without skipping records.
 *
 * @package Inquiry_Form_Inbox
 */

defined( 'ABSPATH' ) || exit;

final class IFI_Privacy {

	/** Records per exporter/eraser round. */
	private const PER_PAGE = 500;

	/** Exporter group id, also used as the item id prefix. */
	private const GROUP = 'ifi_inquiry';

	/**
	 * Post statuses that hold inquiry data.
	 *
	 * `'any'` is deliberately not used: it excludes trash, and a trashed inquiry still
	 * contains the visitor's name, email, message and IP address.
	 */
	private const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );

	/**
	 * Register privacy hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Suggest a privacy policy snippet on the Privacy screen.
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			'Inquiry Form Inbox for Headless WordPress',
			wp_kses_post(
				__( 'This plugin stores inquiry data submitted through the site contact form: name, email, message, form fields, browser language, timezone, IP address, source page, and any uploaded attachments. Records are visible only to authorized administrators and are used to send inquiry notifications and delivery confirmations. Use the WordPress privacy tools to export or erase records by email address; deleting an inquiry also removes its attachments.', 'inquiry-form-inbox' )
			)
		);
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['inquiry-form-inbox'] = array(
			'exporter_friendly_name' => __( 'Inquiry Form Inbox for Headless WordPress', 'inquiry-form-inbox' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['inquiry-form-inbox'] = array(
			'eraser_friendly_name' => __( 'Inquiry Form Inbox for Headless WordPress', 'inquiry-form-inbox' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export inquiry records for an email address, one page at a time.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page index, starting at 1.
	 * @return array{data: array, done: bool}
	 */
	public static function export( $email_address, $page = 1 ) {
		$page  = max( 1, (int) $page );
		$posts = self::query( $email_address, ( $page - 1 ) * self::PER_PAGE );
		$data  = array();

		foreach ( $posts as $post ) {
			$fields = self::export_fields( $post );
			if ( ! $fields ) {
				continue;
			}
			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'Inquiries', 'inquiry-form-inbox' ),
				'item_id'     => self::GROUP . '_' . $post->ID,
				'data'        => $fields,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $posts ) < self::PER_PAGE,
		);
	}

	/**
	 * Erase inquiry records for an email address, one batch at a time.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Unused: each round deletes what it read, so the next
	 *                              round reads page 1 again and sees the remaining rows.
	 * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
	 */
	public static function erase( $email_address, $page = 1 ) {
		unset( $page );

		$posts          = self::query( $email_address, 0 );
		$items_removed  = 0;
		$items_retained = 0;
		$messages       = array();

		foreach ( $posts as $post ) {
			if ( wp_delete_post( $post->ID, true ) ) {
				++$items_removed;
				continue;
			}
			++$items_retained;
			$messages[] = sprintf(
				/* translators: %s: inquiry number or record id. */
				__( 'Inquiry %s could not be deleted.', 'inquiry-form-inbox' ),
				(string) get_post_meta( $post->ID, Inquiry_Form_Inbox::META_PREFIX . 'no', true ) ?: (string) $post->ID
			);
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => count( $posts ) < self::PER_PAGE,
		);
	}

	/**
	 * Fetch one page of inquiries belonging to an email address.
	 *
	 * @param string $email_address Email address.
	 * @param int    $offset        Row offset.
	 * @return WP_Post[]
	 */
	private static function query( $email_address, int $offset ) {
		$email = sanitize_email( (string) $email_address );
		if ( ! is_email( $email ) ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'        => Inquiry_Form_Inbox::POST_TYPE,
				'post_status'      => self::STATUSES,
				'posts_per_page'   => self::PER_PAGE,
				'offset'           => $offset,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filtering by the visitor's email is the whole point of the exporter.
					array(
						'key'     => Inquiry_Form_Inbox::META_PREFIX . 'email',
						'value'   => $email,
						'compare' => '=',
					),
				),
			)
		);
	}

	/**
	 * Build the export rows for one inquiry.
	 *
	 * Fixed metadata first, then one row per submitted form field using the label the
	 * visitor saw. `field_values` is the raw submission; exporting `fields` (a list of
	 * field names) as this class used to do told the data subject nothing.
	 *
	 * @param WP_Post $post Inquiry record.
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function export_fields( WP_Post $post ): array {
		$rows = array();

		foreach ( self::meta_labels() as $suffix => $label ) {
			$raw  = get_post_meta( $post->ID, Inquiry_Form_Inbox::META_PREFIX . $suffix, true );
			$flat = 'attachments' === $suffix
				? self::attachment_names( $raw )
				: self::flatten( $raw );

			if ( '' !== $flat ) {
				$rows[] = array(
					'name'  => $label,
					'value' => $flat,
				);
			}
		}

		$values = get_post_meta( $post->ID, Inquiry_Form_Inbox::META_PREFIX . 'field_values', true );
		$labels = get_post_meta( $post->ID, Inquiry_Form_Inbox::META_PREFIX . 'field_labels', true );
		if ( ! is_array( $values ) ) {
			return $rows;
		}

		foreach ( $values as $field => $value ) {
			$flat = self::flatten( $value );
			if ( '' === $flat ) {
				continue;
			}
			$label  = is_array( $labels ) && isset( $labels[ $field ] ) ? trim( (string) $labels[ $field ] ) : '';
			$rows[] = array(
				'name'  => '' !== $label ? $label : (string) $field,
				'value' => $flat,
			);
		}

		return $rows;
	}

	/**
	 * Meta suffix => human label, for every piece of personal data the plugin stores.
	 *
	 * @return array<string, string>
	 */
	private static function meta_labels(): array {
		return array(
			'no'          => __( 'Inquiry number', 'inquiry-form-inbox' ),
			'email'       => __( 'Email', 'inquiry-form-inbox' ),
			'ip'          => __( 'IP address', 'inquiry-form-inbox' ),
			'lang'        => __( 'Browser language', 'inquiry-form-inbox' ),
			'timezone'    => __( 'Timezone', 'inquiry-form-inbox' ),
			'locale'      => __( 'Page locale', 'inquiry-form-inbox' ),
			'variant'     => __( 'Form type', 'inquiry-form-inbox' ),
			'page_url'    => __( 'Source page', 'inquiry-form-inbox' ),
			'page_title'  => __( 'Source page title', 'inquiry-form-inbox' ),
			'attachments' => __( 'Attachments', 'inquiry-form-inbox' ),
			'mail_error'  => __( 'Delivery error', 'inquiry-form-inbox' ),
		);
	}

	/**
	 * Reduce a stored meta value to a readable string.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string
	 */
	private static function flatten( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( ! is_array( $value ) ) {
			return '';
		}

		$parts = array();
		array_walk_recursive(
			$value,
			static function ( $item ) use ( &$parts ) {
				if ( is_scalar( $item ) && '' !== (string) $item ) {
					$parts[] = (string) $item;
				}
			}
		);

		return implode( ', ', $parts );
	}

	/**
	 * File names from an attachment meta value.
	 *
	 * @param mixed $value Attachment list.
	 * @return string
	 */
	private static function attachment_names( $value ): string {
		$names = array();
		foreach ( (array) $value as $file ) {
			if ( is_array( $file ) && isset( $file['name'] ) && '' !== (string) $file['name'] ) {
				$names[] = (string) $file['name'];
			}
		}
		return implode( ', ', $names );
	}
}
