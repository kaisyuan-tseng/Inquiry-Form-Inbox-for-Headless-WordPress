<?php
/**
 * Main plugin class: constants, trait composition and hook registration.
 *
 * The plugin header lives only in inquiry-form-inbox.php. WordPress scans plugin
 * directories for that block, so a second copy here would make this file look like
 * another plugin — and when the two drift apart, the stale one is what gets reported.
 *
 * Business logic lives in includes/trait-ifi-*.php. The public filters and actions are
 * documented in readme.txt and docs/ARCHITECTURE.md.
 *
 * @package Inquiry_Form_Inbox
 */

defined( 'ABSPATH' ) || exit;

final class Inquiry_Form_Inbox {


	const VERSION        = '3.1.3';
	const TEXT_DOMAIN    = 'inquiry-form-inbox';
	const REST_NAMESPACE = 'inquiry-form-inbox/v1';

	const POST_TYPE = 'ifi_inquiry';
	/** 2.x 的 post type，仅迁移使用 */
	const LEGACY_POST_TYPE = 'eln_inquiry';
	/** 1.x 的 post type，仅迁移使用 */
	const LEGACY_POST_TYPE_1X = 'inquiry';
	const VIEW_SLUG           = 'ifi-view';
	const TPL_SLUG            = 'ifi-template';
	/** 垃圾规则页：顶层菜单「询盘」下的第二个子菜单 */
	const SPAM_SLUG = 'ifi-spam';
	/** 后台顶层菜单：列表页 admin.php?page=ifi-inquiry，邮件模板页是它下面的子菜单 */
	const LIST_SLUG = 'ifi-inquiry';
	const HONEYPOT  = 'companyWebsite';

	/** 邮件模板设置存在这个 option 里 */
	const OPT_TEMPLATE = 'ifi_template';
	/** 自动确认模板（含开关 + 语言组） */
	const OPT_AUTOREPLY = 'ifi_autoreply';
	/** 人工回信模板（mailto 的主题/正文骨架，弹窗「回信」按钮用） */
	const OPT_REPLY = 'ifi_reply';
	/** 垃圾规则（蜜罐 / 关键词 / 邮箱域名 / IP / 链接上限 / 重复提交） */
	const OPT_SPAM = 'ifi_spam_rules';
	/** 历史询盘补编号的一次性开关（ifi_inquiry_no 是后加的 meta） */
	const OPT_BACKFILL = 'ifi_numbers_backfilled';
	/** 历史询盘补字段值的一次性开关（ifi_inquiry_field_values 是后加的 meta） */
	const OPT_BACKFILL_VALUES = 'ifi_field_values_backfilled';

	/** 询盘编号：IQ-YYYYMMDD + 3 位当日流水（如 IQ-20261105001） */
	const NO_PREFIX = 'IQ-';

	/** 迁移版本与标识符映射 */
	const OPT_DB_VERSION = 'ifi_db_version';
	const DB_VERSION     = '30000';
	const META_PREFIX    = 'ifi_inquiry_';
	/** 2.x 的 meta 前缀，仅迁移使用 */
	const LEGACY_META_PREFIX = 'eln_inquiry_';
	/** 1.x 的 meta 前缀，仅迁移使用 */
	const LEGACY_META_PREFIX_1X = 'inquiry_';
	/** 流水计数器的 option 前缀 */
	const SEQ_PREFIX = 'ifi_seq_';
	/** 2.x 的流水号 option 前缀，仅迁移使用 */
	const LEGACY_SEQ_PREFIX = 'eln_seq_';
	/** 1.x 的流水号 option 前缀，仅迁移使用 */
	const LEGACY_SEQ_PREFIX_1X = 'inquiry_seq_';
	const RATE_PREFIX          = 'ifi_rl_';
	/** 2.x 的限流 transient 前缀，迁移时清掉 */
	const LEGACY_RATE_PREFIX = 'eln_rl_';

	/** 自定义能力 */
	const CAP_VIEW   = 'ifi_view_inquiries';
	const CAP_EDIT   = 'ifi_edit_inquiries';
	const CAP_DELETE = 'ifi_delete_inquiries';

	/**
	 * 邮件里唯一的品牌色（页头色条 / 页脚竖线 / 链接）。
	 * 取 WordPress 后台主色 #2271b1：模板与后台视觉一致。
	 * 不做成 {{accent}} 占位符：模板源码里直接写这个色值就行，
	 * 想换色改默认源码或自己的模板即可，插件不掺和配色。
	 */
	const BRAND_COLOR = '#2271b1';

	/** 附件总目录（在 uploads 下，可静态访问） */
	public const STORAGE_DIR = 'ifi-inquiries';

	/** 2.x 遗留附件目录，仅迁移使用 */
	public const LEGACY_STORAGE_DIR = 'eln-inquiries';

	/** 1.x 遗留附件目录，仅迁移使用 */
	public const LEGACY_STORAGE_DIR_1X = 'inquiries';

	/*
	==================================================================
	 * 共享常量（原本散落在各 trait 里）
	 *
	 * 为什么集中到类上：**trait 常量是 PHP 8.2 才引入的语法**。在 8.0 / 8.1 上
	 * `trait X { const Y = 1; }` 是编译期 fatal（Cannot use 'const' in trait /
	 * Traits cannot have constants），整个文件解析失败 —— 本地 PHP 8.3+ 跑得好好的，
	 * 一放到停在 8.1 的主机上就白屏。插件头声明的是 Requires PHP 8.0，
	 * 所以两边必须对齐：常量留在类上，trait 里的 self:: 引用照样命中。
	 * ================================================================== */

	/** 静态资源版本号（与插件版本一致；实际 URL 还会拼上文件修改时间） */
	private const ASSET_VER = '3.1.3';

	/** 每个附件分组的白名单：扩展名 + 单文件大小上限（字节） */
	private const FILE_GROUPS = array(
		'drawings' => array(
			'max_size' => 20971520,
			'ext'      => array( 'pdf', 'step', 'stp', 'dwg', 'dxf', 'igs', 'sldprt', 'sldasm', 'zip', 'rar', '7z' ),
		),
		'pictures' => array(
			'max_size' => 10485760,
			'ext'      => array( 'png', 'jpg', 'jpeg', 'webp' ),
		),
	);

	/**
	 * 扩展名 => mime 映射，喂给 wp_check_filetype()。
	 *
	 * **不能**把 FILE_GROUPS 里的扩展名数组直接当第三个参数传：WordPress 要的是
	 * 「扩展名正则 => mime」的映射，传纯列表时键变成 0/1/2，内部拼出的正则是
	 * `\.(0)$`，任何文件都匹配不上，结果 ext/type 双双为 false ——
	 * 表现就是「纯文本询盘能发，带附件的全被判成非法文件类型」。
	 */
	private const FILE_MIME_MAP = array(
		'pdf'      => 'application/pdf',
		'step|stp' => 'application/step',
		'dwg'      => 'application/acad',
		'dxf'      => 'application/dxf',
		'igs|iges' => 'model/iges',
		'sldprt'   => 'application/octet-stream',
		'sldasm'   => 'application/octet-stream',
		'zip'      => 'application/zip',
		'rar'      => 'application/rar',
		'7z'       => 'application/x-7z-compressed',
		'png'      => 'image/png',
		'jpg|jpeg' => 'image/jpeg',
		'webp'     => 'image/webp',
	);

	/** 内容嗅探命中这些 mime 一律拒绝（扩展名可以改，内容类型骗不了 finfo） */
	private const DENIED_MIMES = array(
		'text/html',
		'text/x-php',
		'application/x-php',
		'application/php',
		'application/x-httpd-php',
		'application/x-httpd-php-source',
	);

	/**
	 * 双窗口提交频率限制（同一可信请求标识）的**出厂默认值**：
	 * 1 分钟最多 3 次、1 小时最多 10 次。两个窗口都是固定窗口，按首次提交起算，
	 * 到点自动清零；超限时返回 429 + Retry-After，期间不再放行。
	 *
	 * 次数可以在「Block Rules」页改（存在 option `ifi_spam_rules` 的 rate_minute /
	 * rate_hour 里，0 = 关掉该窗口）；这里只是没保存过时的兜底。
	 */
	private const RATE_MINUTE_LIMIT = 3;
	private const RATE_HOUR_LIMIT   = 10;
	private const RATE_MINUTE_TTL   = 60;
	private const RATE_HOUR_TTL     = 3600;
	/** 访客标识 cookie：服务端签发、HttpOnly，脚本读不到 */
	private const RATE_VISITOR_COOKIE = 'ifi_vid';

	/** 进页脚元信息、不进正文字段表的键（环境数据与来源信息都在这里） */
	private const META_FIELDS = array(
		'formVariant',
		'pageUrl',
		'pageTitle',
		'pageTitleEn',
		'locale',
		'browserLanguage',
		'timezone',
		'fieldLabels',
		'ip',
	);

	/** 模板页用的 CodeMirror 设置（enqueue 时拿到，footer 打印脚本时用） */
	private static $code_editor_settings = null;

	/**
	 * Locale override for the current render, or '' to follow the site locale.
	 *
	 * Only set by with_textdomain(): when switch_to_locale() refuses a locale that is not
	 * installed site-wide, the plugin swaps just its own text domain instead, and the
	 * string dictionary needs to be keyed by that locale rather than the site's.
	 */
	private static $render_locale = '';

	/** String dictionaries already built, keyed by locale. */
	private static $string_cache = array();

	/** Template configuration already loaded, keyed by kind. */
	private static $tpl_cache = array();

	/** 正在发本站的询盘邮件（phpmailer_init 里据此补纯文本正文） */
	private static $sending = false;

	/** 最近一次 wp_mail 失败的原因（wp_mail_failed 捕获，写进记录 meta 供后台排查） */
	private static $mail_error = '';

	use IFI_Core_Trait;
	use IFI_I18n_Trait;
	use IFI_Manifest_Trait;
	use IFI_REST_Trait;
	use IFI_Upload_Trait;
	use IFI_Rate_Limiter_Trait;
	use IFI_Template_Trait;
	use IFI_Mailer_Trait;
	use IFI_Storage_Trait;
	use IFI_CPT_Trait;
	use IFI_CPT_Actions_Trait;
	use IFI_Admin_View_Trait;
	use IFI_Admin_Modal_Trait;
	use IFI_Dashboard_Trait;
	use IFI_Settings_Trait;
	use IFI_Settings_Render_Trait;
	use IFI_Spam_Trait;
	use IFI_Spam_Render_Trait;
	use IFI_Assets_Trait;
	use IFI_Endpoints_Trait;
	use IFI_Install_Health_Trait;


	public static function init(): void {
		// 激活钩子在主文件里注册（register_activation_hook 必须在插件加载时调用）。
		// 激活后的下一次后台请求显示一次性成功通知；安装结构异常时给出诊断。
		add_action( 'admin_notices', array( __CLASS__, 'activation_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'install_health_notice' ) );

		// 激活时立刻补能力；init_capabilities() 也挂在 admin_init 上做自愈，
		// 因为激活钩子不会在插件更新时触发，升级上来的站点需要另一条路径。
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
		IFI_Privacy::init();
		add_action( 'admin_init', array( 'IFI_Migration', 'maybe_migrate' ) );
		add_action( 'admin_notices', array( 'IFI_Migration', 'admin_notice' ) );
		add_filter( 'map_meta_cap', array( 'IFI_Migration', 'map_meta_cap' ), 10, 4 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'purge_record_files' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'restore_status' ) );
		add_action( 'admin_post_ifi_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_init', array( __CLASS__, 'backfill_numbers' ) );
		add_action( 'admin_init', array( __CLASS__, 'backfill_field_values' ) );

		/*
		 * 列定义要挂两个钩子，缺一不可：
		 *
		 *   manage_{post_type}_posts_columns  原生 edit.php?post_type=ifi_inquiry 走这条
		 *   manage_edit-{post_type}_columns   自渲染列表页（admin.php?page=ifi-inquiry）
		 *                                     的 get_column_headers() 只认这条
		 *
		 * 只挂前者时，自渲染页的表格会渲染出「有记录但没有表头、每行也没有单元格」，
		 * 因为列集合为空。
		 */
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_columns', array( __CLASS__, 'admin_columns' ) );
		// 编号列和日期一样可点表头排序（按 ifi_inquiry_no 数字序）
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		// 主列 = 编号：行操作（查看/删除）挂在编号下面；客户、来源两列都是纯文本
		add_filter( 'list_table_primary_column', array( __CLASS__, 'primary_column' ), 10, 2 );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );
		// 列表页视图：全部 / 新询盘 / 已联系 / 回收站；批量操作只留「移动至回收站」
		add_filter( 'views_edit-' . self::POST_TYPE, array( __CLASS__, 'admin_views' ) );
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, array( __CLASS__, 'admin_bulk_actions' ) );
		// 列表上方的来源筛选
		add_action( 'restrict_manage_posts', array( __CLASS__, 'source_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_list_filters' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_list_styles' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		// 「标记为已联系 / 标记为新询盘」两个不需要多一步确认的动作
		add_action( 'admin_post_ifi_contact', array( __CLASS__, 'handle_contact' ) );
		add_action( 'admin_post_ifi_uncontact', array( __CLASS__, 'handle_uncontact' ) );
		add_action( 'admin_post_ifi_not_spam', array( __CLASS__, 'handle_not_spam' ) );
		add_action( 'admin_notices', array( __CLASS__, 'contacted_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'not_spam_notice' ) );
		// 列表页顶部的一行统计：新询盘数（跳 New 视图）+ 是否有未发出的邮件
		add_action( 'admin_notices', array( __CLASS__, 'list_board' ) );
		add_action( 'admin_notices', array( __CLASS__, 'deleted_notice' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_list_page' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_view_page' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_template_page' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_spam_page' ) );
		// 自渲染列表页沿用原生 edit-ifi_inquiry 的 screen id，钩子不用改
		add_filter( 'current_screen', array( __CLASS__, 'list_screen' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_template_setting' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_spam_setting' ) );
		add_action( 'admin_post_ifi_test_mail', array( __CLASS__, 'handle_test_mail' ) );
		add_action( 'admin_post_ifi_reply_addlang', array( __CLASS__, 'handle_add_reply_lang' ) );
		add_action( 'admin_post_ifi_reply_dellang', array( __CLASS__, 'handle_delete_reply_lang' ) );
		// 回信模板的语言组走同一对处理器，靠请求里的 kind=reply 区分
		add_action( 'admin_post_ifi_rp_addlang', array( __CLASS__, 'handle_add_reply_lang' ) );
		add_action( 'admin_post_ifi_rp_dellang', array( __CLASS__, 'handle_delete_reply_lang' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_template_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_spam_assets' ) );
		// 询盘列表：查看弹窗（AJAX 取正文）与弹窗骨架
		add_action( 'wp_ajax_ifi_inquiry_detail', array( __CLASS__, 'ajax_inquiry_detail' ) );
		add_action( 'admin_footer', array( __CLASS__, 'print_list_modal' ) );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'print_template_script' ) );
		// 仪表盘「最近询盘」小挂件
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_dashboard_widget' ) );

		// 发信：补纯文本正文（只有 HTML 的邮件更容易被 Gmail 判垃圾），并记录失败原因
		add_action( 'phpmailer_init', array( __CLASS__, 'fill_alt_body' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ) );
		add_action( 'wp_ajax_ifi_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'load-post.php', array( __CLASS__, 'block_edit_screen' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'block_edit_screen' ) );

		// 模板缓存跟着 option 走：程序里改完 option 立刻读模板时不会拿到旧值
		foreach ( array( self::OPT_TEMPLATE, self::OPT_AUTOREPLY, self::OPT_REPLY ) as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'flush_template_cache' ) );
			add_action( 'add_option_' . $option, array( __CLASS__, 'flush_template_cache' ) );
		}
	}

	/* ================================================================== REST */
}
