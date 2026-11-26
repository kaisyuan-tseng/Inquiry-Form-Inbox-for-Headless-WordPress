<?php
/**
 * Simplified Chinese translations for strings added during the 3.0.15 normalization pass.
 *
 * Keyed by msgid so tools/fill-translations.php can drop them into the PO without
 * disturbing existing entries. Full-width punctuation is correct here — that is the whole
 * reason the "label: value" separators became translatable format strings.
 *
 * @package Inquiry_Form_Inbox
 */

return array(
	'This plugin stores inquiry data submitted through the site contact form: name, email, message, form fields, browser language, timezone, IP address, source page, and any uploaded attachments. Records are visible only to authorized administrators and are used to send inquiry notifications and delivery confirmations. Use the WordPress privacy tools to export or erase records by email address; deleting an inquiry also removes its attachments.'
		=> '本插件保存通过站点联系表单提交的询盘数据：姓名、邮箱、留言、表单字段、浏览器语言、时区、IP 地址、来源页面以及上传的附件。这些记录仅对有权查看询盘的管理员可见，用于发送询盘通知与送达确认。可在 WordPress 隐私工具中按邮箱地址导出或删除记录；删除询盘时附件会一并清除。',

	'Inquiry Form Inbox migration error: %s' => '询盘表单收件箱迁移出错：%s',
	'Inquiry %s could not be deleted.'        => '询盘 %s 无法删除。',

	'Page locale'   => '页面语言',
	'Delivery error' => '投递错误',

	// Full-width separators: this is what the hard-coded '：'/'（）' used to do, correctly.
	'%1$s: %2$s'  => '%1$s：%2$s',
	'%1$s (%2$s)' => '%1$s（%2$s）',

	'Live Preview' => '实时预览',

	// 同一个 msgid「Trash」在两种语境下的译法不同，键写成 "context\x04msgid"。
	"list view\x04Trash"  => '回收站',
	"row action\x04Trash" => '移至回收站',

	'When enabled, an auto-confirmation that was not sent is not counted as an email problem. Failed inquiry notifications are still reported.'
		=> '开启后，自动确认邮件未发出不计入发信异常；询盘通知失败仍会计入。',

	'Sorry, you are not allowed to access this page.' => '抱歉，你无权访问此页面。',
	'Missing required field: %s'                     => '缺少必填字段：%s',
	'Invalid email address.'                         => '邮箱地址无效。',
	'Too many submissions. Please try again later.'  => '提交过于频繁，请稍后再试。',

	'The upload could not be processed. Please try again.' => '附件处理失败，请重试。',
	'The upload failed. Please try again.'                 => '上传失败，请重试。',
	'Too many files in one submission.'                    => '单次提交的附件过多。',
	'File type .%s is not allowed.'                        => '不允许上传 .%s 类型的文件。',
	'%s is empty.'                                         => '%s 是空文件。',
	'%s has an invalid declared size.'                     => '%s 声明的文件大小与实际不符。',
	'%1$s exceeds the %2$d MB limit.'                      => '%1$s 超过 %2$d MB 上限。',
	'The upload could not be stored.'                      => '附件保存失败。',

	'Sent with the notification email, not archived under uploads.' => '已随通知邮件发送，未归档到 uploads 目录。',

	// Development notice, shown only with WP_DEBUG.
	'Unknown string key: %s' => '未知的字符串键：%s',
);
