<?php
/**
 * IFI_I18n_Trait — gettext-backed strings for Inquiry Form Inbox for Headless WordPress.
 */

defined( 'ABSPATH' ) || exit;

trait IFI_I18n_Trait {

	/**
	 * Build the string map. Source language is English; translations come from gettext.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return array<string, string>
	 */
	private static function strings( ?string $locale = null ): array {
		$locale = $locale ?? ( '' !== self::$render_locale ? self::$render_locale : get_locale() );
		if ( isset( self::$string_cache[ $locale ] ) ) {
			return self::$string_cache[ $locale ];
		}

		$map = array(
			/* 菜单与视图 */
			'menu_name'                => __( 'Inquiries', 'inquiry-form-inbox' ),
			'all_items'                => __( 'All Inquiries', 'inquiry-form-inbox' ),
			'search_items'             => __( 'Search Inquiries', 'inquiry-form-inbox' ),
			'not_found'                => __( 'No inquiries yet.', 'inquiry-form-inbox' ),
			'not_found_trash'          => __( 'No inquiries in Trash.', 'inquiry-form-inbox' ),
			'view_pending'             => __( 'Pending', 'inquiry-form-inbox' ),
			'view_contacted'           => __( 'Contacted', 'inquiry-form-inbox' ),
			'view_spam'                => __( 'Spam', 'inquiry-form-inbox' ),
			'view_all'                 => __( 'All', 'inquiry-form-inbox' ),
			// 列表编号后的处理状态徽章；待处理 / 已联系复用视图文案，短词用上下文区分
			'badge_new'                => _x( 'New', 'inquiry badge', 'inquiry-form-inbox' ),
			'badge_spam'               => _x( 'Spam', 'inquiry badge', 'inquiry-form-inbox' ),
			// 视图链接与行操作在英文里都是 Trash，中文一个是「回收站」一个是「移至回收站」：
			// 只能靠 gettext 上下文区分，否则同一个 msgid 只能有一种译法。
			'view_trash'               => _x( 'Trash', 'list view', 'inquiry-form-inbox' ),
			'all_sources'              => __( 'All sources', 'inquiry-form-inbox' ),

			/* 列表列 */
			'col_no'                   => __( '#', 'inquiry-form-inbox' ),
			'col_from'                 => __( 'From', 'inquiry-form-inbox' ),
			'col_message'              => __( 'Message', 'inquiry-form-inbox' ),
			'col_source'               => __( 'Source', 'inquiry-form-inbox' ),
			'col_attachments'          => __( 'Attachments', 'inquiry-form-inbox' ),
			'col_status'               => __( 'Delivery status', 'inquiry-form-inbox' ),
			'col_received'             => __( 'Received', 'inquiry-form-inbox' ),
			'col_email'                => __( 'Email', 'inquiry-form-inbox' ),

			/* 自动发信状态：两封邮件的组合状态压成一个词 */
			'status_all'               => __( 'All Sent', 'inquiry-form-inbox' ),
			'status_only_notify'       => __( 'Only Notification', 'inquiry-form-inbox' ),
			'status_only_reply'        => __( 'Only Auto-Confirmation', 'inquiry-form-inbox' ),
			'status_none'              => __( 'Not Sent', 'inquiry-form-inbox' ),
			'status_none_hint'         => __( 'Neither the inquiry notification nor the auto-confirmation was sent', 'inquiry-form-inbox' ),
			'status_notify_only'       => __( 'Only the inquiry notification was sent', 'inquiry-form-inbox' ),
			'status_reply_only'        => __( 'Only the auto-confirmation was sent', 'inquiry-form-inbox' ),
			'status_all_hint'          => __( 'Both the inquiry notification and the auto-confirmation were sent', 'inquiry-form-inbox' ),
			'status_yes'               => __( 'Sent', 'inquiry-form-inbox' ),
			'status_no'                => __( 'Failed', 'inquiry-form-inbox' ),
			'label_notify'             => __( 'Inquiry notification', 'inquiry-form-inbox' ),
			'label_reply'              => __( 'Auto-confirmation', 'inquiry-form-inbox' ),
			/* translators: 1: field label, 2: value. */
			'pair_label_value'         => __( '%1$s: %2$s', 'inquiry-form-inbox' ),
			/* translators: 1: name, 2: qualifier shown in parentheses. */
			'pair_label_note'          => __( '%1$s (%2$s)', 'inquiry-form-inbox' ),

			/* 标记已联系 */
			'mark_contacted'           => __( 'Mark as Contacted', 'inquiry-form-inbox' ),
			'mark_pending'             => __( 'Mark as Pending', 'inquiry-form-inbox' ),
			'contacted_notice'         => __( 'Marked as contacted.', 'inquiry-form-inbox' ),
			'uncontacted_notice'       => __( 'Marked as pending.', 'inquiry-form-inbox' ),

			/* 垃圾视图：这不是垃圾 */
			'not_spam_action'          => __( 'Not Spam', 'inquiry-form-inbox' ),
			'not_spam_notice'          => __( 'Marked as not spam.', 'inquiry-form-inbox' ),

			/* 仪表盘 */
			'file_one'                 => __( '1 File', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'files_many'               => __( '%d Files', 'inquiry-form-inbox' ),
			'dash_title'               => __( 'Recent inquiries', 'inquiry-form-inbox' ),
			'dash_daily_title'         => __( 'New Today', 'inquiry-form-inbox' ),
			'dash_pending_title'       => __( 'Pending', 'inquiry-form-inbox' ),
			'dash_spam_title'          => __( 'Spam', 'inquiry-form-inbox' ),
			'dash_spam_none_today'     => __( 'No spam today', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'dash_spam_today'          => __( '%d today', 'inquiry-form-inbox' ),
			'dash_email_problem'       => __( 'Email Problem', 'inquiry-form-inbox' ),
			'dash_email_delivered'     => __( 'Emails Sent', 'inquiry-form-inbox' ),
			'dash_all_delivered'       => __( 'All emails sent', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'dash_failed_notify'       => __( '%d notifications failed', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'dash_failed_reply'        => __( '%d confirmations failed', 'inquiry-form-inbox' ),
			'dash_unsent_ignored'      => __( 'Unsent warnings are ignored', 'inquiry-form-inbox' ),
			'dash_auto_off'            => __( 'Auto-confirmation is off', 'inquiry-form-inbox' ),
			'dash_view'                => __( 'View', 'inquiry-form-inbox' ),
			'dash_sources_title'       => __( 'Sources', 'inquiry-form-inbox' ),
			'dash_langs_title'         => __( 'Languages', 'inquiry-form-inbox' ),
			'dash_variant_title'       => __( 'Form Type', 'inquiry-form-inbox' ),
			'dash_monthly_title'       => __( 'Monthly Trend', 'inquiry-form-inbox' ),
			'dash_vs_last_month'       => __( 'vs last month', 'inquiry-form-inbox' ),
			'dash_other'               => __( 'Other', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'dash_vs_prev'             => __( 'vs previous %d days', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'dash_today_at'            => __( 'Today %s', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'dash_yesterday_at'        => __( 'Yesterday %s', 'inquiry-form-inbox' ),
			'dash_empty'               => __( 'No inquiries yet.', 'inquiry-form-inbox' ),
			'dash_all'                 => __( 'View all inquiries', 'inquiry-form-inbox' ),

			/* 启用成功反馈与安装健康检查 */
			'activation_title'         => __( 'Inquiry Form Inbox is active', 'inquiry-form-inbox' ),
			'activation_body'          => __( 'Start with the email template, review the block rules, or open the inbox.', 'inquiry-form-inbox' ),
			'activation_go_template'   => __( 'Email Template', 'inquiry-form-inbox' ),
			'activation_go_rules'      => __( 'Block Rules', 'inquiry-form-inbox' ),
			'activation_go_inbox'      => __( 'Open Inquiries', 'inquiry-form-inbox' ),
			'install_health_title'     => __( 'Inquiry Form Inbox installation check', 'inquiry-form-inbox' ),
			/* translators: %s: comma-separated plugin files that carry a plugin header. */
			'install_health_multi'     => __( 'This plugin folder contains more than one plugin header: %s. The install screen may activate the wrong file and report “The plugin does not have a valid header.” Delete the extra copies and install the official zip.', 'inquiry-form-inbox' ),
			/* translators: %s: actual main plugin file name. */
			'install_health_main'      => __( 'The plugin main file is %s, not inquiry-form-inbox.php. Reinstall the official zip so the main file has the expected name.', 'inquiry-form-inbox' ),

			/* 邮件模板设置 */
			'tpl_menu'                 => __( 'Email Template', 'inquiry-form-inbox' ),
			'tpl_card_content'         => __( 'Template Content', 'inquiry-form-inbox' ),
			'tpl_subject'              => __( 'Email Subject', 'inquiry-form-inbox' ),
			'tpl_variables'            => __( 'Available variables:', 'inquiry-form-inbox' ),
			'tpl_vars_fields'          => __( 'Fields:', 'inquiry-form-inbox' ),
			'tpl_save'                 => __( 'Save Changes', 'inquiry-form-inbox' ),
			'tpl_desktop'              => __( 'Desktop', 'inquiry-form-inbox' ),
			'tpl_mobile'               => __( 'Mobile', 'inquiry-form-inbox' ),
			'tpl_subject_hint'         => __( 'Use a field name from Field labels, e.g. {{rows.fullName.value}}. Leave empty for the default subject.', 'inquiry-form-inbox' ),
			'tpl_labels_hint'          => __( 'Field names must match what your frontend sends. Drag rows to reorder; the label becomes the row title in the email.', 'inquiry-form-inbox' ),
			'tpl_html'                 => __( 'Email Template', 'inquiry-form-inbox' ),
			'tpl_add_media'            => __( 'Add Media', 'inquiry-form-inbox' ),
			'tpl_media_insert'         => __( 'Insert', 'inquiry-form-inbox' ),
			'tpl_html_hint'            => __( 'Full HTML of the email, header included. The comments at the top of the source mark where the header text lives; {{...}} placeholders are filled with inquiry data.', 'inquiry-form-inbox' ),
			'tpl_tab_notify'           => __( 'Inquiry Notification Template', 'inquiry-form-inbox' ),
			'tpl_tab_reply'            => __( 'Auto-Confirmation Template', 'inquiry-form-inbox' ),
			'tpl_reply_on'             => __( 'Enable auto-confirmation', 'inquiry-form-inbox' ),
			'tpl_reply_on_hint'        => __( 'Sent to the address the visitor typed, right after they submit. Replies come back to the inquiry inbox.', 'inquiry-form-inbox' ),
			'tpl_ignore_unsent'        => __( 'Ignore unsent warning', 'inquiry-form-inbox' ),
			'tpl_ignore_unsent_hint'   => __( 'When enabled, all email-status warnings are suppressed, including the one shown while auto-confirmation is off.', 'inquiry-form-inbox' ),
			'tpl_lang_bar'             => __( 'Language', 'inquiry-form-inbox' ),
			'tpl_lang_default'         => __( 'Default', 'inquiry-form-inbox' ),
			'tpl_lang_code'            => __( 'Language', 'inquiry-form-inbox' ),
			'tpl_lang_add'             => __( 'Add', 'inquiry-form-inbox' ),
			'tpl_lang_delete'          => __( 'Delete this language group', 'inquiry-form-inbox' ),
			'tpl_lang_delete_confirm'  => __( 'Delete this language group and its template?', 'inquiry-form-inbox' ),
			'tpl_lang_added'           => __( 'Language group added.', 'inquiry-form-inbox' ),
			'tpl_lang_deleted'         => __( 'Language group deleted.', 'inquiry-form-inbox' ),
			'tpl_lang_exists'          => __( 'That language group already exists.', 'inquiry-form-inbox' ),
			'tpl_reply_labels_hint'    => __( 'Only affects {{rows}} in this language group.', 'inquiry-form-inbox' ),
			'tpl_lang_default_hint'    => __( 'Used when the visitor’s browser language matches no language group', 'inquiry-form-inbox' ),
			'tpl_labels_sort'          => __( 'Drag to reorder', 'inquiry-form-inbox' ),
			'tpl_preview'              => __( 'Live Preview', 'inquiry-form-inbox' ),
			'tpl_preview_note_ph'      => __( 'Preview uses placeholders — each {{...}} is replaced with the inquiry data when the email is sent.', 'inquiry-form-inbox' ),
			'tpl_test'                 => __( 'Send Test Email', 'inquiry-form-inbox' ),
			'tpl_test_hint'            => __( 'Renders the preview and sends it to the inquiry inbox — never to a customer.', 'inquiry-form-inbox' ),
			'tpl_test_ok'              => __( 'Test email sent.', 'inquiry-form-inbox' ),
			'tpl_test_fail'            => __( 'Test email failed. Check your mailer configuration.', 'inquiry-form-inbox' ),
			'ph_rows'                  => __( 'Field table. Headers use the field labels.', 'inquiry-form-inbox' ),
			'ph_rows_label'            => __( "Replace * with a field name — outputs that field's display label (e.g. {{rows.fullName.label}}).", 'inquiry-form-inbox' ),
			'ph_rows_value'            => __( "Replace * with a field name — outputs that field's value (e.g. {{rows.fullName.value}}).", 'inquiry-form-inbox' ),
			'ph_no'                    => __( 'Inquiry number', 'inquiry-form-inbox' ),
			'ph_source'                => __( 'Source page title', 'inquiry-form-inbox' ),
			'ph_form_type'             => __( 'Form style the visitor used, e.g. Drawer form', 'inquiry-form-inbox' ),
			'ph_time'                  => __( 'Submitted at, site timezone', 'inquiry-form-inbox' ),
			'ph_time_local'            => __( 'Submitted at, visitor timezone', 'inquiry-form-inbox' ),
			'ph_time_zone'             => __( 'Visitor timezone, e.g. Asia/Shanghai', 'inquiry-form-inbox' ),
			'tpl_labels'               => __( 'Field labels', 'inquiry-form-inbox' ),
			'tpl_labels_sample'        => __( 'No fields yet. Add the field names your frontend sends — each one becomes a row in the email.', 'inquiry-form-inbox' ),
			'tpl_label_add'            => __( 'Add', 'inquiry-form-inbox' ),
			'tpl_label_key'            => __( 'Field name', 'inquiry-form-inbox' ),
			'tpl_label_value'          => __( 'Label', 'inquiry-form-inbox' ),
			'tpl_label_remove'         => __( 'Remove field', 'inquiry-form-inbox' ),

			/* 垃圾规则 */
			'spam_menu'                => __( 'Block Rules', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'spam_stats_total'         => __( '%s blocked in the last 30 days', 'inquiry-form-inbox' ),
			'spam_card_honeypot'       => __( 'Honeypot', 'inquiry-form-inbox' ),
			'spam_honeypot_hint'       => __( 'A field real visitors never see. Submissions that fill it are bots.', 'inquiry-form-inbox' ),
			'spam_honeypot_on'         => __( 'Enable the honeypot', 'inquiry-form-inbox' ),
			'spam_honeypot_field'      => __( 'Hidden field name', 'inquiry-form-inbox' ),
			'spam_honeypot_field_hint' => __( 'Your frontend posts this field and hides it with CSS. Letters, digits, hyphens and underscores only.', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'spam_honeypot_filter'     => __( 'A code filter (ifi_honeypot_field) is overriding this setting: the form must post %s. The value above takes effect again once that filter is removed.', 'inquiry-form-inbox' ),
			'spam_card_content'        => __( 'Content rules', 'inquiry-form-inbox' ),
			'spam_card_content_hint'   => __( 'Keywords and links are checked in every text field the form sends, except the email address — that one is matched by domain.', 'inquiry-form-inbox' ),
			'spam_keywords'            => __( 'Blocked keywords', 'inquiry-form-inbox' ),
			'spam_keywords_hint'       => __( 'One per line. Case-insensitive and matched anywhere in the text, so prefer phrases over short words.', 'inquiry-form-inbox' ),
			'spam_domains'             => __( 'Blocked email domains', 'inquiry-form-inbox' ),
			'spam_domains_hint'        => __( 'One per line, e.g. example.com. Subdomains are covered too.', 'inquiry-form-inbox' ),
			'spam_max_links'           => __( 'Maximum links', 'inquiry-form-inbox' ),
			'spam_max_links_hint'      => __( 'Counts http://, https:// and www. links across all text fields. 0 = no limit.', 'inquiry-form-inbox' ),
			'spam_card_network'        => __( 'Network and duplicates', 'inquiry-form-inbox' ),
			'spam_card_network_hint'   => __( 'Hard blocks and duplicate detection.', 'inquiry-form-inbox' ),
			'spam_card_rate'           => __( 'Rate limits', 'inquiry-form-inbox' ),
			'spam_card_rate_hint'      => __( 'How many submissions a visitor can send before the API returns HTTP 429.', 'inquiry-form-inbox' ),
			'spam_ips'                 => __( 'Blocked IP addresses', 'inquiry-form-inbox' ),
			'spam_ips_hint'            => __( 'One per line: 203.0.113.10, 2001:db8::1, or a range like 203.0.113.0/24. Matching requests are dropped on arrival and are not recorded.', 'inquiry-form-inbox' ),
			'spam_duplicate'           => __( 'Duplicate window', 'inquiry-form-inbox' ),
			'spam_duplicate_hint'      => __( 'Blocks the same message from the same email again inside this window. 0 = off.', 'inquiry-form-inbox' ),
			/* translators: 1: submissions allowed per minute, 2: submissions allowed per hour. */
			'spam_rate_minute'         => __( 'Per minute', 'inquiry-form-inbox' ),
			'spam_rate_hour'           => __( 'Per hour', 'inquiry-form-inbox' ),
			'spam_rate_hint'           => __( '0 turns that window off. Over the limit, the API returns HTTP 429 with a Retry-After header; no record is created.', 'inquiry-form-inbox' ),
			'spam_minutes'             => __( 'minutes', 'inquiry-form-inbox' ),
			'spam_card_action'         => __( 'When a rule matches', 'inquiry-form-inbox' ),
			'spam_archive'             => __( 'Keep blocked submissions in the Spam view', 'inquiry-form-inbox' ),
			'spam_archive_hint'        => __( 'Blocked submissions are never emailed. Turn this off to drop them without a trace.', 'inquiry-form-inbox' ),
			'spam_card_test'           => __( 'Test a submission', 'inquiry-form-inbox' ),
			'spam_test_hint'           => __( 'Runs the rules in this form, including unsaved edits. Nothing is stored and no email is sent.', 'inquiry-form-inbox' ),
			'spam_test_name'           => __( 'Name', 'inquiry-form-inbox' ),
			'spam_test_ip'             => __( 'IP address', 'inquiry-form-inbox' ),
			'spam_test_run'            => __( 'Run test', 'inquiry-form-inbox' ),
			'spam_test_empty'          => __( 'Fill in at least one field to run the test.', 'inquiry-form-inbox' ),
			'spam_test_clean'          => __( 'No rules matched', 'inquiry-form-inbox' ),
			'spam_test_clean_hint'     => __( 'This submission would be delivered.', 'inquiry-form-inbox' ),
			'spam_test_blocked'        => __( 'Would be blocked', 'inquiry-form-inbox' ),
			'spam_test_ip_blocked'     => __( 'Dropped on arrival', 'inquiry-form-inbox' ),
			'spam_test_ip_hint'        => __( 'This IP is on the blocklist: the request would be dropped and not recorded.', 'inquiry-form-inbox' ),
			'spam_reason_honeypot'     => __( 'Honeypot', 'inquiry-form-inbox' ),
			'spam_reason_keyword'      => __( 'Keyword', 'inquiry-form-inbox' ),
			'spam_reason_domain'       => __( 'Email domain', 'inquiry-form-inbox' ),
			'spam_reason_links'        => __( 'Too many links', 'inquiry-form-inbox' ),
			'spam_reason_duplicate'    => __( 'Duplicate submission', 'inquiry-form-inbox' ),
			'spam_reason_ip'           => __( 'Blocked IP', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'spam_bad_ips'             => __( 'These IP entries are not valid addresses or ranges and will never match: %s', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'spam_bad_domains'         => __( 'These email domains do not look valid and will never match: %s', 'inquiry-form-inbox' ),
			/* translators: 1: the field name that was rejected, 2: the field name now in use. */
			'spam_bad_field'           => __( '“%1$s” cannot be used as the hidden field name — it needs letters, digits, hyphens or underscores, and cannot be one of the form’s own fields. Using %2$s instead.', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'spam_detail_keyword'      => __( 'matched "%s"', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'spam_detail_domain'       => __( 'domain %s', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'spam_detail_links'        => __( '%s links', 'inquiry-form-inbox' ),

			/* 行操作与详情 */
			'view_action'              => __( 'View', 'inquiry-form-inbox' ),
			'restore_action'           => __( 'Restore', 'inquiry-form-inbox' ),
			'delete_action'            => __( 'Delete', 'inquiry-form-inbox' ),
			'trash_action'             => _x( 'Trash', 'row action', 'inquiry-form-inbox' ),
			'confirm_delete'           => __( 'Delete this inquiry and its attachments? This cannot be undone.', 'inquiry-form-inbox' ),
			'deleted_notice'           => __( 'Inquiry deleted.', 'inquiry-form-inbox' ),
			'invalid_request'          => __( 'Invalid request.', 'inquiry-form-inbox' ),
			'not_found_msg'            => __( 'Inquiry not found.', 'inquiry-form-inbox' ),

			/* 错误与提示（后台校验、REST 响应、上传校验共用） */
			'error_forbidden'          => __( 'Sorry, you are not allowed to access this page.', 'inquiry-form-inbox' ),
			/* translators: %s: form field name. */
			'error_missing_field'      => __( 'Missing required field: %s', 'inquiry-form-inbox' ),
			'error_invalid_email'      => __( 'Invalid email address.', 'inquiry-form-inbox' ),
			'error_rate_limited'       => __( 'Too many submissions. Please try again later.', 'inquiry-form-inbox' ),
			'error_mail_failed'        => __( 'Your inquiry could not be sent. Please email us directly.', 'inquiry-form-inbox' ),
			'error_upload_dir'         => __( 'The upload could not be processed. Please try again.', 'inquiry-form-inbox' ),
			'error_upload_failed'      => __( 'The upload failed. Please try again.', 'inquiry-form-inbox' ),
			'error_upload_too_many'    => __( 'Too many files in one submission.', 'inquiry-form-inbox' ),
			/* translators: %s: file name. */
			'error_upload_empty'       => __( '%s is empty.', 'inquiry-form-inbox' ),
			/* translators: %s: file name. */
			'error_upload_size'        => __( '%s has an invalid declared size.', 'inquiry-form-inbox' ),
			/* translators: 1: file name, 2: size limit in megabytes. */
			'error_upload_large'       => __( '%1$s exceeds the %2$d MB limit.', 'inquiry-form-inbox' ),
			'error_upload_store'       => __( 'The upload could not be stored.', 'inquiry-form-inbox' ),
			'error_unknown_sender'     => __( 'Unnamed', 'inquiry-form-inbox' ),
			'attachment_not_archived'  => __( 'Sent with the notification email, not archived under uploads.', 'inquiry-form-inbox' ),
			'view_page_title'          => __( 'View Inquiry', 'inquiry-form-inbox' ),
			'back'                     => __( '← All Inquiries', 'inquiry-form-inbox' ),
			'content_heading'          => __( 'Inquiry content', 'inquiry-form-inbox' ),
			'modal_close'              => __( 'Close', 'inquiry-form-inbox' ),
			'modal_loading'            => __( 'Loading…', 'inquiry-form-inbox' ),
			'modal_error'              => __( 'Could not load this inquiry.', 'inquiry-form-inbox' ),
			'modal_hint'               => __( 'Inquiry details', 'inquiry-form-inbox' ),
			'modal_prev'               => __( 'Previous inquiry', 'inquiry-form-inbox' ),
			'modal_next'               => __( 'Next inquiry', 'inquiry-form-inbox' ),
			'modal_reply'              => __( 'Reply', 'inquiry-form-inbox' ),
			'modal_delete'             => __( 'Delete', 'inquiry-form-inbox' ),
			'reply_menu'               => __( 'Reply Template', 'inquiry-form-inbox' ),
			'reply_to'                 => __( 'To', 'inquiry-form-inbox' ),
			'reply_cc'                 => __( 'Cc', 'inquiry-form-inbox' ),
			'reply_bcc'                => __( 'Bcc', 'inquiry-form-inbox' ),
			'reply_card_hint'          => __( 'Used by the reply button in the inquiry detail — matched to the visitor’s browser language, falling back to the default group.', 'inquiry-form-inbox' ),
			'reply_subject'            => __( 'Subject', 'inquiry-form-inbox' ),
			'reply_body'               => __( 'Body', 'inquiry-form-inbox' ),
			'reply_addr_hint'          => __( 'Separate multiple addresses with commas.', 'inquiry-form-inbox' ),
			'form_page'                => __( 'Page form', 'inquiry-form-inbox' ),
			'form_modal'               => __( 'Popup form', 'inquiry-form-inbox' ),
			'form_drawer'              => __( 'Drawer form', 'inquiry-form-inbox' ),
			'form_floating'            => __( 'Floating form', 'inquiry-form-inbox' ),
			'form_sticky'              => __( 'Sticky form', 'inquiry-form-inbox' ),
			/* translators: Placeholders must be preserved. */
			'email_subject'            => __( 'New inquiry from %s', 'inquiry-form-inbox' ),
			'read_only'                => __( 'Inquiries are read-only. Open them from the Inquiries list.', 'inquiry-form-inbox' ),
			'email_subject_reply'      => __( 'We received your inquiry', 'inquiry-form-inbox' ),
		);

		/**
		 * Filter the string dictionaries.
		 *
		 * The `en` entry is the base map for every locale (its values already went through
		 * gettext under the active locale). A per-locale entry is applied as an **overlay**:
		 * returning a single key does not wipe the other two hundred.
		 */
		$all = apply_filters(
			'ifi_strings',
			array(
				'en'    => $map,
				'zh_CN' => array(),
			)
		);

		$base    = ( isset( $all['en'] ) && is_array( $all['en'] ) ) ? $all['en'] : $map;
		$overlay = ( isset( $all[ $locale ] ) && is_array( $all[ $locale ] ) ) ? $all[ $locale ] : array();
		$dict    = $overlay ? array_merge( $base, $overlay ) : $base;

		self::$string_cache[ $locale ] = $dict;
		return $dict;
	}

	/**
	 * Drop every cached string dictionary.
	 *
	 * Cached per locale because the map is built from 200-plus gettext calls; dropped when
	 * the extension point changes it out from under us, and by the test suite.
	 */
	public static function flush_string_cache(): void {
		self::$string_cache = array();
	}

	/**
	 * Load the plugin text domain and allow bundled MO files to win when present.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain(
			'inquiry-form-inbox',
			false,
			dirname( plugin_basename( IFI_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Look up a UI string by dictionary key.
	 *
	 * Returning an empty string for an unknown key used to fail silently: a mistyped key
	 * produced a blank button, a blank table header or a blank notice, with no error
	 * anywhere. tests/static/lint-strings.php is the primary guard; this fallback makes a
	 * missed key visible at runtime instead of invisible.
	 *
	 * @param string      $key    Dictionary key.
	 * @param string|null $locale Optional locale override.
	 * @return string The string, or the key itself when it is not declared.
	 */
	private static function t( string $key, ?string $locale = null ): string {
		$strings = self::strings( $locale );
		if ( isset( $strings[ $key ] ) ) {
			return (string) $strings[ $key ];
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: dictionary key that has no string. */
					esc_html__( 'Unknown string key: %s', 'inquiry-form-inbox' ),
					esc_html( $key )
				),
				'3.0.15'
			);
		}

		return $key;
	}

	/**
	 * Render with a specific site locale active.
	 *
	 * The auto-confirmation is written in the visitor's language, but `__()` resolves
	 * against the site locale. Switching around the render is what makes a language group
	 * real: without it the template swapped language while every fixed label — the default
	 * subject, "Attachments", "Page form" — stayed in the administrator's language.
	 *
	 * @param string   $lang   Canonical group tag, e.g. zh-hans. Empty = default group.
	 * @param callable $render Callback that renders with the locale in place.
	 * @return mixed Whatever $render returns.
	 */
	private static function with_locale( string $lang, callable $render ) {
		$locale = self::locale_for_lang( $lang );
		if ( '' === $locale || ! function_exists( 'switch_to_locale' ) ) {
			return $render();
		}

		if ( switch_to_locale( $locale ) ) {
			try {
				return $render();
			} finally {
				restore_previous_locale();
			}
		}

		return self::with_textdomain( $locale, $render );
	}

	/**
	 * Render with only this plugin's text domain swapped.
	 *
	 * switch_to_locale() refuses any locale that is not installed site-wide. A Chinese
	 * site answering an English visitor hits exactly that: the visitor's group is `en`,
	 * but only zh_CN is installed, so the switch is rejected and the auto-confirmation
	 * would render its fixed labels in Chinese. Fall back to loading just our own MO for
	 * the duration of the render, then put the site translation back.
	 *
	 * Needs unload_textdomain() (6.1+): without it there is no clean way to restore the
	 * domain, so the render is left in the site locale instead of risking a half-swapped
	 * text domain leaking into the rest of the request.
	 *
	 * @param string   $locale Locale name, e.g. en_US.
	 * @param callable $render Callback that renders with the domain swapped.
	 * @return mixed Whatever $render returns.
	 */
	private static function with_textdomain( string $locale, callable $render ) {
		if ( ! function_exists( 'unload_textdomain' ) ) {
			return $render();
		}

		$mofile = '';
		if ( 'en_US' !== $locale ) {
			$candidates = array(
				WP_LANG_DIR . '/plugins/inquiry-form-inbox-' . $locale . '.mo',
				dirname( IFI_PLUGIN_FILE ) . '/languages/inquiry-form-inbox-' . $locale . '.mo',
			);
			foreach ( $candidates as $candidate ) {
				if ( is_readable( $candidate ) ) {
					$mofile = $candidate;
					break;
				}
			}
			if ( '' === $mofile ) {
				return $render();
			}
		}

		// load_textdomain() will not replace an already-loaded domain, so unload first.
		unload_textdomain( 'inquiry-form-inbox' );
		if ( '' !== $mofile ) {
			load_textdomain( 'inquiry-form-inbox', $mofile );
		}

		// Key the string dictionary by the swapped locale: without this the per-locale
		// cache would keep serving the site-locale map and the swap would be invisible.
		$previous_locale     = self::$render_locale;
		self::$render_locale = $locale;

		try {
			return $render();
		} finally {
			self::$render_locale = $previous_locale;
			unload_textdomain( 'inquiry-form-inbox' );
			self::load_textdomain();
		}
	}

	/**
	 * Map a canonical language tag to a WordPress locale.
	 *
	 * Only tags with a real WordPress locale are mapped. Anything else returns '' so the
	 * render stays in the site locale instead of switching to a locale that has no
	 * translation files and would change nothing but cost a locale reload.
	 *
	 * @param string $lang Canonical tag from canonical_lang().
	 * @return string Locale name, or '' when unmapped.
	 */
	private static function locale_for_lang( string $lang ): string {
		$map = array(
			'en'      => 'en_US',
			'zh-hans' => 'zh_CN',
			'zh-hant' => 'zh_TW',
			'ja'      => 'ja',
			'ko'      => 'ko_KR',
			'de'      => 'de_DE',
			'fr'      => 'fr_FR',
			'es'      => 'es_ES',
			'pt'      => 'pt_BR',
			'it'      => 'it_IT',
			'nl'      => 'nl_NL',
			'ru'      => 'ru_RU',
			'pl'      => 'pl_PL',
			'tr'      => 'tr_TR',
			'ar'      => 'ar',
			'th'      => 'th',
			'vi'      => 'vi',
			'id'      => 'id_ID',
			'hi'      => 'hi_IN',
		);

		return $map[ $lang ] ?? '';
	}

	/**
	 * 字段展示名：后台覆盖 > 前端随表单提交的标签 > 字段名转可读文本。
	 *
	 * 插件不假设任何字段叫法，也不内置字段词表：三者都没有时才退到 readable_label()。
	 *
	 * @param string     $key       字段名.
	 * @param array|null $labels    后台保存的覆盖表（预览时要传未保存的那份）.
	 * @param array|null $submitted 前端提交时带来的 fieldLabels.
	 */
	private static function field_label( string $key, ?array $labels = null, ?array $submitted = null ): string {
		$labels = $labels ?? (array) ( self::tpl()['labels'] ?? array() );

		// 1) 后台覆盖（站点管理员说了算）
		$custom = trim( (string) ( $labels[ $key ] ?? '' ) );
		if ( $custom !== '' ) {
			return $custom;
		}

		// 2) 前端提交时带来的标签（对接方自己的文案）
		$from_form = trim( (string) ( ( $submitted ?? array() )[ $key ] ?? '' ) );
		if ( $from_form !== '' ) {
			return $from_form;
		}

		// 3) 都没有：字段名本身转为可读文本，插件不假设任何字段叫法
		return self::readable_label( $key );
	}

	/**
	 * 字段名的可读化：插件对字段名做的唯一「翻译」。
	 *
	 * outerDiameter → Outer Diameter；shaft_diameter → Shaft Diameter；material1 → Material 1
	 *
	 * 驼峰断词只在两个位置发生：小写/数字之后遇到大写（outer|Diameter），或连续大写
	 * 之后跟着一个「大写+小写」的词首（HTTP|Server）。**不是**每个大写字母前都断，
	 * 否则缩写会被切成 S K U、I D。
	 */
	private static function readable_label( string $key ): string {
		$label = (string) preg_replace( '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $key );
		$label = str_replace( array( '-', '_' ), ' ', $label );
		$label = (string) preg_replace( '/(?<=[a-zA-Z])(?=\d)/', ' ', $label );
		$label = (string) preg_replace( '/\s+/', ' ', $label );
		return ucwords( trim( $label ) );
	}
}
