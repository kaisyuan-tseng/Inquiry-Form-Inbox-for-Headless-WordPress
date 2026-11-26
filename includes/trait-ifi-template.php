<?php
/**
 * IFI_Template_Trait — Inquiry Form Inbox for Headless WordPress.
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Template_Trait {


	/** 两种模板：发给销售的通知、发给访客的自动确认 */
	private static function option_name( string $kind = 'notify' ): string {
		return $kind === 'autoreply' ? self::OPT_AUTOREPLY : self::OPT_TEMPLATE;
	}

	/** 模板默认值：后台只保存这几项，其余全部体现在 HTML 源码里 */
	private static function template_defaults( string $kind = 'notify' ): array {
		$defaults = array(
			'subject' => '', // 空 = 语言字典的主题格式
			'html'    => '', // 空 = 使用默认模板源码
			'labels'  => array(), // 字段标签覆盖：字段名 => 显示名
		);
		if ( $kind === 'autoreply' ) {
			// 自动确认按浏览器语言分「语言组」：索引 0 永远是默认（兜底）组
			$defaults['enabled']       = '0';
			$defaults['ignore_unsent'] = '0';
			$defaults['groups']        = array( self::empty_group() );
		}
		return $defaults;
	}

	/** 一个空的语言组（lang 为空 = 默认/兜底） */
	private static function empty_group( string $lang = '' ): array {
		return array(
			'lang'    => $lang,
			'subject' => '',
			'html'    => '',
			'labels'  => array(),
		);
	}

	/** 回信模板的空语言组：正文是纯文本，没有字段标签（{{rows}} 用通知模板那份标签） */
	private static function empty_reply_group( string $lang = '' ): array {
		return array(
			'lang'    => $lang,
			'subject' => '',
			'body'    => '',
		);
	}

	/**
	 * 自动确认的语言组（规范化后的列表，索引 0 恒为默认组）。
	 */
	private static function reply_groups( ?array $cfg = null ): array {
		return self::group_list( $cfg ?? self::tpl( 'autoreply' ), 'html' );
	}

	/**
	 * 回信模板的语言组：正文是纯文本（mailto），所以键名是 body 而不是 html。
	 */
	private static function reply_tpl_groups( ?array $cfg = null ): array {
		return self::group_list( $cfg ?? self::reply_tpl(), 'body' );
	}

	/**
	 * 语言组归一（自动确认与回信共用）：补齐缺省字段、把 lang 为空的组提到第一位、
	 * 同一语言只留第一个。读（渲染/匹配）与写（保存）都走它，索引才对得上。
	 *
	 * @param array  $cfg      含 groups 的配置.
	 * @param string $body_key 正文键名：自动确认是 html（HTML 源码），回信是 body（纯文本）.
	 */
	private static function group_list( array $cfg, string $body_key = 'html' ): array {
		$groups = isset( $cfg['groups'] ) && is_array( $cfg['groups'] ) ? $cfg['groups'] : array();

		$clean = array();
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$item = array(
				'lang'    => self::canonical_lang( (string) ( $group['lang'] ?? '' ) ),
				'subject' => (string) ( $group['subject'] ?? '' ),
			);

			$item[ $body_key ] = (string) ( $group[ $body_key ] ?? '' );
			if ( $body_key === 'html' ) {
				// 字段标签是「自动确认」独有的：邮件正文里 {{rows}} 的表头跟着语言组走
				$item['labels'] = (array) ( $group['labels'] ?? array() );
			}
			$clean[] = $item;
		}

		// 把默认组换到第 0 位（没有就补一个）
		$default_index = null;
		foreach ( $clean as $i => $group ) {
			if ( $group['lang'] === '' ) {
				$default_index = $i;
				break;
			}
		}
		if ( $default_index === null ) {
			array_unshift( $clean, $body_key === 'html' ? self::empty_group() : self::empty_reply_group() );
		} elseif ( $default_index !== 0 ) {
			$swap                    = $clean[0];
			$clean[0]                = $clean[ $default_index ];
			$clean[ $default_index ] = $swap;
		}

		// 同一语言只留第一个（默认组不算重复）
		$seen = array( '' => true );
		$out  = array( $clean[0] );
		foreach ( array_slice( $clean, 1 ) as $group ) {
			if ( $group['lang'] === '' || isset( $seen[ strtolower( $group['lang'] ) ] ) ) {
				continue;
			}
			$seen[ strtolower( $group['lang'] ) ] = true;
			$out[]                                = $group;
		}

		return $out;
	}

	/**
	 * 按访客浏览器语言挑语言组：完整标签精确命中（zh-cn）→ 主语言命中（zh）
	 * → 默认组。没有浏览器语言（旧前端/无 JS）也走默认组。
	 */
	private static function reply_group( array $fields, ?array $cfg = null ): array {
		return self::match_lang_group( $fields, self::reply_groups( $cfg ) );
	}

	/**
	 * 从规范化后的语言组里挑一组（回信模板也走它：谁来信就用谁的语言回）。
	 *
	 * @param array $fields 询盘字段（含 browserLanguage）.
	 * @param array $groups 已归一的语言组，索引 0 是默认组.
	 */
	private static function match_lang_group( array $fields, array $groups ): array {
		$groups = $groups ? array_values( $groups ) : array( self::empty_group() );
		// 访客浏览器标签先按同一套别名归一：en-US → en，zh-CN → zh-hans，zh-TW → zh-hant
		$lang = self::canonical_lang( (string) ( $fields['browserLanguage'] ?? '' ) );
		if ( $lang === '' ) {
			return $groups[0];
		}

		foreach ( $groups as $group ) {
			if ( strtolower( $group['lang'] ) === $lang ) {
				return $group;
			}
		}
		$primary = explode( '-', $lang )[0];
		foreach ( $groups as $group ) {
			if ( $group['lang'] !== '' && explode( '-', strtolower( $group['lang'] ) )[0] === $primary ) {
				return $group;
			}
		}
		return $groups[0];
	}

	/** 当前生效的模板配置（默认值 + 后台保存值），按 kind 各自缓存 */
	private static function tpl( string $kind = 'notify' ): array {
		if ( ! isset( self::$tpl_cache[ $kind ] ) ) {
			$saved    = get_option( self::option_name( $kind ) );
			$is_saved = is_array( $saved ) && $saved !== array(); // 保存过 = 之后空就是空
			$tpl      = wp_parse_args( is_array( $saved ) ? $saved : array(), self::template_defaults( $kind ) );
			if ( $kind === 'autoreply' ) {
				// 语言组统一规范化：0 号恒为默认组，同一语言只留一份
				$tpl['groups'] = self::reply_groups( $tpl );
				if ( ! $is_saved ) {
					// 从未保存过：各组空源码用内置默认（首次进后台就能看到完整模板）
					foreach ( $tpl['groups'] as $i => $g ) {
						$tpl['groups'][ $i ]['html'] = self::default_template_html( 'autoreply' );
					}
				}
			} elseif ( ! $is_saved ) {
				$tpl['html'] = self::default_template_html( 'notify' );
			}
			self::$tpl_cache[ $kind ] = $tpl;
		}
		return self::$tpl_cache[ $kind ];
	}

	/**
	 * 丢弃模板缓存。
	 *
	 * 挂到三个模板 option 的 update 钩子上：程序里改完 option 紧接着读模板时，
	 * 不会拿回改之前的那份。
	 */
	public static function flush_template_cache(): void {
		self::$tpl_cache = array();
	}

	/**
	 * 自动确认可选的语言（i18n 选项）：键 = 浏览器语言标签，值 = 给人看的名字。
	 *
	 * 名称按「当前后台语言」显示：英文后台是 Simplified Chinese，中文后台是简体中文；
	 * 英文不再区分 US/GB，中文只分简繁（zh-hans / zh-hant）。
	 */
	private static function lang_options(): array {
		$names = array(
			'en'      => __( 'English', 'inquiry-form-inbox' ),
			'zh-hans' => __( 'Simplified Chinese', 'inquiry-form-inbox' ),
			'zh-hant' => __( 'Traditional Chinese', 'inquiry-form-inbox' ),
			'ja'      => __( 'Japanese', 'inquiry-form-inbox' ),
			'ko'      => __( 'Korean', 'inquiry-form-inbox' ),
			'de'      => __( 'German', 'inquiry-form-inbox' ),
			'fr'      => __( 'French', 'inquiry-form-inbox' ),
			'es'      => __( 'Spanish', 'inquiry-form-inbox' ),
			'pt'      => __( 'Portuguese', 'inquiry-form-inbox' ),
			'it'      => __( 'Italian', 'inquiry-form-inbox' ),
			'nl'      => __( 'Dutch', 'inquiry-form-inbox' ),
			'ru'      => __( 'Russian', 'inquiry-form-inbox' ),
			'pl'      => __( 'Polish', 'inquiry-form-inbox' ),
			'tr'      => __( 'Turkish', 'inquiry-form-inbox' ),
			'ar'      => __( 'Arabic', 'inquiry-form-inbox' ),
			'th'      => __( 'Thai', 'inquiry-form-inbox' ),
			'vi'      => __( 'Vietnamese', 'inquiry-form-inbox' ),
			'id'      => __( 'Indonesian', 'inquiry-form-inbox' ),
			'hi'      => __( 'Hindi', 'inquiry-form-inbox' ),
		);

		$options = array();
		foreach ( $names as $code => $name ) {
			$options[ $code ] = $name . ' (' . $code . ')';
		}
		return $options;
	}

	/** 语言标签的显示名（认不出来的原样大写显示，兼容手改过的旧值） */
	private static function lang_label( string $code ): string {
		if ( $code === '' ) {
			return self::t( 'tpl_lang_default' );
		}
		$options = self::lang_options();
		return $options[ $code ] ?? strtoupper( $code );
	}

	/**
	 * 语言标签归一：能对上候选表就用表里的规范写法（zh-hans，别再变 zh-cn），
	 * 对不上就小写保留（自定义/旧数据）。顺手剔掉非法字符。
	 *
	 * 旧值与浏览器标签都走同一套别名：en-US/en-GB → en，zh/zh-CN → zh-hans，
	 * zh-TW/zh-HK → zh-hant，这样历史语言组和访客浏览器都能继续匹配。
	 */
	private static function canonical_lang( string $lang ): string {
		$lang = (string) preg_replace( '/[^A-Za-z0-9\-_]/', '', trim( $lang ) );
		if ( $lang === '' ) {
			return '';
		}

		$lower = strtolower( str_replace( '_', '-', $lang ) );
		$alias = array(
			'en-us' => 'en',
			'en-gb' => 'en',
			'zh'    => 'zh-hans',
			'zh-cn' => 'zh-hans',
			'zh-sg' => 'zh-hans',
			'zh-tw' => 'zh-hant',
			'zh-hk' => 'zh-hant',
			'zh-mo' => 'zh-hant',
		);
		if ( isset( $alias[ $lower ] ) ) {
			return $alias[ $lower ];
		}
		if ( strpos( $lower, 'en-' ) === 0 ) {
			return 'en';
		}
		if ( strpos( $lower, 'zh-' ) === 0 ) {
			return ( strpos( $lower, 'hant' ) !== false || preg_match( '/-(tw|hk|mo)(-|$)/', $lower ) )
				? 'zh-hant'
				: 'zh-hans';
		}

		foreach ( array_keys( self::lang_options() ) as $code ) {
			if ( strcmp( $code, $lower ) === 0 ) {
				return $code;
			}
		}
		return $lower;
	}

	/**
	 * 预览数据：不读真实询盘，字段值直接用占位符 {{字段名}}。
	 *
	 * 插件不知道本站有哪些字段、更不知道这些字段该填什么；预览只负责展示模板形状，
	 * 真实发送时由 build_body() 把同样的位置替换成询盘数据。
	 *
	 * @param array|null $labels 当前模板/语言组保存的字段标签（决定预览里的行与顺序）.
	 * @return array{fields: array<string, mixed>, no: string, time: int, ip: string}
	 */
	private static function preview_source( ?array $labels = null ): array {
		$fields = array();
		foreach ( (array) self::label_fields( $labels )['fields'] as $field_key => $default_label ) {
			$fields[ $field_key ] = '{{rows.' . $field_key . '.value}}';
		}

		return array(
			'fields' => $fields,
			'no'     => '',
			'time'   => 0,
			'ip'     => '',
		);
	}

	/** 预览说明：明确这是占位符预览，不是某条真实询盘 */
	private static function preview_note(): string {
		return self::t( 'tpl_preview_note_ph' );
	}

	/**
	 * 默认邮件模板源码（后台代码框里看到的就是它）。
	 *
	 * 版式即 WordPress 后台的视觉语言：浅灰底（#f0f0f1）+ 白色卡片 + #dcdcde 细边框，
	 * 顶部 3px 后台蓝（#2271b1）色条，系统字体栈，页脚浅灰信息块。
	 * 页头与页脚的文案都写在这里（英文），想换说法或换语言直接改代码。
	 * $kind = 'autoreply' 时换成发给访客的那份默认源码。
	 */
	private static function default_template_html( string $kind = 'notify' ): string {
		if ( $kind === 'autoreply' ) {
			return self::default_autoreply_html();
		}
		return <<<'HTML'
<div style="margin:0;padding:40px 12px;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #dcdcde;border-radius:4px;border-collapse:separate;">
    <tr>
      <td style="padding:22px 32px;border-top:3px solid #2271b1;border-bottom:1px solid #dcdcde;">
        <!-- Header line 1: inquiry number + heading. Edit the copy here. -->
        <div style="font-size:20px;line-height:1.4;color:#1d2327;font-weight:600;">New inquiry {{no}}</div>
        <!-- Header line 2: source page + form type + submitted time -->
        <div style="margin-top:6px;font-size:12px;line-height:1.7;color:#646970;">From {{source}} ({{form_type}}) &middot; {{time}}</div>
      </td>
    </tr>
    <tr>
      <td style="padding:20px 32px 0;">
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;">
          {{rows}}
        </table>
      </td>
    </tr>
    <tr>
      <td style="padding:22px 32px 32px;">
        <!-- Footer block: the labels live below, the placeholders only inject values -->
        <div style="padding:14px 16px;background:#f6f7f7;border:1px solid #f0f0f1;border-radius:3px;font-size:12px;line-height:1.8;color:#646970;">
          Browser language: {{lang}} &middot; Timezone: {{time_zone}}<br>
          Submitted: {{time}} &middot; Local time: {{time_local}}<br>
          IP: {{ip}}
        </div>
      </td>
    </tr>
  </table>
</div>
HTML;
	}

	/**
	 * 默认的「自动确认」源码。
	 *
	 * 收件人是访客本人，文案用英文（站点默认语是 en，询盘客户多数在海外），
	 * 正文回显他刚提交的内容（{{rows}} 的表头用的就是他表单自己的措辞），
	 * 页脚只留编号和时间，不放 IP 这类内部信息。
	 */
	private static function default_autoreply_html(): string {
		return <<<'HTML'
<div style="margin:0;padding:40px 12px;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #dcdcde;border-radius:4px;border-collapse:separate;">
    <tr>
      <td style="padding:22px 32px;border-top:3px solid #2271b1;border-bottom:1px solid #dcdcde;">
        <!-- Header: heading + inquiry number. Edit the copy here. -->
        <div style="font-size:20px;line-height:1.4;color:#1d2327;font-weight:600;">We've received your inquiry</div>
        <div style="margin-top:6px;font-size:12px;line-height:1.7;color:#646970;">#{{no}} &middot; {{time}}</div>
      </td>
    </tr>
    <tr>
      <td style="padding:24px 32px 0;font-size:14px;line-height:1.8;color:#3c434a;">
        Dear {{rows.fullName.value}},<br>
        Thank you for contacting us. Your message has reached our team and we will reply within one business day.
      </td>
    </tr>
    <tr>
      <td style="padding:24px 32px 0;">
        <!-- Echo back what the visitor just sent. The table headers come from their own form labels. -->
        <div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#646970;margin-bottom:8px;">Your message</div>
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;">
          {{rows}}
        </table>
      </td>
    </tr>
    <tr>
      <td style="padding:22px 32px 32px;">
        <div style="padding:14px 16px;background:#f6f7f7;border:1px solid #f0f0f1;border-radius:3px;font-size:12px;line-height:1.8;color:#646970;">
          Inquiry #{{no}}<br>
          {{time}}
        </div>
      </td>
    </tr>
  </table>
</div>
HTML;
	}

	/**
	 * 邮件正文 = 模板源码（后台保存的，没有就用默认源码）+ 占位符替换。
	 *
	 * **占位符只输出值，不输出任何标签文案**——「语言：」「时区：」这类字眼由源码自己写，
	 * 想让邮件说什么话，完全取决于模板。
	 *
	 *   字段  {{rows}}（整表）、{{rows.字段名.label}} / {{rows.字段名.value}}
	 *   数据  {{no}} {{source}} {{form_type}}
	 *         {{time}}（站点时间） {{time_local}}（访客当地时间）
	 *         {{ip}} {{lang}}（浏览器语言） {{time_zone}}（访客时区）
	 *
	 * 页头、页脚、各类固定文案都直接写在源码里——想换语言/换说法就改代码。
	 */

	/* ---------------------------------------------------------------- 回信（mailto） */

	/**
	 * 弹窗「回信」的最终内容：按这条询盘的浏览器语言挑一组语言组，
	 * 再把占位符全部换成真实值。
	 *
	 * mailto 只认纯文本，而且点击时浏览器不做任何替换，所以填充放在 PHP 侧完成，
	 * 前端拿到的是可以整段塞进 `mailto:` 的成品字符串。
	 *
	 * @param int $post_id 询盘 ID.
	 * @return array{to:string,cc:string,bcc:string,subject:string,body:string}
	 */
	private static function reply_payload( int $post_id ): array {
		$cfg    = self::reply_tpl();
		$fields = self::record_fields( $post_id );
		$group  = self::match_lang_group( $fields, self::reply_tpl_groups( $cfg ) );

		// 回信是写给访客的，语言组里的固定文案（表单类型等）也要按访客语言渲染
		return self::with_locale(
			(string) ( $group['lang'] ?? '' ),
			static function () use ( $cfg, $group, $fields, $post_id ) {
				$tokens = self::reply_tokens( $fields, $post_id );

				return array(
					'to'      => trim( strtr( (string) ( $cfg['to'] ?? '' ), $tokens ) ),
					'cc'      => trim( strtr( (string) ( $cfg['cc'] ?? '' ), $tokens ) ),
					'bcc'     => trim( strtr( (string) ( $cfg['bcc'] ?? '' ), $tokens ) ),
					'subject' => trim( strtr( (string) ( $group['subject'] ?? '' ), $tokens ) ),
					'body'    => strtr( (string) ( $group['body'] ?? '' ), $tokens ),
				);
			}
		);
	}

	/**
	 * 回信里的占位符：和邮件模板同一套，但值保持原样（纯文本，不做 HTML 转义）。
	 *
	 * @param array $fields  询盘字段（record_fields 还原出来的）.
	 * @param int   $post_id 询盘 ID.
	 * @return array<string, string>
	 */
	private static function reply_tokens( array $fields, int $post_id ): array {
		// {{rows}} 的表头用「询盘通知」那份字段标签：回信是写给内部看的，跟后台口径一致
		$labels = (array) ( self::tpl( 'notify' )['labels'] ?? array() );
		$tz     = self::field_timezone( $fields );
		if ( '' === $tz ) {
			// 字段值里没有 timezone（它属于 meta，不入 ifi_inquiry_field_values），
			// 回信模板要的当地时间只能从记录 meta 补：否则 {{time_local}} 是空的。
			$tz = self::field_timezone( array( 'timezone' => (string) get_post_meta( $post_id, 'ifi_inquiry_timezone', true ) ) );
		}

		$no = (string) get_post_meta( $post_id, 'ifi_inquiry_no', true );
		if ( $no === '' ) {
			$no = self::NO_PREFIX . get_the_date( 'Ymd', $post_id ) . '001';
		}
		$time = (string) get_the_date( self::datetime_format() . ' T', $post_id );

		return self::field_tokens( $fields, false, $labels ) + array(
			'{{site_name}}'  => '', // 已废弃，模板里不再使用
			'{{source}}'     => self::source_label( $fields ),
			'{{form_type}}'  => self::recorded_form_type( (string) ( $fields['formVariant'] ?? '' ) ),
			'{{rows}}'       => self::field_table_text( $fields, $labels ),
			'{{no}}'         => $no,
			'{{time}}'       => $time,
			'{{time_local}}' => self::local_time_label( $tz, self::datetime_format() . ' T', (int) get_post_time( 'U', true, $post_id ) ),
			'{{ip}}'         => (string) get_post_meta( $post_id, 'ifi_inquiry_ip', true ),
			'{{lang}}'       => (string) ( $fields['browserLanguage'] ?? '' ),
			'{{time_zone}}'  => $tz,
			// 兼容旧模板：{{name}} / {{email}} / {{date}}
			'{{name}}'       => (string) ( $fields['fullName'] ?? '' ),
			'{{email}}'      => (string) ( $fields['email'] ?? '' ),
			'{{date}}'       => $time,
		);
	}

	/** {{rows}} 的纯文本版：一行一个「标签: 值」（mailto 正文不认 HTML 表格） */
	private static function field_table_text( array $fields, ?array $labels = null ): string {
		$lines = array();
		foreach ( self::ordered_fields( $fields, $labels ) as $key => $value ) {
			$label   = self::field_label( $key, $labels, (array) ( $fields['fieldLabels'] ?? array() ) );
			$lines[] = $label . ': ' . trim( (string) $value );
		}
		return implode( "\n", $lines );
	}

	/**
	 * 把一条已入库的询盘还原成「像前端刚提交」的字段数组。
	 *
	 * 字段值存在 ifi_inquiry_field_values（正文是渲染死的 HTML，倒推不可靠），
	 * 环境值（来源页、语言、时区、表单形态）从各自的 meta 补回来。
	 *
	 * @param int $post_id 询盘 ID.
	 */
	private static function record_fields( int $post_id ): array {
		$values = get_post_meta( $post_id, 'ifi_inquiry_field_values', true );
		$fields = is_array( $values ) ? $values : array();

		$env = array(
			'formVariant'     => 'ifi_inquiry_variant',
			'pageUrl'         => 'ifi_inquiry_page_url',
			'pageTitle'       => 'ifi_inquiry_page_title',
			'pageTitleEn'     => 'ifi_inquiry_page_title_en',
			'browserLanguage' => 'ifi_inquiry_lang',
			'timezone'        => 'ifi_inquiry_timezone',
		);
		foreach ( $env as $key => $meta_key ) {
			if ( empty( $fields[ $key ] ) ) {
				$fields[ $key ] = (string) get_post_meta( $post_id, $meta_key, true );
			}
		}

		if ( empty( $fields['fieldLabels'] ) ) {
			$labels = get_post_meta( $post_id, 'ifi_inquiry_field_labels', true );
			if ( is_array( $labels ) && $labels ) {
				$fields['fieldLabels'] = $labels;
			}
		}
		if ( empty( $fields['email'] ) ) {
			$fields['email'] = (string) get_post_meta( $post_id, 'ifi_inquiry_email', true );
		}
		if ( empty( $fields['fullName'] ) ) {
			$fields['fullName'] = (string) get_the_title( $post_id );
		}

		return $fields;
	}
}
