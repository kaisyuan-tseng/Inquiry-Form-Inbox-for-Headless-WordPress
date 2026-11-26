<?php
/**
 * IFI_Settings_Render_Trait — Inquiry Form Inbox for Headless WordPress.
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Settings_Render_Trait {


	public static function render_template_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( self::t( 'error_forbidden' ) ), '', array( 'response' => 403 ) );
		}

		self::enqueue_template_assets();

		$kind     = self::current_kind();
		$is_auto  = $kind === 'autoreply';
		$is_reply = ! $is_auto && $kind === 'reply';
		$form_id  = $is_reply ? 'ifi-reply-form' : 'ifi-tpl-form';

		// 自动确认按浏览器语言分语言组：页面一次只编辑一组（索引 0 = 默认兜底组）
		$slots         = $is_auto ? self::reply_groups() : array();
		$slot          = $is_auto ? self::current_slot( $slots ) : 0;
		$slot_group    = $is_auto ? $slots[ $slot ] : array();
		$enabled       = $is_auto ? (string) ( self::tpl( 'autoreply' )['enabled'] ?? '0' ) : '0';
		$ignore_unsent = $is_auto ? (string) ( self::tpl( 'autoreply' )['ignore_unsent'] ?? '0' ) : '0';

		// 通知只有一份模板；自动确认取「当前语言组」这一份
		$tpl = $is_auto ? $slot_group : self::tpl( 'notify' );
		// 代码框显示的永远是这个模板真正生效的源码：没保存过 tpl() 已注入默认，
		// 保存过就原样显示（清空过就是空的，不再偷偷塞回默认模板）
		$code = (string) $tpl['html'];

		$base_args = array( 'kind' => $kind );
		if ( $is_auto ) {
			$base_args['slot'] = $slot;
		}
		// 测试发信带上 kind（与 slot），nonce 也按 kind 分，免得在 A 标签页误伤 B 的配置
		$test  = wp_nonce_url(
			add_query_arg( $base_args, admin_url( 'admin-post.php?action=ifi_test_mail' ) ),
			'ifi_test_mail_' . $kind
		);
		$name  = self::option_name( $kind );
		$group = $is_auto ? 'ifi_autoreply_group' : 'ifi_template_group';

        // phpcs:disable WordPress.Security.NonceVerification
		$notice = '';
		if ( isset( $_GET['ifi_test'] ) ) {
			$ok     = $_GET['ifi_test'] === '1';
			$notice = '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>'
					. esc_html( self::t( $ok ? 'tpl_test_ok' : 'tpl_test_fail' ) ) . '</p></div>';
		} elseif ( isset( $_GET['ifi_lang_added'] ) ) {
			$notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html( self::t( 'tpl_lang_added' ) ) . '</p></div>';
		} elseif ( isset( $_GET['ifi_lang_deleted'] ) ) {
			$notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html( self::t( 'tpl_lang_deleted' ) ) . '</p></div>';
		} elseif ( isset( $_GET['ifi_lang_exists'] ) ) {
			$notice = '<div class="notice notice-warning is-dismissible"><p>' . esc_html( self::t( 'tpl_lang_exists' ) ) . '</p></div>';
		}
        // phpcs:enable

		// 可改的字段标签：字段集与邮件预览共用 label_fields()，两处永远对得上；
		// 传当前模板的 labels 进去，列表顺序 = 用户拖出来的行序
		$label_meta        = self::label_fields( (array) ( $tpl['labels'] ?? array() ) );
		$label_fields      = (array) $label_meta['fields'];
		$labels_are_sample = (bool) $label_meta['sample'];

		// 占位符分组 + 悬浮说明：只给「光看名字猜不出来」的几个，其余不加 title，少一层噪音
		$placeholder_groups = array(
			array( '{{rows}}', '{{rows.*.label}}', '{{rows.*.value}}' ),
			array( '{{no}}', '{{source}}', '{{form_type}}' ),
			array( '{{time}}', '{{time_local}}', '{{ip}}', '{{lang}}', '{{time_zone}}' ),
		);
		$placeholder_hints  = array(
			'{{rows}}'         => self::t( 'ph_rows' ),
			'{{rows.*.label}}' => self::t( 'ph_rows_label' ),
			'{{rows.*.value}}' => self::t( 'ph_rows_value' ),
			'{{no}}'           => self::t( 'ph_no' ),
			'{{source}}'       => self::t( 'ph_source' ),
			'{{form_type}}'    => self::t( 'ph_form_type' ),
			'{{time}}'         => self::t( 'ph_time' ),
			'{{time_local}}'   => self::t( 'ph_time_local' ),
			'{{time_zone}}'    => self::t( 'ph_time_zone' ),
		);

		// 字段标签 = 变量来源：每个标签键给 .value / .label 两个可插入令牌，单独列一组
		$field_var_group = array();
		$field_var_hints = array();
		foreach ( (array) ( $tpl['labels'] ?? array() ) as $field_key => $field_label ) {
			$hint = (string) $field_label !== '' ? (string) $field_label : self::readable_label( (string) $field_key );
			foreach ( array( 'value', 'label' ) as $suffix ) {
				$ph                     = '{{rows.' . $field_key . '.' . $suffix . '}}';
				$field_var_group[]      = $ph;
				$field_var_hints[ $ph ] = $hint;
			}
		}
		// 主题框的 placeholder 必须是一行完整字符串：attribute 里的换行和缩进会被浏览器
		// 当成前导空白渲染出来，看着就像 placeholder 没左对齐
		$subject_placeholder = $is_auto
			? self::t( 'email_subject_reply' )
			: sprintf( self::t( 'email_subject' ), '{{rows.fullName.value}} <{{rows.email.value}}>' );
		?>

		<div class="wrap ifi-tpl-page">
			<header class="ifi-tpl-head">
				<h1><?php echo esc_html( self::t( 'tpl_menu' ) ); ?></h1>
				<div class="ifi-tpl-head__actions">
					<?php if ( ! $is_reply ) : ?>
						<a class="ifi-btn ifi-btn--ghost" href="<?php echo esc_url( $test ); ?>"
							title="<?php echo esc_attr( self::t( 'tpl_test_hint' ) ); ?>">
							<span class="dashicons dashicons-email" aria-hidden="true"></span>
							<?php echo esc_html( self::t( 'tpl_test' ) ); ?>
						</a>
					<?php endif; ?>
					<button type="submit" form="<?php echo esc_attr( $form_id ); ?>" class="ifi-btn ifi-btn--primary">
						<span class="dashicons dashicons-saved" aria-hidden="true"></span>
						<?php echo esc_html( self::t( 'tpl_save' ) ); ?>
					</button>
				</div>
			</header>

			<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<nav class="ifi-tpl-tabs">
				<?php
				$tabs = array(
					'notify'    => self::t( 'tpl_tab_notify' ),
					'autoreply' => self::t( 'tpl_tab_reply' ),
					'reply'     => self::t( 'reply_menu' ),
				);
				foreach ( $tabs as $tab_kind => $tab_label ) :
					?>
					<a href="<?php echo esc_url( self::template_tab_url( $tab_kind ) ); ?>"
						class="ifi-tpl-tab<?php echo $tab_kind === $kind ? ' is-active' : ''; ?>"><?php echo esc_html( $tab_label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php if ( $is_reply ) : ?>
				<?php
				// 回信模板按语言组保存：收件人三件套全局一份，主题与正文按访客浏览器语言分。
				// 用哪一组由弹窗决定（见 reply_payload()），这里只负责编辑当前组。
				$reply_groups = self::reply_tpl_groups();
				$reply_slot   = self::current_slot( $reply_groups );
				$reply_group  = $reply_groups[ $reply_slot ];
				$reply_tpl    = self::reply_tpl();
				?>
				<section class="ifi-tpl-card">
					<div class="ifi-tpl-card__head">
						<span class="ifi-tpl-card__icon dashicons dashicons-undo" aria-hidden="true"></span>
						<h2><?php echo esc_html( self::t( 'reply_menu' ) ); ?></h2>
						<span class="ifi-tpl-card__desc"><?php echo esc_html( self::t( 'reply_card_hint' ) ); ?></span>
					</div>
					<?php self::render_lang_bar( 'reply', $reply_groups, $reply_slot ); ?>
					<form method="post" action="options.php" id="<?php echo esc_attr( $form_id ); ?>" class="ifi-tpl-card__body">
						<?php settings_fields( 'ifi_reply_group' ); ?>
						<input type="hidden" name="<?php echo esc_attr( self::OPT_REPLY ); ?>[cur]" value="<?php echo (int) $reply_slot; ?>" />
						<?php // 语言标签随表单回传：不带它，保存一次就会把这一组的语言抹成默认组 ?>
						<input type="hidden" name="<?php echo esc_attr( self::OPT_REPLY ); ?>[lang]" value="<?php echo esc_attr( (string) $reply_group['lang'] ); ?>" />
						<div class="ifi-field">
							<div class="ifi-field__label"><label for="ifi-reply-to"><?php echo esc_html( self::t( 'reply_to' ) ); ?></label></div>
							<input type="text" name="<?php echo esc_attr( self::OPT_REPLY ); ?>[to]" id="ifi-reply-to"
									class="large-text" value="<?php echo esc_attr( $reply_tpl['to'] ); ?>" />
						</div>
						<div class="ifi-tpl-row">
							<div class="ifi-field ifi-field--grow">
								<div class="ifi-field__label"><label for="ifi-reply-cc"><?php echo esc_html( self::t( 'reply_cc' ) ); ?></label></div>
								<input type="text" name="<?php echo esc_attr( self::OPT_REPLY ); ?>[cc]" id="ifi-reply-cc"
										class="large-text" value="<?php echo esc_attr( $reply_tpl['cc'] ); ?>" />
							</div>
							<div class="ifi-field ifi-field--grow">
								<div class="ifi-field__label"><label for="ifi-reply-bcc"><?php echo esc_html( self::t( 'reply_bcc' ) ); ?></label></div>
								<input type="text" name="<?php echo esc_attr( self::OPT_REPLY ); ?>[bcc]" id="ifi-reply-bcc"
										class="large-text" value="<?php echo esc_attr( $reply_tpl['bcc'] ); ?>" />
							</div>
						</div>
						<?php // 这一行只讲地址，紧贴 To/Cc/Bcc，不跟到下一个字段下面 ?>
						<p class="ifi-hint ifi-hint--addr"><?php echo esc_html( self::t( 'reply_addr_hint' ) ); ?></p>
						<div class="ifi-field">
							<div class="ifi-field__label"><label for="ifi-reply-subject"><?php echo esc_html( self::t( 'reply_subject' ) ); ?></label></div>
							<input type="text" name="<?php echo esc_attr( self::OPT_REPLY ); ?>[subject]" id="ifi-reply-subject"
									class="large-text" value="<?php echo esc_attr( (string) $reply_group['subject'] ); ?>" />
						</div>
						<div class="ifi-field">
							<div class="ifi-field__label"><label for="ifi-reply-body"><?php echo esc_html( self::t( 'reply_body' ) ); ?></label></div>
							<textarea class="large-text code" rows="10" id="ifi-reply-body"
										name="<?php echo esc_attr( self::OPT_REPLY ); ?>[body]"><?php echo esc_textarea( (string) $reply_group['body'] ); ?></textarea>
						</div>
						<?php // 变量区和另外两页是同一份：整表、环境值、字段变量在回信里都能替换（见 reply_payload()） ?>
						<?php self::render_tpl_foot( $placeholder_groups, $placeholder_hints, $field_var_group, $field_var_hints ); ?>
					</form>
				</section>
			<?php else : ?>
				<div class="ifi-tpl-grid">
					<section class="ifi-tpl-card ifi-tpl-editor-card">
						<div class="ifi-tpl-card__head">
							<span class="ifi-tpl-card__icon dashicons dashicons-edit" aria-hidden="true"></span>
							<h2><?php echo esc_html( self::t( 'tpl_card_content' ) ); ?></h2>
						</div>

						<?php if ( $is_auto ) : ?>
							<?php self::render_lang_bar( 'autoreply', $slots, $slot ); ?>
						<?php endif; ?>

						<form method="post" action="options.php" id="<?php echo esc_attr( $form_id ); ?>" class="ifi-tpl-card__body">
							<?php settings_fields( $group ); ?>

							<?php if ( $is_auto ) : ?>
								<input type="hidden" name="<?php echo esc_attr( $name ); ?>[cur]" value="<?php echo (int) $slot; ?>" />
								<?php // 语言标签随表单回传：不带它，保存一次就会把这一组的语言抹成默认组 ?>
								<input type="hidden" name="<?php echo esc_attr( $name ); ?>[lang]" value="<?php echo esc_attr( (string) ( $slot_group['lang'] ?? '' ) ); ?>" />
								<label class="ifi-check" data-ifi-notify-ignore>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1"
											<?php checked( '1', $enabled ); ?> />
									<span><?php echo esc_html( self::t( 'tpl_reply_on' ) ); ?></span>
								</label>
								<p class="ifi-hint" data-ifi-notify-ignore><?php echo esc_html( self::t( 'tpl_reply_on_hint' ) ); ?></p>
								<label class="ifi-check" data-ifi-notify-ignore>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[ignore_unsent]" value="1"
											<?php checked( '1', $ignore_unsent ); ?> />
									<span><?php echo esc_html( self::t( 'tpl_ignore_unsent' ) ); ?></span>
								</label>
								<p class="ifi-hint" data-ifi-notify-ignore><?php echo esc_html( self::t( 'tpl_ignore_unsent_hint' ) ); ?></p>
							<?php endif; ?>

							<div class="ifi-field ifi-field--inline">
								<div class="ifi-field__label">
									<label for="ifi-subject"><?php echo esc_html( self::t( 'tpl_subject' ) ); ?></label>
									<span class="ifi-help" data-tip="<?php echo esc_attr( self::t( 'tpl_subject_hint' ) ); ?>" tabindex="0" aria-label="<?php echo esc_attr( self::t( 'tpl_subject_hint' ) ); ?>">
										<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
									</span>
								</div>
								<div class="ifi-field__control">
									<input name="<?php echo esc_attr( $name ); ?>[subject]" id="ifi-subject" type="text"
											class="large-text" value="<?php echo esc_attr( (string) $tpl['subject'] ); ?>"
											placeholder="<?php echo esc_attr( $subject_placeholder ); ?>" />
								</div>
							</div>

							<div class="ifi-field ifi-field--labels">
								<div class="ifi-field__label">
									<?php echo esc_html( self::t( 'tpl_labels' ) ); ?>
									<span class="ifi-help" data-tip="<?php echo esc_attr( self::t( 'tpl_labels_hint' ) ); ?>" tabindex="0" aria-label="<?php echo esc_attr( self::t( 'tpl_labels_hint' ) ); ?>">
										<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
									</span>
									<span class="ifi-tpl-badge"><?php echo count( $label_fields ); ?></span>
								</div>
								<?php if ( $is_auto ) : ?>
									<p class="ifi-hint" data-ifi-notify-ignore><?php echo esc_html( self::t( 'tpl_reply_labels_hint' ) ); ?></p>
								<?php endif; ?>
								<?php // 空态提示：没有任何标签时显示（手动添加的提示），有标签时隐藏 ?>
								<p class="ifi-hint ifi-labels-sample"<?php echo $labels_are_sample ? '' : ' hidden'; ?>><em><?php echo esc_html( self::t( 'tpl_labels_sample' ) ); ?></em></p>
								<div class="ifi-tpl-labels<?php echo $label_fields ? '' : ' is-empty'; ?>" data-sortable-labels>
									<?php foreach ( $label_fields as $field_key => $default_label ) : ?>
										<div class="ifi-tpl-label-row">
											<span class="ifi-sort" title="<?php echo esc_attr( self::t( 'tpl_labels_sort' ) ); ?>" aria-hidden="true">⠿</span>
											<span class="ifi-tpl-label-key"><?php echo esc_html( $field_key ); ?></span>
											<input type="text"
													name="<?php echo esc_attr( $name ); ?>[labels][<?php echo esc_attr( $field_key ); ?>]"
													value="<?php echo esc_attr( (string) ( $tpl['labels'][ $field_key ] ?? '' ) ); ?>"
													placeholder="<?php echo esc_attr( $default_label ); ?>" />
											<button type="button" class="ifi-tpl-label-del" aria-label="<?php echo esc_attr( self::t( 'tpl_label_remove' ) ); ?>">×</button>
										</div>
									<?php endforeach; ?>
								</div>
								<div class="ifi-tpl-labels__foot">
									<button type="button" class="button button-small" id="ifi-add-label"><?php echo esc_html( self::t( 'tpl_label_add' ) ); ?></button>
									<?php $field_suggestions = self::frontend_field_manifest(); ?>
									<?php if ( $field_suggestions ) : ?>
										<datalist id="ifi-field-suggestions">
											<?php foreach ( $field_suggestions as $suggestion ) : ?>
												<option value="<?php echo esc_attr( $suggestion ); ?>"></option>
											<?php endforeach; ?>
										</datalist>
									<?php endif; ?>
								</div>
							</div>

							<div class="ifi-field ifi-field--editor">
								<div class="ifi-field__label">
									<label for="ifi_html"><?php echo esc_html( self::t( 'tpl_html' ) ); ?></label>
									<span class="ifi-help" data-tip="<?php echo esc_attr( self::t( 'tpl_html_hint' ) ); ?>" tabindex="0" aria-label="<?php echo esc_attr( self::t( 'tpl_html_hint' ) ); ?>">
										<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
									</span>
								</div>
								<div class="ifi-code-editor">
									<div class="ifi-code-editor__tools">
										<div class="wp-media-buttons">
											<?php // 保留 add_media：图标的 dashicon content 挂在这个类上；只去掉内核绑定双击事件的 insert-media ?>
											<button type="button" class="button add_media ifi-add-media" id="ifi-add-media">
												<span class="wp-media-buttons-icon"></span>
												<?php echo esc_html( self::t( 'tpl_add_media' ) ); ?>
											</button>
										</div>
									</div>
									<div class="ifi-code-editor__frame">
										<?php
										// 代码高亮用 WordPress 原生 CodeMirror（wp.codeEditor），
										// 媒体按钮单独放在编辑器上方，选中图片后插入 <img> 到光标处。
										?>
										<textarea name="<?php echo esc_attr( $name ); ?>[html]" id="ifi_html" rows="22" class="large-text code"><?php echo esc_textarea( $code ); ?></textarea>
									</div>
								</div>
							</div>

							<?php self::render_tpl_foot( $placeholder_groups, $placeholder_hints, $field_var_group, $field_var_hints ); ?>
						</form>
					</section>

					<section class="ifi-tpl-card ifi-tpl-preview">
						<div class="ifi-tpl-card__head">
							<span class="ifi-tpl-card__icon dashicons dashicons-visibility" aria-hidden="true"></span>
							<h2><?php echo esc_html( self::t( 'tpl_preview' ) ); ?></h2>
							<div class="ifi-tpl-card__actions">
								<div class="ifi-seg ifi-seg--sm" data-preview-mode role="group" aria-label="<?php echo esc_attr( self::t( 'tpl_preview' ) ); ?>">
									<button type="button" data-size="desktop" class="is-active"><?php echo esc_html( self::t( 'tpl_desktop' ) ); ?></button>
									<button type="button" data-size="mobile"><?php echo esc_html( self::t( 'tpl_mobile' ) ); ?></button>
								</div>
							</div>
						</div>
						<div class="ifi-preview-body">
							<iframe id="ifi-preview" title="<?php echo esc_attr( self::t( 'tpl_preview' ) ); ?>"></iframe>
						</div>
						<?php // 说明文字由预览接口同步更新，这里先渲染一次，免得首屏空一行 ?>
						<p class="ifi-preview-note" id="ifi-preview-note"><?php echo esc_html( self::preview_note() ); ?></p>
					</section>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * 语言组切换条（自动确认与回信模板共用）：标签 + 删除 + 新增。
	 *
	 * 两个页面各存各的 option，所以 action / nonce / 标签页地址都按 $kind 走。
	 *
	 * @param string $kind  autoreply / reply.
	 * @param array  $slots 规范化后的语言组（索引 0 是默认组，不可删）.
	 * @param int    $slot  当前编辑的组.
	 */
	private static function render_lang_bar( string $kind, array $slots, int $slot ): void {
		$add_action = $kind === 'reply' ? 'ifi_rp_addlang' : 'ifi_reply_addlang';
		$del_action = $kind === 'reply' ? 'ifi_rp_dellang' : 'ifi_reply_dellang';
		$nonce_base = $kind === 'reply' ? 'ifi_rp_lang' : 'ifi_reply_lang';
		?>
		<div class="ifi-lang-bar" data-ifi-notify-ignore>
			<span class="ifi-lang-bar__label"><?php echo esc_html( self::t( 'tpl_lang_bar' ) ); ?></span>
			<ul class="ifi-lang-list">
				<?php
				foreach ( $slots as $i => $item ) :
					// 默认组不可删；其它组的删除入口就放在各自的标签上
					$del_url = $i === 0 ? '' : wp_nonce_url(
						add_query_arg(
							array(
								'action' => $del_action,
								'kind'   => $kind,
								'slot'   => $i,
							),
							admin_url( 'admin-post.php' )
						),
						$nonce_base . '_' . $i
					);
					?>
					<li class="ifi-lang-item<?php echo $del_url !== '' ? ' has-del' : ''; ?>">
						<a class="ifi-lang-tab<?php echo $i === $slot ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( self::template_slot_url( $kind, $i ) ); ?>"
							title="<?php echo esc_attr( $item['lang'] === '' ? self::t( 'tpl_lang_default_hint' ) : self::lang_label( (string) $item['lang'] ) ); ?>">
							<?php echo esc_html( self::lang_label( (string) $item['lang'] ) ); ?>
						</a>
						<?php if ( $del_url !== '' ) : ?>
							<a class="ifi-lang-del" href="<?php echo esc_url( $del_url ); ?>"
								title="<?php echo esc_attr( self::t( 'tpl_lang_delete' ) ); ?>"
								onclick="return confirm('<?php echo esc_js( self::t( 'tpl_lang_delete_confirm' ) ); ?>');">×</a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ifi-lang-add">
				<input type="hidden" name="action" value="<?php echo esc_attr( $add_action ); ?>" />
				<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>" />
				<?php // 独立字段名，避免和下面 settings_fields 的 _wpnonce 撞 id ?>
				<?php wp_nonce_field( $nonce_base, '_wpnonce_lang' ); ?>
				<select name="newlang" aria-label="<?php echo esc_attr( self::t( 'tpl_lang_code' ) ); ?>">
					<option value=""><?php echo esc_html( self::t( 'tpl_lang_code' ) ); ?>…</option>
					<?php
					$used_langs = array_map(
						static function ( $g ) {
							return (string) $g['lang'];
						},
						$slots
					);
					foreach ( self::lang_options() as $opt_code => $opt_label ) :
						if ( in_array( $opt_code, $used_langs, true ) ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( $opt_code ); ?>"><?php echo esc_html( $opt_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button button-small"><?php echo esc_html( self::t( 'tpl_lang_add' ) ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * 变量区（三个标签页共用一份）：信息图标 + 「可用变量：」+ 可点击插入的占位符。
	 *
	 * 结构与类名只写在这里一处，通知、自动确认、回复模板三页的说明行永远长得一样；
	 * 差别只在传进来的清单——回复模板是纯文本 mailto，没有整表 {{rows}}。
	 *
	 * @param array $groups      占位符分组，逐组平铺；组内顺序即显示顺序.
	 * @param array $hints       占位符 => 悬浮说明；没写说明的不加提示.
	 * @param array $fields      字段变量（{{rows.字段名.label}} / {{rows.字段名.value}}）.
	 * @param array $field_hints 字段变量 => 悬浮说明（通常就是字段标签）.
	 */
	private static function render_tpl_foot( array $groups, array $hints = array(), array $fields = array(), array $field_hints = array() ): void {
		$all = array();
		foreach ( $groups as $group ) {
			$all = array_merge( $all, $group );
		}
		?>
		<div class="ifi-tpl-foot">
			<p class="ifi-vars">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<span class="ifi-vars__label"><?php echo esc_html( self::t( 'tpl_variables' ) ); ?></span>
				<?php // 占位符单独成列：换行后从第一个变量开始，不回到标签下面 ?>
				<span class="ifi-vars__list">
					<?php
					foreach ( $all as $ph ) :
						$hint = (string) ( $hints[ $ph ] ?? '' );
						?>
						<button type="button" class="ifi-var" data-insert-ph="<?php echo esc_attr( $ph ); ?>"
							<?php
							if ( $hint !== '' ) :
								?>
								data-tip="<?php echo esc_attr( $ph . ' — ' . $hint ); ?>"<?php endif; ?>
							><?php echo esc_html( $ph ); ?></button>
					<?php endforeach; ?>
					<?php if ( $fields ) : ?>
						<span class="ifi-vars__sep" data-ifi-notify-ignore><?php echo esc_html( self::t( 'tpl_vars_fields' ) ); ?></span>
						<?php foreach ( $fields as $ph ) : ?>
							<button type="button" class="ifi-var ifi-var--field" data-ifi-notify-ignore data-insert-ph="<?php echo esc_attr( $ph ); ?>"
								data-tip="<?php echo esc_attr( $ph . ' — ' . ( $field_hints[ $ph ] ?? '' ) ); ?>"
								><?php echo esc_html( $ph ); ?></button>
						<?php endforeach; ?>
					<?php endif; ?>
				</span>
			</p>
		</div>
		<?php
	}
}
