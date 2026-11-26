<?php
/**
 * IFI_Endpoints_Trait — Inquiry Form Inbox for Headless WordPress.
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Endpoints_Trait {


	/** 实时预览：拿表单里未保存的值渲染一封示例邮件 */
	public static function ajax_preview(): void {
		check_ajax_referer( 'ifi_preview', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'ifi_forbidden',
					'message' => self::t( 'error_forbidden' ),
				),
				403
			);
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$kind = isset( $_POST['kind'] ) ? sanitize_key( (string) $_POST['kind'] ) : 'notify';
		$kind = $kind === 'autoreply' ? 'autoreply' : 'notify';

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['template'] ) && is_array( $_POST['template'] ) ? wp_unslash( $_POST['template'] ) : array();

		if ( $kind === 'autoreply' ) {
			// 预览看的是「当前语言组」这一份，不走保存逻辑（不做跨组合并）
			$tpl = self::sanitize_group( $raw );
		} else {
			$tpl = self::sanitize_template_of( $raw, $kind );
		}

		// 预览不取真实询盘：字段值用占位符，{{...}} 原样显示；
		// 字段集合用当前模板/语言组自己保存的标签（后台手动维护）
		$source = self::preview_source( (array) ( $tpl['labels'] ?? array() ) );
		if ( $kind === 'autoreply' && $tpl['lang'] !== '' ) {
			// 让预览里的 {{lang}} 也跟着该组语言走
			$source['fields']['browserLanguage'] = $tpl['lang'];
		}

		$html = self::build_body( $source['fields'], array(), $tpl, $source['no'], $kind, $source['time'], $source['ip'], true );

		// 默认模板源码只是一个外层 <div>：补一层整页背景，iframe 拉高时底部不会露出白底。
		// 已经是完整文档（含 <html> / <!DOCTYPE）的模板原样返回，避免嵌套 html/body。
		if ( stripos( $html, '<html' ) === false && stripos( $html, '<!doctype' ) === false ) {
			$html = '<!DOCTYPE html><html><head><meta charset="utf-8" />'
				. '<style>html,body{margin:0;padding:0;min-height:100%;background:#f0f0f1;}</style>'
				. '</head><body>' . $html . '</body></html>';
		}

		wp_send_json_success(
			array(
				'html' => $html,
				'kind' => $kind,
				'note' => self::preview_note(),
			)
		);
	}

	/**
	 * 发一封测试邮件。
	 *
	 * 收件人固定用询盘收件人（admin_email / filter ifi_recipient），
	 * 两套模板发往同一收件箱，便于对比。
	 * 自动确认页发的是「当前语言组」的模板，不是默认组。
	 */
	public static function handle_test_mail(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( self::t( 'error_forbidden' ) ), '', array( 'response' => 403 ) );
		}
		$kind = self::current_kind();
		check_admin_referer( 'ifi_test_mail_' . $kind );

		if ( $kind === 'autoreply' ) {
			$slots = self::reply_groups();
			$slot  = self::current_slot( $slots );
			$tpl   = $slots[ $slot ];
		} else {
			$slot = 0;
			$tpl  = self::tpl( 'notify' );
		}

		$source = self::preview_source();
		if ( ! empty( $tpl['lang'] ) ) {
			$source['fields']['browserLanguage'] = (string) $tpl['lang'];
		}
		$body    = self::build_body( $source['fields'], array(), $tpl, $source['no'], $kind, $source['time'], $source['ip'] );
		$subject = self::build_subject( $source['fields'], $tpl, $kind );
		$to      = (string) apply_filters( 'ifi_recipient', get_option( 'admin_email' ) );

		// 和正式发信走同一个封装：补纯文本正文、失败原因也照同一套规则记录
		$sent = self::mail(
			$to,
			'[TEST] ' . $subject,
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::TPL_SLUG,
					'kind'     => $kind,
					'slot'     => $slot,
					'ifi_test' => $sent ? '1' : '0',
				),
				self::list_url()
			)
		);
		exit;
	}

	/** 语言组页面的类型：自动确认（默认）还是回信模板 */
	private static function lang_kind(): string {
        // phpcs:disable WordPress.Security.NonceVerification
		$kind = isset( $_POST['kind'] ) ? sanitize_key( (string) wp_unslash( $_POST['kind'] ) )
			: ( isset( $_GET['kind'] ) ? sanitize_key( (string) wp_unslash( $_GET['kind'] ) ) : '' );
        // phpcs:enable
		return $kind === 'reply' ? 'reply' : 'autoreply';
	}

	/** 语言组增删的 nonce 前缀（两个页面各一套，互不串用） */
	private static function lang_nonce( string $kind ): string {
		return $kind === 'reply' ? 'ifi_rp_lang' : 'ifi_reply_lang';
	}

	/** 当前 kind 的配置与语言组 */
	private static function lang_state( string $kind ): array {
		if ( $kind === 'reply' ) {
			$cfg = self::reply_tpl();
			return array( $cfg, self::reply_tpl_groups( $cfg ) );
		}
		$cfg = self::tpl( 'autoreply' );
		return array( $cfg, self::reply_groups( $cfg ) );
	}

	/** 把语言组写回去（保持各自的 option 结构与 sanitize 过滤链） */
	private static function save_lang_groups( string $kind, array $cfg, array $groups ): void {
		if ( $kind === 'reply' ) {
			$cfg['groups'] = $groups;
			update_option( self::OPT_REPLY, $cfg, false );
			return;
		}
		update_option(
			self::OPT_AUTOREPLY,
			array(
				'enabled'       => empty( $cfg['enabled'] ) ? '0' : '1',
				'ignore_unsent' => empty( $cfg['ignore_unsent'] ) ? '0' : '1',
				'groups'        => $groups,
			),
			false
		);
	}

	/** 自动确认 / 回信模板：新增一个语言组（语言代码来自页面顶部的小表单） */
	public static function handle_add_reply_lang(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( self::t( 'error_forbidden' ) ), '', array( 'response' => 403 ) );
		}
		$kind = self::lang_kind();
		check_admin_referer( self::lang_nonce( $kind ), '_wpnonce_lang' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$lang = self::canonical_lang( (string) sanitize_text_field( wp_unslash( $_POST['newlang'] ?? '' ) ) );

		list( $cfg, $groups ) = self::lang_state( $kind );

		if ( $lang === '' ) {
			wp_safe_redirect( self::template_slot_url( $kind, 0 ) );
			exit;
		}

		foreach ( $groups as $i => $group ) {
			if ( $group['lang'] === $lang ) {
				wp_safe_redirect( add_query_arg( 'ifi_lang_exists', 1, self::template_slot_url( $kind, $i ) ) );
				exit;
			}
		}

		$groups[] = $kind === 'reply' ? self::empty_reply_group( $lang ) : self::empty_group( $lang );
		self::save_lang_groups( $kind, $cfg, $groups );

		wp_safe_redirect( add_query_arg( 'ifi_lang_added', 1, self::template_slot_url( $kind, count( $groups ) - 1 ) ) );
		exit;
	}

	/** 自动确认 / 回信模板：删除一个语言组（默认组不可删） */
	public static function handle_delete_reply_lang(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( self::t( 'error_forbidden' ) ), '', array( 'response' => 403 ) );
		}
		$kind = self::lang_kind();
        // phpcs:ignore WordPress.Security.NonceVerification
		$slot = isset( $_GET['slot'] ) ? max( 0, (int) $_GET['slot'] ) : 0;
		check_admin_referer( self::lang_nonce( $kind ) . '_' . $slot );

		list( $cfg, $groups ) = self::lang_state( $kind );

		if ( $slot > 0 && isset( $groups[ $slot ] ) ) {
			unset( $groups[ $slot ] );
			self::save_lang_groups( $kind, $cfg, array_values( $groups ) );
		}

		wp_safe_redirect( add_query_arg( 'ifi_lang_deleted', 1, self::template_slot_url( $kind, 0 ) ) );
		exit;
	}
}
