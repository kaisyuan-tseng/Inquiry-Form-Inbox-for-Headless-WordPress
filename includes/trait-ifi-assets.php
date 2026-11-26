<?php
/**
 * IFI_Assets_Trait — Inquiry Form Inbox for Headless WordPress.
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Assets_Trait {


	/* 静态资源版本号（ASSET_VER）在类上：trait 常量需要 PHP 8.2。 */

	/** 当前是否在「邮件模板」页 */
	private static function is_template_screen(): bool {
        // phpcs:disable WordPress.Security.NonceVerification
		$by_query = isset( $_GET['page'] ) && $_GET['page'] === self::TPL_SLUG;
        // phpcs:enable
		if ( $by_query ) {
			return true;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! isset( $screen->id ) ) {
			return false;
		}
		// 邮件模板是顶层菜单「询盘」下的子菜单：screen id = ifi-inquiry_page_ifi-template
		return $screen->id === self::LIST_SLUG . '_page_' . self::TPL_SLUG;
	}

	/** 静态资源 URL（以主插件文件为基准，不依赖外部常量） */
	private static function asset_url( string $file ): string {
		return plugins_url( $file, dirname( __DIR__ ) . '/inquiry-form-inbox.php' );
	}

	/** 资源版本号：插件版本 + 文件修改时间，升级后浏览器不会继续用旧缓存 */
	private static function asset_ver( string $file ): string {
		$path  = dirname( __DIR__ ) . '/assets/' . $file;
		$mtime = file_exists( $path ) ? (string) filemtime( $path ) : '';
		return self::ASSET_VER . ( $mtime !== '' ? '.' . $mtime : '' );
	}

	/** 后台共享样式：询盘列表弹窗 + 仪表盘挂件 + 列表列宽 */
	public static function enqueue_admin_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! isset( $screen->id ) ) {
			return;
		}
		$is_list      = $screen->id === 'edit-' . self::POST_TYPE;
		$is_dashboard = $screen->id === 'dashboard';
		if ( ! $is_list && ! $is_dashboard ) {
			return;
		}
		wp_enqueue_style( 'ifi-admin-css', self::asset_url( 'assets/admin.css' ), array(), self::asset_ver( 'admin.css' ) );
		// 弹窗脚本两个屏共用：列表页点「查看」，仪表盘点姓名，都在原地打开同一条询盘。
		wp_enqueue_script( 'ifi-admin-js', self::asset_url( 'assets/admin.js' ), array( 'jquery' ), self::asset_ver( 'admin.js' ), true );
	}

	/** 模板页资源：媒体库 + WP 自带 CodeMirror + jQuery UI 排序 + 本页 CSS/JS */
	public static function enqueue_template_assets(): void {
		// 列表页 / 仪表盘的共享样式须在 admin_print_styles 之前入队
		self::enqueue_admin_assets();

		if ( ! self::is_template_screen() ) {
			return;
		}
		// 字段标签的顺序就是邮件里的行序，用 WP 自带的 sortable，不额外打包拖拽库
		wp_enqueue_script( 'jquery-ui-sortable' );
		// 「添加媒体」用 WP 原生媒体库
		wp_enqueue_media();
		// 原生 Add Media 按钮：.button 外观来自 buttons.css，
		// 图标（dashicons \f104）与间距来自 editor.css（句柄名是 editor-buttons，不是 editor）
		wp_enqueue_style( 'buttons' );
		wp_enqueue_style( 'editor-buttons' );
		// 代码高亮用 WordPress 原生代码编辑器（CodeMirror），与插件/主题文件编辑器同一套
		$settings                   = wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
		self::$code_editor_settings = is_array( $settings ) ? $settings : null;

		wp_enqueue_style( 'ifi-template-css', self::asset_url( 'assets/template.css' ), array(), self::asset_ver( 'template.css' ) );
		wp_enqueue_script(
			'ifi-template-js',
			self::asset_url( 'assets/template.js' ),
			array( 'jquery', 'jquery-ui-sortable' ),
			self::asset_ver( 'template.js' ),
			true
		);
	}

	/** 模板页脚本配置：在 footer 打印脚本之前注入 window.IFITpl */
	public static function print_template_script(): void {
		if ( ! self::is_template_screen() ) {
			return;
		}

		$kind   = self::current_kind();
		$config = array(
			'ajax'         => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'ifi_preview' ),
			'codeSettings' => self::$code_editor_settings,
			'mediaTitle'   => self::t( 'tpl_add_media' ),
			'mediaInsert'  => self::t( 'tpl_media_insert' ),
			// 两个标签页共用一个渲染器：option 名与 kind 都从 PHP 传，JS 不写死
			'name'         => self::option_name( $kind ),
			'kind'         => $kind,
			// 字段标签手动增删时，新建行用的占位符与删除按钮说明
			'labelKeyPh'   => self::t( 'tpl_label_key' ),
			'labelValuePh' => self::t( 'tpl_label_value' ),
			'labelRemove'  => self::t( 'tpl_label_remove' ),
		);

		$json = wp_json_encode(
			$config,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		wp_add_inline_script(
			'ifi-template-js',
			'window.IFITpl=' . $json . ';',
			'before'
		);
	}

	/** 当前是否在「垃圾规则」页 */
	private static function is_spam_screen(): bool {
        // phpcs:disable WordPress.Security.NonceVerification
		$by_query = isset( $_GET['page'] ) && $_GET['page'] === self::SPAM_SLUG;
        // phpcs:enable
		if ( $by_query ) {
			return true;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! isset( $screen->id ) ) {
			return false;
		}
		// 与邮件模板页同理：顶层菜单「询盘」下的子菜单 ifi-inquiry_page_ifi-spam
		return $screen->id === self::LIST_SLUG . '_page_' . self::SPAM_SLUG;
	}

	/**
	 * 垃圾规则页资源：卡片与表单控件直接复用邮件模板页那套 CSS（不引 CodeMirror，
	 * 也不引预览脚本），本页只多一个 spam.css。
	 */
	public static function enqueue_spam_assets(): void {
		if ( ! self::is_spam_screen() ) {
			return;
		}
		wp_enqueue_style( 'ifi-template-css', self::asset_url( 'assets/template.css' ), array(), self::asset_ver( 'template.css' ) );
		wp_enqueue_style( 'ifi-spam-css', self::asset_url( 'assets/spam.css' ), array( 'ifi-template-css' ), self::asset_ver( 'spam.css' ) );
	}
}
