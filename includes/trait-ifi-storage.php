<?php
/**
 * IFI_Storage_Trait — Inquiry Form Inbox for Headless WordPress.
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Storage_Trait {


	/**
	 * 把被规则拦截的提交留档为垃圾记录。
	 *
	 * 不发通知、不发自动确认，只在后台 Spam 视图可见；字段按普通提交的清洗规则处理，
	 * 但只保留最小集（姓名 / 邮箱 / 消息 / 来源 / 语言 / IP）。
	 *
	 * 正文走 build_body() 用当前通知模板渲染，和普通询盘同款：后台 View 打开时看到的是
	 * 同一张邮件卡片，而不是一段裸文本。渲染不等于发信——这里只写库。
	 */
	public static function store_spam( array $fields, string $reason ): int {
		$text = static function ( string $key ) use ( $fields ): string {
			$value = $fields[ $key ] ?? '';
			return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
		};

		$message = isset( $fields['message'] ) && is_string( $fields['message'] )
			? sanitize_textarea_field( wp_unslash( $fields['message'] ) )
			: '';
		$name    = $text( 'fullName' );
		$email   = sanitize_email( $text( 'email' ) );
		$no      = self::next_inquiry_no();
		$ip      = self::client_ip();

		$clean = array(
			'fullName'        => $name,
			'email'           => $email,
			'message'         => $message,
			'formVariant'     => $text( 'formVariant' ),
			'pageUrl'         => esc_url_raw( $text( 'pageUrl' ) ),
			'pageTitle'       => $text( 'pageTitle' ),
			'pageTitleEn'     => $text( 'pageTitleEn' ),
			'browserLanguage' => $text( 'browserLanguage' ),
			'timezone'        => self::field_timezone( $fields ),
		);
		$body  = self::build_body( $clean, array(), null, $no, 'notify', time(), $ip );

		// 和 store_inquiry() 一样，只对这一次写入放行模板里的内联样式
		$ifi_preserve = static function () use ( $body ) {
			return $body;
		};
		add_filter( 'content_save_pre', $ifi_preserve, 100 );

		$post_id = wp_insert_post(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'post_title'     => $name !== '' ? $name : self::t( 'error_unknown_sender' ),
				'post_content'   => $body,
				'meta_input'     => array(
					'ifi_inquiry_email'         => $email,
					'ifi_inquiry_variant'       => $clean['formVariant'],
					'ifi_inquiry_page_url'      => $clean['pageUrl'],
					'ifi_inquiry_page_title'    => $clean['pageTitle'],
					'ifi_inquiry_page_title_en' => $clean['pageTitleEn'],
					'ifi_inquiry_lang'          => $clean['browserLanguage'],
					'ifi_inquiry_timezone'      => $clean['timezone'],
					'ifi_inquiry_ip'            => $ip,
					'ifi_inquiry_no'            => $no,
					'ifi_inquiry_spam'          => $reason,
					'ifi_inquiry_fields'        => array( 'fullName', 'email', 'message' ),
					'ifi_inquiry_field_values'  => array(
						'fullName' => $name,
						'email'    => $email,
						'message'  => $message,
					),
				),
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);

		remove_filter( 'content_save_pre', $ifi_preserve, 100 );

		return is_wp_error( $post_id ) ? 0 : (int) $post_id;
	}


	/**
	 * 一条询盘的 meta 契约（正式入库与演示数据共用，避免两处各写一份）。
	 *
	 * 改这里的键就是改数据模型：详情、列表、隐私导出、迁移都按这些键读。
	 *
	 * @param array  $fields        已净化的提交字段.
	 * @param string $ifi_inquiry_no 询盘编号.
	 * @return array<string, mixed>
	 */
	private static function inquiry_meta( array $fields, string $ifi_inquiry_no ): array {
		return array(
			'ifi_inquiry_email'         => (string) ( $fields['email'] ?? '' ),
			'ifi_inquiry_variant'       => (string) ( $fields['formVariant'] ?? '' ),
			'ifi_inquiry_locale'        => (string) ( $fields['locale'] ?? '' ),
			'ifi_inquiry_page_url'      => (string) ( $fields['pageUrl'] ?? '' ),
			'ifi_inquiry_page_title'    => (string) ( $fields['pageTitle'] ?? '' ),
			'ifi_inquiry_page_title_en' => (string) ( $fields['pageTitleEn'] ?? '' ),
			'ifi_inquiry_ip'            => self::client_ip(),
			'ifi_inquiry_lang'          => (string) ( $fields['browserLanguage'] ?? '' ),
			'ifi_inquiry_timezone'      => self::field_timezone( $fields ),
			'ifi_inquiry_no'            => $ifi_inquiry_no,
			// 记录本条出现过哪些字段（供后台动态列出标签项），与是否传了标签无关
			'ifi_inquiry_fields'        => array_values( array_diff( array_keys( $fields ), self::META_FIELDS ) ),
			'ifi_inquiry_field_labels'  => (array) ( $fields['fieldLabels'] ?? array() ),
			// 字段值另存一份：预览要按「当前模板」重渲染，而正文是渲染死的，倒推不可靠
			'ifi_inquiry_field_values'  => self::storable_values( $fields ),
		);
	}

	private static function store_inquiry( array $fields, string $body_html, string $ifi_inquiry_no = '' ): int {
		$meta = self::inquiry_meta( $fields, $ifi_inquiry_no );

		// 正文由 build_body() 生成（用户值入库前已 esc_html）。核心 kses 会剥掉 rgba() 颜色，
		// 这里只对本插件这一次写入挂一个定向过滤器，不再全局 kses_remove_filters()。
		$ifi_preserve = static function ( $content ) use ( $body_html ) {
			return $body_html;
		};
		add_filter( 'content_save_pre', $ifi_preserve, 100 );
		$post_id = wp_insert_post(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				// 标题只存姓名：列表「姓名」列直接展示，邮箱有独立列
				'post_title'     => (string) ( $fields['fullName'] ?? self::t( 'error_unknown_sender' ) ),
				'post_content'   => $body_html,
				'meta_input'     => $meta,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		remove_filter( 'content_save_pre', $ifi_preserve, 100 );

		return is_wp_error( $post_id ) ? 0 : (int) $post_id;
	}

	/**
	 * 给早期记录补编号。
	 *
	 * ifi_inquiry_no 是后加的 meta，之前入库的询盘没有它，列表「编号」列就一直显示 —。
	 * 按提交时间顺序补号，并把当天的流水计数器推到已用最大值，免得下一封询盘撞号。
	 * 只跑一次（option 记账），之后完全零开销。
	 */
	public static function backfill_numbers(): void {
		if ( get_option( self::OPT_BACKFILL ) === '1' ) {
			return;
		}
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
                 WHERE p.post_type = %s AND m.meta_id IS NULL
                 ORDER BY p.post_date ASC, p.ID ASC",
				'ifi_inquiry_no',
				self::POST_TYPE
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration query.

		$used = array();
		foreach ( (array) $ids as $id ) {
			$id           = (int) $id;
			$day          = mysql2date( 'Ymd', (string) get_post_field( 'post_date', $id ) );
			$used[ $day ] = ( $used[ $day ] ?? 0 ) + 1;
			update_post_meta( $id, 'ifi_inquiry_no', self::NO_PREFIX . $day . str_pad( (string) $used[ $day ], 3, '0', STR_PAD_LEFT ) );
		}

		// 补出来的号已经占用了当天前 N 个流水位，计数器必须跟上
		foreach ( $used as $day => $count ) {
			$key = 'ifi_seq_' . $day;
			if ( (int) get_option( $key, 0 ) < $count ) {
				update_option( $key, $count, false );
			}
		}

		update_option( self::OPT_BACKFILL, '1', false );
	}

	/**
	 * 入库的字段值：只留提交上来的内容，环境字段与蜜罐不算「用户填的东西」。
	 *
	 * 预览要用这些值按当前模板重渲染，所以和正文分开存一份：
	 * 正文是渲染死的 HTML，改模板之后就再也还原不出原始值了。
	 */
	private static function storable_values( array $fields ): array {
		$values = array();
		foreach ( $fields as $field_key => $value ) {
			if ( ! is_string( $field_key ) || ! is_scalar( $value ) ) {
				continue;
			}
			if ( in_array( $field_key, self::META_FIELDS, true ) || $field_key === self::honeypot_field() ) {
				continue;
			}
			$values[ $field_key ] = (string) $value;
		}
		return $values;
	}

	/**
	 * 给早期记录补字段值（ifi_inquiry_field_values 是后加的 meta）。
	 *
	 * 这些记录只留了渲染好的正文，没有单独的字段值。正文里那张字段表是
	 * build_rows() 生成的固定结构（一行一个 th/td），按标签反查
	 * ifi_inquiry_field_labels 就知道这行属于哪个字段，认不出标签的行（附件清单等）跳过。
	 * 补不出来的记录写一个空数组占位，免得每轮后台请求都被重新扫一遍。
	 */
	public static function backfill_field_values(): void {
		if ( get_option( self::OPT_BACKFILL_VALUES ) === '1' ) {
			return;
		}

		$limit = 200;
		$ids   = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => 'ifi_inquiry_field_labels',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => 'ifi_inquiry_field_values',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$labels = (array) get_post_meta( $id, 'ifi_inquiry_field_labels', true );
			update_post_meta(
				$id,
				'ifi_inquiry_field_values',
				self::values_from_body( (string) get_post_field( 'post_content', $id ), $labels )
			);
		}

		// 这一轮已经把没有值的记录扫完了；还有下一批就等下次后台请求接着来
		if ( count( $ids ) < $limit ) {
			update_option( self::OPT_BACKFILL_VALUES, '1', false );
		}
	}

	/** 从留档正文里倒推字段值（见 backfill_field_values()） */
	private static function values_from_body( string $html, array $labels ): array {
		if ( ! preg_match_all( '#<tr>\s*<th[^>]*>(.*?)</th>\s*<td[^>]*>(.*?)</td>\s*</tr>#s', $html, $rows, PREG_SET_ORDER ) ) {
			return array();
		}

		$by_label = array();
		foreach ( $labels as $field_key => $label ) {
			if ( is_string( $field_key ) ) {
				$by_label[ self::plain_text( (string) $label ) ] = $field_key;
			}
		}

		$values = array();
		foreach ( $rows as $row ) {
			$label = self::plain_text( $row[1] );
			if ( $label !== '' && isset( $by_label[ $label ] ) ) {
				$values[ $by_label[ $label ] ] = self::plain_text( $row[2] );
			}
		}
		return $values;
	}

	/** 正文片段转纯文本：<br> 还原成换行，再去标签、解实体 */
	private static function plain_text( string $html ): string {
		$text = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/* ================================================================ 后台 */
}
