<?php
/**
 * IFI_Rate_Limiter_Trait — Inquiry Form Inbox for Headless WordPress.
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Rate_Limiter_Trait {

	/**
	 * Read a $_SERVER entry as a sanitized, unslashed string.
	 *
	 * Request headers are untrusted input: PHP does not slash $_SERVER, but a header value
	 * can still carry stray escaping, and every direct read here was a PHPCS violation
	 * waiting to be re-reviewed. Funnelling all of them through one helper keeps the
	 * sanitization visible and impossible to forget.
	 *
	 * @param string $key Server array key, e.g. 'REMOTE_ADDR'.
	 * @return string Empty string when absent or not scalar.
	 */
	private static function server_string( string $key ): string {
		if ( ! isset( $_SERVER[ $key ] ) || ! is_scalar( $_SERVER[ $key ] ) ) {
			return '';
		}
		return trim( sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) );
	}

	/**
	 * Proxy IPs that are allowed to set X-Forwarded-For / X-Real-IP.
	 *
	 * 两种配法，效果一样：
	 *   - wp-config.php：define( 'IFI_TRUSTED_PROXIES', array( '203.0.113.10' ) );
	 *   - 任意插件：add_filter( 'ifi_trusted_proxies', fn() => array( '203.0.113.10' ) );
	 * filter 优先，常量作为「不想为一行配置再装一个 mu-plugin」时的出口。
	 */
	private static function trusted_proxies(): array {
		$configured = defined( 'IFI_TRUSTED_PROXIES' ) ? (array) constant( 'IFI_TRUSTED_PROXIES' ) : array();
		$proxies    = apply_filters( 'ifi_trusted_proxies', $configured );
		if ( ! is_array( $proxies ) ) {
			return array();
		}
		return array_values( array_map( 'strval', array_filter( $proxies, 'is_scalar' ) ) );
	}

	/**
	 * 请求是否经过一台「不在白名单里的代理」。
	 *
	 * 主站把 /wp-json 反代到 WordPress（SiteGround 的 Node 应用就是这么干的）时，
	 * REMOTE_ADDR 是代理自己的地址，转发头里才是访客地址。代理没进白名单，
	 * 就既不能采信转发头，也不能拿 REMOTE_ADDR 当访客身份——否则全站访客
	 * 共用一个限流桶，第 4 个访客起一律 429。
	 */
	private static function behind_untrusted_proxy(): bool {
		$remote = self::server_string( 'REMOTE_ADDR' );
		if ( '' !== $remote && in_array( $remote, self::trusted_proxies(), true ) ) {
			return false;
		}

		return '' !== self::server_string( 'HTTP_X_FORWARDED_FOR' )
			|| '' !== self::server_string( 'HTTP_X_REAL_IP' );
	}

	/**
	 * Resolve the chain IP without trusting arbitrary client headers.
	 *
	 * X-Forwarded-For is written as "client, proxy1, proxy2": the leftmost entry is
	 * whatever the visitor sent, so it can be rotated to escape the rate limiter.
	 * Only the hops added by our own proxies are trustworthy, which means walking the
	 * chain from the server side (right to left) and skipping configured proxies.
	 */
	private static function chain_ip(): string {
		$remote = self::server_string( 'REMOTE_ADDR' );
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$trusted = self::trusted_proxies();
		if ( ! in_array( $remote, $trusted, true ) ) {
			return $remote;
		}

		$hops      = array();
		$forwarded = self::server_string( 'HTTP_X_FORWARDED_FOR' );

		if ( '' !== $forwarded ) {
			foreach ( explode( ',', $forwarded ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( '' !== $candidate && filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$hops[] = $candidate;
				}
			}
		} else {
			// X-Real-IP has no chain semantics; it is only a fallback for one-hop proxies.
			$real_ip = self::server_string( 'HTTP_X_REAL_IP' );
			if ( '' !== $real_ip && filter_var( $real_ip, FILTER_VALIDATE_IP ) ) {
				$hops[] = $real_ip;
			}
		}

		$hops[] = $remote;

		for ( $i = count( $hops ) - 1; $i >= 0; $i-- ) {
			if ( ! in_array( $hops[ $i ], $trusted, true ) ) {
				return $hops[ $i ];
			}
		}

		return $remote;
	}

	/**
	 * Signed visitor cookie used only when no routable chain IP is available.
	 */
	private static function visitor_cookie_id(): string {
		$raw   = isset( $_COOKIE[ self::RATE_VISITOR_COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::RATE_VISITOR_COOKIE ] ) ) : '';
		$salt  = wp_salt( 'auth' );
		$parts = explode( '.', $raw, 2 );

		if (
			2 === count( $parts )
			&& preg_match( '/^[a-f0-9]{32}$/', $parts[0] )
			&& hash_equals( hash_hmac( 'sha256', $parts[0], $salt ), $parts[1] )
		) {
			return $parts[0];
		}

		$vid       = bin2hex( random_bytes( 16 ) );
		$signature = hash_hmac( 'sha256', $vid, $salt );
		if ( ! headers_sent() ) {
			setcookie(
				self::RATE_VISITOR_COOKIE,
				$vid . '.' . $signature,
				array(
					'expires'  => time() + DAY_IN_SECONDS,
					'path'     => '/',
					'httponly' => true,
					'secure'   => is_ssl(),
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::RATE_VISITOR_COOKIE ] = $vid . '.' . $signature;
		return $vid;
	}

	private static function rate_identity(): string {
		$ip       = self::chain_ip();
		$routable = $ip !== '' && filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);

		if ( $routable && ! self::behind_untrusted_proxy() ) {
			return 'ip_' . $ip;
		}
		return 'vid_' . self::visitor_cookie_id();
	}

	/**
	 * 生效的限流窗口：次数上限与窗口长度（秒）。
	 *
	 * 次数来自 Block Rules 页（`rate_minute` / `rate_hour`），窗口长度是固定的 60s / 3600s。
	 * 站点仍可以用 `ifi_rate_limits` 过滤器整份替换——包括 `ttl`，比如把「每小时」改成
	 * 滚动 6 小时。每一项都会重新清洗：`limit` <= 0 表示关掉该窗口（页面上填 0 同理），
	 * `ttl` 小于 1 退回默认值——配置写错一个数字不该把整站提交锁死。
	 *
	 * @return array<string, array{limit: int, ttl: int}>
	 */
	private static function rate_limits(): array {
		$rules    = self::spam_rules();
		$defaults = array(
			'minute' => array(
				'limit' => (int) $rules['rate_minute'],
				'ttl'   => self::RATE_MINUTE_TTL,
			),
			'hour'   => array(
				'limit' => (int) $rules['rate_hour'],
				'ttl'   => self::RATE_HOUR_TTL,
			),
		);

		$filtered = apply_filters( 'ifi_rate_limits', $defaults );
		if ( ! is_array( $filtered ) ) {
			return $defaults;
		}

		$limits = array();
		foreach ( $defaults as $name => $fallback ) {
			$window          = is_array( $filtered[ $name ] ?? null ) ? $filtered[ $name ] : array();
			$limits[ $name ] = array(
				'limit' => max( 0, (int) ( $window['limit'] ?? $fallback['limit'] ) ),
				'ttl'   => max( 1, (int) ( $window['ttl'] ?? $fallback['ttl'] ) ),
			);
		}

		return $limits;
	}

	/**
	 * 固定窗口双限流：默认 1 分钟 3 次 + 1 小时 10 次。
	 *
	 * transient 里存 ['start' => 窗口起点, 'count' => 次数]，是否过期按 start 判断，
	 * 不依赖每次 set_transient 被续写的 TTL（否则窗口会无限滑动、永不恢复）。
	 * 超限的请求本身不计数，否则被拒的请求会把窗口一直顶住。
	 * 返回 0 = 放行（已计数）；>0 = 还要等待的秒数。
	 */
	private static function rate_consume( string $identity ): int {
		$now     = time();
		$hash    = md5( $identity );
		$windows = self::rate_limits();
		$states  = array();
		$retry   = 0;

		foreach ( $windows as $name => $cfg ) {
			// limit = 0：过滤器显式关掉了这个窗口
			if ( (int) $cfg['limit'] <= 0 ) {
				continue;
			}
			$key   = 'ifi_rl_' . $name . '_' . $hash;
			$state = get_transient( $key );
			if ( ! is_array( $state ) || ! isset( $state['start'], $state['count'] )
				|| $now - (int) $state['start'] >= $cfg['ttl'] ) {
				$state = array(
					'start' => $now,
					'count' => 0,
				);
			}
			$states[ $key ] = array(
				'state' => $state,
				'ttl'   => $cfg['ttl'],
			);
			if ( (int) $state['count'] >= $cfg['limit'] ) {
				$retry = max( $retry, $cfg['ttl'] - ( $now - (int) $state['start'] ) );
			}
		}

		if ( $retry > 0 ) {
			return max( 1, $retry );
		}

		foreach ( $states as $key => $item ) {
			$state          = $item['state'];
			$state['count'] = (int) $state['count'] + 1;
			set_transient( $key, $state, $item['ttl'] );
		}
		return 0;
	}

	/**
	 * 提交者 IP：**只认服务端识别**，前端上报的一律忽略。
	 * 是否采信代理头由可信代理配置决定，避免客户端伪造头污染日志。
	 * 限流标识另算（见 rate_identity()）：优先可信链路地址，回环时改用访客 cookie。
	 */
	private static function client_ip(): string {
		$ip = self::chain_ip();
		return $ip !== '' ? $ip : 'unknown';
	}

	/**
	 * 环境字段清洗（浏览器语言 / 时区）。
	 *
	 * 这两项由前端采集、可信度低于服务端，入库前必须验一遍：
	 * 语言按 BCP47 粗校验（en、zh-CN、zh-Hant-TW…），时区交给 PHP 的时区库；
	 * 不合格的整条丢掉 —— 邮件里宁可空着，也不要写进一个假的 Asia/Shanghia。
	 * 顺手丢掉前端自报的 `ip`：IP 一律以服务端识别为准（见 client_ip()）。
	 */
	private static function normalize_env_fields( array $fields ): array {
		if ( isset( $fields['browserLanguage'] ) ) {
			$lang                      = trim( (string) $fields['browserLanguage'] );
			$fields['browserLanguage'] = preg_match( '/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{1,8})*$/', $lang )
				? substr( $lang, 0, 35 )
				: '';
		}
		if ( isset( $fields['timezone'] ) ) {
			$tz                 = trim( (string) $fields['timezone'] );
			$fields['timezone'] = @timezone_open( $tz ) !== false ? substr( $tz, 0, 64 ) : '';
		}
		unset( $fields['ip'] );

		return array_filter(
			$fields,
			static function ( $value ) {
				return $value !== '' && $value !== null && $value !== array();
			}
		);
	}

	/** 访客时区（IANA 标识）：缺失或不合法时返回空串，模板里就不会多出一行空标签 */
	private static function field_timezone( array $fields ): string {
		$tz = trim( (string) ( $fields['timezone'] ?? '' ) );
		return ( $tz !== '' && @timezone_open( $tz ) !== false ) ? $tz : '';
	}

	/** 按访客时区渲染的当地时间；没有时区时返回空串 */
	private static function local_time_label( string $tz, string $format = 'Y-m-d H:i:s T', int $timestamp = 0 ): string {
		if ( $tz === '' ) {
			return '';
		}
		return (string) wp_date( $format, $timestamp > 0 ? $timestamp : time(), new DateTimeZone( $tz ) );
	}

	/* ================================================================ 邮件 */

	/** 邮件统一字体：与 WordPress 后台一致的系统字体栈（不依赖任何 Web 字体） */
	private static function font_stack(): string {
		return "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;";
	}

	/**
	 * 字段表样式：对齐 WordPress 后台表单的观感——标签列深色加粗、数值列次级灰，
	 * 只保留横向分隔线（#f0f0f1，后台列表分隔线色）。邮件客户端只认内联样式，所以逐条写死。
	 */
	private static function th_style(): string {
		return 'text-align:left;vertical-align:top;padding:11px 16px 11px 0;'
			. 'border-bottom:1px solid #f0f0f1;width:110px;font-size:13px;color:#1d2327;'
			. 'font-weight:600;line-height:1.6;' . self::font_stack();
	}

	private static function td_style(): string {
		return 'vertical-align:top;padding:11px 0;border-bottom:1px solid #f0f0f1;'
			. 'font-size:14px;color:#3c434a;line-height:1.7;word-break:break-word;' . self::font_stack();
	}

	/* ================================================================ 邮件模板 */
}
