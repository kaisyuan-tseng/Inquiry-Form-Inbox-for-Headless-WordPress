<?php
/**
 * IFI_Admin_Modal_Trait — Inquiry Form Inbox for Headless WordPress.
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Admin_Modal_Trait {


	/** 打印详情弹窗骨架 + 样式 + 脚本（列表页与仪表盘共用；参考 WP「外观 → 主题详情」的覆盖层） */
	public static function print_list_modal(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		// 仪表盘挂件也在原地打开同一个弹窗（admin.js 的 .ifi-open 处理），
		// 所以 dashboard 屏同样要打印骨架；看板那段脚本找不到 #ifi-board 会自行跳过。
		if ( $screen->id !== 'edit-' . self::POST_TYPE && $screen->id !== 'dashboard' ) {
			return;
		}
		// 没有查看权限就不打印骨架：仪表盘人人可见，不该给无关账号塞一个空弹窗
		if ( ! current_user_can( Inquiry_Form_Inbox::CAP_VIEW ) ) {
			return;
		}
		self::enqueue_admin_assets();
		?>
		<?php
		// 弹窗骨架先按默认语言组铺一份兜底值；每条询盘真正的回信内容由
		// 详情接口按它的浏览器语言现算（见 ajax_inquiry_detail 的 reply 字段）
		$ifi_reply_default = self::reply_tpl_groups()[0];
		?>
		<div id="ifi-modal" class="ifi-modal" role="dialog" aria-modal="true"
			aria-label="<?php echo esc_attr( self::t( 'modal_hint' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'ifi_inquiry_detail' ) ); ?>"
			data-viewed-nonce="<?php echo esc_attr( wp_create_nonce( 'ifi_inquiry_viewed' ) ); ?>"
			data-loading="<?php echo esc_attr( self::t( 'modal_loading' ) ); ?>"
			data-error="<?php echo esc_attr( self::t( 'modal_error' ) ); ?>"
			data-reply-to="<?php echo esc_attr( self::reply_tpl()['to'] ); ?>"
			data-reply-cc="<?php echo esc_attr( self::reply_tpl()['cc'] ); ?>"
			data-reply-bcc="<?php echo esc_attr( self::reply_tpl()['bcc'] ); ?>"
			data-reply-subject="<?php echo esc_attr( (string) $ifi_reply_default['subject'] ); ?>"
			data-reply-body="<?php echo esc_attr( (string) $ifi_reply_default['body'] ); ?>"
			data-confirm-delete="<?php echo esc_attr( self::t( 'confirm_delete' ) ); ?>">
			<div class="ifi-modal__panel" data-ifiver="r30">
			<div class="ifi-modal__top">
				<div class="ifi-modal__nav">
					<button type="button" class="ifi-modal__arrow ifi-modal__prev" aria-label="<?php echo esc_attr( self::t( 'modal_prev' ) ); ?>" disabled>
						<svg width="20" height="20" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>
					</button>
					<button type="button" class="ifi-modal__arrow ifi-modal__next" aria-label="<?php echo esc_attr( self::t( 'modal_next' ) ); ?>" disabled>
						<svg width="20" height="20" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg>
					</button>
				</div>
				<button type="button" class="ifi-modal__close" aria-label="<?php echo esc_attr( self::t( 'modal_close' ) ); ?>">&times;</button>
			</div>
			<div class="ifi-modal__wrap">
				<div class="ifi-modal__main">
					<div class="ifi-modal__stage"></div>
				</div>
				<aside class="ifi-modal__side">
					<?php // 编号就是这块侧栏的标题；其余字段与列表表头一一对应 ?>
					<h2 class="ifi-modal__title ifi-modal__no"></h2>
					<dl class="ifi-modal__fields">
						<dt><?php echo esc_html( self::t( 'col_from' ) ); ?></dt>
						<dd class="ifi-modal__from"></dd>
						<dt><?php echo esc_html( self::t( 'col_attachments' ) ); ?></dt>
						<dd class="ifi-modal__attach"></dd>
						<dt><?php echo esc_html( self::t( 'col_source' ) ); ?></dt>
						<dd class="ifi-modal__source"></dd>
						<dt><?php echo esc_html( self::t( 'col_status' ) ); ?></dt>
						<dd><span class="ifi-modal__chip" hidden></span></dd>
						<dt><?php echo esc_html( self::t( 'col_received' ) ); ?></dt>
						<dd class="ifi-modal__date"></dd>
					</dl>
				</aside>
			</div>
			<div class="ifi-modal__foot">
				<a class="button button-compact button-primary ifi-modal__reply" hidden><?php echo esc_html( self::t( 'modal_reply' ) ); ?></a>
				<a class="button button-compact delete-theme ifi-modal__delete" hidden><?php echo esc_html( self::t( 'modal_delete' ) ); ?></a>
			</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- 仪表盘 */
}
