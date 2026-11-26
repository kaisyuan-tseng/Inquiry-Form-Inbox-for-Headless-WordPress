<?php
/**
 * IFI_Core_Trait — Inquiry Form Inbox for Headless WordPress.
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Core_Trait {


	/** 统一成正斜杠：Windows 反斜杠路径过 update_post_meta 的 wp_unslash 会损坏（\0→NUL） */
	private static function norm( string $path ): string {
		return str_replace( '\\', '/', $path );
	}

	/**
	 * 站点设置的日期 / 时间格式。
	 *
	 * 插件不自己写死 `Y-m-d H:i` / `g:i a`：后台「设置 → 常规」里选了什么，列表、弹窗、
	 * 仪表盘和邮件就显示什么。格式被清空时回退到 WordPress 的默认值，不至于输出空串。
	 */
	private static function date_format(): string {
		$format = (string) get_option( 'date_format' );
		return '' !== $format ? $format : 'F j, Y';
	}

	/** 站点设置的时间格式；空值时回退到 WordPress 默认 */
	private static function time_format(): string {
		$format = (string) get_option( 'time_format' );
		return '' !== $format ? $format : 'g:i a';
	}

	/** 日期 + 时间：完整时间戳的场合（title 属性、邮件正文） */
	private static function datetime_format(): string {
		return self::date_format() . ' ' . self::time_format();
	}

	/** 上传目录信息（附件放在 uploads 下，浏览器可直开） */
	private static function uploads_dir(): array {
		static $u = null;
		if ( $u === null ) {
			$dir = wp_get_upload_dir();
			$u   = array(
				'basedir' => self::norm( $dir['basedir'] ),
				'baseurl' => rtrim( self::norm( $dir['baseurl'] ), '/' ),
				'webpath' => rtrim( (string) parse_url( $dir['baseurl'], PHP_URL_PATH ), '/' ),
			);
		}
		return $u;
	}

	/** 附件库根目录（规范化绝对路径） */
	private static function storage_base(): string {
		return self::uploads_dir()['basedir'] . '/' . self::STORAGE_DIR . '/';
	}

	/** 附件库相对 Web 路径前缀（相对站点根，主站 80 与 WP 8081 两个域都可用） */
	private static function storage_web_base(): string {
		return self::uploads_dir()['webpath'] . '/' . self::STORAGE_DIR . '/';
	}

	/**
	 * 表单形态表：前端提交的 formVariant 值 => 后台显示名。
	 *
	 * POST 端点对 formVariant 只做 sanitize_key，任何值都能收下并入库；这张表决定的只是
	 * **后台认不认得它的名字**——列表「来源」列的括注、邮件里的 {{form_type}}、看板上的
	 * 表单类型卡都从这里取。前端要加一种自己的形态（侧滑、整屏、嵌入式…）不必改插件：
	 * 提交自己的 slug，再用 ifi_form_variants 给它一个显示名；没登记的值照样入库，
	 * 列表里原样显示 slug、看板归到「—」。
	 *
	 * @return array<string, string>
	 */
	private static function form_variants(): array {
		return (array) apply_filters(
			'ifi_form_variants',
			array(
				'page'     => self::t( 'form_page' ),
				'modal'    => self::t( 'form_modal' ),
				'drawer'   => self::t( 'form_drawer' ),
				'floating' => self::t( 'form_floating' ),
				'sticky'   => self::t( 'form_sticky' ),
			)
		);
	}

	/**
	 * 单个形态的显示名：登记过就翻译，没登记就原样返回 slug。
	 *
	 * 不做「未知即空」：前台自己发明的形态被原样显示，比悄悄消失更容易发现对不上。
	 * 空值返回空串，调用方据此决定要不要加括注、要不要落到页面表单。
	 *
	 * @param string $variant 前端提交的 formVariant 值.
	 */
	private static function variant_label( string $variant ): string {
		if ( '' === $variant ) {
			return '';
		}
		$variants = self::form_variants();
		$label    = $variants[ $variant ] ?? null;
		// 过滤器传回来非标量（数组、对象）时不炸，退回 slug：显示名由第三方给，不能假定类型
		if ( ! is_scalar( $label ) ) {
			return $variant;
		}
		return (string) $label;
	}

	/**
	 * 邮件 / 来源兜底用的形态名：没有 formVariant 的老记录按页面表单算
	 * （3.0 之前只有页内表单这一种，空值就是它，不能留一行空的「()」）。
	 *
	 * @param string $variant 前端提交的 formVariant 值.
	 */
	private static function recorded_form_type( string $variant ): string {
		$label = self::variant_label( $variant );
		return '' !== $label ? $label : self::t( 'form_page' );
	}

	/*
	 * 其余共享常量（附件白名单、限流窗口、元字段、样例值）都在类上，
	 * 不在这里：trait 常量是 PHP 8.2 的语法，8.0 / 8.1 编译期就 fatal。
	 */

	/**
	 * One-time success notice after activation.
	 *
	 * IFI_Capabilities::activate() sets a per-user transient; the next admin request shows this
	 * notice once and clears it. No redirect is involved, so AJAX activation keeps working.
	 */
	public static function activation_notice(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$key = 'ifi_activation_notice_' . $user_id;
		if ( ! get_transient( $key ) ) {
			return;
		}
		delete_transient( $key );

		$links = array(
			array( self::t( 'activation_go_template' ), admin_url( 'admin.php?page=' . self::TPL_SLUG ) ),
			array( self::t( 'activation_go_rules' ), admin_url( 'admin.php?page=' . self::SPAM_SLUG ) ),
			array( self::t( 'activation_go_inbox' ), self::list_url() ),
		);
		?>
		<div class="notice notice-success is-dismissible">
			<p><strong><?php echo esc_html( self::t( 'activation_title' ) ); ?></strong></p>
			<p><?php echo esc_html( self::t( 'activation_body' ) ); ?></p>
			<p>
				<?php foreach ( $links as $link ) : ?>
					<a class="button button-secondary" style="margin-right:6px;" href="<?php echo esc_url( $link[1] ); ?>"><?php echo esc_html( $link[0] ); ?></a>
				<?php endforeach; ?>
			</p>
		</div>
		<?php
	}

	/*
	 * i18n 相关实现都在 IFI_I18n_Trait：字符串走 gettext，按 key 从 strings() 取；
	 * 这里不再保存任何字典，也不做语言选择 —— 语言只是在渲染前由 with_locale() 切换。
	 */
}
