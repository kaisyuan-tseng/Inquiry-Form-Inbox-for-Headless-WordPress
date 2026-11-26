# 术语一致性审校与重译记录

**对象**：`languages/inquiry-form-inbox-zh_CN.po`（及由它编译的 `.mo`）、`docs/GLOSSARY.md`
**日期**：2026-11-25
**原则**：英文 msgid 是母版；中文定译以 WordPress 核心 zh_CN 语言包为首选依据；
代码块、变量名、占位符、URL、版本号一律不动。

## 一、依据与方法

从本机 WordPress 7.1.5 的 `wp-content/languages/zh_CN.mo`（4,814 条）和
`admin-zh_CN.mo`（3,518 条）中提取标准译法，与插件的 233 条 msgid 做两轮比对：

1. **完全匹配**——同一英文串在核心与插件里译法不同的，共 11 条，逐条裁决；
2. **用词扫描**——按「你/您」「关键词/关键字」「手机/移动端」等成对用词全局检索，找出
   核心里没有同句、但术语应当一致的情况。

结论：30 条需要改。全部写入可复现的映射 `tools/i18n/zh_CN-review.php`，用
`tools/fill-translations.php` 回写 PO，再由 WP-CLI 重编 MO。术语表中文列同步更新 23 条
（其余 7 条是插件头与隐私政策，不在 UI 表里）。

## 二、采用 WordPress 核心译法

| msgid | 原译 | 定译 | 核心出处 |
|---|---|---|---|
| `Desktop` | 桌面 | **桌面端** | `admin-zh_CN.mo` |
| `Mobile` | 手机 | **移动端** | `admin-zh_CN.mo` |
| `Indonesian` | 印尼语 | **印度尼西亚语** | `admin-zh_CN.mo` |
| `Keyword` / `Blocked keywords` | 关键词 | **关键字 / 屏蔽关键字** | `admin-zh_CN.mo` |
| `Sorry, you are not allowed to access this page.` | 你 | **您** | 核心用敬称；插件另一条 `We received your inquiry` 也已用「您」 |
| `All Inquiries` / `All sources` | 所有 | **全部** | 核心 `All` → 全部 |

`Trash`（回收站）、`Delete`（删除）、`Edit`（编辑）、`View`（查看）、`Search`（搜索）、
`Apply`（应用）、`Filter`（筛选）、`Date`（日期）、`Author`（作者）、`Title`（标题）、
`Status`（状态）、`Settings`（设置）、`Email`（邮箱）、`Subject`（主题）、
`Restore`（恢复）、`Bulk actions`（批量操作）原本就与核心一致，未改动。

## 三、刻意保留的偏离（附理由）

「WordPress 原生优先」不等于机械套用。以下 7 条核心译法会歪曲插件语境，保留插件定译：

| msgid | 核心 | 保留 | 理由 |
|---|---|---|---|
| `Source` | 源 | **来源** | 核心指导入/数据来源；插件指来源**页面标题**，译成「源」不可理解 |
| `Subject` | 主题 | **邮件主题** | WordPress 里 `Theme` 也译「主题」，裸用「主题」会歧义 |
| `Pending` | 待审 | **待处理** | 插件语义是「尚未标记已联系」，没有审核动作 |
| `Spam` | 垃圾 | **垃圾询盘** | 特指本插件的询盘，避免与核心的垃圾评论混淆 |
| `Name` | 显示名称 | **姓名** | 指访客在表单里填的姓名，不是用户资料里的显示名 |
| `Unknown`（发件人兜底） | — | **未署名** | 姓名语境下比「未知」自然 |
| `%1$s: %2$s` | ASCII 冒号 | **全角冒号** | 中英文标点差异是本格式串存在的理由；英文版仍是 ASCII |

## 四、按英文母版修正

### 4.1 补全漏译

| msgid | 原译 | 定译 |
|---|---|---|
| `Source page` | 来源 | **来源页面** |
| `No spam today` | 今日无垃圾 | **今日无垃圾询盘** |
| `Inquiry details` | 询盘内容 | **询盘详情**（`Inquiry content` 才是「询盘内容」，原来两条撞车） |
| `New inquiry from %s` | 收到新询盘：%s | **来自 %s 的新询盘** |
| 隐私政策里的 `message` | 留言 | **消息**（与 `col_message` 统一） |

### 4.2 删除中文里没有对应英文的增译

| msgid | 原译 | 定译 |
|---|---|---|
| `← All Inquiries` | ← **返回**所有询盘 | ← 全部询盘 |
| `Could not load this inquiry.` | 加载失败，**请重试**。 | **无法加载这条询盘。** |
| `Hard blocks and duplicate detection.` | **直接丢弃的网络黑名单**，以及重复提交识别。 | **硬性拦截与重复检测。** |
| `The upload failed. Please try again.` | 上传失败，**请重试**。 | 上传失败。 |

> 前两条的「返回」「请重试」对中文用户其实友好，但英文母版里没有；以英文为母版时
> 应移到英文原文里，而不是偷偷加在译文里。
>
> 后续在 3.1.2 由用户指定：`← All Inquiries` 定译改回「← 所有询盘」（仍不带「返回」），
> 见第十一节；上表保留当时的裁决，不改写历史。

### 4.3 中文句子里残留英文界面词

| msgid | 原译 | 定译 |
|---|---|---|
| `When enabled, the Email Problem card on All Inquiries is hidden.` | 开启后，**All Inquiries** 页的 **Email Problem** 卡片将隐藏。 | 开启后，「**全部询盘**」页的「**邮件异常**」卡片将隐藏。 |

### 4.4 全文术语统一

- `Replies come back…` 的「回信」→「**回复**」，与 `Reply template`（回复模板）、
  `Email reply`（邮件回复）一致。
- `Languages` 仪表盘卡片的「访客语言」→「**语言**」，英文只是 Languages。
- `Delivery error` 的「投递错误」→「**发送错误**」，与 `Email status`（发信状态）、
  `Email Delivered`（邮件已送达）同一词族。
- 三条 keyword 相关译文统一为「关键字」。
- 三条 All 相关译文统一为「全部」。

### 4.5 产品名保持英文

`Inquiry Form Inbox for Headless WordPress` 原译「询盘表单收件箱（Headless WordPress）」，
改为**保持英文原名**——品牌与产品名不翻译，这是技术翻译的通行做法。

## 五、英文母版本身的问题（未改，建议后续处理）

| msgid | 问题 | 建议 |
|---|---|---|
| `Test email failed. Check WP Mail SMTP.` | 点名了一个具体插件。站点用 PHPMailer、SendGrid 等其他方案时，这句提示是错的 | 改成 `Test email failed. Check your mailer configuration.` |
| `Email status` 与 `Automatic Status` | 两个列名对中文读者语义重叠（译作「发信状态」「自动发信状态」） | 英文改为 `Delivery` / `Automatic status`，或明确前者是单封、后者是组合 |

这两条属于源文问题，**本次没有动英文**——用户要求以英文为母版，改源文需要单独决策并
重新生成本地化链路。

## 六、未覆盖

- **繁体中文已删除**：`zh_TW` 的 PO / MO、`tools/i18n/zh_TW.php` 和 `ifi_strings` 里的
  `zh_TW` 覆盖位一并移除。模板语言组里的 `zh-hant` 保留——那是管理员自己写的访客邮件
  内容，不是插件界面的翻译，两者不是一回事。
- **代码注释**：`includes/` 里有中文注释，本次未动——它们不进入翻译链路，也不出现在 UI 上。
- **readme / docs**：英文文档翻译不在本次范围；`GLOSSARY.md` 只是同步了译文列。

## 七、验证

```
php tools/fill-translations.php languages/inquiry-form-inbox-zh_CN.po tools/i18n/zh_CN-review.php
  → filled 30 of 30 mapped strings

php tools/wp-cli.phar i18n make-mo languages/
  → Success: Created 2 files

php tests/static/i18n-parity.php
  → zh_CN PO 233 | MO 230 | untranslated 3 | stale 0 | MO-empty 0
  → Result: OK (warnings only)

php tests/bin/gate.php --fast
  → php-lint / phpcs / string keys / gettext / readme / unit  all ok

3 条未翻译的是作者名、作者 URI、插件 URI——元数据，本来就不该翻译。

```

UI 套件 6/6 pages clean，站点为英文与中文时各验证过一次——等于对新译文做了一次真实浏览器
验收。此前 UI 断言写死了英文，站点一换语言就全红；现已改为读 `<html lang>` 选择中/英锚点
（不能用「页面里有无汉字」判断，演示数据里本来就有中文姓名）。**没有改动站点的 `WPLANG`。**

## 八、英文命名修订（用户授权后的追加轮次）

用户明确授权「英文也可以改成更正确的称呼」后，对英文母版本身做了一轮命名审计。
改动写入可复现映射 `tools/i18n/en-rename.php`，共 19 条：

| 原英文 | 新英文 | 理由 |
|---|---|---|
| **Spam Rules** | **Block Rules** | 规则不只是判定垃圾内容，还封 IP、拦重复提交；「拦截规则」才描述它实际做的事 |
| Automatic Status | Delivery status | 与另一列 Email status 语义重叠 |
| Email status | Emails | 详情弹窗里列出两封邮件各自结果，不是「状态」 |
| Email Delivered / All emails delivered | Emails sent / All emails sent | SMTP 成功只能说 sent，不能说 delivered 到收件箱 |
| The message could not be delivered… | Your inquiry could not be sent… | `message` 是正文字段，这里说的是整条询盘 |
| Ignore undelivered warning | Ignore unsent warning | 同上 |
| Daily new | New today | 语法不自然 |
| `%d notification failed` / `%d confirmation failed` | `%d notifications failed` / `%d confirmations failed` | 单复数 |
| Network and repeats | Network and duplicates | repeats 不是名词 |
| Run check / No rule matches | Run test / No rules matched | 测试面板的动词与结果要成对 |
| Dropped at the door | Dropped on arrival | 与提示文案里的 "on arrival" 一致 |
| Duplicate | Duplicate submission | 单说 Duplicate 不知道是什么重复了 |
| These IP rules are not valid addresses… | These IP entries are not valid… | 规则不等于地址 |
| Check WP Mail SMTP. | Check your mailer configuration. | 通用插件不点名第三方插件 |
| Unknown | Unnamed | 发件人姓名兜底，不是「未知」这件事 |

**没有改的**：`Spam` 作为**视图**名（WordPress 原生术语，也是被拦提交的落档位置）、
所有 `spam_*` 内部字典键与 option 名（标识符不是用户可见的称呼，改名只增加迁移风险）、
`zh-hant` 模板语言组（那是管理员写的访客邮件内容，不是插件界面翻译）。

同步更新了 `readme.txt`、`INTEGRATION.md`、`docs/ARCHITECTURE.md`、UI 测试锚点与代码注释。
`changelog.txt` 里的历史条目保持原样——它记录的是 3.0.14 当时的名字。

## 九、繁体中文已删除

`languages/inquiry-form-inbox-zh_TW.po` / `.mo`、`tools/i18n/zh_TW.php`，以及
`ifi_strings()` 里的 `zh_TW` 覆盖位一并移除；readme 的 FAQ、术语表与报告同步更新。
现在随包发布的界面翻译只有 `zh_CN`（英文是源文，不需要翻译文件）。

`zh-hant` **模板语言组**保留：那是管理员自己写的访客邮件内容，不是插件界面翻译。
它没有对应的 gettext 目录，所以该组的固定标签（默认主题、`Page form` 等）回退英文；
已有一条集成测试锁定这个行为，防止以后误把回退当成崩溃。


## 十、3.1.2 新增文案（限流卡片）

限流阈值从「只读说明句」改成两个可编辑输入框，英文母版与中文定译一并更新：

| msgid | 中文定译 | 说明 |
|---|---|---|
| `Rate limits` | 限流 | 卡片标题。核心 zh_CN 无此串（已核对 `zh_CN.mo`），按业界通行说法取「限流」；比直译「频率限制」更常用，与 `docs/ARCHITECTURE.md` 正文一致。 |
| `How many submissions a visitor can send before the API returns HTTP 429.` | 同一访客在每个时间窗口内最多能提交多少次，超出后接口返回 HTTP 429。 | 原文用 `how many`（次数）而不是 `how often`（频率），因为两个字段填的是次数；`returns HTTP 429` 是把口语的 `answers 429` 改回来的标准说法。 |
| `Per minute` / `Per hour` | 每分钟 / 每小时 | 窗口单位；具体次数由输入框给出，不在标签里重复「次」。 |
| `0 turns that window off. Over the limit, the API returns HTTP 429 with a Retry-After header; no record is created.` | 0 表示关闭该窗口。超出上限时，接口返回 HTTP 429 并附带 Retry-After 响应头；这类请求不会生成记录。 | `that window` 指上方对应字段；`Retry-After` 是响应头名，保持英文。`nothing is stored` 一度译作「本次提交不会入库」——「入库」是实现层说法，界面文案不该用，改为「这类请求不会生成记录」，并补出主语。 |

**英文母版同时修正的三处**：

1. `Rate limit` → `Rate limits`：卡片里有两个阈值，且与 `Content rules`、`Network and duplicates` 的复数标题一致；
2. `How often … answers 429` → `How many submissions … returns HTTP 429`：`how often` 问的是频率，字段填的是次数；`answers 429` 不是技术文档的常规说法；
3. `get HTTP 429 with Retry-After` → `the API returns HTTP 429 with a Retry-After header`：`get` 口语化，`Retry-After` 前缺冠词，且没说清它是响应头。

**没有改的**：`Per minute` / `Per hour` 两词的写法；`spam_rate_*` 内部键名；
`Retry-After`、`HTTP 429` 等协议标识一律保持英文。

**同页术语统一（追加）**：试跑面板的 `Nothing is stored and no email is sent.` 原译
「不入库，也不发信」，与上面同一概念两种说法，一并改为「不会生成记录，也不会发信」。
`docs/GLOSSARY.md` 对应两行同步。界面文案里不再出现「入库」「建库」这类实现层动词。


## 十一、3.1.2 子菜单用词的覆盖（用户指定）

`wp-first-item`（顶层菜单自动生成的第一个子菜单项）原显示「全部询盘」，按用户要求改为
**所有询盘**。英语母版 `All Inquiries` 未动，只改 zh_CN 目录：

| msgid | 3.0.15 定译 | 3.1.2 定译 |
|---|---|---|
| `All Inquiries` | 全部询盘 | **所有询盘** |
| `← All Inquiries` | ← 全部询盘 | **← 所有询盘** |

**理由**：3.0.15 采用核心 `All` → 全部 的译法；但本站仪表盘的对应链接已经写作
「查看所有询盘」，子菜单与返回链接跟着统一，读起来才是一个产品。浏览器标题不受影响——
它取的是 `menu_name`（Inquiries → 询盘），不是这条子菜单标签。

`add_menu_page()` 的同名子菜单注释、术语表「子菜单」行同步；`ifi_strings()` 的英文键与
option 名均未变化，无需迁移。
