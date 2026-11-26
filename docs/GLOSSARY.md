# 界面用语总表

只收录**后台界面上看得见、读得到**的词：菜单名、页面标题、视图、状态徽章、按钮、提示、
字段标签、错误提示，以及这些词的定译。数据键、REST 契约、钩子和开发者命名约定不在本表
范围内。

表是从代码里导出的：225 条界面文案 = `IFI_I18n_Trait::strings()` 的键 + `languages/*.po`
的英文 msgid + zh_CN 定译。表中的 `Key` 只用于
在代码里定位这条文案，**不是界面上显示的文字**。

---

## 一、界面上出现的名字

| 位置 | 英文 | 中文 | 注意 |
|---|---|---|---|
| 插件名（插件列表页） | Inquiry Form Inbox for Headless WordPress | 同左 | 只在插件列表出现，后台内部不用全名 |
| 左侧顶层菜单 | Inquiries | 询盘 | **不是** Forms / Messages / Leads |
| 子菜单「全部」 | All Inquiries | 所有询盘 | 只作菜单标签，浏览器标题仍是 Inquiries |
| 列表页 | Inquiries | 询盘 | `h1` |
| 邮件模板页 | Email Template | 邮件模板 | 三个页签：Inquiry notification / Auto-confirmation / Reply template |
| 拦截规则页 | Block Rules | 拦截规则 | |
| 详情弹窗 | View Inquiry | 查看询盘 | 标题来自该条询盘的姓名；这是浏览器标题 |
| 仪表盘挂件 | Recent inquiries | 最近询盘 | |

## 二、列表顶部的视图

| 英文 | 中文 | 显示什么 |
|---|---|---|
| All | 全部 | 排除垃圾询盘与回收站 |
| Pending | 待处理 | 还没标记「已联系」的 |
| Contacted | 已联系 | 已标记「已联系」的，可撤销 |
| Spam | 垃圾询盘 | 被规则拦下但留档的 |
| Trash | 回收站 | 已删除、可恢复的 |

> **Pending 是唯一说法。** 2.x 用过 "New"，文档里还残留过，现已统一。不要再出现
> New / Unread / Open / 新询盘 / 未读。

## 三、发信状态徽章

列表「Automatic Status」列把两封邮件压成**四选一**的徽章：

| 英文 | 中文 | 含义 |
|---|---|---|
| All Sent | 已全部发送 | 通知与自动确认都成功 |
| Only Notification | 仅通知 | 只有通知成功 |
| Only Auto-Confirmation | 仅自动确认 | 只有访客确认信成功 |
| Not Sent | 未发送 | 两封都没成功，或根本没尝试 |

每种组合的悬停说明见文案表里 `status_*_hint` 四条。

单封邮件的原始状态只有三个：`Sent`（已发送）、`Failed`（发送失败）、空（未尝试，不显示）。

## 四、界面文案全表

按所在页面分组。`Key` 仅用于定位代码，界面上不显示。


225 条，按所在页面分组。`Key` 是代码里的字典键（`self::t('…')` 的实参），
`English` 是 msgid（gettext 源串，也是 `languages/*.po` 的键），`中文` 是 zh_CN 定译。


#### 导航与页面（6 条）

| Key | English (msgid) | 中文 |
|---|---|---|
| `menu_name` | Inquiries | 询盘 |
| `all_items` | All Inquiries | 所有询盘 |
| `search_items` | Search Inquiries | 搜索询盘 |
| `back` | ← All Inquiries | ← 所有询盘 |
| `content_heading` | Inquiry content | 询盘内容 |
| `reply_menu` | Reply Template | 回复模板 |

#### 列表列名（8 条）

| Key | English (msgid) | 中文 |
|---|---|---|
| `col_no` | # | # |
| `col_from` | From | 来自 |
| `col_message` | Message | 消息 |
| `col_source` | Source | 来源 |
| `col_attachments` | Attachments | 附件 |
| `col_status` | Delivery status | 发信状态 |
| `col_received` | Received | 接收时间 |
| `col_email` | Email | 邮箱 |

#### 视图与空态（11 条）

| Key | English (msgid) | 中文 |
|---|---|---|
| `not_found` | No inquiries yet. | 暂无询盘。 |
| `not_found_trash` | No inquiries in Trash. | 回收站中没有询盘。 |
| `view_pending` | Pending | 待处理 |
| `view_contacted` | Contacted | 已联系 |
| `view_spam` | Spam | 垃圾询盘 |
| `view_all` | All | 全部 |
| `badge_new` | New（gettext 上下文 `inquiry badge`） | 新增 |
| `badge_spam` | Spam（gettext 上下文 `inquiry badge`） | 垃圾 |
| `view_trash` | Trash（gettext 上下文 `list view`） | 回收站 |
| `all_sources` | All sources | 所有来源 |
| `not_found_msg` | Inquiry not found. | 询盘不存在。 |

#### 发信状态徽章（10 条）

| Key | English (msgid) | 中文 |
|---|---|---|
| `status_all` | All Sent | 已全部发送 |
| `status_only_notify` | Only Notification | 仅通知 |
| `status_only_reply` | Only Auto-Confirmation | 仅自动确认 |
| `status_none` | Not Sent | 未发送 |
| `status_none_hint` | Neither the inquiry notification nor the auto-confirmation was sent | 询盘通知、自动确认均未发送 |
| `status_notify_only` | Only the inquiry notification was sent | 仅发送询盘通知 |
| `status_reply_only` | Only the auto-confirmation was sent | 仅发送自动确认 |
| `status_all_hint` | Both the inquiry notification and the auto-confirmation were sent | 询盘通知、自动确认均已发送 |
| `status_yes` | Sent | 已发送 |
| `status_no` | Failed | 发送失败 |

#### 行操作与提示（18 条）

| Key | English (msgid) | 中文 |
|---|---|---|
| `mark_contacted` | Mark as Contacted | 标记为已联系 |
| `not_spam_action` | Not Spam | 不是垃圾 |
| `not_spam_notice` | Marked as not spam. | 已标记为非垃圾。 |
| `mark_pending` | Mark as Pending | 标记为待处理 |
| `contacted_notice` | Marked as contacted. | 已标记为已联系。 |
| `uncontacted_notice` | Marked as pending. | 已标记为待处理。 |
| `view_action` | View | 查看 |
| `restore_action` | Restore | 恢复 |
| `delete_action` | Delete | 删除 |
| `trash_action` | Trash（gettext 上下文 `row action`） | 移至回收站 |
| `confirm_delete` | Delete this inquiry and its attachments? This cannot be undone. | 确定删除这条询盘及其附件？此操作不可恢复。 |
| `deleted_notice` | Inquiry deleted. | 询盘已删除。 |
| `attachment_not_archived` | Sent with the notification email, not archived under uploads. | 已随通知邮件发送，未归档到 uploads 目录。 |
| `form_page` | Page form | 页面表单 |
| `form_modal` | Popup form | 弹窗表单 |
| `form_drawer` | Drawer form | 抽屉表单 |
| `form_floating` | Floating form | 悬浮表单 |
| `form_sticky` | Sticky form | 固定表单 |

#### 详情弹窗（10 条）

| Key | English (msgid) | 中文 |
|---|---|---|
| `view_page_title` | View Inquiry | 查看询盘 |
| `modal_close` | Close | 关闭 |
| `modal_loading` | Loading… | 加载中… |
| `modal_error` | Could not load this inquiry. | 无法加载这条询盘。 |
| `modal_hint` | Inquiry details | 询盘详情 |
| `modal_prev` | Previous inquiry | 上一条询盘 |
| `modal_next` | Next inquiry | 下一条询盘 |
| `modal_reply` | Reply | 回复 |
| `modal_delete` | Delete | 删除 |
| `read_only` | Inquiries are read-only. Open them from the Inquiries list. | 询盘为只读，请从「询盘」列表打开。 |

#### 邮件模板页（53 条）

| Key | English (msgid) | 中文 |
|---|---|---|
| `tpl_menu` | Email Template | 邮件模板 |
| `tpl_card_content` | Template Content | 模板内容 |
| `tpl_subject` | Email Subject | 邮件主题 |
| `tpl_variables` | Available variables: | 可用变量： |
| `tpl_vars_fields` | Fields: | 字段： |
| `tpl_save` | Save Changes | 保存更改 |
| `tpl_desktop` | Desktop | 桌面端 |
| `tpl_mobile` | Mobile | 移动端 |
| `tpl_subject_hint` | Use a field name from Field labels, e.g. {{rows.fullName.value}}. Leave empty for the default subject. | 可使用字段标签里的字段名，例如 {{rows.fullName.value}}；留空用默认主题。 |
| `tpl_labels_hint` | Field names must match what your frontend sends. Drag rows to reorder; the label becomes the row title in the email. | 字段名要和前端表单发送的字段名一致。拖动行可调整顺序；标签会成为邮件里的行标题。 |
| `tpl_html` | Email Template | 邮件模板 |
| `tpl_add_media` | Add Media | 添加媒体 |
| `tpl_media_insert` | Insert | 插入 |
| `tpl_html_hint` | Full HTML of the email, header included. The comments at the top of the source mark where the header text lives; {{...}} placeholders are filled with inquiry data. | 整封邮件的 HTML，页头也在里面。源码顶部的注释标出了页头文案的位置；{{...}} 由询盘数据替换。 |
| `tpl_tab_notify` | Inquiry Notification Template | 询盘通知模板 |
| `tpl_tab_reply` | Auto-Confirmation Template | 自动确认模板 |
| `tpl_reply_on` | Enable auto-confirmation | 启用自动确认 |
| `tpl_reply_on_hint` | Sent to the address the visitor typed, right after they submit. Replies come back to the inquiry inbox. | 访客提交后立即发到其填写的地址；回复会回到询盘收件箱。 |
| `tpl_ignore_unsent` | Ignore unsent warning | 忽略未发送警告 |
| `tpl_ignore_unsent_hint` | When enabled, all email-status warnings are suppressed, including the one shown while auto-confirmation is off. | 开启后，邮件状态不再警告，包括自动确认未启用时的提示。 |
| `tpl_lang_bar` | Language | 语言 |
| `tpl_lang_default` | Default | 默认 |
| `tpl_lang_code` | Language | 语言 |
| `tpl_lang_add` | Add | 添加 |
| `tpl_lang_delete` | Delete this language group | 删除该语言组 |
| `tpl_lang_delete_confirm` | Delete this language group and its template? | 删除该语言组及其模板？ |
| `tpl_lang_added` | Language group added. | 语言组已添加。 |
| `tpl_lang_deleted` | Language group deleted. | 语言组已删除。 |
| `tpl_lang_exists` | That language group already exists. | 该语言组已存在。 |
| `tpl_reply_labels_hint` | Only affects {{rows}} in this language group. | 只影响当前语言组的 {{rows}}。 |
| `tpl_lang_default_hint` | Used when the visitor’s browser language matches no language group | 访客的浏览器语言没匹配到任何语言组时，发这一份 |
| `tpl_labels_sort` | Drag to reorder | 拖动排序 |
| `tpl_preview` | Live Preview | 实时预览 |
| `tpl_preview_note_ph` | Preview uses placeholders — each {{...}} is replaced with the inquiry data when the email is sent. | 预览使用占位符——发送邮件时每个 {{...}} 会被替换成询盘数据。 |
| `tpl_test` | Send Test Email | 发送测试邮件 |
| `tpl_test_hint` | Renders the preview and sends it to the inquiry inbox — never to a customer. | 把预览内容发到询盘收件箱，不会发给客户。 |
| `tpl_test_ok` | Test email sent. | 测试邮件已发送。 |
| `tpl_test_fail` | Test email failed. Check your mailer configuration. | 测试邮件发送失败，请检查邮件发送配置。 |
| `ph_rows` | Field table. Headers use the field labels. | 字段表格，表头用字段标签 |
| `ph_rows_label` | Replace * with a field name — outputs that field's display label (e.g. {{rows.fullName.label}}). | 把 * 换成字段名 —— 输出该字段的显示名（例如 {{rows.fullName.label}}）。 |
| `ph_rows_value` | Replace * with a field name — outputs that field's value (e.g. {{rows.fullName.value}}). | 把 * 换成字段名 —— 输出该字段的值（例如 {{rows.fullName.value}}）。 |
| `ph_no` | Inquiry number | 询盘编号 |
| `ph_source` | Source page title | 来源页面标题 |
| `ph_form_type` | Form style the visitor used, e.g. Drawer form | 访客使用的表单形态，如「抽屉表单」 |
| `ph_time` | Submitted at, site timezone | 提交时间（站点时区） |
| `ph_time_local` | Submitted at, visitor timezone | 提交时间（访客时区） |
| `ph_time_zone` | Visitor timezone, e.g. Asia/Shanghai | 访客时区，如 Asia/Shanghai |
| `tpl_labels` | Field labels | 字段标签 |
| `tpl_labels_sample` | No fields yet. Add the field names your frontend sends — each one becomes a row in the email. | 还没有字段。添加前端表单发送的字段名——每一行都会成为邮件里的一行。 |
| `tpl_label_add` | Add | 添加 |
| `tpl_label_key` | Field name | 字段名 |
| `tpl_label_value` | Label | 标签 |
| `tpl_label_remove` | Remove field | 删除字段 |

#### 回信模板（7 条）

| Key | English (msgid) | 中文 |
|---|---|---|
| `reply_to` | To | 收件人 |
| `reply_cc` | Cc | 抄送 |
| `reply_bcc` | Bcc | 密送 |
| `reply_card_hint` | Used by the reply button in the inquiry detail — matched to the visitor’s browser language, falling back to the default group. | 后台询盘详情里的「回复」按钮用这套内容：按访客浏览器语言匹配语言组，匹配不到用默认组。 |
| `reply_subject` | Subject | 邮件主题 |
| `reply_body` | Body | 邮件正文 |
| `reply_addr_hint` | Separate multiple addresses with commas. | 多个地址用逗号分隔。 |

#### 拦截规则页（51 条）

| Key | English (msgid) | 中文 |
|---|---|---|
| `spam_menu` | Block Rules | 拦截规则 |
| `spam_stats_total` | %s blocked in the last 30 days | 近 30 天拦截 %s 条 |
| `spam_card_honeypot` | Honeypot | 蜜罐 |
| `spam_honeypot_hint` | A field real visitors never see. Submissions that fill it are bots. | 正常访客看不到的字段；填了它的都是机器人。 |
| `spam_honeypot_on` | Enable the honeypot | 启用蜜罐 |
| `spam_honeypot_field` | Hidden field name | 隐藏字段名 |
| `spam_honeypot_field_hint` | Your frontend posts this field and hides it with CSS. Letters, digits, hyphens and underscores only. | 前端提交这个字段，并用 CSS 把它藏起来；只允许字母、数字、下划线和短横线。 |
| `spam_honeypot_filter` | A code filter (ifi_honeypot_field) is overriding this setting: the form must post %s. The value above takes effect again once that filter is removed. | 代码里注册了 ifi_honeypot_field 过滤器，实际生效的字段名是 %s；移除该过滤器之前，上面填的值不会生效。 |
| `spam_card_content` | Content rules | 内容规则 |
| `spam_card_content_hint` | Keywords and links are checked in every text field the form sends, except the email address — that one is matched by domain. | 关键字与链接会扫描表单提交的所有文本字段，邮箱除外——邮箱按下面的域名规则匹配。 |
| `spam_keywords` | Blocked keywords | 屏蔽关键字 |
| `spam_keywords_hint` | One per line. Case-insensitive and matched anywhere in the text, so prefer phrases over short words. | 每行一个。不区分大小写，可匹配文本中的任意位置；建议用短语，少用短词。 |
| `spam_domains` | Blocked email domains | 屏蔽邮箱域名 |
| `spam_domains_hint` | One per line, e.g. example.com. Subdomains are covered too. | 每行一个，例如 example.com；子域名同样拦。 |
| `spam_max_links` | Maximum links | 链接上限 |
| `spam_max_links_hint` | Counts http://, https:// and www. links across all text fields. 0 = no limit. | 统计所有文本字段里的 http://、https:// 与 www. 链接。0 = 不限。 |
| `spam_card_network` | Network and duplicates | 网络与重复提交 |
| `spam_card_network_hint` | Hard blocks and duplicate detection. | 硬性拦截与重复检测。 |
| `spam_ips` | Blocked IP addresses | 屏蔽 IP 地址 |
| `spam_ips_hint` | One per line: 203.0.113.10, 2001:db8::1, or a range like 203.0.113.0/24. Matching requests are dropped on arrival and are not recorded. | 每行一个：203.0.113.10、2001:db8::1，或 203.0.113.0/24 这样的网段。命中的请求到达时即丢弃，不留记录。 |
| `spam_duplicate` | Duplicate window | 重复提交窗口 |
| `spam_duplicate_hint` | Blocks the same message from the same email again inside this window. 0 = off. | 窗口内同一邮箱再发同样的内容会被拦下。0 = 关闭。 |
| `spam_card_rate` | Rate limits | 限流 |
| `spam_card_rate_hint` | How many submissions a visitor can send before the API returns HTTP 429. | 同一访客在每个时间窗口内最多能提交多少次，超出后接口返回 HTTP 429。 |
| `spam_rate_minute` | Per minute | 每分钟 |
| `spam_rate_hour` | Per hour | 每小时 |
| `spam_rate_hint` | 0 turns that window off. Over the limit, the API returns HTTP 429 with a Retry-After header; no record is created. | 0 表示关闭该窗口。超出上限时，接口返回 HTTP 429 并附带 Retry-After 响应头；这类请求不会生成记录。 |
| `spam_minutes` | minutes | 分钟 |
| `spam_card_action` | When a rule matches | 命中规则时 |
| `spam_archive` | Keep blocked submissions in the Spam view | 将被拦提交保留在「垃圾询盘」视图 |
| `spam_archive_hint` | Blocked submissions are never emailed. Turn this off to drop them without a trace. | 被拦的提交永远不发信。关掉这一项就直接丢弃，不留任何记录。 |
| `spam_card_test` | Test a submission | 试跑一条提交 |
| `spam_test_hint` | Runs the rules in this form, including unsaved edits. Nothing is stored and no email is sent. | 用表单里这份规则试跑（包括还没保存的改动），不会生成记录，也不会发信。 |
| `spam_test_name` | Name | 姓名 |
| `spam_test_ip` | IP address | IP 地址 |
| `spam_test_run` | Run test | 试跑 |
| `spam_test_empty` | Fill in at least one field to run the test. | 至少填一项才能试跑。 |
| `spam_test_clean` | No rules matched | 没有命中任何规则 |
| `spam_test_clean_hint` | This submission would be delivered. | 这条提交会正常送达。 |
| `spam_test_blocked` | Would be blocked | 会被拦截 |
| `spam_test_ip_blocked` | Dropped on arrival | 到达时丢弃 |
| `spam_test_ip_hint` | This IP is on the blocklist: the request would be dropped and not recorded. | 这个 IP 在黑名单里：请求会被直接丢弃，不留记录。 |
| `spam_reason_honeypot` | Honeypot | 蜜罐 |
| `spam_reason_keyword` | Keyword | 关键字 |
| `spam_reason_domain` | Email domain | 邮箱域名 |
| `spam_reason_links` | Too many links | 链接过多 |
| `spam_reason_duplicate` | Duplicate submission | 重复提交 |
| `spam_reason_ip` | Blocked IP | 屏蔽 IP |
| `spam_bad_ips` | These IP entries are not valid addresses or ranges and will never match: %s | 这些 IP 条目不是合法地址或网段，永远不会命中：%s |
| `spam_bad_domains` | These email domains do not look valid and will never match: %s | 这些邮箱域名看起来不合法，永远不会命中：%s |
| `spam_bad_field` | “%1$s” cannot be used as the hidden field name — it needs letters, digits, hyphens or underscores, and cannot be one of the form’s own fields. Using %2$s instead. | 「%1$s」不能作为隐藏字段名：只能用字母、数字、下划线或短横线，也不能与表单自身的字段重名。已改用 %2$s。 |
| `spam_detail_keyword` | matched "%s" | 命中“%s” |
| `spam_detail_domain` | domain %s | 域名 %s |
| `spam_detail_links` | %s links | %s 个链接 |

#### 仪表盘挂件（25 条）

| Key | English (msgid) | 中文 |
|---|---|---|
| `dash_title` | Recent inquiries | 最近询盘 |
| `dash_daily_title` | New Today | 今日新增 |
| `dash_pending_title` | Pending | 待处理 |
| `dash_spam_title` | Spam | 垃圾询盘 |
| `dash_spam_none_today` | No spam today | 今日无垃圾询盘 |
| `dash_spam_today` | %d today | 今天 %d 条 |
| `dash_email_problem` | Email Problem | 邮件异常 |
| `dash_email_delivered` | Emails Sent | 邮件已发送 |
| `dash_all_delivered` | All emails sent | 邮件均已发送 |
| `dash_failed_notify` | %d notifications failed | %d 封通知失败 |
| `dash_failed_reply` | %d confirmations failed | %d 封确认失败 |
| `dash_unsent_ignored` | Unsent warnings are ignored | 未发送警告已忽略 |
| `dash_auto_off` | Auto-confirmation is off | 自动确认未启用 |
| `dash_view` | View | 查看 |
| `dash_sources_title` | Sources | 来源 |
| `dash_langs_title` | Languages | 语言 |
| `dash_variant_title` | Form Type | 表单类型 |
| `dash_monthly_title` | Monthly Trend | 月度趋势 |
| `dash_vs_last_month` | vs last month | 对比上月 |
| `dash_other` | Other | 其他 |
| `dash_vs_prev` | vs previous %d days | 对比前 %d 天 |
| `dash_today_at` | Today %s | 今天 %s |
| `dash_yesterday_at` | Yesterday %s | 昨天 %s |
| `dash_empty` | No inquiries yet. | 暂无询盘。 |
| `dash_all` | View all inquiries | 查看全部询盘 |

#### 启用反馈与安装检查（9 条）

| Key | English (msgid) | 中文 |
|---|---|---|
| `activation_title` | Inquiry Form Inbox is active | 询盘表单收件箱已启用 |
| `activation_body` | Start with the email template, review the block rules, or open the inbox. | 可以从邮件模板、拦截规则或询盘列表开始配置。 |
| `activation_go_template` | Email Template | 邮件模板 |
| `activation_go_rules` | Block Rules | 拦截规则 |
| `activation_go_inbox` | Open Inquiries | 打开询盘 |
| `install_health_title` | Inquiry Form Inbox installation check | 询盘表单收件箱安装检查 |
| `install_health_multi` | This plugin folder contains more than one plugin header: %s. The install screen may activate the wrong file and report “The plugin does not have a valid header.” Delete the extra copies and install the official zip. | 这个插件目录里有多个插件头：%s。安装页可能启用错误的文件，并提示“该插件没有有效的标题”。请删除多余副本并安装官方 zip。 |
| `install_health_main` | The plugin main file is %s, not inquiry-form-inbox.php. Reinstall the official zip so the main file has the expected name. | 插件主文件名是 %s，不是 inquiry-form-inbox.php。请重新安装官方 zip，让主文件使用预期文件名。 |

#### 错误与校验（20 条）

| Key | English (msgid) | 中文 |
|---|---|---|
| `label_notify` | Inquiry notification | 询盘通知 |
| `label_reply` | Auto-confirmation | 自动确认 |
| `file_one` | 1 File | 1 个文件 |
| `files_many` | %d Files | %d 个文件 |
| `invalid_request` | Invalid request. | 请求无效。 |
| `error_forbidden` | Sorry, you are not allowed to access this page. | 抱歉，您无权访问此页面。 |
| `error_missing_field` | Missing required field: %s | 缺少必填字段：%s |
| `error_invalid_email` | Invalid email address. | 邮箱地址无效。 |
| `error_rate_limited` | Too many submissions. Please try again later. | 提交过于频繁，请稍后再试。 |
| `error_mail_failed` | Your inquiry could not be sent. Please email us directly. | 您的询盘未能发送，请直接给我们发邮件。 |
| `error_upload_dir` | The upload could not be processed. Please try again. | 附件处理失败，请重试。 |
| `error_upload_failed` | The upload failed. Please try again. | 上传失败。 |
| `error_upload_too_many` | Too many files in one submission. | 单次提交的附件过多。 |
| `error_upload_empty` | %s is empty. | %s 是空文件。 |
| `error_upload_size` | %s has an invalid declared size. | %s 声明的文件大小与实际不符。 |
| `error_upload_large` | %1$s exceeds the %2$d MB limit. | %1$s 超过 %2$d MB 上限。 |
| `error_upload_store` | The upload could not be stored. | 附件保存失败。 |
| `error_unknown_sender` | Unnamed | 未署名 |
| `email_subject` | New inquiry from %s | 来自 %s 的新询盘 |
| `email_subject_reply` | We received your inquiry | 已收到您的询盘 |

#### 命名与格式（2 条）

| Key | English (msgid) | 中文 |
|---|---|---|
| `pair_label_value` | %1$s: %2$s | %1$s：%2$s |
| `pair_label_note` | %1$s (%2$s) | %1$s（%2$s） |

## 五、模板编辑器里能点的变量

编辑邮件模板时， subjects/正文旁的「可用变量」列出这 9 个。点一下插到光标处。

| 变量 | 界面上的说明 | 输出 |
|---|---|---|
| `{{rows}}` | 字段表格，表头用字段标签 | 表格，或纯文本里的「标签: 值」逐行 |
| `{{rows.*.label}}` | 把 * 换成字段名 → 该字段的显示名 | 如 `{{rows.fullName.label}}` |
| `{{rows.*.value}}` | 把 * 换成字段名 → 该字段的值 | 如 `{{rows.fullName.value}}` |
| `{{no}}` | 询盘编号 | `IQ-20261125001` |
| `{{source}}` | 来源页面标题 | 页面标题（可带表单形态括注） |
| `{{form_type}}` | 页面表单或弹窗表单 | 按访客语言输出 |
| `{{time}}` | 提交时间（站点时区） | |
| `{{time_local}}` | 提交时间（访客时区） | |
| `{{time_zone}}` | 访客时区 | 如 `Asia/Shanghai` |

> **界面文案里不再出现** `{{site_name}}`（已废弃）。`{{accent}}` 从来不是变量 ——
> 模板里想换颜色就直接改源码里的 `#2271b1`。

## 六、语言组下拉里能选的

| 英文 | 中文 |
|---|---|
| English | 英语 |
| Simplified Chinese | 简体中文 |
| Traditional Chinese | 繁体中文 |
| Japanese | 日语 |
| Korean | 韩语 |
| German | 德语 |
| French | 法语 |
| Spanish | 西班牙语 |
| Portuguese | 葡萄牙语 |
| Italian | 意大利语 |
| Dutch | 荷兰语 |
| Russian | 俄语 |
| Polish | 波兰语 |
| Turkish | 土耳其语 |
| Arabic | 阿拉伯语 |
| Thai | 泰语 |
| Vietnamese | 越南语 |
| Indonesian | 印度尼西亚语 |
| Hindi | 印地语 |

一个组配一种语言；**第 0 组是默认组**（不带语言标签），匹配不到时用它的内容。
访客浏览器报 `en-GB` 会命中英语组，报 `zh-CN` 会命中简中组；只配了简中时，
繁中访客也会拿到简中（同语言换字形，好过换语言）。

## 七、易混词

| 界面上要用 | 不要写成 | 理由 |
|---|---|---|
| Inquiries / 询盘 | Messages, Leads, Forms / 留言、线索、商机 | 一条询盘就是一条完整提交 |
| Pending / 待处理 | New, Unread, Open / 新询盘、未读、待回复 | 状态机只有待处理与已联系两态 |
| Contacted / 已联系 | Replied, Closed, Done / 已回复、已关闭 | 联系不等于回复 |
| Inquiry notification / 询盘通知 | Alert, Thanks mail / 提醒、感谢信 | 这是发给站长的那封 |
| Auto-confirmation / 自动确认 | Auto-reply / 自动回复 | 和下面的「回复模板」会撞 |
| Reply template / 回复模板 | Response, Answer / 答复模板 | 人工回信用的那套 |
| All Sent / 已全部发送 | Sent / 已发送 | 徽章描述两封的组合，不是单封 |
| Source / 来源 | Referrer, Page / 出处、页面 | |
| Attachment / 附件 | File, Upload / 文件、上传 | |
| Field label / 字段标签 | Field name / 字段名 | |
| Spam / 垃圾询盘 | Junk, Trash / 垃圾邮件、垃圾 | Trash 是回收站 |
| Inquiry number / 编号 | ID, Serial / 序号 | |

## 八、界面上还不统一的地方

| 位置 | 现状 | 建议定法 |
|---|---|---|
| 完整的译法裁决与改动记录见 `TRANSLATION-REVIEW.md`。
| 模板页的第二个页签 | 英文是 `Auto-confirmation`，中文是「自动确认」；早年的键名里还带 `reply` | 就叫**自动确认**，不要叫自动回复 |
| 「发送测试邮件失败」的英文提示 | 里面点名了 `WP Mail SMTP` | 用别的 SMTP 插件的站点会觉得莫名其妙，应改成泛指 "Check your mailer configuration." |
| 详情弹窗的「邮件回复」与「回复模板」 | 中文用「回复」，架构文档里写「回信」 | 统一用**回复**，改文档不改界面 |

## 九、改一条界面文案之后

1. 先在第四节里找，能用现成的就别造新词。
2. 改英文原串（msgid）等于让所有语言的译文失效，要同步改 `languages/*.po`。
3. 跑 `php tests/static/lint-strings.php`：它会报「注册了没用」和「用了没注册」。
4. 跑 `composer pot && composer mo`，再跑 `php tests/static/i18n-parity.php`。
5. 跑 `node tests/ui/run.mjs`：它会检查页面上有无 PHP 提示、控制台错误、
   以及**有没有空标题、空表头、空字段标签**——最后这项抓到过两次真实的界面事故。
6. 回填第四节的表格。
