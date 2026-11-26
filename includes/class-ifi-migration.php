<?php
/**
 * Versioned data migration for Inquiry Form Inbox 3.0.0.
 *
 * 3.0.0 把插件从 email-log-notifications（eln_*）整体改名为 inquiry-form-inbox（ifi_*）：
 * post type、meta 前缀、options、附件目录、能力、迁移版本全部换新。
 * 这里负责把 2.x 的数据一次性搬到新标识下；1.x（inquiry / inquiry_*）直升也能一并收口。
 *
 * @package Inquiry_Form_Inbox
 */

defined( 'ABSPATH' ) || exit;

final class IFI_Migration {

	/**
	 * 需要改名的 meta 尾段（不含前缀）。
	 *
	 * @return string[]
	 */
	private static function meta_suffixes() {
		return array(
			'email',
			'variant',
			'locale',
			'page_url',
			'page_title',
			'page_title_en',
			'ip',
			'lang',
			'timezone',
			'no',
			'fields',
			'field_labels',
			'field_values',
			'attachments',
			'mail_sent',
			'mail_error',
			'autoreply_sent',
			'autoreply_error',
			'name',
			'contacted',
		);
	}

	/**
	 * 需要改名的 option（旧 => 新）。值随名字一起搬，不重新计算。
	 *
	 * @return array<string, string>
	 */
	private static function option_map() {
		return array(
			'eln_template'                => Inquiry_Form_Inbox::OPT_TEMPLATE,
			'eln_autoreply'               => Inquiry_Form_Inbox::OPT_AUTOREPLY,
			'eln_reply'                   => Inquiry_Form_Inbox::OPT_REPLY,
			'eln_numbers_backfilled'      => Inquiry_Form_Inbox::OPT_BACKFILL,
			'eln_field_values_backfilled' => Inquiry_Form_Inbox::OPT_BACKFILL_VALUES,
			'eln_db_version'              => Inquiry_Form_Inbox::OPT_DB_VERSION,
		);
	}

	/**
	 * Activation hook: make sure the roles carry the plugin capabilities.
	 *
	 * Deliberately does not migrate data — migration needs an admin session so it can move
	 * the attachment directory and surface a failure notice (see maybe_migrate()).
	 *
	 * Also leaves a one-time flag for the activating user: the next admin page shows a
	 * success notice with the three next-step links. No redirect happens here — the install
	 * screen activates plugins over AJAX, and a redirect would break that response.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ) {
		unset( $network_wide );
		self::init_capabilities();

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			set_transient( 'ifi_activation_notice_' . $user_id, 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * Run migration once in the admin.
	 */
	public static function maybe_migrate() {
		self::init_capabilities();

		if ( ! is_admin() ) {
			return;
		}

		// 3.0.7：字段标签改为后台手动维护。旧版本会把自动收集到的字段随保存写进
		// 模板（ifi_template）和每个语言组（ifi_autoreply.groups[*]）的 labels，
		// 这里一次性清掉，避免旧标签继续出现在新界面里。
		if ( get_option( 'ifi_labels_manual' ) !== '1' ) {
			self::clear_auto_labels();
			update_option( 'ifi_labels_manual', '1', false );
		}

		if ( get_option( Inquiry_Form_Inbox::OPT_DB_VERSION ) === Inquiry_Form_Inbox::DB_VERSION ) {
			return;
		}

		global $wpdb;

		$steps = array(
			'post_type'    => array( __CLASS__, 'migrate_post_type' ),
			'meta_keys'    => array( __CLASS__, 'migrate_meta_keys' ),
			'options'      => array( __CLASS__, 'migrate_options' ),
			'attachments'  => array( __CLASS__, 'migrate_attachments' ),
			'content'      => array( __CLASS__, 'migrate_post_content' ),
			'capabilities' => array( __CLASS__, 'migrate_capabilities' ),
		);

		foreach ( $steps as $name => $callback ) {
			if ( ! call_user_func( $callback ) ) {
				update_option(
					'ifi_migration_error',
					sprintf( 'Migration step %s failed: %s', $name, (string) $wpdb->last_error ),
					false
				);
				return;
			}
		}

		delete_option( 'ifi_migration_error' );
		delete_option( 'eln_migration_error' );
		update_option( Inquiry_Form_Inbox::OPT_DB_VERSION, Inquiry_Form_Inbox::DB_VERSION, false );
	}

	/**
	 * 一次性清掉旧版本自动收集的字段标签（模板 + 所有语言组）。
	 */
	private static function clear_auto_labels() {
		foreach ( array( Inquiry_Form_Inbox::OPT_TEMPLATE, Inquiry_Form_Inbox::OPT_AUTOREPLY ) as $option ) {
			$cfg = get_option( $option, null );
			if ( ! is_array( $cfg ) ) {
				continue;
			}
			if ( isset( $cfg['labels'] ) ) {
				$cfg['labels'] = array();
			}
			if ( isset( $cfg['groups'] ) && is_array( $cfg['groups'] ) ) {
				foreach ( $cfg['groups'] as $i => $group ) {
					if ( is_array( $group ) ) {
						$cfg['groups'][ $i ]['labels'] = array();
					}
				}
			}
			update_option( $option, $cfg, false );
		}
	}

	/**
	 * Grant the plugin capabilities to the roles that need them.
	 *
	 * Runs on activation, and once per admin request as a self-heal: the activation hook
	 * does not fire on plugin updates, so a site that upgraded from an earlier version
	 * would otherwise never receive a capability added afterwards.
	 *
	 * WP_Role::add_cap() calls update_option() unconditionally, so granting all six on
	 * every admin request meant six wp_user_roles writes per page load. The has_cap()
	 * guard makes the steady state read-only.
	 */
	public static function init_capabilities() {
		$caps = array(
			Inquiry_Form_Inbox::CAP_VIEW,
			Inquiry_Form_Inbox::CAP_EDIT,
			Inquiry_Form_Inbox::CAP_DELETE,
		);

		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Map plugin-specific meta capabilities.
	 *
	 * @param string[] $caps    Primitive capabilities.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Capability arguments.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		$map = array(
			'ifi_view_inquiry'   => Inquiry_Form_Inbox::CAP_VIEW,
			'ifi_edit_inquiry'   => Inquiry_Form_Inbox::CAP_EDIT,
			'ifi_delete_inquiry' => Inquiry_Form_Inbox::CAP_DELETE,
		);

		if ( ! isset( $map[ $cap ] ) ) {
			return $caps;
		}

		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || Inquiry_Form_Inbox::POST_TYPE !== $post->post_type ) {
			return array( 'do_not_allow' );
		}

		return array( $map[ $cap ] );
	}

	/**
	 * Show a migration failure notice.
	 */
	public static function admin_notice() {
		$error = get_option( 'ifi_migration_error', '' );
		if ( '' === $error ) {
			return;
		}
		// 迁移失败是可自愈的状态：修好之后 notice 自己消失，所以不需要关闭按钮。
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: database error message. */
					__( 'Inquiry Form Inbox migration error: %s', 'inquiry-form-inbox' ),
					$error
				)
			)
		);
	}

	/** 2.x 的 eln_inquiry 与 1.x 的 inquiry 都并入 ifi_inquiry */
	private static function migrate_post_type() {
		global $wpdb;
		foreach ( array( Inquiry_Form_Inbox::LEGACY_POST_TYPE, Inquiry_Form_Inbox::LEGACY_POST_TYPE_1X ) as $old_type ) {
			$wpdb->update(
				$wpdb->posts,
				array( 'post_type' => Inquiry_Form_Inbox::POST_TYPE ),
				array( 'post_type' => $old_type ),
				array( '%s' ),
				array( '%s' )
			);
			if ( ! empty( $wpdb->last_error ) ) {
				return false;
			}
		}
		return true;
	}

	/** eln_inquiry_* 与 inquiry_* 两代 meta 前缀统一搬到 ifi_inquiry_* */
	private static function migrate_meta_keys() {
		global $wpdb;
		$prefixes = array(
			Inquiry_Form_Inbox::LEGACY_META_PREFIX,    // 2.x：eln_inquiry_
			Inquiry_Form_Inbox::LEGACY_META_PREFIX_1X, // 1.x：inquiry_
		);

		foreach ( $prefixes as $old_prefix ) {
			foreach ( self::meta_suffixes() as $suffix ) {
				$wpdb->update(
					$wpdb->postmeta,
					array( 'meta_key' => Inquiry_Form_Inbox::META_PREFIX . $suffix ),
					array( 'meta_key' => $old_prefix . $suffix ),
					array( '%s' ),
					array( '%s' )
				);
				if ( ! empty( $wpdb->last_error ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** options 改名 + 清理旧的流水号 / 限流 transient / 旧迁移错误 */
	private static function migrate_options() {
		global $wpdb;

		foreach ( self::option_map() as $old_name => $new_name ) {
			self::rename_option( $old_name, $new_name );
		}

		// 每日流水号：eln_seq_YYYYMMDD / inquiry_seq_YYYYMMDD → ifi_seq_YYYYMMDD
		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE 'eln\_seq\_%' OR option_name LIKE 'inquiry\_seq\_%'"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 一次性迁移查询。
		if ( ! empty( $wpdb->last_error ) ) {
			return false;
		}
		foreach ( (array) $rows as $row ) {
			$new_name = 'ifi_seq_' . substr( (string) $row->option_name, strrpos( (string) $row->option_name, '_' ) + 1 );
			self::rename_option( (string) $row->option_name, $new_name, (string) $row->option_value );
		}

		// 旧版本的限流 transient 直接清掉（窗口只有 1 小时，不需要搬）
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . Inquiry_Form_Inbox::LEGACY_RATE_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . Inquiry_Form_Inbox::LEGACY_RATE_PREFIX ) . '%'
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 一次性迁移查询。

		return empty( $wpdb->last_error );
	}

	/**
	 * 改名一个 option：目标已存在则保留目标、删掉旧的，避免覆盖新配置。
	 *
	 * @param string      $old_name 旧名.
	 * @param string      $new_name 新名.
	 * @param string|null $value    已知值（批量循环里传入，省一次查询）.
	 */
	private static function rename_option( $old_name, $new_name, $value = null ) {
		if ( $value === null ) {
			$value = get_option( $old_name, null );
			if ( $value === null ) {
				return;
			}
		}
		if ( get_option( $new_name, null ) === null ) {
			update_option( $new_name, $value, false );
		}
		delete_option( $old_name );
	}

	/** 附件目录：uploads/eln-inquiries 与 uploads/inquiries 合并进 uploads/ifi-inquiries */
	private static function migrate_attachments() {
		$uploads = wp_upload_dir();
		$base    = wp_normalize_path( $uploads['basedir'] );
		$new_dir = $base . '/' . Inquiry_Form_Inbox::STORAGE_DIR;

		wp_mkdir_p( $new_dir );
		self::write_index_php( $new_dir );

		foreach ( array( Inquiry_Form_Inbox::LEGACY_STORAGE_DIR, Inquiry_Form_Inbox::LEGACY_STORAGE_DIR_1X ) as $old_name ) {
			$old_dir = $base . '/' . $old_name;
			if ( ! is_dir( $old_dir ) ) {
				continue;
			}
			$entries = scandir( $old_dir );
			if ( false === $entries ) {
				return false;
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry || 'index.php' === $entry ) {
					continue;
				}
				$source = $old_dir . '/' . $entry;
				$target = $new_dir . '/' . $entry;
				if ( is_dir( $source ) && ! is_dir( $target ) ) {
					if ( ! @rename( $source, $target ) ) {
						return false;
					}
				}
			}
			self::write_index_php( $old_dir );
			self::update_attachment_meta_paths( $old_dir, $new_dir );
		}

		return true;
	}

	/**
	 * Replace old upload paths in attachment metadata.
	 *
	 * @param string $old_dir Old absolute directory.
	 * @param string $new_dir New absolute directory.
	 */
	private static function update_attachment_meta_paths( $old_dir, $new_dir ) {
		$meta_key = Inquiry_Form_Inbox::META_PREFIX . 'attachments';
		$post_ids = get_posts(
			array(
				'post_type'      => Inquiry_Form_Inbox::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => $meta_key,
			)
		);

		foreach ( $post_ids as $post_id ) {
			$files = get_post_meta( $post_id, $meta_key, true );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as &$file ) {
				if ( ! is_array( $file ) ) {
					continue;
				}
				foreach ( array( 'path', 'web_path', 'url' ) as $field ) {
					if ( isset( $file[ $field ] ) && is_string( $file[ $field ] ) ) {
						$file[ $field ] = str_replace( $old_dir, $new_dir, $file[ $field ] );
						$file[ $field ] = str_replace(
							array( '/' . Inquiry_Form_Inbox::LEGACY_STORAGE_DIR . '/', '/' . Inquiry_Form_Inbox::LEGACY_STORAGE_DIR_1X . '/' ),
							'/' . Inquiry_Form_Inbox::STORAGE_DIR . '/',
							$file[ $field ]
						);
					}
				}
			}
			unset( $file );
			update_post_meta( $post_id, $meta_key, $files );
		}
	}

	/** 正文里写死的旧附件路径同样换掉（详情页直接展示这段 HTML） */
	private static function migrate_post_content() {
		global $wpdb;
		foreach ( array( Inquiry_Form_Inbox::LEGACY_STORAGE_DIR, Inquiry_Form_Inbox::LEGACY_STORAGE_DIR_1X ) as $old_name ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_type = %s",
					'/' . $old_name . '/',
					'/' . Inquiry_Form_Inbox::STORAGE_DIR . '/',
					Inquiry_Form_Inbox::POST_TYPE
				)
			);
			if ( ! empty( $wpdb->last_error ) ) {
				return false;
			}
		}
		return true;
	}

	/** 旧的自定义能力一次性摘掉，新的由 init_capabilities() 每轮补齐 */
	private static function migrate_capabilities() {
		$old_caps = array( 'eln_view_inquiries', 'eln_edit_inquiries', 'eln_delete_inquiries' );
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $old_caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		return true;
	}

	private static function write_index_php( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$file = $dir . '/index.php';
		if ( ! file_exists( $file ) ) {
			@file_put_contents( $file, "<?php\n// Silence is golden.\n" );
		}
	}
}
