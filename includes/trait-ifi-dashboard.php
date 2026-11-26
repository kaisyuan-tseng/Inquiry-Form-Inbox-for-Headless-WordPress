<?php
/**
 * IFI_Dashboard_Trait — Inquiry Form Inbox for Headless WordPress.
 *
 * - 询盘列表页顶部的概览看板：4 张 KPI 卡（总数 / 新询盘 / 发信 / 近 7 天）+ 3 张明细卡（语言 / 表单类型 / 来源），可折叠；
 * - WordPress 仪表盘上的「最近询盘」挂件：最近几条记录与逐封发信状态。
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Dashboard_Trait {


	/* ------------------------------------------------------------ 列表页看板 */

	/**
	 * 列表页顶部的概览看板，挂在 admin_notices 上（只在询盘列表屏输出）。
	 *
	 * 结构上先落在页头，再由 admin.js 挪到标题下方（.wp-header-end 之后）——
	 * edit.php 在标题与列表之间没有可用的 PHP 钩子，这是唯一能稳定落到「顶部」的位置。
	 * 折叠状态由 admin.js 记在 localStorage，翻页/刷新后保持。
	 */
	public static function list_board(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'edit-' . self::POST_TYPE ) {
			return;
		}
		if ( ! current_user_can( Inquiry_Form_Inbox::CAP_VIEW ) ) {
			return;
		}

		// 与「来源」卡一致：语言/形态多取一些行交给 CSS，界面只露出前三行，其余滚动查看
		$today    = self::today_count();
		$pending  = self::pending_count();
		$langs    = self::locale_stats();
		$variants = self::variant_stats();
		$sources  = self::meta_stats( 'ifi_inquiry_page_title_en', 500 );

		// 迷你图：近 14 天每日新增（已排除垃圾记录）
		$daily14  = self::daily_series( 14 );
		$labels14 = self::daily_labels( 14 );

		// 第二行：今年月度趋势
		$months       = self::monthly_series();
		$month_labels = self::month_labels();
		$month_total  = array_sum( $months );
		$month_peak   = max( 1, max( $months ) );

		// 垃圾卡：近 30 天拦截量 + 每日柱状图
		$spam_series = self::spam_daily_series( 30 );
		$labels30    = self::daily_labels( 30 );
		$spam30      = array_sum( $spam_series );
		$spam_today  = (int) ( $spam_series[ count( $spam_series ) - 1 ] ?? 0 );
		$spam_peak   = max( 1, max( $spam_series ) );

		$failed14 = self::daily_failed_series( 14 );
		// 「忽略未发送警告」是全局开关：勾选后任何未发送成功的邮件都不计入异常，
		// 与「启用自动确认」是否开启无关；不勾选时通知与自动确认的失败都要警告。
		$autoreply_cfg = self::tpl( 'autoreply' );
		$ignore_unsent = (string) ( $autoreply_cfg['ignore_unsent'] ?? '0' ) === '1';
		$notify_failed = self::failed_count( 'ifi_inquiry_mail_sent' );
		$reply_failed  = self::failed_count( 'ifi_inquiry_autoreply_sent' );
		$unsent        = self::board_unsent( $autoreply_cfg );

		$all_url = self::list_url();
		$new_url = add_query_arg( 'ifi_status', 'pending', $all_url );
		?>
		<section class="ifi-board" id="ifi-board">
			<div class="ifi-board__body" id="ifi-board-body">
				<div class="ifi-board__kpis">
					<div class="ifi-board__card ifi-board__card--kpi">
						<?php echo self::area_chart( $daily14, $labels14, '#2271b1', 'ifi-g-daily' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已转义。 ?>
						<div class="ifi-board__kpi">
							<h3 class="ifi-board__card-title"><?php echo esc_html( self::t( 'dash_daily_title' ) ); ?></h3>
							<p class="ifi-board__num"><?php echo esc_html( number_format_i18n( $today ) ); ?></p>
						</div>
					</div>

					<div class="ifi-board__card ifi-board__card--kpi">
						<div class="ifi-board__kpi">
							<h3 class="ifi-board__card-title"><?php echo esc_html( self::t( 'dash_pending_title' ) ); ?></h3>
							<p class="ifi-board__num"><?php echo esc_html( number_format_i18n( $pending ) ); ?></p>
							<p class="ifi-board__hint">
								<a href="<?php echo esc_url( $new_url ); ?>"><?php echo esc_html( self::t( 'dash_view' ) ); ?></a>
							</p>
						</div>
					</div>

					<div class="ifi-board__card ifi-board__card--kpi">
						<div class="ifi-board__spark ifi-board__spark--corner" aria-hidden="true">
							<?php foreach ( $spam_series as $index => $value ) : ?>
								<span class="<?php echo $index === count( $spam_series ) - 1 ? 'is-last' : ''; ?>"
									data-label="<?php echo esc_attr( ( $labels30[ $index ] ?? '' ) . ' · ' . (int) $value ); ?>"
									style="height:<?php echo $value > 0 ? (int) max( 8, round( $value / $spam_peak * 100 ) ) : 0; ?>%"></span>
							<?php endforeach; ?>
						</div>
						<div class="ifi-board__kpi">
							<h3 class="ifi-board__card-title"><?php echo esc_html( self::t( 'dash_spam_title' ) ); ?></h3>
							<p class="ifi-board__num"><?php echo esc_html( number_format_i18n( $spam30 ) ); ?></p>
							<p class="ifi-board__hint">
								<?php if ( $spam_today > 0 ) : ?>
									<span class="ifi-board__warn"><?php echo esc_html( sprintf( self::t( 'dash_spam_today' ), $spam_today ) ); ?></span>
								<?php else : ?>
									<span class="ifi-board__ok"><?php echo esc_html( self::t( 'dash_spam_none_today' ) ); ?></span>
								<?php endif; ?>
							</p>
						</div>
					</div>

					<div class="ifi-board__card ifi-board__card--kpi">
						<?php echo self::area_chart( $failed14, $labels14, '#d63638', 'ifi-g-mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="ifi-board__kpi">
							<h3 class="ifi-board__card-title"><?php echo esc_html( $unsent > 0 ? self::t( 'dash_email_problem' ) : self::t( 'dash_email_delivered' ) ); ?></h3>
							<p class="ifi-board__num<?php echo $unsent > 0 ? ' is-warn' : ''; ?>"><?php echo esc_html( number_format_i18n( $unsent ) ); ?></p>
							<p class="ifi-board__hint">
								<?php if ( $unsent > 0 ) : ?>
									<span><?php echo esc_html( sprintf( self::t( 'dash_failed_notify' ), $notify_failed ) ); ?></span>
									<span aria-hidden="true">·</span>
									<span><?php echo esc_html( sprintf( self::t( 'dash_failed_reply' ), $reply_failed ) ); ?></span>
								<?php elseif ( $ignore_unsent ) : ?>
									<span><?php echo esc_html( self::t( 'dash_unsent_ignored' ) ); ?></span>
								<?php else : ?>
									<span class="ifi-board__ok"><?php echo esc_html( self::t( 'dash_all_delivered' ) ); ?></span>
								<?php endif; ?>
							</p>
						</div>
					</div>
				</div>

				<div class="ifi-board__details">
					<div class="ifi-board__card ifi-board__card--monthly">
						<div class="ifi-board__card-head">
							<h3 class="ifi-board__card-title"><?php echo esc_html( self::t( 'dash_monthly_title' ) ); ?></h3>
						</div>
						<?php
						// 本月与上月的提交量对比：把环比挂在年度总数后面
						$month_index = (int) current_time( 'n' );
						$month_now   = (int) ( $months[ $month_index - 1 ] ?? 0 );
						$month_prev  = $month_index > 1 ? (int) ( $months[ $month_index - 2 ] ?? 0 ) : 0;
						?>
						<p class="ifi-board__month-num">
							<?php echo esc_html( number_format_i18n( $month_total ) ); ?>
							<?php if ( $month_prev > 0 ) : ?>
								<?php echo self::delta_html( $month_now, $month_prev, 0, self::t( 'dash_vs_last_month' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已转义。 ?>
								<span class="ifi-board__month-vs"><?php echo esc_html( self::t( 'dash_vs_last_month' ) ); ?></span>
							<?php endif; ?>
						</p>
						<div class="ifi-board__spark ifi-board__spark--months" aria-hidden="true">
							<?php $current_month = $month_index - 1; ?>
							<?php foreach ( $months as $index => $value ) : ?>
								<span class="<?php echo $index === $current_month ? 'is-last' : ''; ?>"
									data-month="<?php echo esc_attr( $month_labels[ $index ] ?? '' ); ?>"
									data-label="<?php echo esc_attr( ( $month_labels[ $index ] ?? '' ) . ' · ' . (int) $value ); ?>"
									style="height:<?php echo $value > 0 ? (int) max( 6, round( $value / $month_peak * 100 ) ) : 0; ?>%"></span>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="ifi-board__card">
						<div class="ifi-board__card-head">
							<h3 class="ifi-board__card-title"><?php echo esc_html( self::t( 'dash_sources_title' ) ); ?></h3>
						</div>
						<?php if ( ! $sources['rows'] ) : ?>
							<p class="ifi-board__hint">—</p>
						<?php else : ?>
							<ul class="ifi-board__rows ifi-board__scroll">
								<?php foreach ( $sources['rows'] as $index => $row ) : ?>
									<?php $swatch = $index < 5 ? self::palette_color( $index ) : '#c3c4c7'; ?>
									<li class="ifi-board__row">
										<?php if ( $row['value'] !== '' ) : ?>
											<a class="ifi-board__row-label" href="<?php echo esc_url( add_query_arg( 'ifi_inquiry_source', $row['value'], $all_url ) ); ?>" title="<?php echo esc_attr( $row['value'] ); ?>"><span class="ifi-board__swatch" style="background:<?php echo esc_attr( $swatch ); ?>"></span><?php echo esc_html( $row['value'] ); ?></a>
										<?php else : ?>
											<span class="ifi-board__row-label"><span class="ifi-board__swatch" style="background:<?php echo esc_attr( $swatch ); ?>"></span>—</span>
										<?php endif; ?>
										<span class="ifi-board__value">
											<span class="ifi-board__count"><?php echo esc_html( number_format_i18n( $row['total'] ) ); ?></span>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<div class="ifi-board__donut-wrap">
							<?php echo self::donut_html( array_slice( $sources['rows'], 0, 5 ), $sources['total'], self::t( 'dash_sources_title' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</div>

					<div class="ifi-board__card">
						<div class="ifi-board__card-head">
							<h3 class="ifi-board__card-title"><?php echo esc_html( self::t( 'dash_langs_title' ) ); ?></h3>
						</div>
						<?php if ( ! $langs['rows'] ) : ?>
							<p class="ifi-board__hint">—</p>
						<?php else : ?>
							<?php // 与「来源」一致：前三行可见，更多语言滚动查看（不再用「另有 N 种」占一行） ?>
							<ul class="ifi-board__rows ifi-board__scroll">
								<?php foreach ( $langs['rows'] as $index => $row ) : ?>
									<li class="ifi-board__row">
										<span class="ifi-board__row-label"><span class="ifi-board__swatch" style="background:<?php echo esc_attr( self::palette_color( $index ) ); ?>"></span><?php echo esc_html( $row['value'] !== '' ? $row['value'] : '—' ); ?></span>
										<span class="ifi-board__value">
											<span class="ifi-board__count"><?php echo esc_html( number_format_i18n( $row['total'] ) ); ?></span>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<div class="ifi-board__donut-wrap">
							<?php echo self::donut_html( array_slice( $langs['rows'], 0, 5 ), $langs['total'], self::t( 'dash_langs_title' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</div>

					<div class="ifi-board__card ifi-board__card--formtype">
						<div class="ifi-board__card-head">
							<h3 class="ifi-board__card-title"><?php echo esc_html( self::t( 'dash_variant_title' ) ); ?></h3>
						</div>
						<?php if ( $variants['total'] <= 0 ) : ?>
							<?php // 没有任何询盘时与「来源」「语言」两张卡一致：只留一个破折号，不铺一排 0。 ?>
							<p class="ifi-board__hint">—</p>
						<?php else : ?>
							<ul class="ifi-board__rows ifi-board__scroll">
								<?php foreach ( $variants['rows'] as $index => $row ) : ?>
									<li class="ifi-board__row">
										<span class="ifi-board__row-label"><span class="ifi-board__swatch" style="background:<?php echo esc_attr( self::palette_color( $index ) ); ?>"></span><?php echo esc_html( $row['label'] ); ?></span>
										<span class="ifi-board__count"><?php echo esc_html( number_format_i18n( $row['total'] ) ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
							<?php
							// 每段一个形态，最后一段吃掉四舍五入的余数，
							// 条形始终正好 100%，不会因为逐段 round 留下 1% 的缝。
							$segments      = $variants['rows'];
							$segment_count = count( $segments );
							$used_pct      = 0;
							$bar_aria      = array();
							foreach ( $segments as $index => $segment ) {
								$segments[ $index ]['pct'] = $index === $segment_count - 1
									? max( 0, 100 - $used_pct )
									: (int) round( $segment['total'] / $variants['total'] * 100 );
								$used_pct                 += $segments[ $index ]['pct'];
								$bar_aria[]                = sprintf( '%s %d%%', $segment['label'], $segments[ $index ]['pct'] );
							}
							?>
							<div class="ifi-board__bar" role="img" aria-label="<?php echo esc_attr( implode( ', ', $bar_aria ) ); ?>">
								<?php foreach ( $segments as $index => $segment ) : ?>
									<span class="ifi-board__bar-seg"
										data-label="<?php echo esc_attr( sprintf( '%s · %d (%d%%)', $segment['label'], $segment['total'], $segment['pct'] ) ); ?>"
										style="width:<?php echo (int) $segment['pct']; ?>%;background:<?php echo esc_attr( $segment['color'] ?? self::palette_color( $index ) ); ?>">
										<?php // 占比太小就不标数字：形态多了以后，几个「1%」会挤在条上糊成一片 ?>
										<?php if ( $segment['pct'] >= 10 ) : ?>
											<span class="ifi-board__bar-pct"><?php echo esc_html( $segment['pct'] . '%' ); ?></span>
										<?php endif; ?>
									</span>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</section>
		<?php
	}

	/** 迷你面积图（SVG）+ 每个数据点的悬停热区（显示日期与数值） */
	private static function area_chart( array $series, array $labels, string $color, string $id ): string {
		$count = count( $series );
		if ( $count < 2 ) {
			return '';
		}
		$max = max( 1, max( $series ) );

		$points = array();
		foreach ( $series as $index => $value ) {
			$x        = round( $index / ( $count - 1 ) * 100, 2 );
			$y        = round( 38 - ( $value / $max ) * 32, 2 );
			$points[] = $x . ',' . $y;
		}
		$line = implode( ' ', $points );
		$area = '0,40 ' . $line . ' 100,40';

		$svg = '<svg class="ifi-board__area" viewBox="0 0 100 40" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
			. '<defs><linearGradient id="' . esc_attr( $id ) . '" x1="0" y1="0" x2="0" y2="1">'
			. '<stop offset="0" stop-color="' . esc_attr( $color ) . '" stop-opacity=".22"/>'
			. '<stop offset="1" stop-color="' . esc_attr( $color ) . '" stop-opacity="0"/>'
			. '</linearGradient></defs>'
			. '<polygon points="' . esc_attr( $area ) . '" fill="url(#' . esc_attr( $id ) . ')"/>'
			. '<polyline points="' . esc_attr( $line ) . '" fill="none" stroke="' . esc_attr( $color )
			. '" stroke-width="1.5" vector-effect="non-scaling-stroke"/></svg>';

		$hits = '';
		foreach ( $series as $index => $value ) {
			$x     = round( $index / ( $count - 1 ) * 100, 2 );
			$y     = round( 5 + ( $value / $max ) * 80, 2 );
			$hits .= '<span style="left:' . $x . '%;bottom:' . $y . '%" data-label="'
				. esc_attr( ( $labels[ $index ] ?? '' ) . ' · ' . (int) $value ) . '"></span>';
		}

		return $svg . '<div class="ifi-board__hit" aria-hidden="true">' . $hits . '</div>';
	}

	/** 近 N 天的日期标签（M j），给迷你图的悬停提示用 */
	private static function daily_labels( int $days ): array {
		$labels = array();
		$from   = current_datetime()->modify( '-' . ( $days - 1 ) . ' days' );
		for ( $i = 0; $i < $days; $i++ ) {
			$labels[] = wp_date( self::date_format(), $from->modify( '+' . $i . ' days' )->getTimestamp() );
		}
		return $labels;
	}

	/**
	 * 按某个 meta 分组统计提交量（来源、访客语言共用）。
	 *
	 * @param string $meta_key meta 键.
	 * @param int    $limit    卡片里最多列几行，其余用「另有 N 个」收尾.
	 * @return array{rows: array<int, array{value: string, total: int}>, more: int}
	 */
	private static function meta_stats( string $meta_key, int $limit = 5 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS value, COUNT(*) AS total
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish'
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} sp
                       WHERE sp.post_id = p.ID AND sp.meta_key = 'ifi_inquiry_spam'
                   )
                 GROUP BY pm.meta_value
                 ORDER BY total DESC, pm.meta_value ASC",
				$meta_key,
				self::POST_TYPE
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 列表页看板的分组统计。

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'value' => (string) $row->value,
				'total' => (int) $row->total,
			);
		}

		return array(
			'rows'  => array_slice( $out, 0, $limit ),
			'more'  => max( 0, count( $out ) - $limit ),
			'total' => array_sum( array_column( $out, 'total' ) ),
		);
	}

	/**
	 * 访客语言分布：meta 里存的是浏览器标签（`en-US`），卡片上按界面语言显示「英语（美国）」。
	 *
	 * 与来源卡同一条 SQL，只是把值换成可读名；认不出的标签原样留着，至少还能看出是哪种语言。
	 *
	 * @return array{rows: array<int, array{value: string, total: int}>, more: int, total: int}
	 */
	private static function locale_stats(): array {
		$stats   = self::meta_stats( 'ifi_inquiry_lang', 500 );
		$display = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : determine_locale();
		$display = $display ? $display : get_locale();

		foreach ( $stats['rows'] as $index => $row ) {
			if ( '' !== $row['value'] ) {
				$stats['rows'][ $index ]['value'] = IFI_Locale::label( $row['value'], (string) $display );
			}
		}

		return $stats;
	}

	/** 今年 12 个月的提交量（老 → 新，已排除垃圾） */
	private static function monthly_series(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT MONTH(p.post_date) AS m, COUNT(*) AS total
                 FROM {$wpdb->posts} p
                 WHERE p.post_type = %s AND p.post_status = 'publish'
                   AND YEAR(p.post_date) = %d
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} sp
                       WHERE sp.post_id = p.ID AND sp.meta_key = 'ifi_inquiry_spam'
                   )
                 GROUP BY MONTH(p.post_date)",
				self::POST_TYPE,
				(int) current_time( 'Y' )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 列表页看板的月度统计。

		$series = array_fill( 1, 12, 0 );
		foreach ( (array) $rows as $row ) {
			$month = (int) $row->m;
			if ( $month >= 1 && $month <= 12 ) {
				$series[ $month ] = (int) $row->total;
			}
		}

		return array_values( $series );
	}

	/**
	 * 12 个月的坐标轴标签。
	 *
	 * 后台界面语言跟站点语言可以不同（站点 en_US、管理员 zh_CN 很常见），而全局
	 * `$wp_locale` 在 wp-settings.php 里初始化得早，拿到的仍是站点语言——于是中文界面
	 * 的迷你图标着 Jan / Feb。这里按**当前界面语言**取：中文用 WP 自己的「1 月」口径，
	 * 其它语言沿用月份缩写。
	 */
	private static function month_labels(): array {
		$locale = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : determine_locale();
		$locale = $locale ? $locale : get_locale();

		if ( 0 === stripos( $locale, 'zh' ) ) {
			$labels = array();
			for ( $month = 1; $month <= 12; $month++ ) {
				$labels[] = sprintf( '%d 月', $month );
			}
			return $labels;
		}

		$wp_locale = $GLOBALS['wp_locale'] ?? null;
		if ( ! $wp_locale instanceof WP_Locale ) {
			$wp_locale = new WP_Locale();
		}

		$labels = array();
		for ( $month = 1; $month <= 12; $month++ ) {
			$labels[] = $wp_locale->get_month_abbrev( $wp_locale->get_month( $month ) );
		}
		return $labels;
	}

	/** 环形图配色：WordPress 后台色板（蓝 → 浅蓝 → 灰阶），与列表页主色一致 */
	private static function palette_color( int $index ): string {
		$palette = array( '#2271b1', '#72aee6', '#8c8f94', '#a7aaad', '#c3c4c7' );
		return $palette[ $index % count( $palette ) ];
	}

	/**
	 * 环形（扇形）迷你图：SVG 描边弧线，每段可悬停查看名称、数量与占比；
	 * 环外只标出占比最高的三个分段（各自 ≥10%）的百分比，中心不显示总数。
	 *
	 * @param array<int, array{value: string, total: int}> $rows  前 N 行数据.
	 * @param int                                          $total 全部行数之和.
	 * @param string                                       $label 无障碍名称.
	 */
	private static function donut_html( array $rows, int $total, string $label ): string {
		if ( $total <= 0 || ! $rows ) {
			return '';
		}

		$segments = '';
		$marks    = '';
		$legend   = array();
		$offset   = 0.0;
		$marked   = 0;

		foreach ( $rows as $index => $row ) {
			$length    = (int) $row['total'] / $total * 100;
			$pct       = (int) round( $length );
			$segments .= self::donut_segment( $length, $offset, self::palette_color( $index ), $row['value'] . ' · ' . (int) $row['total'] . ' (' . $pct . '%)' );
			if ( $pct >= 10 && $marked < 3 ) {
				$marks .= self::donut_pct_mark( $offset + $length / 2, $pct );
				++$marked;
			}
			$offset  += $length;
			$legend[] = $row['value'] . ' ' . (int) $row['total'];
		}

		if ( $offset < 99.9 ) {
			$rest      = max( 0, $total - array_sum( array_column( $rows, 'total' ) ) );
			$rest_pct  = (int) round( $rest / $total * 100 );
			$segments .= self::donut_segment( 100 - $offset, $offset, '#c3c4c7', self::t( 'dash_other' ) . ' · ' . $rest . ' (' . $rest_pct . '%)' );
			$legend[]  = self::t( 'dash_other' ) . ' ' . $rest;
		}

		return '<span class="ifi-donut" role="img" aria-label="' . esc_attr( $label . ': ' . implode( ', ', $legend ) ) . '">'
			. '<svg viewBox="-10 -10 62 62" aria-hidden="true" focusable="false">'
			. '<circle class="ifi-donut__track" cx="21" cy="21" r="15.9155" fill="none" stroke="#dcdcde" stroke-width="8"/>'
			. $segments // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已转义。
			. $marks    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已转义。
			. '</svg></span>';
	}

	/**
	 * 环外的百分比标注：按弧段中点角度落在圆环外侧（半径 26，与环留出间距）。
	 *
	 * @param float $angle 从 12 点方向顺时针的百分比位置（0–100）.
	 * @param int   $pct   四舍五入后的百分比.
	 */
	private static function donut_pct_mark( float $angle, int $pct ): string {
		$rad = deg2rad( $angle * 3.6 - 90 );
		$x   = 21 + 26 * cos( $rad );
		$y   = 21 + 26 * sin( $rad );

		return '<text class="ifi-donut__pct" x="' . esc_attr( round( $x, 2 ) ) . '" y="' . esc_attr( round( $y, 2 ) )
			. '" text-anchor="middle" dominant-baseline="central">' . esc_html( $pct . '%' ) . '</text>';
	}

	/** 环形图的一段弧线（周长按 100 计，长度即百分比） */
	private static function donut_segment( float $length, float $offset, string $color, string $label ): string {
		return sprintf(
			'<circle class="ifi-donut__seg" cx="21" cy="21" r="15.9155" fill="none" stroke="%s" stroke-width="8"'
			. ' stroke-dasharray="%.2f %.2f" stroke-dashoffset="%.2f" transform="rotate(-90 21 21)" data-label="%s"></circle>',
			esc_attr( $color ),
			$length,
			max( 0, 100 - $length ),
			-$offset,
			esc_attr( $label )
		);
	}

	/**
	 * 最近 N 天每天收到的询盘数（老 → 新），给迷你图用。
	 *
	 * @return int[]
	 */
	private static function daily_series( int $days = 7 ): array {
		global $wpdb;

		$start = current_datetime()->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d 00:00:00' );
		$end   = current_datetime()->modify( '+1 day' )->format( 'Y-m-d 00:00:00' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(p.post_date) AS day, COUNT(*) AS total
                 FROM {$wpdb->posts} p
                 WHERE p.post_type = %s AND p.post_status = 'publish'
                   AND p.post_date >= %s AND p.post_date < %s
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} sp
                       WHERE sp.post_id = p.ID AND sp.meta_key = 'ifi_inquiry_spam'
                   )
                 GROUP BY DATE(p.post_date)",
				self::POST_TYPE,
				$start,
				$end
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 列表页看板的迷你图。

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row->day ] = (int) $row->total;
		}

		$series = array();
		$from   = current_datetime()->modify( '-' . ( $days - 1 ) . ' days' );
		for ( $i = 0; $i < $days; $i++ ) {
			$key      = $from->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			$series[] = $map[ $key ] ?? 0;
		}

		return $series;
	}

	/**
	 * 最近 N 天每天「通知发送失败」的询盘数（老 → 新），给发信卡片的迷你图用。
	 *
	 * @return int[]
	 */
	private static function daily_failed_series( int $days = 14 ): array {
		global $wpdb;

		$start = current_datetime()->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d 00:00:00' );
		$end   = current_datetime()->modify( '+1 day' )->format( 'Y-m-d 00:00:00' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(p.post_date) AS day, COUNT(*) AS total
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'no'
                 WHERE p.post_type = %s AND p.post_status = 'publish'
                   AND p.post_date >= %s AND p.post_date < %s
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} sp
                       WHERE sp.post_id = p.ID AND sp.meta_key = 'ifi_inquiry_spam'
                   )
                 GROUP BY DATE(p.post_date)",
				'ifi_inquiry_mail_sent',
				self::POST_TYPE,
				$start,
				$end
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 列表页看板的迷你图。

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row->day ] = (int) $row->total;
		}

		$series = array();
		$from   = current_datetime()->modify( '-' . ( $days - 1 ) . ' days' );
		for ( $i = 0; $i < $days; $i++ ) {
			$key      = $from->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			$series[] = $map[ $key ] ?? 0;
		}

		return $series;
	}

	/**
	 * 表单形态分布：只统计 form_variants() 登记的五种，没有「其他」也没有自定义 slug 行。
	 *
	 * 空值按页面表单算（与 recorded_form_type() 同一口径）。前端自造、后台没登记的
	 * 形态仍然原样入库、列表「来源」列照常显示，只是不进这张卡——免得一个陌生 slug
	 * 在后台看起来像第六种官方形态。
	 *
	 * @return array{rows: array<int, array{key: string, label: string, total: int}>, total: int}
	 */
	private static function variant_stats(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS variant, COUNT(*) AS total
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish'
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} sp
                       WHERE sp.post_id = p.ID AND sp.meta_key = 'ifi_inquiry_spam'
                   )
                 GROUP BY pm.meta_value",
				'ifi_inquiry_variant',
				self::POST_TYPE
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 列表页看板的表单类型统计。

		$variants = self::form_variants();
		$counts   = array_fill_keys( array_keys( $variants ), 0 );

		foreach ( (array) $rows as $row ) {
			$variant = (string) $row->variant;
			$count   = (int) $row->total;
			if ( '' === $variant ) {
				$counts['page'] = ( $counts['page'] ?? 0 ) + $count;
			} elseif ( isset( $counts[ $variant ] ) ) {
				$counts[ $variant ] += $count;
			}
			// 没登记的 slug 不进这张卡：列表里照常显示原值
		}

		$out = array();
		foreach ( array_keys( $variants ) as $key ) {
			$key = (string) $key;
			if ( '' === $key || ( $counts[ $key ] ?? 0 ) <= 0 ) {
				continue;
			}
			$out[] = array(
				'key'   => $key,
				'label' => self::variant_label( $key ),
				'total' => (int) $counts[ $key ],
			);
		}

		return array(
			'rows'  => $out,
			'total' => array_sum( $counts ),
		);
	}

	/** 环比百分比：涨绿、跌红、持平/无基数灰；上一个窗口为 0 时显示 — */
	private static function delta_html( int $current, int $previous, int $days, ?string $label = null ): string {
		if ( $previous <= 0 ) {
			return '<span class="ifi-board__delta is-flat">—</span>';
		}

		$percent = (int) round( ( $current - $previous ) / $previous * 100 );
		$class   = $percent > 0 ? 'is-up' : ( $percent < 0 ? 'is-down' : 'is-flat' );
		$arrow   = $percent > 0 ? '↑ ' : ( $percent < 0 ? '↓ ' : '' );
		$text    = $percent === 0 ? '0%' : sprintf( '%+d%%', $percent );
		$label   = $label ?? sprintf( self::t( 'dash_vs_prev' ), $days );

		return '<span class="ifi-board__delta ' . esc_attr( $class ) . '" title="'
			. esc_attr( $label ) . '">'
			. esc_html( $arrow . $text ) . '</span>';
	}

	/** 今天收到的真实询盘数（不含垃圾） */
	private static function today_count(): int {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'after'     => 'today midnight',
						'inclusive' => true,
					),
				),
				'meta_query'     => array(
					array(
						'key'     => 'ifi_inquiry_spam',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * 看板「邮件状态」的异常数。
	 *
	 * 「忽略未发送警告」是全局开关，只认自己的值：打开时任何未发送成功的邮件
	 * （通知或自动确认）都不计入，与「启用自动确认」是否开启无关。
	 *
	 * @param array $cfg 自动确认配置（含 ignore_unsent）.
	 */
	private static function board_unsent( array $cfg ): int {
		if ( (string) ( $cfg['ignore_unsent'] ?? '0' ) === '1' ) {
			return 0;
		}
		return self::unsent_count();
	}

	/** 通知或自动确认明确发送失败（'no'）的询盘数；'none'（自动确认未启用）不算异常 */
	private static function unsent_count(): int {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => 'ifi_inquiry_spam',
						'compare' => 'NOT EXISTS',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => 'ifi_inquiry_mail_sent',
							'value'   => 'no',
							'compare' => '=',
						),
						array(
							'key'     => 'ifi_inquiry_autoreply_sent',
							'value'   => 'no',
							'compare' => '=',
						),
					),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/** 某个发信状态 meta 明确为 Failed（'no'）的询盘数（不含垃圾） */
	private static function failed_count( string $meta_key ): int {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => 'ifi_inquiry_spam',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $meta_key,
						'value'   => 'no',
						'compare' => '=',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * 近 N 天每天被规则拦截的垃圾询盘数（老 → 新）。
	 *
	 * @return int[]
	 */
	private static function spam_daily_series( int $days = 30 ): array {
		global $wpdb;

		$start = current_datetime()->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d 00:00:00' );
		$end   = current_datetime()->modify( '+1 day' )->format( 'Y-m-d 00:00:00' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(p.post_date) AS day, COUNT(*) AS total
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'ifi_inquiry_spam'
                 WHERE p.post_type = %s AND p.post_status = 'publish'
                   AND p.post_date >= %s AND p.post_date < %s
                 GROUP BY DATE(p.post_date)",
				self::POST_TYPE,
				$start,
				$end
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 列表页看板的迷你图。

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row->day ] = (int) $row->total;
		}

		$series = array();
		$from   = current_datetime()->modify( '-' . ( $days - 1 ) . ' days' );
		for ( $i = 0; $i < $days; $i++ ) {
			$key      = $from->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			$series[] = $map[ $key ] ?? 0;
		}

		return $series;
	}

	/* ---------------------------------------------------------- 核心仪表盘挂件 */

	/** 仪表盘「最近询盘」小挂件：只有能看询盘列表的用户才显示 */
	public static function register_dashboard_widget(): void {
		if ( ! current_user_can( Inquiry_Form_Inbox::CAP_VIEW ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'ifi_recent',
			self::t( 'dash_title' ),
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	/**
	 * 仪表盘挂件：与列表页看板同一套读法——四个小指标 + 最近几条记录 + 一个入口。
	 *
	 * 上一版是「活动 → 最近发布」那套三列网格（日期列 / 名称 / 两个状态圆点），
	 * 站点有五种表单形态之后就说不清「这条从哪来」了；这里换成本插件自己的语言：
	 * 编号、姓名 + 邮箱、来源（带形态括注）、发信徽章、接收时间。
	 */
	public static function render_dashboard_widget(): void {
		self::enqueue_admin_assets();

		$limit = max( 1, (int) apply_filters( 'ifi_dashboard_limit', 5 ) );
		$posts = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'meta_query'  => array(
					array(
						'key'     => 'ifi_inquiry_spam',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$counts  = wp_count_posts( self::POST_TYPE );
		$total   = max( 0, (int) ( $counts->publish ?? 0 ) - self::spam_count() );
		$today   = self::today_count();
		$pending = self::pending_count();

		// 与看板同一个口径：开启「忽略未发送警告」后，任何未发送成功的邮件都不算异常
		$autoreply_cfg = self::tpl( 'autoreply' );
		$ignore_unsent = (string) ( $autoreply_cfg['ignore_unsent'] ?? '0' ) === '1';
		$unsent        = self::board_unsent( $autoreply_cfg );

		$all_url     = self::list_url();
		$pending_url = add_query_arg( 'ifi_status', 'pending', $all_url );
		$spam_url    = add_query_arg( 'ifi_status', 'spam', $all_url );

		$tiles = array(
			array(
				'label' => self::t( 'dash_daily_title' ),
				'value' => $today,
				'url'   => $all_url,
				'warn'  => false,
			),
			array(
				'label' => self::t( 'dash_pending_title' ),
				'value' => $pending,
				'url'   => $pending_url,
				'warn'  => false,
			),
			array(
				'label' => self::t( 'dash_spam_title' ),
				'value' => self::spam_count(),
				'url'   => $spam_url,
				'warn'  => false,
			),
			array(
				'label' => $unsent > 0 ? self::t( 'dash_email_problem' ) : self::t( 'dash_email_delivered' ),
				'value' => $unsent,
				'url'   => $all_url,
				'warn'  => $unsent > 0,
			),
		);
		?>
		<div class="ifi-widget">
			<ul class="ifi-widget__kpis">
				<?php foreach ( $tiles as $tile ) : ?>
					<li class="ifi-widget__kpi">
						<a class="ifi-widget__kpi-link" href="<?php echo esc_url( $tile['url'] ); ?>">
							<span class="ifi-widget__kpi-num<?php echo $tile['warn'] ? ' is-warn' : ''; ?>"><?php echo esc_html( number_format_i18n( (int) $tile['value'] ) ); ?></span>
							<span class="ifi-widget__kpi-label"><?php echo esc_html( (string) $tile['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( ! $posts ) : ?>
				<p class="ifi-widget__empty"><?php echo esc_html( self::t( 'dash_empty' ) ); ?></p>
			<?php else : ?>
				<ul class="ifi-widget__list">
					<?php
					foreach ( $posts as $item ) :
						$detail = add_query_arg(
							array(
								'post_type' => self::POST_TYPE,
								'ifi_view'  => $item->ID,
							),
							admin_url( 'edit.php' )
						);
						$no     = (string) get_post_meta( $item->ID, 'ifi_inquiry_no', true );
						$name   = (string) get_post_meta( $item->ID, 'ifi_inquiry_name', true );
						$name   = '' !== $name ? $name : $item->post_title;
						$email  = (string) get_post_meta( $item->ID, 'ifi_inquiry_email', true );
						$source = self::record_source_label( $item->ID );
						$sub    = implode( ' · ', array_filter( array( $email, $source ) ) );
						?>
						<li class="ifi-widget__item">
							<div class="ifi-widget__row">
								<?php if ( '' !== $no ) : ?>
									<span class="ifi-widget__no"><?php echo esc_html( $no ); ?></span>
								<?php endif; ?>
								<?php
								// .ifi-open 由 admin.js 接管：点姓名就在本页打开详情弹窗，不跳列表页。
								// href 仍指向列表页的 ifi_view 地址，作为无脚本时的兜底。
								?>
								<a class="ifi-widget__name ifi-open" href="<?php echo esc_url( $detail ); ?>" data-id="<?php echo (int) $item->ID; ?>"><?php echo esc_html( $name ); ?></a>
								<span class="ifi-widget__when"><?php echo esc_html( self::received_label( $item->ID ) ); ?></span>
							</div>
							<div class="ifi-widget__row ifi-widget__row--sub">
								<span class="ifi-widget__sub" title="<?php echo esc_attr( $sub ); ?>"><?php echo esc_html( $sub !== '' ? $sub : '—' ); ?></span>
								<?php echo self::mail_status_html( $item->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in mail_status_html(). ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p class="ifi-widget__foot">
				<a href="<?php echo esc_url( $all_url ); ?>"><?php echo esc_html( self::t( 'dash_all' ) ); ?> <span class="count">(<?php echo (int) $total; ?>)</span></a>
			</p>
		</div>
		<?php
	}
}
