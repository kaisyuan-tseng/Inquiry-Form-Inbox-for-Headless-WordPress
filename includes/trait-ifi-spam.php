<?php
/**
 * IFI_Spam_Trait — Inquiry Form Inbox for Headless WordPress.
 *
 * 垃圾规则：设置项、匹配器与命中后的处置。
 *
 * 所有规则都在服务端、入库存档与发信之前执行。命中即「静默成功」——
 * 访客拿到的响应与正常提交完全一样（200 {"sent":true}），
 * 机器人无法通过响应判断自己有没有被拦。
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Spam_Trait {

	/**
	 * 解析蜜罐字段名时的重入标记。
	 *
	 * 字段名现在来自规则，而取规则会经过 `ifi_spam_rules` 过滤器；站长若在这个
	 * 过滤器里调用 honeypot_field()，没有这道标记就是无限递归。
	 */
	private static $honeypot_resolving = false;


	/**
	 * 规则默认值。
	 *
	 * 蜜罐与「留档」默认开：装上就有保护，而且不会误伤正常访客。
	 * 蜜罐字段名默认用常量 `HONEYPOT`，站长可以在设置页改成自己前端实际提交的名字。
	 *
	 * 另外带一份**保守的起步名单**：一次性邮箱域名 + 高确定性的垃圾词。
	 * 挑选标准是「真实客户几乎不可能写出来」——一次性邮箱不是谈生意的地址，
	 * viagra / casino / guest post 也不会出现在询盘正文里，误伤概率可以忽略。
	 * 名单只是起点，站长可以在页面上整组清空。
	 *
	 * IP 黑名单与数值上限（链接数、重复窗口）默认留空：这两类一旦配错会直接把
	 * 正常访客挡在门外，必须由站长按自己站点的情况开。
	 *
	 * 限流次数沿用内置的 3 次/分钟、10 次/小时（常量即出厂值），站长可以在页面上改；
	 * 0 表示关掉该窗口。
	 */
	private static function spam_defaults(): array {
		return array(
			'honeypot'       => '1',
			'honeypot_field' => self::HONEYPOT,
			'archive'        => '1',
			'keywords'       => array(
				'viagra',
				'cialis',
				'casino',
				'porn',
				'escort service',
				'crypto giveaway',
				'seo package',
				'guest post',
				'buy backlinks',
			),
			'domains'        => array(
				// 常见的一次性 / 临时邮箱：询盘不会用这些地址谈生意
				'mailinator.com',
				'guerrillamail.com',
				'sharklasers.com',
				'10minutemail.com',
				'tempmail.com',
				'temp-mail.org',
				'yopmail.com',
				'trashmail.com',
				'getnada.com',
				'dispostable.com',
			),
			'ips'            => array(),
			'max_links'      => 0,
			'duplicate'      => 0,
			'rate_minute'    => self::RATE_MINUTE_LIMIT,
			'rate_hour'      => self::RATE_HOUR_LIMIT,
		);
	}

	/** 「Block Rules」保存注册：整份规则是一个 option，清洗只有一处 */
	public static function register_spam_setting(): void {
		register_setting(
			'ifi_spam_group',
			self::OPT_SPAM,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_spam_rules' ),
				'default'           => self::spam_defaults(),
			)
		);
	}

	/**
	 * 当前生效的规则（默认值 + 后台保存值），可被 ifi_spam_rules 过滤器整体替换。
	 *
	 * 不缓存：get_option 本身走 alloptions 缓存，而测试页需要拿「表单里那份」规则，
	 * 缓存反而会让两种来源打架。
	 */
	private static function spam_rules(): array {
		$saved = get_option( self::OPT_SPAM );
		$rules = self::normalize_spam_rules(
			wp_parse_args( is_array( $saved ) ? $saved : array(), self::spam_defaults() )
		);

		$filtered = apply_filters( 'ifi_spam_rules', $rules );
		// 过滤器返回残缺数组时不重跑清洗（那会改掉别人写进来的值），补齐键就够了
		return is_array( $filtered ) ? wp_parse_args( $filtered, $rules ) : $rules;
	}

	/** 单个开关规则是否启用（honeypot / archive） */
	private static function spam_rule_on( string $rule ): bool {
		return (string) ( self::spam_rules()[ $rule ] ?? '0' ) === '1';
	}

	/**
	 * 蜜罐字段名：设置页可改（默认 `companyWebsite`），filter 仍可覆盖。
	 *
	 * 前端必须用同名隐藏字段；名字只能含字母、数字、下划线与短横线，也不能与表单
	 * 自身的字段重名——REST 收集字段时用的是同一套字符集，非法名字永远命中不了提交的键。
	 */
	private static function honeypot_field(): string {
		if ( self::$honeypot_resolving ) {
			return self::HONEYPOT;
		}
		self::$honeypot_resolving = true;

		try {
			$field = (string) ( self::spam_rules()['honeypot_field'] ?? '' );
			$field = self::spam_field_name( (string) apply_filters( 'ifi_honeypot_field', $field ) );
			return '' !== $field ? $field : self::HONEYPOT;
		} finally {
			self::$honeypot_resolving = false;
		}
	}

	/**
	 * 表单契约字段：蜜罐字段名不能与它们重名。
	 *
	 * 与必填字段重名时每条提交都报 400，看得见；与 pageTitle、timezone 这类通常非空的
	 * 可选字段重名，则会把每一条正常询盘都当成机器人——静默丢光，所以直接不认。
	 *
	 * @return string[]
	 */
	private static function spam_reserved_fields(): array {
		return array(
			'fullName',
			'email',
			'message',
			'browserLanguage',
			'timezone',
			'pageTitle',
			'pageTitleEn',
			'pageUrl',
			'formVariant',
			'locale',
			'fieldLabels',
		);
	}

	/**
	 * 字段名归一：只留 `[A-Za-z0-9_-]`；清洗后为空、或与表单字段重名时返回空串。
	 *
	 * 与 handle() 收集字段时的清洗规则一致，否则设置页填的名字永远命中不了提交的键。
	 * 不额外截断长度：采集那边也不截，两边口径必须一样。
	 */
	private static function spam_field_name( string $field ): string {
		$field = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', trim( $field ) );

		if ( '' === $field || in_array( $field, self::spam_reserved_fields(), true ) ) {
			return '';
		}

		return $field;
	}

	/**
	 * 保存时的清洗：复选框归一成 0/1，三个清单按行拆分、去空、去重。
	 *
	 * 无效的 IP / 域名**不丢弃**——原样留着，页面上会提示「这些行永远不会命中」，
	 * 站长自己看得见才改得动；静默删掉只会让人以为规则生效了。
	 */
	public static function sanitize_spam_rules( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$raw   = trim( (string) ( $input['honeypot_field'] ?? '' ) );

		// 填了却用不了（非法字符，或与表单字段重名）：存回默认值，并在页面上说明原因。
		// 静默改掉会让站长以为新名字已经生效，而蜜罐其实还叫 companyWebsite。
		if ( '' !== $raw && '' === self::spam_field_name( $raw ) && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPT_SPAM,
				'ifi_honeypot_field',
				sprintf( self::t( 'spam_bad_field' ), $raw, self::HONEYPOT ),
				'warning'
			);
		}

		return self::normalize_spam_rules( $input );
	}

	/** 规则结构归一：读取、保存、页面试跑三条路径共用，索引与类型永远对得上 */
	private static function normalize_spam_rules( array $rules ): array {
		$honeypot_field = self::spam_field_name( (string) ( $rules['honeypot_field'] ?? '' ) );
		if ( '' === $honeypot_field ) {
			$honeypot_field = self::HONEYPOT;
		}

		$domains = array();
		foreach ( self::spam_lines( $rules['domains'] ?? array() ) as $domain ) {
			// 允许写成 @example.com：手抄 DNSBL 或邮箱后缀时很常见
			$domains[] = ltrim( strtolower( $domain ), '@' );
		}

		// 关键词按不区分大小写去重（匹配本来就不区分），保留第一次出现的写法
		$keywords = array();
		$seen     = array();
		foreach ( self::spam_lines( $rules['keywords'] ?? array() ) as $word ) {
			$key = mb_strtolower( $word );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$keywords[]   = $word;
		}

		return array(
			'honeypot'       => empty( $rules['honeypot'] ) ? '0' : '1',
			'honeypot_field' => $honeypot_field,
			'archive'        => empty( $rules['archive'] ) ? '0' : '1',
			'keywords'       => $keywords,
			'domains'        => array_values( array_unique( $domains ) ),
			'ips'            => self::spam_lines( $rules['ips'] ?? array() ),
			'max_links'      => max( 0, (int) ( $rules['max_links'] ?? 0 ) ),
			'duplicate'      => max( 0, (int) ( $rules['duplicate'] ?? 0 ) ),
			'rate_minute'    => max( 0, (int) ( $rules['rate_minute'] ?? self::RATE_MINUTE_LIMIT ) ),
			'rate_hour'      => max( 0, (int) ( $rules['rate_hour'] ?? self::RATE_HOUR_LIMIT ) ),
		);
	}

	/** 多行文本 → 清单：数组或 textarea 字符串都收，去空白、去空行、去重 */
	private static function spam_lines( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}
		$lines = array();
		foreach ( (array) $value as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}
			$line = trim( sanitize_text_field( (string) $line ) );
			if ( $line !== '' ) {
				$lines[] = $line;
			}
		}
		return array_values( array_unique( $lines ) );
	}

	/* ================================================================ 匹配 */

	/**
	 * 内容规则：按顺序过一遍，返回第一条命中的规则。
	 *
	 * 只读。重复提交这里只查不记——记账放在提交成功之后（spam_remember），
	 * 否则发信失败、访客重试时，会被自己上一次失败的提交判成重复。
	 *
	 * @param array      $fields 已清洗的提交字段.
	 * @param array|null $rules  规则；测试页传表单里那份未保存的.
	 * @return array{reason:string, hit:string}
	 */
	private static function spam_verdict( array $fields, ?array $rules = null ): array {
		$rules = $rules ?? self::spam_rules();
		$texts = self::spam_scanned_text( $fields );
		$clean = array(
			'reason' => '',
			'hit'    => '',
		);

		// 关键词：任意文本字段里出现即命中（不区分大小写，子串匹配）
		if ( ! empty( $rules['keywords'] ) ) {
			foreach ( $texts as $text ) {
				foreach ( $rules['keywords'] as $word ) {
					if ( mb_stripos( $text, $word ) !== false ) {
						return array(
							'reason' => 'keyword',
							'hit'    => $word,
						);
					}
				}
			}
		}

		// 邮箱域名：example.com 同时命中 mail.example.com
		$domain = self::spam_email_domain( (string) ( $fields['email'] ?? '' ) );
		if ( $domain !== '' ) {
			foreach ( $rules['domains'] as $rule ) {
				if ( self::spam_domain_match( $domain, (string) $rule ) ) {
					return array(
						'reason' => 'domain',
						'hit'    => (string) $rule,
					);
				}
			}
		}

		// 链接数量：所有文本字段加起来超过上限
		$max_links = (int) $rules['max_links'];
		if ( $max_links > 0 ) {
			$links = 0;
			foreach ( $texts as $text ) {
				$links += self::spam_link_count( $text );
			}
			if ( $links > $max_links ) {
				return array(
					'reason' => 'links',
					'hit'    => (string) $links,
				);
			}
		}

		// 重复提交：窗口内同邮箱 + 同正文
		if ( (int) $rules['duplicate'] > 0 ) {
			$key = self::spam_duplicate_key( $fields );
			if ( $key !== '' && get_transient( $key ) ) {
				return array(
					'reason' => 'duplicate',
					'hit'    => '',
				);
			}
		}

		/**
		 * 自定义规则：返回带 reason / hit 的数组即可接入同一套处置与统计。
		 *
		 * @param array $verdict 空结果.
		 * @param array $fields  已清洗的提交字段.
		 * @param array $rules   生效中的规则.
		 */
		$verdict = apply_filters( 'ifi_spam_verdict', $clean, $fields, $rules );
		if ( ! is_array( $verdict ) || empty( $verdict['reason'] ) ) {
			return $clean;
		}

		return wp_parse_args( $verdict, $clean );
	}

	/**
	 * 参与关键词与链接匹配的文本：所有非空字符串字段，
	 * 跳过环境数据（页面地址 / IP / 语言…）与**邮箱**。
	 *
	 * 邮箱单独交给域名规则：把它也丢进关键词扫描的话，一条 "info" 就会挡掉所有
	 * info@ 开头的正常询盘，`user@www.example.com` 还会被算成一条链接。
	 */
	private static function spam_scanned_text( array $fields ): array {
		$texts = array();
		foreach ( $fields as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) || $value === '' ) {
				continue;
			}
			if ( $key === 'email' || in_array( $key, self::META_FIELDS, true ) ) {
				continue;
			}
			$texts[] = $value;
		}
		return $texts;
	}

	/** 文本里的链接数：http(s):// 与 www. 各算一个 */
	private static function spam_link_count( string $text ): int {
		return (int) preg_match_all( '#(?:https?://|www\.)[^\s<>"\')\]]+#i', $text );
	}

	/** 邮箱的域名部分（最后一个 @ 之后） */
	private static function spam_email_domain( string $email ): string {
		$at = strrpos( $email, '@' );
		return $at === false ? '' : strtolower( trim( substr( $email, $at + 1 ) ) );
	}

	/** 域名是否命中规则：完全相等或落在它的子域下 */
	private static function spam_domain_match( string $domain, string $rule ): bool {
		$rule = ltrim( strtolower( trim( $rule ) ), '@' );
		if ( $domain === '' || $rule === '' ) {
			return false;
		}
		return $domain === $rule || str_ends_with( $domain, '.' . $rule );
	}

	/** 请求是否来自黑名单网络（在限流之前调用，见 IFI_REST_Trait::handle） */
	private static function spam_ip_blocked( ?array $rules = null ): bool {
		return self::spam_ip_hit( self::client_ip(), $rules ?? self::spam_rules() );
	}

	/** 单个 IP 是否落在某份规则清单里 */
	private static function spam_ip_hit( string $ip, array $rules ): bool {
		if ( $ip === '' || $ip === 'unknown' || empty( $rules['ips'] ) ) {
			return false;
		}
		foreach ( $rules['ips'] as $rule ) {
			if ( self::spam_ip_match( $ip, (string) $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 单个 IP 与单条规则比对：精确地址，或 CIDR（IPv4 / IPv6 都支持）。
	 *
	 * 用 inet_pton() 转成二进制再比：字符串比较会把 2001:db8::1 与 2001:0db8::1
	 * 当成两个地址，压缩写法一多就漏。
	 */
	private static function spam_ip_match( string $ip, string $rule ): bool {
		$rule = trim( $rule );
		$addr = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 非法输入返回 false，下面统一处理。
		if ( $addr === false || $rule === '' ) {
			return false;
		}

		if ( strpos( $rule, '/' ) === false ) {
			$target = @inet_pton( $rule ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $target !== false && $addr === $target;
		}

		list( $subnet, $bits ) = explode( '/', $rule, 2 );
		$bits                  = (int) $bits;
		$network               = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $network === false || strlen( $addr ) !== strlen( $network ) ) {
			return false;
		}
		if ( $bits < 0 || $bits > strlen( $addr ) * 8 ) {
			return false;
		}

		$bytes = intdiv( $bits, 8 );
		if ( $bytes > 0 && substr( $addr, 0, $bytes ) !== substr( $network, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( $rest === 0 ) {
			return true;
		}

		$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
		return ( $addr[ $bytes ] & $mask ) === ( $network[ $bytes ] & $mask );
	}

	/** 校验一条 IP 规则能否生效（页面用它提示写错的行） */
	private static function spam_ip_valid( string $rule ): bool {
		$rule = trim( $rule );
		if ( $rule === '' ) {
			return false;
		}
		if ( strpos( $rule, '/' ) === false ) {
			return @inet_pton( $rule ) !== false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		list( $subnet, $bits ) = explode( '/', $rule, 2 );
		if ( ! ctype_digit( $bits ) ) {
			return false;
		}
		$network = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $network === false ) {
			return false;
		}
		return (int) $bits <= strlen( $network ) * 8;
	}

	/** 校验一条邮箱域名规则能否生效 */
	private static function spam_domain_valid( string $rule ): bool {
		$rule = ltrim( strtolower( trim( $rule ) ), '@' );
		// 至少要有一个点：写 "gmail" 这种既拦不到目标域，又让人误以为生效
		return preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $rule ) === 1;
	}

	/* ================================================================ 处置 */

	/**
	 * 命中规则后的留档。
	 *
	 * archive 关闭时直接丢弃，连记录都不建；开着则进 Spam 视图，
	 * 供后台核对与统计。两种情况都不发信、都不写发信状态。
	 *
	 * @param array  $fields 原始参数（store_spam 自己清洗）.
	 * @param string $reason 规则标识.
	 * @param string $detail 命中细节（关键词 / 域名 / 链接数），仅供排查.
	 */
	private static function spam_archive( array $fields, string $reason, string $detail = '' ): void {
		if ( ! self::spam_rule_on( 'archive' ) ) {
			return;
		}
		$post_id = self::store_spam( $fields, $reason );
		if ( $post_id > 0 && $detail !== '' ) {
			update_post_meta( $post_id, 'ifi_inquiry_spam_detail', $detail );
		}
	}

	/** 提交成功后记一笔，供重复提交规则识别（窗口内同邮箱 + 同正文 = 重复） */
	private static function spam_remember( array $fields ): void {
		$window = (int) self::spam_rules()['duplicate'];
		if ( $window <= 0 ) {
			return;
		}
		$key = self::spam_duplicate_key( $fields );
		if ( $key !== '' ) {
			set_transient( $key, time(), $window * MINUTE_IN_SECONDS );
		}
	}

	/** 重复提交的指纹：邮箱 + 正文（空白折叠，避免多打几个换行就绕过） */
	private static function spam_duplicate_key( array $fields ): string {
		$email   = mb_strtolower( trim( (string) ( $fields['email'] ?? '' ) ) );
		$message = trim( (string) ( $fields['message'] ?? '' ) );
		if ( $email === '' || $message === '' ) {
			return '';
		}
		$message = (string) preg_replace( '/\s+/u', ' ', $message );
		return 'ifi_dup_' . md5( $email . '|' . $message );
	}

	/* ================================================================ 后台文案 */

	/** 规则标识 => 显示名（列表徽章、统计条、试跑结果共用） */
	private static function spam_reasons(): array {
		return array(
			'honeypot'  => self::t( 'spam_reason_honeypot' ),
			'keyword'   => self::t( 'spam_reason_keyword' ),
			'domain'    => self::t( 'spam_reason_domain' ),
			'links'     => self::t( 'spam_reason_links' ),
			'duplicate' => self::t( 'spam_reason_duplicate' ),
			'ip'        => self::t( 'spam_reason_ip' ),
		);
	}

	/** 让列表页把 meta 里的规则标识翻成可读文案（未知标识原样显示，兼容旧数据） */
	public static function spam_reason_label( string $reason ): string {
		$labels = self::spam_reasons();
		return (string) ( $labels[ $reason ] ?? $reason );
	}

	/** 命中细节的可读写法：关键词带引号、域名带前缀、链接数带单位 */
	private static function spam_hit_detail( string $reason, string $hit ): string {
		switch ( $reason ) {
			case 'keyword':
				/* translators: Placeholders must be preserved. */
				return sprintf( self::t( 'spam_detail_keyword' ), $hit );
			case 'domain':
				/* translators: Placeholders must be preserved. */
				return sprintf( self::t( 'spam_detail_domain' ), $hit );
			case 'links':
				/* translators: Placeholders must be preserved. */
				return sprintf( self::t( 'spam_detail_links' ), number_format_i18n( (int) $hit ) );
		}
		return '';
	}

	/**
	 * 近 N 天各规则拦下多少条（按 ifi_inquiry_spam 分组）。
	 *
	 * 只统计留在 Spam 视图里的记录：IP 黑名单是到门口就丢、不留档的，不在其中。
	 */
	private static function spam_block_counts( int $days = 30 ): array {
		global $wpdb;

		// post_date 存的是站点本地时间，截止时间也按本地时间算
		$since = current_datetime()->modify( '-' . max( 1, $days ) . ' days' )->format( 'Y-m-d H:i:s' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.meta_value AS reason, COUNT(*) AS total
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'ifi_inquiry_spam'
                 WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_date >= %s
                 GROUP BY m.meta_value",
				self::POST_TYPE,
				$since
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 统计条一次分组查询。

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row->reason ] = (int) $row->total;
		}
		return $counts;
	}
}
