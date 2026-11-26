<?php
/**
 * Terminology review — zh_CN (3.0.15).
 *
 * English source strings are the master. Where the wording appears verbatim in WordPress's
 * own zh_CN language pack, that translation wins: Desktop, Mobile, Indonesian, Keyword,
 * and the polite 您. The few deliberate departures are listed in docs/TRANSLATION-REVIEW.md
 * with the reason each one is kept.
 *
 * Applied with:
 *   php tools/fill-translations.php languages/inquiry-form-inbox-zh_CN.po tools/i18n/zh_CN-review.php
 *   php tools/wp-cli.phar i18n make-mo languages/
 *
 * @package Inquiry_Form_Inbox
 */

return array(
	// --- WordPress core terminology -----------------------------------------
	'Desktop'                                    => '桌面端',
	'Mobile'                                     => '移动端',
	'Indonesian'                                 => '印度尼西亚语',
	'Keyword'                                    => '关键字',
	'Blocked keywords'                           => '屏蔽关键字',
	'Keywords and links are checked in every text field the form sends, except the email address — that one is matched by domain.'
		=> '关键字与链接会扫描表单提交的所有文本字段，邮箱除外——邮箱按下面的域名规则匹配。',
	'Sorry, you are not allowed to access this page.' => '抱歉，您无权访问此页面。',

	// All → 全部, as in core. Applies to the menu label and the back link too.
	'All Inquiries'                              => '全部询盘',
	'View all inquiries'                         => '查看所有询盘',
	'← All Inquiries'                            => '← 全部询盘',
	'All sources'                                => '全部来源',

	// --- Fidelity to the English source -------------------------------------
	'This plugin stores inquiry data submitted through the site contact form: name, email, message, form fields, browser language, timezone, IP address, source page, and any uploaded attachments. Records are visible only to authorized administrators and are used to send inquiry notifications and delivery confirmations. Use the WordPress privacy tools to export or erase records by email address; deleting an inquiry also removes its attachments.'
		=> '本插件保存通过站点联系表单提交的询盘数据：姓名、邮箱、消息、表单字段、浏览器语言、时区、IP 地址、来源页面以及上传的附件。这些记录仅对有权查看询盘的管理员可见，用于发送询盘通知和送达确认。可在 WordPress 隐私工具中按邮箱地址导出或删除记录；删除询盘时附件会一并清除。',

	'Source page'                                => '来源页面',
	'No spam today'                              => '今日无垃圾询盘',
	'Inquiry details'                            => '询盘详情',
	'Could not load this inquiry.'               => '无法加载这条询盘。',
	'New inquiry from %s'                        => '来自 %s 的新询盘',
	'Inquiries are read-only. Open them from the Inquiries list.'
		=> '询盘为只读，请从「询盘」列表打开。',
	'Languages'                                  => '语言',

	// One term for "reply" throughout: 回复, never 回信.
	'Sent to the address the visitor typed, right after they submit. Replies come back to the inquiry inbox.'
		=> '访客提交后立即发到其填写的地址；回复会回到询盘收件箱。',

	// Remove Chinese that has no counterpart in the source.
	'Hard blocks and duplicate detection.'       => '硬性拦截与重复检测。',
	'One per line. Case-insensitive and matched anywhere in the text, so prefer phrases over short words.'
		=> '每行一个。不区分大小写，可匹配文本中的任意位置；建议用短语，少用短词。',
	'One per line: 203.0.113.10, 2001:db8::1, or a range like 203.0.113.0/24. Matching requests are dropped on arrival and are not recorded.'
		=> '每行一个：203.0.113.10、2001:db8::1，或 203.0.113.0/24 这样的网段。命中的请求到达时即丢弃，不留记录。',
	'“%1$s” cannot be used as the hidden field name — it needs letters, digits, hyphens or underscores, and cannot be one of the form’s own fields. Using %2$s instead.'
		=> '「%1$s」不能作为隐藏字段名：只能用字母、数字、下划线或短横线，也不能与表单自身的字段重名。已改用 %2$s。',
	'Your frontend posts this field and hides it with CSS. Letters, digits, hyphens and underscores only.'
		=> '前端提交这个字段，并用 CSS 把它藏起来；只允许字母、数字、下划线和短横线。',
	'A code filter (ifi_honeypot_field) is overriding this setting: the form must post %s. The value above takes effect again once that filter is removed.'
		=> '代码里注册了 ifi_honeypot_field 过滤器，实际生效的字段名是 %s；移除该过滤器之前，上面填的值不会生效。',
	'The upload failed. Please try again.'       => '上传失败。',

	// Make the Spam view name consistent wherever it is quoted.
	'Keep blocked submissions in the Spam view'  => '将被拦提交保留在「垃圾询盘」视图',

	// Terminology: delivery → 发送, matching 发信状态 and 已送达 elsewhere.
	'Delivery error'                             => '发送错误',
	'Inquiry Form Inbox migration error: %s'     => '询盘收件箱迁移出错：%s',

	// Product names stay in English; do not translate the header title.
	'Inquiry Form Inbox for Headless WordPress'  => 'Inquiry Form Inbox for Headless WordPress',
);
