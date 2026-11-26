# Architecture

Inquiry Form Inbox is the WordPress half of a headless form. The front end posts to one
REST endpoint; everything after that happens in the admin.

This file is the developer reference. `INTEGRATION.md` is the front-end contract,
`GLOSSARY.md` lists every UI string and the approved wording, and `readme.txt` is the public
description.

---

## 1. 定位

headless 前端只管提交，WordPress 这边是一个完整的询盘收件箱：

| 能力 | 说明 |
|---|---|
| Inbox | 每条询盘留档（字段值 + 来源页 + 附件），列表一眼看清谁、说了什么、发没发出去 |
| 分类 | Pending / Contacted 两个状态，行操作可标记「已联系」，也可撤销 |
| Notifications | 新询盘通知销售、按访客语言自动确认回访客（两份事务邮件，主题与正文可在后台按语言分组编辑） |
| Delivery log | 每封询盘邮件留一条投递结果（成功 / 失败 + SMTP 报错原文），列表与详情可查 |

## 2. 设计取向

- **后台样式与交互全部用 WordPress 后台原生体系**（列表视图、行操作、`.button-small`），不另造一套。
- **字段展示名后台可改、可拖动排序**；没配就回落到表单自己提交的标签；都没有才把字段名转成可读文本。
  插件不假设任何字段叫法，也不内置字段词表。
- **邮件里的站名、配色都写在模板源码里**，插件不注入任何品牌元素。
- **文案统一走 gettext**，按 key 从 `IFI_I18n_Trait::strings()` 取。字典只存 UI 字符串；
  `ifi_strings` 过滤器可按 locale 覆盖或增补。

## 3. 端点

```
POST /wp-json/inquiry-form-inbox/v1/inquiry
Content-Type: multipart/form-data
```

- **必填**：`fullName` / `email` / `message`（可用 `ifi_required_fields` 整组替换）
- **按名透传**：其余字段按前端提交的名字原样收下
- **环境数据**：`browserLanguage` / `timezone` 由前端采集，服务端校验后入库
- **IP**：默认只取 `REMOTE_ADDR`；只有 `REMOTE_ADDR` 是配置的可信代理时才采信 `X-Forwarded-For` / `X-Real-IP`。转发链从右向左读，跳过可信代理，取第一个不可信跳——最左侧条目由客户端提供，不能作为限流或入库依据
- **附件**：`files[]`（单个 ≤20MB）、`images[]`（单个 ≤10MB）；字段用途由前端 `accept` 决定，服务端只校验上传错误、数量与大小。通知邮件按上传分组分行，表头取该分组的字段标签（`files` / `images`）；只有所有分组都没配标签时，`attachments` 才作为整份清单的兜底
- **蜜罐**：`companyWebsite`（默认，后台可改）非空即机器人，静默丢弃（不消耗限流配额）
- **限流**：同一可信请求标识默认 1 分钟 3 次 / 1 小时 10 次（后台「Block Rules」页可改，0 = 关掉该窗口），超限 429 + `Retry-After`；窗口长度固定为 60s / 3600s，`ifi_rate_limits` 过滤器可整份替换（含 `ttl`）
- **垃圾规则**（后台「Block Rules」页，option `ifi_spam_rules`）：蜜罐开关、关键词、邮箱域名、
  IP/CIDR 黑名单、链接上限、重复提交窗口；命中一律返回 200 `{"sent":true}`（不提示、不回信），
  默认留档到 Spam 视图

**3.0.0 起旧的 `email-log-notifications/v1` 与 `site-inquiries/v1` 命名空间已移除**，
前端需改用上面的地址。

### 3.1 响应与 `code` 契约

前端应当只依赖 HTTP 状态码与 `code`，`message` 是给人看的（且可被站点语言本地化）。

| 状态 | `code` |
|---|---|
| 200 | —（`{"sent":true}`） |
| 400 | `inquiry_missing_field` / `inquiry_invalid_email` / `inquiry_bad_upload` |
| 429 | `inquiry_rate_limited`（附 `retry_after` 与 `Retry-After`） |
| 500 | `inquiry_mail_failed` |

> 500 时记录**已经入库**，只是邮件没发出去；后台列表能看到并重试。

### 3.2 表单形态（`formVariant`）

`formVariant` 是前端自报的入口形态，插件对值只做 `sanitize_key`（小写、只留 `[a-z0-9_-]`），
**接受任意 slug**。认识的五个值由 `IFI_Core_Trait::form_variants()` 登记：

| 值 | 含义 |
|---|---|
| `page` | 页内嵌表单 |
| `modal` | 弹窗 |
| `drawer` | 抽屉（侧滑面板） |
| `floating` | 悬浮（浮标展开） |
| `sticky` | 固定（吸底 / 吸边） |

登记的形态有三处消费方：列表「来源」列的括注、邮件 `{{form_type}}`、看板的表单类型卡。
没登记的值照样入库、列表原样显示 slug；**看板只统计上面这五种**，既没有「其他」档，也不会把一个陌生 slug 显示成第六种官方形态。
要给自造形态一个正式名字，用 `ifi_form_variants`：值是**显示名**（自己翻好），不是字典键。

```php
add_filter( 'ifi_form_variants', function ( array $variants ) {
    $variants['slide'] = 'Slide-in panel';
    return $variants;
} );
```

空值（3.0 之前入库的老记录）按页面表单算：邮件里不会出现空的「()」，看板也计入「页面表单」。

## 4. 附件留档

```
wp-content/uploads/ifi-inquiries/{年月}/{文章ID-随机}/{原文件名}
```

- 在 `uploads` 下可直接访问；目录后缀用 CSPRNG 生成，防猜测
- 邮件附件来自留档路径（真实附件）；删除询盘记录时附件一并清理
- 归档失败（跨挂载点 `rename` 失败）时退回 `copy` + `unlink`；仍失败则保留暂存路径，
  后台按文件名与大小列出但不可点，邮件照样带附件
- 永久删除走 `before_delete_post` → `purge_record_files()`：删 meta 里记的文件与记录目录，
  再按 `{文章ID}-*` 目录名兜底清一次（防 meta 缺项）；退回 `get_temp_dir()/ifi-stage-*`
  的文件也一并删除。路径先 `realpath()` 解析、再判断是否位于 `uploads/ifi-inquiries/`
  或 `ifi-stage-*` 内，meta 被构造成 `../` 也不会变成任意文件删除

## 5. 后台结构与钩子

### 5.1 菜单

顶层菜单「Inquiries」（`admin.php?page=ifi-inquiry`），其下三个子页：

| 页面 | slug | 权限 |
|---|---|---|
| 询盘列表（自渲染，沿用原生 `edit-ifi_inquiry` screen） | `ifi-inquiry` | `ifi_view_inquiries` |
| 邮件模板（三标签页） | `ifi-template` | `manage_options` |
| 垃圾规则 | `ifi-spam` | `manage_options` |
| 只读详情（隐藏页） | `ifi-view` | `ifi_view_inquiries` + 对象级 |

### 5.2 列表列

编号 / 来自 / 内容摘要 / 附件 / 来源 / 自动发信状态 / 接收时间。

编号是主列，点「查看」打开只读详情弹窗；无 Add New、无编辑/快速编辑，直连 `edit` 被拦；
永久删除只对持有 `ifi_delete_inquiries` 的用户开放，且只允许从回收站发起。

第一列只装「编号 + 行操作」，宽度按行操作单行所需定死 218px（英文
`View | Mark as Contacted | Trash` 与中文「查看 | 标记为已联系 | 移至回收站」实测 189px）：
用百分比会在宽屏上白涨到 300px，窄屏又掉到 168px 把最后一项裁掉。其余六列用百分比。

「接收时间」和所有界面时间一样，格式取「设置 → 常规」的 `date_format` / `time_format`，
今天 / 昨天仍用相对说法（今天 14:05），其余按站点日期格式显示。

### 5.3 发信状态

两封邮件（询盘通知 / 自动确认）的组合状态压成一枚徽章：
`All Sent` / `Only Notification` / `Only Auto-Confirmation` / `Not Sent`。
失败与「未尝试」在 `Not Sent` 里用颜色区分，逐封明细与 SMTP 报错原文放 `title`。

**垃圾询盘**只出现在 Spam 视图，行操作是 `View | Not Spam | Trash`，没有「已联系」——
它根本没送达。「Not Spam」删掉 `ifi_inquiry_spam` 把它放回普通列表（nonce + 对象级能力校验）。
垃圾记录不在发信时渲染，但**留档正文走同一个 `build_body()`**，所以 View 打开看到的邮件卡片
与正常询盘一样，而不是一段裸文本。

### 5.4 概览看板与仪表盘挂件

列表页顶部的看板（`.ifi-board`）是四张 KPI 卡 + 四张明细卡，可折叠；折叠状态记在 localStorage。

- **语言卡**把浏览器标签显示成「当前界面语言对它的称呼（地区）」：简体中文界面下 `en-US` →
  英语（美国），`zh-Hant-*` 走繁体列。名称表在 `includes/class-ifi-locale.php`（英 / 简 / 繁
  三列），查不到的标签原样显示——开发机与不少共享主机没有 intl 扩展，不能指望 `Locale`，
  而 WordPress 自带的语言包清单只给各语言的**母语**名称，拿不到「中文界面下的英语」。
- **表单类型卡**每行带色块，颜色与下方占比条同一套 `palette_color()`，一眼能对上分段。
- **「忽略未发送警告」**（自动确认页）开启后，自动确认未发出**不计入**「邮件异常」：卡片仍在，
  数字与副文案只统计询盘通知失败；关闭时两者合并计数。关闭 = 两封都算异常，不是「隐藏卡片」。

WordPress 仪表盘上的「最近询盘」挂件用同一套读法：四个指标（今日新增 / 待处理 / 垃圾 /
邮件异常）+ 最近 5 条记录（编号 / 姓名 / 邮箱 / 来源 / 发信徽章 / 接收时间）+ 底部入口，
条数由 `ifi_dashboard_limit` 控制。旧的三列「日期 + 名称 + 状态圆点」版式与其样式已删除。

看板、列表、弹窗、挂件与邮件里的时间都读站点的 `date_format` / `time_format`，
插件不再自己写死 `Y-m-d H:i` 或 `g:i a`。

### 5.5 启用反馈与安装检查

- `IFI_Capabilities::activate()` 只补能力并写一个 per-user transient，不做重定向、不输出：
  安装完成页的 AJAX 启用需要干净的 JSON 响应。
- 下一次后台请求由 `activation_notice()` 显示一次性成功通知，附 Email Template /
  Block Rules / Inquiries 三个入口，显示后删除 transient。
- `install_health_notice()` 只在插件自己的页面与插件列表页提示结构问题：同一目录多个
  `Plugin Name` 头、主文件名异常。该检查只报告，不删除文件。它针对的是
  WordPress 安装页报 “The plugin does not have a valid header.” 的常见原因。

## 6. 邮件模板

| 标签页 | option | 收件人 | 结构 |
|---|---|---|---|
| 询盘通知 | `ifi_template` | 站长/销售（`admin_email`，filter `ifi_recipient`） | `subject` / `html` / `labels` |
| 自动确认 | `ifi_autoreply` | 访客自己填的邮箱 | `enabled` + `groups[]`（每组 `lang` / `subject` / `html` / `labels`） |
| 回信模板 | `ifi_reply` | 由 `to` / `cc` / `bcc` 决定（默认取访客邮箱） | `to`/`cc`/`bcc` + `groups[]`（每组 `lang` / `subject` / `body`） |

- 语言组按访客浏览器语言匹配：完整标签精确命中 → 主语言命中 → 默认组（索引 0）。
- 语言标签在保存时归一（`zh-CN` → `zh-hans`，`zh-TW` → `zh-hant`，`en-*` → `en`）。
- **渲染时会切换 WordPress locale**（`switch_to_locale()` / `restore_previous_locale()`）：
  换的不只是模板源码，「附件」「页面表单」这类固定文案也跟着换成访客语言。
- 占位符只输出值，不带任何标签文案——「Timezone:」这类字眼由模板源码自己写。
- `{{time}}` / `{{time_local}}` 的日期时间格式取站点的 `date_format` / `time_format`，
  末尾拼时区缩写（`CST` / `GMT`）。
- 默认模板里的颜色直接写在模板源码里；插件不再提供品牌色常量或占位符，想换色改模板即可。
- 源码用 `wp_kses` 清洗（放行内联样式与 `{{token}}`），该字段仅 `manage_options` 可写。

## 7. 数据模型

| 存储 | 名称 |
|---|---|
| post type | `ifi_inquiry`（私有，`show_ui` 但 `public=false`） |
| meta 前缀 | `ifi_inquiry_` |
| option | `ifi_template` / `ifi_autoreply` / `ifi_reply` / `ifi_spam_rules` |
| 流水号 | `ifi_seq_YYYYMMDD`（MySQL `LAST_INSERT_ID(expr)` 原子自增） |
| 限流 transient | `ifi_rl_{window}_{hash}` |
| 重复提交 transient | `ifi_dup_{hash}` |
| 附件目录 | `uploads/ifi-inquiries/` |
| capability | `ifi_view_inquiries` / `ifi_edit_inquiries` / `ifi_delete_inquiries` |

插件不再提供 1.x / 2.x 数据迁移，也不再回填旧记录的编号或字段值；只处理当前 `ifi_*` 数据。

## 8. 开发者钩子

### Filters

| 钩子 | 默认 | 作用 |
|---|---|---|
| `ifi_recipient` | `admin_email` | 询盘收件人，同时作为自动确认的 Reply-To |
| `ifi_required_fields` | `fullName`/`email`/`message` | 必填字段 |
| `ifi_max_files` | `20` | 单次提交附件总数 |
| `ifi_file_groups` | `files` 20 MB、`images` 10 MB | 附件字段名 => 单文件大小上限（字节）；服务端不判断文件类型，上限非正数的字段不接收附件 |
| `ifi_length_limits` | 见 `handle()` | 各文本字段长度上限 |
| `ifi_dashboard_limit` | `5` | 仪表盘挂件显示条数 |
| `ifi_field_manifest` | `array()` | 给后台「字段标签」页提供字段名建议 |
| `ifi_strings` | `en` 字典 | 按 locale 覆盖或增补 UI 文案 |
| `ifi_spam_rules` | 默认值 + 后台保存值 | 整份垃圾规则（蜜罐 / 关键词 / 域名 / IP / 链接 / 重复 / 两个限流次数） |
| `ifi_spam_verdict` | `array()` | 自定义垃圾判定，返回 `['reason'=>..,'hit'=>..]` 即按命中处理 |
| `ifi_honeypot_field` | 后台「隐藏字段名」（默认 `companyWebsite`） | 蜜罐字段名；过滤器优先于设置页的值，改了前端要同步 |
| `ifi_rate_limits` | `minute` 3/60s、`hour` 10/3600s（次数取自 Block Rules 页的 `rate_minute` / `rate_hour`） | 两个限流窗口的 `limit` 与 `ttl`；`limit` 设 0 关闭该窗口；优先级高于设置页 |
| `ifi_trusted_proxies` | `IFI_TRUSTED_PROXIES` 常量 | 可信代理，决定是否采信转发头 |
| `ifi_form_variants` | `page`/`modal`/`drawer`/`floating`/`sticky` | 表单形态 slug => 显示名；加自定义形态不必改插件 |

### Actions

| 钩子 | 参数 | 时机 |
|---|---|---|
| `ifi_autoreply_sent` | `$sent, $email, $fields` | 自动确认发送之后 |

## 9. 性能相关

- `ifi_strings` 的字典按 locale 做进程内静态缓存，每个 locale 只构建一次。
- `tpl()` 按 kind 缓存；`uploads_dir()` / `stage_dir()` / 附件目录 CSPRNG 后缀同样缓存。
  **改动 template option 后同一请求内不会再读一遍**，这是刻意的（一次请求只发一种邮件）。
- 限流用固定窗口：transient 里存窗口起点与计数，是否过期按起点判断，不续写 TTL。
- `PENDING` / `CONTACTED` / `SPAM` 计数走 `$wpdb` 分组查询，不做全量 `WP_Query`。
