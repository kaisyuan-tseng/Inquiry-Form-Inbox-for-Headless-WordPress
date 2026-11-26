<?php
/**
 * IFI_Admin_View_Trait — Inquiry Form Inbox for Headless WordPress.
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Admin_View_Trait {

	public static function register_view_page(): void {
		$hook = add_submenu_page(
			null,
			self::t( 'view_page_title' ),
			self::t( 'view_page_title' ),
			Inquiry_Form_Inbox::CAP_VIEW,
			self::VIEW_SLUG,
			array( __CLASS__, 'render_view_page' )
		);

		// 校验放在 load-* 阶段：此时后台页头还没输出，wp_die 才能给出真正的 403/404；
		// 等 render_view_page 里再 wp_die，状态码早已被页头固定成 200。
		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'guard_view_page' ) );
		}
	}

	/* ---------------------------------------------------------------- 查看弹窗 */

	/** AJAX：取一条询盘的正文，给列表页的「查看」弹窗用 */
	public static function ajax_inquiry_detail(): void {
		check_ajax_referer( 'ifi_inquiry_detail', 'nonce' );
		if ( ! current_user_can( Inquiry_Form_Inbox::CAP_VIEW ) ) {
			wp_send_json_error(
				array(
					'code'    => 'ifi_forbidden',
					'message' => self::t( 'error_forbidden' ),
				),
				403
			);
		}

		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || $post->post_type !== self::POST_TYPE ) {
			wp_send_json_error(
				array(
					'code'    => 'ifi_not_found',
					'message' => self::t( 'not_found_msg' ),
				),
				404
			);
		}
		if ( ! current_user_can( 'ifi_edit_inquiry', $id ) ) {
			wp_send_json_error(
				array(
					'code'    => 'ifi_forbidden',
					'message' => self::t( 'error_forbidden' ),
				),
				403
			);
		}

		$notify = (string) get_post_meta( $id, 'ifi_inquiry_mail_sent', true );
		$reply  = (string) get_post_meta( $id, 'ifi_inquiry_autoreply_sent', true );
		$email  = (string) get_post_meta( $id, 'ifi_inquiry_email', true );
		// 「来自」与列表同形状：粗体姓名 + 换行邮箱（转义在 PHP 侧完成）
		$from = '<strong>' . esc_html( get_the_title( $post ) ) . '</strong>';
		if ( $email !== '' ) {
			$from .= '<br><span class="ifi-muted">' . esc_html( $email ) . '</span>';
		}

		// 弹窗的状态徽章与列表同口径：两封邮件的组合状态压成一个词，颜色只分三档
		if ( $notify === 'yes' && $reply === 'yes' ) {
			$state = 'all';
			$label = self::t( 'status_all' );
		} elseif ( $notify === 'yes' ) {
			$state = 'partial';
			$label = self::t( 'status_only_notify' );
		} elseif ( $reply === 'yes' ) {
			$state = 'partial';
			$label = self::t( 'status_only_reply' );
		} else {
			$state = 'none';
			$label = self::t( 'status_none' );
		}

		wp_send_json_success(
			array(
				'no'     => (string) get_post_meta( $id, 'ifi_inquiry_no', true ),
				'title'  => get_the_title( $post ),
				'date'   => get_the_date( self::datetime_format(), $post ),
				'email'  => $email,
				'from'   => $from,
				'source' => self::record_source_label( $id ),
				'attach' => self::attachments_html( $id ), // 附件直链（与列表「附件」列同源）
			// 弹窗「删除」按钮只在回收站记录里出现：普通记录先走「移到回收站」。
			// wp_nonce_url 会把 & 转成 &#038; 实体（那是给 HTML 属性用的），这里要走 JSON，得先还原成裸 &
				'delete' => ( 'trash' === $post->post_status && current_user_can( 'ifi_delete_inquiry', $id ) )
					? htmlspecialchars_decode( wp_nonce_url( admin_url( 'admin-post.php?action=ifi_delete&id=' . $id ), 'ifi_del_' . $id ), ENT_QUOTES )
					: null,
				'state'  => $state, // all | partial | none
				'status' => $label,
				// 回信内容：按这条询盘的浏览器语言挑语言组，占位符已在 PHP 侧填好
				// （mailto 是纯文本、浏览器不做替换，前端拿到就能直接用）
				'reply'  => self::reply_payload( $id ),
				// 正文由 build_body() 生成，但库里的数据同样按不可信处理；输出前再用模板
				// 保存时那套 kses 白名单过一遍，兼顾 2.x 迁移记录与历史数据。
				'html'   => self::render_stored_body( (string) $post->post_content ),
			)
		);
	}

	/**
	 * AJAX：弹窗关闭后把记录标为「已查看」。
	 *
	 * 列表编号后的「新增」徽章据此变成「待处理」；已联系 / 垃圾的优先级更高，
	 * 标记不会改变它们。重复调用是幂等的。
	 */
	public static function ajax_mark_viewed(): void {
		check_ajax_referer( 'ifi_inquiry_viewed', 'nonce' );

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $id || get_post_type( $id ) !== self::POST_TYPE ) {
			wp_send_json_error(
				array(
					'code'    => 'ifi_not_found',
					'message' => self::t( 'not_found_msg' ),
				),
				404
			);
		}
		if ( ! current_user_can( 'ifi_edit_inquiry', $id ) ) {
			wp_send_json_error(
				array(
					'code'    => 'ifi_forbidden',
					'message' => self::t( 'error_forbidden' ),
				),
				403
			);
		}

		// 已联系 / 垃圾 / 回收站不需要「已查看」；只有普通询盘才从新增进入待处理
		$skip_viewed = 'trash' === get_post_status( $id )
			|| metadata_exists( 'post', $id, 'ifi_inquiry_spam' )
			|| metadata_exists( 'post', $id, 'ifi_inquiry_contacted' );
		if ( ! $skip_viewed && ! metadata_exists( 'post', $id, 'ifi_inquiry_viewed' ) ) {
			update_post_meta( $id, 'ifi_inquiry_viewed', time() );
		}

		$badge = self::inquiry_badge( $id );
		wp_send_json_success( $badge ?? array() );
	}

	/** 详情页入参校验（load-* 阶段执行，早于任何输出） */
	public static function guard_view_page(): void {
		if ( ! current_user_can( Inquiry_Form_Inbox::CAP_VIEW ) ) {
			wp_die( esc_html( self::t( 'error_forbidden' ) ), '', array( 'response' => 403 ) );
		}

		$post_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || $post->post_type !== self::POST_TYPE ) {
			wp_die( esc_html( self::t( 'not_found_msg' ) ), '', array( 'response' => 404 ) );
		}
		if ( ! current_user_can( 'ifi_edit_inquiry', $post_id ) ) {
			wp_die( esc_html( self::t( 'error_forbidden' ) ), '', array( 'response' => 403 ) );
		}
	}

	/** 只读详情页：只留邮件正文；元信息表与正文重复，已去掉 */
	public static function render_view_page(): void {
		if ( ! current_user_can( Inquiry_Form_Inbox::CAP_VIEW ) ) {
			wp_die( esc_html( self::t( 'error_forbidden' ) ), '', array( 'response' => 403 ) );
		}

		$post_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || $post->post_type !== self::POST_TYPE ) {
			wp_die( esc_html( self::t( 'not_found_msg' ) ), '', array( 'response' => 404 ) );
		}
		if ( ! current_user_can( 'ifi_edit_inquiry', $post_id ) ) {
			wp_die( esc_html( self::t( 'error_forbidden' ) ), '', array( 'response' => 403 ) );
		}

		$delete_url = ( 'trash' === $post->post_status && current_user_can( 'ifi_delete_inquiry', $post_id ) )
			? wp_nonce_url( admin_url( 'admin-post.php?action=ifi_delete&id=' . $post_id ), 'ifi_del_' . $post_id )
			: '';
		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html( get_the_title( $post ) ); ?>
				<a href="<?php echo esc_url( self::list_url() ); ?>" class="page-title-action"><?php echo esc_html( self::t( 'back' ) ); ?></a>
				<?php if ( $delete_url !== '' ) : ?>
					<a href="<?php echo esc_url( $delete_url ); ?>" class="page-title-action" style="color:#b32d2e;"
						onclick="return confirm('<?php echo esc_js( self::t( 'confirm_delete' ) ); ?>');"><?php echo esc_html( self::t( 'delete_action' ) ); ?></a>
				<?php endif; ?>
			</h1>

			<h2 class="title"><?php echo esc_html( self::t( 'content_heading' ) ); ?></h2>
			<div style="max-width:760px;background:#fff;border:1px solid #dcdcde;padding:16px;">
				<?php
				// 正文渲染前统一过滤：用户值在入库时已转义，这里再守住历史/导入数据。
				echo self::render_stored_body( (string) $post->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</div>
		</div>
		<?php
	}

	/* ================================================================ 邮件模板设置 */
}
