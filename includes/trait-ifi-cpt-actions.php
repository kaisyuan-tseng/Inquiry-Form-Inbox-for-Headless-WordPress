<?php
/**
 * IFI_CPT_Actions_Trait — Inquiry Form Inbox for Headless WordPress.
 *
 * 列表单元格渲染、行操作、标记已联系、删除与只读拦截。
 */

defined( 'ABSPATH' ) || exit;

trait IFI_CPT_Actions_Trait {

	/**
	 * 自动发信状态：列表里一枚徽章，把两封邮件的组合状态压成一个词。
	 *   All Sent / Only Notification / Only Auto-Confirmation / Not Sent
	 * 失败（'no'）与未尝试（'none'）在「Not Sent」里用颜色区分：红 = 发失败，灰 = 未尝试。
	 * 逐封明细与 SMTP 报错原文仍放 title，悬停可查。
	 */
	private static function mail_status_html( int $post_id ): string {
		$notify  = (string) get_post_meta( $post_id, 'ifi_inquiry_mail_sent', true );
		$reply   = (string) get_post_meta( $post_id, 'ifi_inquiry_autoreply_sent', true );
		$n_error = (string) get_post_meta( $post_id, 'ifi_inquiry_mail_error', true );
		$r_error = (string) get_post_meta( $post_id, 'ifi_inquiry_autoreply_error', true );

		$word = static function ( string $state ): string {
			if ( $state === 'yes' ) {
				return self::t( 'status_yes' );
			}
			return $state === 'no' ? self::t( 'status_no' ) : self::t( 'status_none' );
		};

		if ( $notify === 'yes' && $reply === 'yes' ) {
			$label = self::t( 'status_all' );
			$class = 'is-all';
			$note  = self::t( 'status_all_hint' );
		} elseif ( $notify === 'yes' ) {
			$label = self::t( 'status_only_notify' );
			$class = 'is-partial';
			$note  = self::t( 'status_notify_only' );
		} elseif ( $reply === 'yes' ) {
			$label = self::t( 'status_only_reply' );
			$class = 'is-partial';
			$note  = self::t( 'status_reply_only' );
		} else {
			$label = self::t( 'status_none' );
			$class = ( $notify === 'no' || $reply === 'no' ) ? 'is-fail' : 'is-none';
			$note  = self::t( 'status_none_hint' );
		}

		$lines = array(
			self::label_value(
				self::t( 'label_notify' ),
				$word( $notify ) . ( $n_error !== '' ? ' (' . $n_error . ')' : '' )
			),
			self::label_value(
				self::t( 'label_reply' ),
				$word( $reply ) . ( $r_error !== '' ? ' (' . $r_error . ')' : '' )
			),
		);
		$title = $note . "\n" . implode( "\n", $lines );

		return '<span class="ifi-status-chip ' . esc_attr( $class ) . '" title="' . esc_attr( $title )
			. '" aria-label="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * 清理前端传来的页面标题：去掉 document.title 常见的「文章：」「Post:」类前缀。
	 *
	 * 前端应传干净的页面标题，但实际数据里出现过 `文章：How to …` 这种把整段
	 * document.title 直接提交上来的情况；展示前统一剥掉，避免 Source 列带前缀。
	 *
	 * 前缀表可用 ifi_source_title_prefixes 过滤器替换，换一种语言的站点不必改代码。
	 */
	private static function clean_source_title( string $title ): string {
		$prefixes = (array) apply_filters( 'ifi_source_title_prefixes', array( 'Article', 'Post', '文章' ) );
		$prefixes = array_values(
			array_filter(
				array_map(
					static function ( $prefix ): string {
						return trim( (string) $prefix );
					},
					$prefixes
				),
				static function ( string $prefix ): bool {
					return '' !== $prefix;
				}
			)
		);

		if ( ! $prefixes ) {
			return trim( $title );
		}

		$quoted  = array_map(
			static function ( string $prefix ): string {
				return preg_quote( $prefix, '/' );
			},
			$prefixes
		);
		$pattern = '/^\s*(?:' . implode( '|', $quoted ) . ')\s*[：:]\s*/u';

		return trim( (string) preg_replace( $pattern, '', $title ) );
	}

	/**
	 * 单条记录的来源展示名：英文页面标题 > 本地化标题 > URL 路径。
	 *
	 * @param int  $post_id      询盘记录 ID.
	 * @param bool $with_variant 是否在标题后括注表单类型；列表列只要标题.
	 */
	private static function record_source_label( int $post_id, bool $with_variant = true ): string {
		$name = (string) get_post_meta( $post_id, 'ifi_inquiry_page_title_en', true );
		if ( $name === '' ) {
			$name = (string) get_post_meta( $post_id, 'ifi_inquiry_page_title', true );
		}
		$url     = (string) get_post_meta( $post_id, 'ifi_inquiry_page_url', true );
		$variant = (string) get_post_meta( $post_id, 'ifi_inquiry_variant', true );
		if ( $name === '' && $url !== '' ) {
			$path = parse_url( $url, PHP_URL_PATH );
			$name = $path && $path !== '/' ? trim( (string) $path, '/' ) : '';
		}
		$name = self::clean_source_title( $name );
		if ( ! $with_variant ) {
			return $name !== '' ? $name : '—';
		}

		$variant_label = self::variant_label( $variant );
		if ( $variant_label === '' ) {
			return $name !== '' ? $name : '—';
		}

		return $name !== '' ? self::label_note( $name, $variant_label ) : $variant_label;
	}

	/**
	 * Compose a "label: value" line.
	 *
	 * The separator lives in the dictionary rather than in this file so translators can
	 * use a full-width colon for CJK locales and an ASCII one everywhere else, instead of
	 * one hard-coded punctuation choice leaking into every locale.
	 *
	 * @param string $label Already-translated label.
	 * @param string $value Already-translated value.
	 * @return string
	 */
	private static function label_value( string $label, string $value ): string {
		/* translators: 1: field label, 2: value. */
		return sprintf( self::t( 'pair_label_value' ), $label, $value );
	}

	/** Compose "name (qualifier)", e.g. a page title followed by its form type. */
	private static function label_note( string $name, string $note ): string {
		/* translators: 1: name, 2: qualifier shown in parentheses. */
		return sprintf( self::t( 'pair_label_note' ), $name, $note );
	}

	/**
	 * 一条记录的附件清单，永远是「数组的数组」。
	 *
	 * `get_post_meta( …, true )` 在键不存在时返回空字符串，而 `(array) ''` 得到的是
	 * `array( '' )` —— 非空、可迭代，于是每条没有附件的询盘都会被算成「1 个附件」，
	 * 详情里显示 `file-0 (0 B)`。这里统一收口，调用方不必各自防御。
	 *
	 * @param int $post_id 询盘 ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function record_attachments( int $post_id ): array {
		$files = get_post_meta( $post_id, 'ifi_inquiry_attachments', true );
		if ( ! is_array( $files ) ) {
			return array();
		}

		return array_values( array_filter( $files, 'is_array' ) );
	}

	/** 附件直链列表（新标签页浏览器打开；列表列与详情页共用） */
	private static function attachments_html( int $post_id, string $separator = '<br>' ): string {
		$files = self::record_attachments( $post_id );
		if ( ! $files ) {
			return '—';
		}
		$links = array();
		foreach ( array_values( $files ) as $i => $file ) {
			$url  = (string) ( $file['url'] ?? '' );
			$name = esc_html( (string) ( $file['name'] ?? ( 'file-' . $i ) ) );
			$size = ' <span class="ifi-muted">(' . esc_html( self::size_label( (int) ( $file['size'] ?? 0 ) ) ) . ')</span>';

			if ( $url !== '' ) {
				$links[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
					. $name . '</a>' . $size;
				continue;
			}
			// 没能归档到 uploads 的记录（老数据或归档失败）：至少把文件名与大小显示出来，
			// 否则后台看起来像「访客根本没传附件」，而邮件里其实是带着附件的。
			$links[] = '<span title="' . esc_attr( self::t( 'attachment_not_archived' ) ) . '">'
				. $name . '</span>' . $size;
		}
		return $links ? implode( $separator, $links ) : '—';
	}

	/** 行操作：查看 / 标记已联系（已联系则显示「标记为新询盘」）/ 移到回收站；回收站里换成「恢复 + 永久删除」 */
	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( $post->post_type !== self::POST_TYPE ) {
			return $actions;
		}

		if ( $post->post_status === 'trash' ) {
			$actions = array(
				'untrash' => '<a href="' . esc_url(
					wp_nonce_url(
						admin_url( 'post.php?post=' . $post->ID . '&action=untrash' ),
						'untrash-post_' . $post->ID
					)
				) . '">' . esc_html( self::t( 'restore_action' ) ) . '</a>',
			);
			if ( current_user_can( 'ifi_delete_inquiry', $post->ID ) ) {
				$permanent         = wp_nonce_url(
					admin_url( 'admin-post.php?action=ifi_delete&id=' . $post->ID ),
					'ifi_del_' . $post->ID
				);
				$actions['delete'] = '<a href="' . esc_url( $permanent ) . '" style="color:#b32d2e;" onclick="return confirm(\''
					. esc_js( self::t( 'confirm_delete' ) ) . '\');">' . esc_html( self::t( 'delete_action' ) ) . '</a>';
			}
			return $actions;
		}

		$actions = array(
			'view' => '<a class="ifi-open" href="' . esc_url( admin_url( 'admin.php?page=' . self::VIEW_SLUG . '&id=' . $post->ID ) )
					. '" data-id="' . (int) $post->ID . '">' . esc_html( self::t( 'view_action' ) ) . '</a>',
		);

		$spam = (string) get_post_meta( $post->ID, 'ifi_inquiry_spam', true );
		if ( '' !== $spam ) {
			// 垃圾询盘不参与「已联系」：它根本没送达，能做的决定是「这不是垃圾」
			if ( current_user_can( 'ifi_edit_inquiry', $post->ID ) ) {
				$not_spam                = wp_nonce_url(
					admin_url( 'admin-post.php?action=ifi_not_spam&id=' . $post->ID ),
					'ifi_not_spam_' . $post->ID
				);
				$actions['ifi_not_spam'] = '<a href="' . esc_url( $not_spam ) . '">' . esc_html( self::t( 'not_spam_action' ) ) . '</a>';
			}
		} elseif ( self::is_contacted( $post->ID ) ) {
			$uncontact              = wp_nonce_url(
				admin_url( 'admin-post.php?action=ifi_uncontact&id=' . $post->ID ),
				'ifi_contact_' . $post->ID
			);
			$actions['ifi_contact'] = '<a href="' . esc_url( $uncontact ) . '">' . esc_html( self::t( 'mark_pending' ) ) . '</a>';
		} else {
			$contact                = wp_nonce_url(
				admin_url( 'admin-post.php?action=ifi_contact&id=' . $post->ID ),
				'ifi_contact_' . $post->ID
			);
			$actions['ifi_contact'] = '<a href="' . esc_url( $contact ) . '">' . esc_html( self::t( 'mark_contacted' ) ) . '</a>';
		}

		// 永久删除只出现在回收站；普通行是「Trash」，交给核心 .trash 样式显示红字
		if ( current_user_can( 'ifi_delete_inquiry', $post->ID ) ) {
			$trash_url = get_delete_post_link( $post->ID );
			if ( $trash_url !== '' ) {
				$actions['trash'] = '<a href="' . esc_url( $trash_url ) . '">' . esc_html( self::t( 'trash_action' ) ) . '</a>';
			}
		}
		return $actions;
	}

	/** 是否已标记联系过 */
	private static function is_contacted( int $post_id ): bool {
		return (string) get_post_meta( $post_id, 'ifi_inquiry_contacted', true ) !== '';
	}

	/** 标记为已联系 */
	public static function handle_contact(): void {
		self::set_contacted( true );
	}

	/** 撤销「已联系」标记，回到新询盘 */
	public static function handle_uncontact(): void {
		self::set_contacted( false );
	}

	/**
	 * 标记 / 撤销的公共实现：nonce 证意图、capability 证权限、post type 兜底，
	 * 完成后回到来源页（列表或仪表盘），带一个提示参数。
	 */
	private static function set_contacted( bool $contacted ): void {
		$post_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'ifi_contact_' . $post_id );

		if ( ! $post_id || get_post_type( $post_id ) !== self::POST_TYPE ) {
			wp_die( esc_html( self::t( 'not_found_msg' ) ) );
		}
		if ( ! current_user_can( 'ifi_edit_inquiry', $post_id ) ) {
			wp_die( esc_html( self::t( 'invalid_request' ) ) );
		}

		if ( $contacted ) {
			update_post_meta( $post_id, 'ifi_inquiry_contacted', time() );
		} else {
			delete_post_meta( $post_id, 'ifi_inquiry_contacted' );
		}

		$fallback = self::list_url();
		$referer  = wp_get_referer();
		$target   = $referer !== false && $referer !== '' ? $referer : $fallback;

		wp_safe_redirect( add_query_arg( $contacted ? 'ifi_contacted' : 'ifi_uncontacted', 1, $target ) );
		exit;
	}

	/** 标记结果提示 */
	public static function contacted_notice(): void {
        // phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['ifi_contacted'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( self::t( 'contacted_notice' ) ) );
		} elseif ( isset( $_GET['ifi_uncontacted'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( self::t( 'uncontacted_notice' ) ) );
		}
        // phpcs:enable
	}

	/**
	 * 「不是垃圾」：去掉 ifi_inquiry_spam，记录回到普通询盘列表。
	 *
	 * 与「已联系」一样：nonce 证意图、对象级 capability 证权限、post type 兜底。
	 */
	public static function handle_not_spam(): void {
		$post_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'ifi_not_spam_' . $post_id );

		if ( ! $post_id || get_post_type( $post_id ) !== self::POST_TYPE ) {
			wp_die( esc_html( self::t( 'not_found_msg' ) ) );
		}
		if ( ! current_user_can( 'ifi_edit_inquiry', $post_id ) ) {
			wp_die( esc_html( self::t( 'invalid_request' ) ) );
		}

		delete_post_meta( $post_id, 'ifi_inquiry_spam' );

		// 回到来源页（多半是 Spam 视图，那条记录刚刚从里面消失）
		$fallback = self::list_url();
		$referer  = wp_get_referer();
		$target   = $referer !== false && $referer !== '' ? $referer : $fallback;

		wp_safe_redirect( add_query_arg( 'ifi_not_spammed', 1, $target ) );
		exit;
	}

	/** 「不是垃圾」结果提示 */
	public static function not_spam_notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['ifi_not_spammed'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( self::t( 'not_spam_notice' ) ) );
		}
	}

	/** 删除询盘（连带附件）：管理员专用，其余只读 */
	public static function handle_delete(): void {
		$post_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'ifi_del_' . $post_id );
		if ( ! $post_id || get_post_type( $post_id ) !== self::POST_TYPE ) {
			wp_die( esc_html( self::t( 'not_found_msg' ) ) );
		}
		if ( ! current_user_can( 'ifi_delete_inquiry', $post_id ) ) {
			wp_die( esc_html( self::t( 'invalid_request' ) ) );
		}
		// 永久删除只允许从回收站发起，避免绕过「先移到回收站」的流程
		if ( 'trash' !== get_post_status( $post_id ) ) {
			wp_die( esc_html( self::t( 'invalid_request' ) ) );
		}
		wp_delete_post( $post_id, true ); // before_delete_post 钩子会联动清理附件
		wp_safe_redirect( add_query_arg( 'ifi_deleted', 1, self::list_url() ) );
		exit;
	}

	/** 删除成功提示 */
	public static function deleted_notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['ifi_deleted'] ) && isset( $_GET['post_type'] ) && $_GET['post_type'] === self::POST_TYPE ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( self::t( 'deleted_notice' ) ) );
		}
	}

	/** 直连 edit / add-new 一律拦截；回收站相关的动作放行（恢复、移入回收站、永久删除） */
	public static function block_edit_screen(): void {
        // phpcs:ignore WordPress.Security.NonceVerification
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification
		$type = isset( $_GET['post_type'] ) ? sanitize_key( (string) $_GET['post_type'] ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';

		// post.php?action=untrash 是后台「已移到回收站，撤销」链接的落点，
		// 拦掉就没法撤销了；这几个动作都不涉及内容编辑
		if ( in_array( $action, array( 'trash', 'untrash', 'delete' ), true ) ) {
			return;
		}

		if ( $type === self::POST_TYPE || ( $post_id && get_post_type( $post_id ) === self::POST_TYPE ) ) {
			// 用 403 而不是 wp_die 默认的 500：这是"不允许"，不是服务端故障
			wp_die(
				esc_html( self::t( 'read_only' ) ),
				esc_html( self::t( 'menu_name' ) ),
				array( 'response' => 403 )
			);
		}
	}

	/** 从回收站恢复后回到「已留档」状态：询盘没有草稿概念，WordPress 默认会给 draft */
	public static function restore_status( int $post_id ): void {
		if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
			return;
		}
		if ( get_post_status( $post_id ) === 'publish' ) {
			return;
		}
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
	}
}
