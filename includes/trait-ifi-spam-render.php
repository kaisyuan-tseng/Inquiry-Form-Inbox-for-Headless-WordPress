<?php
/**
 * IFI_Spam_Render_Trait — Inquiry Form Inbox for Headless WordPress.
 *
 * 「Block Rules」设置页：左侧规则表单，右侧提交试跑。
 * 视觉沿用邮件模板页（template.css 的页头 / 卡片 / 字段 / 按钮），
 * 本页只额外加载 spam.css。
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Spam_Render_Trait {


	public static function register_spam_page(): void {
		add_submenu_page(
			self::LIST_SLUG,
			self::t( 'spam_menu' ),
			self::t( 'spam_menu' ),
			'manage_options',
			self::SPAM_SLUG,
			array( __CLASS__, 'render_spam_page' )
		);
	}

	public static function render_spam_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( self::t( 'error_forbidden' ) ), '', array( 'response' => 403 ) );
		}

		self::enqueue_spam_assets();

		$rules = self::spam_rules();
		$test  = null;
		$input = array(
			'name'    => '',
			'email'   => '',
			'message' => '',
			'ip'      => '',
		);

		// 试跑按钮把整张表单 POST 回本页（formaction），所以测的就是眼前这份规则，
		// 包括还没保存的改动；点了「保存」也不会丢。
		if ( isset( $_POST['ifi_spam_test'] ) ) {
			check_admin_referer( 'ifi_spam_group-options' );

			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 每一项下面都单独清洗。
			$posted = isset( $_POST[ self::OPT_SPAM ] ) ? wp_unslash( (array) $_POST[ self::OPT_SPAM ] ) : array();
			$input  = array(
				'name'    => sanitize_text_field( wp_unslash( (string) ( $_POST['ifi_test_name'] ?? '' ) ) ),
				'email'   => sanitize_email( wp_unslash( (string) ( $_POST['ifi_test_email'] ?? '' ) ) ),
				'message' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['ifi_test_message'] ?? '' ) ) ),
				'ip'      => sanitize_text_field( wp_unslash( (string) ( $_POST['ifi_test_ip'] ?? '' ) ) ),
			);
			// phpcs:enable

			$rules = self::normalize_spam_rules( $posted );
			$test  = self::spam_test_result( $input, $rules );
		}

		$counts = self::spam_block_counts( 30 );
		$total  = array_sum( $counts );

		$invalid_ips = array();
		foreach ( $rules['ips'] as $rule ) {
			if ( ! self::spam_ip_valid( (string) $rule ) ) {
				$invalid_ips[] = (string) $rule;
			}
		}
		$invalid_domains = array();
		foreach ( $rules['domains'] as $rule ) {
			if ( ! self::spam_domain_valid( (string) $rule ) ) {
				$invalid_domains[] = (string) $rule;
			}
		}

		$name = self::OPT_SPAM;

		// 三个清单按条目数撑高：预置的起步名单一屏能看全，不用在里面滚动找「还有没有」。
		// 上限 12 行，再长就交给 textarea 自己滚。
		$rows_keywords = max( 5, min( 12, count( $rules['keywords'] ) + 1 ) );
		$rows_domains  = max( 4, min( 12, count( $rules['domains'] ) + 1 ) );
		$rows_ips      = max( 4, min( 12, count( $rules['ips'] ) + 1 ) );
		?>
		<div class="wrap ifi-tpl-page ifi-spam-page">
			<header class="ifi-tpl-head">
				<h1><?php echo esc_html( self::t( 'spam_menu' ) ); ?></h1>
				<div class="ifi-tpl-head__actions">
					<button type="submit" form="ifi-spam-form" class="ifi-btn ifi-btn--primary">
						<span class="dashicons dashicons-saved" aria-hidden="true"></span>
						<?php echo esc_html( self::t( 'tpl_save' ) ); ?>
					</button>
				</div>
			</header>

			<?php
			// options.php 存完会带着 settings-updated=true 跳回来，提示存在 transient 里；
			// 这一页没有走 options-head.php，得自己打印，否则点保存像没反应
			settings_errors();
			?>

			<?php // 一条都没拦过就不占位置：空统计条不如不显示 ?>
			<?php if ( $total > 0 ) : ?>
				<div class="ifi-spam-stats">
					<span class="ifi-spam-stats__total">
						<?php
						/* translators: Placeholders must be preserved. */
						echo esc_html( sprintf( self::t( 'spam_stats_total' ), number_format_i18n( $total ) ) );
						?>
					</span>
					<?php
					foreach ( self::spam_reasons() as $reason => $label ) :
						// IP 黑名单不留档，统计里没有它的位置
						if ( $reason === 'ip' ) {
							continue;
						}
						$count = (int) ( $counts[ $reason ] ?? 0 );
						?>
						<span class="ifi-spam-pill<?php echo $count === 0 ? ' is-zero' : ''; ?>">
							<?php echo esc_html( $label ); ?>
							<b><?php echo esc_html( number_format_i18n( $count ) ); ?></b>
						</span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $invalid_ips ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						/* translators: Placeholders must be preserved. */
						echo esc_html( sprintf( self::t( 'spam_bad_ips' ), implode( ', ', $invalid_ips ) ) );
						?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( $invalid_domains ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						/* translators: Placeholders must be preserved. */
						echo esc_html( sprintf( self::t( 'spam_bad_domains' ), implode( ', ', $invalid_domains ) ) );
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php" id="ifi-spam-form">
				<?php settings_fields( 'ifi_spam_group' ); ?>

				<div class="ifi-spam-grid">
					<div class="ifi-spam-col">

						<section class="ifi-tpl-card">
							<div class="ifi-tpl-card__head">
								<span class="ifi-tpl-card__icon dashicons dashicons-shield" aria-hidden="true"></span>
								<h2><?php echo esc_html( self::t( 'spam_card_honeypot' ) ); ?></h2>
								<span class="ifi-tpl-card__desc"><?php echo esc_html( self::t( 'spam_honeypot_hint' ) ); ?></span>
							</div>
							<div class="ifi-tpl-card__body">
								<label class="ifi-check">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[honeypot]" value="1"
										<?php checked( '1', (string) $rules['honeypot'] ); ?> />
									<span><?php echo esc_html( self::t( 'spam_honeypot_on' ) ); ?></span>
								</label>
								<?php
								// 字段名是前端契约的一部分，直接在页面上改；改完前端要同步，否则蜜罐形同虚设。
								// 代码里挂了 ifi_honeypot_field 过滤器时页面上的值不生效——这是少数情况，
								// 所以只在真的挂了的时候提示，平时不拿扩展点去干扰站长。
								$ifi_field_effective = self::honeypot_field();
								$ifi_field_filtered  = has_filter( 'ifi_honeypot_field' )
									&& $ifi_field_effective !== (string) $rules['honeypot_field'];
								?>
								<div class="ifi-field ifi-spam-field-name">
									<div class="ifi-field__label">
										<label for="ifi-spam-honeypot-field"><?php echo esc_html( self::t( 'spam_honeypot_field' ) ); ?></label>
									</div>
									<input type="text" id="ifi-spam-honeypot-field" spellcheck="false" autocomplete="off"
										aria-describedby="<?php echo esc_attr( 'ifi-spam-honeypot-hint' . ( $ifi_field_filtered ? ' ifi-spam-honeypot-filter' : '' ) ); ?>"
										name="<?php echo esc_attr( $name ); ?>[honeypot_field]"
										value="<?php echo esc_attr( (string) $rules['honeypot_field'] ); ?>" />
									<?php if ( $ifi_field_filtered ) : ?>
										<p class="ifi-hint ifi-hint--warn" id="ifi-spam-honeypot-filter">
											<?php
											/* translators: Placeholders must be preserved. */
											echo esc_html( sprintf( self::t( 'spam_honeypot_filter' ), $ifi_field_effective ) );
											?>
										</p>
									<?php endif; ?>
									<p class="ifi-hint" id="ifi-spam-honeypot-hint"><?php echo esc_html( self::t( 'spam_honeypot_field_hint' ) ); ?></p>
								</div>
							</div>
						</section>

						<section class="ifi-tpl-card">
							<div class="ifi-tpl-card__head">
								<span class="ifi-tpl-card__icon dashicons dashicons-filter" aria-hidden="true"></span>
								<h2><?php echo esc_html( self::t( 'spam_card_content' ) ); ?></h2>
								<span class="ifi-tpl-card__desc"><?php echo esc_html( self::t( 'spam_card_content_hint' ) ); ?></span>
							</div>
							<div class="ifi-tpl-card__body">
								<div class="ifi-field ifi-spam-list">
									<div class="ifi-field__label">
										<label for="ifi-spam-keywords"><?php echo esc_html( self::t( 'spam_keywords' ) ); ?></label>
									</div>
									<textarea id="ifi-spam-keywords" rows="<?php echo (int) $rows_keywords; ?>" spellcheck="false"
										name="<?php echo esc_attr( $name ); ?>[keywords]"><?php echo esc_textarea( implode( "\n", $rules['keywords'] ) ); ?></textarea>
									<p class="ifi-hint"><?php echo esc_html( self::t( 'spam_keywords_hint' ) ); ?></p>
								</div>

								<div class="ifi-field ifi-spam-list">
									<div class="ifi-field__label">
										<label for="ifi-spam-domains"><?php echo esc_html( self::t( 'spam_domains' ) ); ?></label>
									</div>
									<textarea id="ifi-spam-domains" rows="<?php echo (int) $rows_domains; ?>" spellcheck="false"
										name="<?php echo esc_attr( $name ); ?>[domains]"><?php echo esc_textarea( implode( "\n", $rules['domains'] ) ); ?></textarea>
									<p class="ifi-hint"><?php echo esc_html( self::t( 'spam_domains_hint' ) ); ?></p>
								</div>

								<div class="ifi-field">
									<div class="ifi-field__label">
										<label for="ifi-spam-max-links"><?php echo esc_html( self::t( 'spam_max_links' ) ); ?></label>
									</div>
									<div class="ifi-spam-num">
										<input type="number" id="ifi-spam-max-links" min="0" step="1"
											name="<?php echo esc_attr( $name ); ?>[max_links]"
											value="<?php echo esc_attr( (string) (int) $rules['max_links'] ); ?>" />
									</div>
									<p class="ifi-hint"><?php echo esc_html( self::t( 'spam_max_links_hint' ) ); ?></p>
								</div>
							</div>
						</section>

						<section class="ifi-tpl-card">
							<div class="ifi-tpl-card__head">
								<span class="ifi-tpl-card__icon dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
								<h2><?php echo esc_html( self::t( 'spam_card_network' ) ); ?></h2>
								<span class="ifi-tpl-card__desc"><?php echo esc_html( self::t( 'spam_card_network_hint' ) ); ?></span>
							</div>
							<div class="ifi-tpl-card__body">
								<div class="ifi-field ifi-spam-list">
									<div class="ifi-field__label">
										<label for="ifi-spam-ips"><?php echo esc_html( self::t( 'spam_ips' ) ); ?></label>
									</div>
									<textarea id="ifi-spam-ips" rows="<?php echo (int) $rows_ips; ?>" spellcheck="false"
										name="<?php echo esc_attr( $name ); ?>[ips]"><?php echo esc_textarea( implode( "\n", $rules['ips'] ) ); ?></textarea>
									<p class="ifi-hint"><?php echo esc_html( self::t( 'spam_ips_hint' ) ); ?></p>
								</div>

								<div class="ifi-field">
									<div class="ifi-field__label">
										<label for="ifi-spam-duplicate"><?php echo esc_html( self::t( 'spam_duplicate' ) ); ?></label>
									</div>
									<div class="ifi-spam-num">
										<input type="number" id="ifi-spam-duplicate" min="0" step="1"
											name="<?php echo esc_attr( $name ); ?>[duplicate]"
											value="<?php echo esc_attr( (string) (int) $rules['duplicate'] ); ?>" />
										<span><?php echo esc_html( self::t( 'spam_minutes' ) ); ?></span>
									</div>
									<p class="ifi-hint"><?php echo esc_html( self::t( 'spam_duplicate_hint' ) ); ?></p>
								</div>
							</div>
						</section>

						<section class="ifi-tpl-card">
							<div class="ifi-tpl-card__head">
								<span class="ifi-tpl-card__icon dashicons dashicons-clock" aria-hidden="true"></span>
								<h2><?php echo esc_html( self::t( 'spam_card_rate' ) ); ?></h2>
								<span class="ifi-tpl-card__desc"><?php echo esc_html( self::t( 'spam_card_rate_hint' ) ); ?></span>
							</div>
							<div class="ifi-tpl-card__body">
								<div class="ifi-spam-pair">
									<div class="ifi-field">
										<div class="ifi-field__label">
											<label for="ifi-spam-rate-minute"><?php echo esc_html( self::t( 'spam_rate_minute' ) ); ?></label>
										</div>
										<div class="ifi-spam-num">
											<input type="number" id="ifi-spam-rate-minute" min="0" step="1"
												name="<?php echo esc_attr( $name ); ?>[rate_minute]"
												value="<?php echo esc_attr( (string) (int) $rules['rate_minute'] ); ?>" />
										</div>
									</div>
									<div class="ifi-field">
										<div class="ifi-field__label">
											<label for="ifi-spam-rate-hour"><?php echo esc_html( self::t( 'spam_rate_hour' ) ); ?></label>
										</div>
										<div class="ifi-spam-num">
											<input type="number" id="ifi-spam-rate-hour" min="0" step="1"
												name="<?php echo esc_attr( $name ); ?>[rate_hour]"
												value="<?php echo esc_attr( (string) (int) $rules['rate_hour'] ); ?>" />
										</div>
									</div>
								</div>
								<p class="ifi-hint"><?php echo esc_html( self::t( 'spam_rate_hint' ) ); ?></p>
							</div>
						</section>

						<section class="ifi-tpl-card">
							<div class="ifi-tpl-card__head">
								<span class="ifi-tpl-card__icon dashicons dashicons-info-outline" aria-hidden="true"></span>
								<h2><?php echo esc_html( self::t( 'spam_card_action' ) ); ?></h2>
							</div>
							<div class="ifi-tpl-card__body">
								<label class="ifi-check">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[archive]" value="1"
										<?php checked( '1', (string) $rules['archive'] ); ?> />
									<span><?php echo esc_html( self::t( 'spam_archive' ) ); ?></span>
								</label>
								<p class="ifi-hint"><?php echo esc_html( self::t( 'spam_archive_hint' ) ); ?></p>
							</div>
						</section>

					</div>

					<section class="ifi-tpl-card ifi-spam-test">
						<div class="ifi-tpl-card__head">
							<span class="ifi-tpl-card__icon dashicons dashicons-search" aria-hidden="true"></span>
							<h2><?php echo esc_html( self::t( 'spam_card_test' ) ); ?></h2>
							<span class="ifi-tpl-card__desc"><?php echo esc_html( self::t( 'spam_test_hint' ) ); ?></span>
						</div>
						<div class="ifi-tpl-card__body">
							<div class="ifi-field">
								<div class="ifi-field__label">
									<label for="ifi-test-name"><?php echo esc_html( self::t( 'spam_test_name' ) ); ?></label>
								</div>
								<input type="text" id="ifi-test-name" name="ifi_test_name"
									value="<?php echo esc_attr( $input['name'] ); ?>" />
							</div>
							<div class="ifi-field">
								<div class="ifi-field__label">
									<label for="ifi-test-email"><?php echo esc_html( self::t( 'col_email' ) ); ?></label>
								</div>
								<input type="text" id="ifi-test-email" name="ifi_test_email"
									value="<?php echo esc_attr( $input['email'] ); ?>" />
							</div>
							<div class="ifi-field">
								<div class="ifi-field__label">
									<label for="ifi-test-ip"><?php echo esc_html( self::t( 'spam_test_ip' ) ); ?></label>
								</div>
								<input type="text" id="ifi-test-ip" name="ifi_test_ip"
									value="<?php echo esc_attr( $input['ip'] ); ?>" />
							</div>
							<div class="ifi-field">
								<div class="ifi-field__label">
									<label for="ifi-test-message"><?php echo esc_html( self::t( 'col_message' ) ); ?></label>
								</div>
								<textarea id="ifi-test-message" rows="5" name="ifi_test_message"><?php echo esc_textarea( $input['message'] ); ?></textarea>
							</div>

							<button type="submit" class="ifi-btn ifi-btn--ghost" name="ifi_spam_test" value="1"
								formaction="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SPAM_SLUG ) ); ?>">
								<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
								<?php echo esc_html( self::t( 'spam_test_run' ) ); ?>
							</button>

							<?php
							if ( $test !== null ) {
								echo self::spam_test_html( $test ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 片段在 spam_test_html() 里逐项转义。
							}
							?>
						</div>
					</section>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * 试跑：用表单里那份规则判一条假提交。
	 *
	 * 只读——不入库、不发信、也不写重复提交的 transient。
	 *
	 * @param array $input 试跑输入（name / email / message / ip）.
	 * @param array $rules 规则（页面上尚未保存的那份）.
	 * @return array{state:string, reason?:string, hit?:string}
	 */
	private static function spam_test_result( array $input, array $rules ): array {
		$fields = array_filter(
			array(
				'fullName' => (string) ( $input['name'] ?? '' ),
				'email'    => (string) ( $input['email'] ?? '' ),
				'message'  => (string) ( $input['message'] ?? '' ),
			),
			static function ( $value ) {
				return $value !== '';
			}
		);

		if ( ! $fields && (string) ( $input['ip'] ?? '' ) === '' ) {
			return array( 'state' => 'empty' );
		}

		// IP 黑名单是最早的一道门：填了 IP 就先看它
		if ( (string) ( $input['ip'] ?? '' ) !== '' && self::spam_ip_hit( (string) $input['ip'], $rules ) ) {
			return array( 'state' => 'ip' );
		}

		$verdict = self::spam_verdict( $fields, $rules );
		if ( $verdict['reason'] !== '' ) {
			return array(
				'state'  => 'blocked',
				'reason' => $verdict['reason'],
				'hit'    => $verdict['hit'],
			);
		}

		return array( 'state' => 'clean' );
	}

	/** 试跑结果块：三态（空 / 放行 / 拦截）各自的文案 */
	private static function spam_test_html( array $test ): string {
		if ( $test['state'] === 'empty' ) {
			return '<p class="ifi-spam-empty">' . esc_html( self::t( 'spam_test_empty' ) ) . '</p>';
		}
		if ( $test['state'] === 'ip' ) {
			return self::spam_result_box( 'is-blocked', self::t( 'spam_test_ip_blocked' ), self::t( 'spam_test_ip_hint' ) );
		}
		if ( $test['state'] === 'clean' ) {
			return self::spam_result_box( 'is-clean', self::t( 'spam_test_clean' ), self::t( 'spam_test_clean_hint' ) );
		}

		$detail = self::spam_hit_detail( (string) $test['reason'], (string) ( $test['hit'] ?? '' ) );
		$line   = self::spam_reason_label( (string) $test['reason'] );
		if ( $detail !== '' ) {
			$line .= ' — ' . $detail;
		}
		return self::spam_result_box( 'is-blocked', self::t( 'spam_test_blocked' ), $line );
	}

	private static function spam_result_box( string $state, string $label, string $text ): string {
		return '<div class="ifi-spam-result ' . esc_attr( $state ) . '">'
			. '<span class="ifi-spam-result__label">' . esc_html( $label ) . '</span>'
			. '<span class="ifi-spam-result__text">' . esc_html( $text ) . '</span>'
			. '</div>';
	}
}
