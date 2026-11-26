<?php
/**
 * IFI_REST_Trait — Inquiry Form Inbox for Headless WordPress.
 */

defined( 'ABSPATH' ) || exit;

trait IFI_REST_Trait {


	public static function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/inquiry', self::route_args() );
	}

	/**
	 * Route definition, shared by registration and by handle()'s "already sanitized" check.
	 *
	 * Keeping one source means a parameter can never be documented, validated and then
	 * re-sanitized as if it were still raw.
	 *
	 * @return array
	 */
	private static function route_args(): array {
		$text = array(
			'type'              => 'string',
			'required'          => false,
			'sanitize_callback' => 'sanitize_text_field',
		);

		/*
		 * Every parameter documented in INTEGRATION.md §2.2 is declared here. Declaring them
		 * is not just documentation: WordPress validates the type and runs the sanitizer
		 * before the callback, and handle() then skips its own pass over these keys.
		 * Undeclared keys still flow through — the plugin deliberately accepts whatever
		 * fields the frontend invents — but they get the generic text treatment.
		 */
		$args = array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle' ),
			// Public inquiry form: intentionally open. Honeypot and rate limits apply in handle().
			'permission_callback' => '__return_true',
			'args'                => array(
				'fullName'        => $text,
				'email'           => $text,
				'message'         => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'browserLanguage' => $text,
				'timezone'        => $text,
				'pageTitle'       => $text,
				'pageTitleEn'     => $text,
				'pageUrl'         => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'esc_url_raw',
				),
				'formVariant'     => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				),
				'locale'          => $text,
				'fieldLabels'     => array(
					'type'              => 'object',
					'required'          => false,
					'sanitize_callback' => array( __CLASS__, 'sanitize_field_labels' ),
				),
			),
		);

		// 蜜罐字段名可用 filter ifi_honeypot_field 改（前端要同步）；
		// 单独赋值而不是写进上面的数组字面量：函数调用当键会把整块对齐打乱
		$args['args'][ self::honeypot_field() ] = $text;

		return $args;
	}

	/**
	 * Clean the submitted fieldLabels map.
	 *
	 * Keys must match the frontend's field names, so they are reduced to the same character
	 * set the generic collector uses rather than run through sanitize_key(), which would
	 * lowercase fullName and break the contract.
	 *
	 * @param mixed $value Raw parameter value.
	 * @return array<string, string>
	 */
	public static function sanitize_field_labels( $value ): array {
		$clean = array();
		foreach ( (array) $value as $key => $label ) {
			if ( ! is_scalar( $label ) ) {
				continue;
			}
			$key   = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );
			$label = sanitize_text_field( wp_unslash( (string) $label ) );
			if ( $key !== '' && $label !== '' ) {
				$clean[ $key ] = $label;
			}
		}
		return $clean;
	}

	public static function handle( WP_REST_Request $request ) {
		// ---------- IP 黑名单：到门口就丢 ----------
		// 排在所有检查之前：命中的请求既不入库也不占限流配额，
		// 免得被拉黑的网络把 Spam 视图和限流窗口一起灌满。响应照旧装作成功。
		if ( self::spam_ip_blocked() ) {
			return new WP_REST_Response( array( 'sent' => true ), 200 );
		}

		$params         = $request->get_params();
		$honeypot_field = self::honeypot_field();

		// ---------- 蜜罐：非空即机器人，装作成功 ----------
		// 放在限流之前：蜜罐命中不该消耗真实访客的配额。记录以垃圾形式留档（不进通知/自动确认），
		// 供后台 Spam 视图与拦截统计使用。
		$honeypot = isset( $params[ $honeypot_field ] ) ? trim( (string) $params[ $honeypot_field ] ) : '';
		if ( $honeypot !== '' && self::spam_rule_on( 'honeypot' ) ) {
			self::spam_archive( $params, 'honeypot' );
			return new WP_REST_Response( array( 'sent' => true ), 200 );
		}

		// ---------- 限流：1 分钟 3 次 / 1 小时 10 次 ----------
		// 标识优先用链路里的可信地址；拿不到时用服务端签发的 HttpOnly 访客 cookie，
		// 避免把经同一出口访问的访客算成一个人。
		$retry_after = self::rate_consume( self::rate_identity() );
		if ( $retry_after > 0 ) {
			$response = new WP_REST_Response(
				array(
					'code'        => 'inquiry_rate_limited',
					'message'     => self::t( 'error_rate_limited' ),
					'retry_after' => $retry_after,
				),
				429
			);
			$response->header( 'Retry-After', (string) $retry_after );
			return $response;
		}

		// ---------- 字段收集 ----------
		// 已声明的参数由 WordPress 在进入本回调前就校验并净化过，这里不再二次净化
		// （二次净化会把已经 esc_url_raw 过的地址再当普通文本处理一遍）。
		$declared = array_keys( self::route_args()['args'] );
		$fields   = array();
		foreach ( $params as $name => $value ) {
			if ( $name === $honeypot_field || ( is_array( $value ) && isset( $value['tmp_name'] ) ) ) {
				continue;
			}
			// 不能用 sanitize_key()：会把 fullName 转成 fullname，破坏字段契约
			$name = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $name );

			// 嵌套字段（如 fieldLabels[fullName]=姓名）逐个清洗
			if ( is_array( $value ) ) {
				$clean = array();
				foreach ( $value as $sub_key => $sub_value ) {
					if ( ! is_string( $sub_value ) ) {
						continue;
					}
					$sub_key   = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $sub_key );
					$sub_value = sanitize_text_field( wp_unslash( $sub_value ) );
					if ( $sub_key !== '' && $sub_value !== '' ) {
						$clean[ $sub_key ] = $sub_value;
					}
				}
				$value = $clean;
			} elseif ( is_string( $value ) && ! in_array( $name, $declared, true ) ) {
				$value = sanitize_textarea_field( wp_unslash( $value ) );
			}

			if ( $name !== '' && $value !== '' && $value !== null && $value !== array() ) {
				$fields[ $name ] = $value;
			}
		}

		// 环境数据（浏览器语言 / 时区）先验一遍；前端自报的 ip 直接丢弃，改用服务端识别
		$fields = self::normalize_env_fields( $fields );

		// 文本长度上限（与前端 maxlength 一致）：超长截断，防止绕过前端的请求灌大文本
		$length_limits = (array) apply_filters(
			'ifi_length_limits',
			array(
				'fullName'    => 80,
				'email'       => 254,
				'message'     => 2000,
				'application' => 100,
				'pageTitle'   => 200,
				'pageTitleEn' => 200,
			)
		);
		foreach ( $length_limits as $key => $max ) {
			if ( isset( $fields[ $key ] ) && is_string( $fields[ $key ] ) && mb_strlen( $fields[ $key ] ) > $max ) {
				$fields[ $key ] = mb_substr( $fields[ $key ], 0, $max );
			}
		}

		/**
		 * 必填字段：默认 fullName / email / message（本站契约）。
		 * 其它前端可以整组换掉，插件本身不假设字段结构。
		 */
		$required_fields = (array) apply_filters( 'ifi_required_fields', array( 'fullName', 'email', 'message' ) );
		foreach ( $required_fields as $required ) {
			if ( empty( $fields[ $required ] ) ) {
				return new WP_Error(
					'inquiry_missing_field',
					sprintf( self::t( 'error_missing_field' ), $required ),
					array( 'status' => 400 )
				);
			}
		}
		if ( ! isset( $fields['email'] ) || ! is_email( $fields['email'] ) ) {
			return new WP_Error( 'inquiry_invalid_email', self::t( 'error_invalid_email' ), array( 'status' => 400 ) );
		}
		// 路由层不能挂 sanitize_email：它会把 "not-an-email" 直接清成空串，
		// 于是「格式不对」被报成「缺必填字段」。先原文接住，验证通过后再规整。
		$fields['email'] = sanitize_email( $fields['email'] );

		// ---------- 垃圾规则：关键词 / 邮箱域名 / 链接数 / 重复提交 ----------
		// 放在附件暂存之前：被拦下的提交不该先把文件搬进临时目录。
		$verdict = self::spam_verdict( $fields );
		if ( $verdict['reason'] !== '' ) {
			self::spam_archive( $fields, $verdict['reason'], $verdict['hit'] );
			return new WP_REST_Response( array( 'sent' => true ), 200 );
		}

		// ---------- 附件校验并落到临时目录 ----------
		try {
			$staged = self::stage_attachments( $request->get_file_params() ); // array( name, tmp_path, size )
		} catch ( RuntimeException $e ) {
			// 抛错时可能有文件已经挪进暂存目录（同批里前几个合法、后面一个非法），
			// 这里必须先清理再返回，否则临时目录会一直堆着没人认领的文件。
			self::cleanup_stage();
			return new WP_Error( 'inquiry_bad_upload', $e->getMessage(), array( 'status' => 400 ) );
		}

		// ---------- 组邮件 ----------
		$now            = time(); // 本次提交的时间戳：通知与自动确认共用，跨零点也不打架
		$to             = apply_filters( 'ifi_recipient', get_option( 'admin_email' ) );
		$tpl            = self::tpl();
		$ifi_inquiry_no = self::next_inquiry_no(); // 20261105001
		$subject        = self::build_subject( $fields, $tpl );
		$body           = self::build_body( $fields, $staged, null, $ifi_inquiry_no, 'notify', $now );
		$headers        = array( 'Content-Type: text/html; charset=UTF-8' );

		// ---------- 留档入库（先入库，附件才好归到记录名下） ----------
		$record_id = self::store_inquiry( $fields, $body, $ifi_inquiry_no );

		// ---------- 附件挪进留档目录（持久化；发信与后台下载都用这份） ----------
		$stored = $record_id ? self::persist_attachments( $staged, $record_id ) : $staged;
		if ( $record_id ) {
			update_post_meta( $record_id, 'ifi_inquiry_attachments', $stored );
		}

		// ---------- 发信：先发通知，再回访客 ----------
		$sent  = self::mail( $to, $subject, $body, $headers, wp_list_pluck( $stored, 'path' ) );
		$error = self::$mail_error;

		if ( $record_id ) {
			update_post_meta( $record_id, 'ifi_inquiry_mail_sent', $sent ? 'yes' : 'no' );
			if ( ! $sent && $error !== '' ) {
				// 失败原因留在记录上，后台详情的「发信」一行会显示出来
				update_post_meta( $record_id, 'ifi_inquiry_mail_error', $error );
			}
		}

		// 自动确认与通知互不牵连：通知挂了也要让访客收到「已收到」，
		// 反之通知成功也不代表自动确认成功，两条状态各记各的。
		// 返回 null = 功能未启用，此时不写 meta，后台显示「—」而不是「失败」。
		$replied = self::send_autoreply( $fields, $ifi_inquiry_no, $now );
		if ( $record_id && $replied !== null ) {
			update_post_meta( $record_id, 'ifi_inquiry_autoreply_sent', $replied ? 'yes' : 'no' );
			if ( $replied === false && self::$mail_error !== '' ) {
				update_post_meta( $record_id, 'ifi_inquiry_autoreply_error', self::$mail_error );
			}
		}

		// 清理暂存目录（文件已被挪走或发信完毕）；
		// 挪不动的文件（persist 失败退回暂存路径）要保留，否则存档里的附件指向空文件
		self::cleanup_stage( wp_list_pluck( $stored, 'path' ) );

		if ( ! $sent ) {
			return new WP_Error(
				'inquiry_mail_failed',
				self::t( 'error_mail_failed' ),
				array( 'status' => 500 )
			);
		}

		// 真正交付成功才记重复提交的指纹：发信失败时访客重试，不该被自己上一次的失败挡住
		self::spam_remember( $fields );

		return new WP_REST_Response( array( 'sent' => true ), 200 );
	}

	/* ================================================================ 附件 */
}
