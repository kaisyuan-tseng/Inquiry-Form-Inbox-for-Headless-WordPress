<?php
/**
 * Simplified Chinese translations for strings added in 3.1.2.
 *
 * The rate-limit thresholds moved from a read-only sentence to two inputs on Block Rules,
 * so the sentence is gone and four new strings replace it.
 *
 * Applied with:
 *   php tools/fill-translations.php languages/inquiry-form-inbox-zh_CN.po tools/i18n/zh_CN-3.1.2.php
 *   php tools/wp-cli.phar i18n make-mo languages/
 *
 * @package Inquiry_Form_Inbox
 */

return array(
	'Rate limits'                    => '限流',
	'How many submissions a visitor can send before the API returns HTTP 429.'
		=> '同一访客在每个时间窗口内最多能提交多少次，超出后接口返回 HTTP 429。',
	'Per minute'                     => '每分钟',
	'Per hour'                       => '每小时',
	'0 turns that window off. Over the limit, the API returns HTTP 429 with a Retry-After header; no record is created.'
		=> '0 表示关闭该窗口。超出上限时，接口返回 HTTP 429 并附带 Retry-After 响应头；这类请求不会生成记录。',

	// 同一页另一处“什么都不保存”：原来写“不入库”，与上面的新说法统一。
	'Runs the rules in this form, including unsaved edits. Nothing is stored and no email is sent.'
		=> '用表单里这份规则试跑（包括还没保存的改动），不会生成记录，也不会发信。',

	// 子菜单首项（wp-first-item）与详情返回链接统一为「所有询盘」：
	// 覆盖 3.0.15 依据核心 All → 全部 作出的「全部询盘」，和仪表盘的「查看所有询盘」一致。
	'All Inquiries'                     => '所有询盘',
	'← All Inquiries'                   => '← 所有询盘',
);
