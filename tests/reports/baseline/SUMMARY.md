# 规范化基线（Phase 0）

采集于 Phase 0，改动前的只读快照。后续每阶段都跟这份数字对比。

**环境**：PHP 8.5.9 · WordPress 7.1.5 · MySQL 26.10 · Node 22.22.2

## 1. 静态检查

| 检查 | 命令 | 结果 |
|---|---|---|
| PHP 语法 | `php tests/static/php-lint.php` | **26 文件 / 0 失败** |
| PHPCS | `vendor/bin/phpcs` | **9 errors / 28 warnings**，分布在 10 个文件 |
| 字符串键 | `php tests/static/lint-strings.php` | **0 缺键**，26 未使用键（占已声明 223 的 11.7%），264 处调用 |
| gettext 链路 | `php tests/static/i18n-parity.php` | **1 项失败**：POT 落后源码 2 条 |
| readme 合规 | `php tests/static/readme-check.php` | **2 fail / 1 warn** |

原始报告：`phpcs.json` · `php-lint.json` · `strings.json` · `i18n.json` · `readme.json`

### PHPCS 明细

| 文件 | E | W |
|---|---|---|
| `class-ifi-migration.php` | 0 | 5 |
| `class-ifi-privacy.php` | 0 | 4 |
| `class-inquiry-form-inbox.php` | 0 | 2 |
| `trait-ifi-cpt-actions.php` | 1 | 2 |
| `trait-ifi-cpt.php` | 0 | 2 |
| `trait-ifi-dashboard.php` | 1 | 3 |
| `trait-ifi-endpoints.php` | 0 | 1 |
| `trait-ifi-rate-limiter.php` | 6 | 0 |
| `trait-ifi-settings-render.php` | 1 | 8 |
| `trait-ifi-upload.php` | 0 | 1 |

9 个 error 全部可归因：1 处 docblock 类型写错、1 处 `$wp_locale` 全局覆盖、6 处 `$_SERVER` 未净化（3 行）、1 处控制结构换行。28 个 warning 全是数组箭头 / 等号对齐，`phpcbf` 可自动修。

> 注：加入 `tests/` 后全量 PHPCS 一度升到 70 errors —— 那是我自己的测试脚本被插件规则集扫到。已在 `phpcs.xml.dist` 排除 `tests/*` 与 `tools/*`，两者各有独立规则集且不进分发包。

## 2. 文案

| 指标 | 数值 |
|---|---|
| 已声明字符串键 | 223 |
| 实际被引用 | 195 |
| `t()` 调用点 | 264（其中 1 处为三元选择，字面量均已登记） |
| 未使用键 | **26**（13 条字段名兜底 + 13 条 3.0.3–3.0.7 改版后废弃的看板文案） |
| 输出路径里的全角中日标点 | **4 处**（`：` `（` `）`） |
| 裸英文 `wp_die` / JSON / 异常消息 | **16 处**（其中 `'Insufficient permissions.'` 重复 10 次） |
| 默认邮件模板里的中文注释 | **5 处** |

## 3. i18n 链路

| 项 | 数值 |
|---|---|
| 源码中可提取的 gettext 字符串 | 234 |
| POT msgid | 237 |
| POT 落后源码 | **2 条**：`Live Preview`、`Sent with the notification email, not archived under uploads.` |
| zh_CN / zh_TW PO | 各 237（stale 0） |
| zh_CN / zh_TW MO | 各 234（与 PO 的非空翻译数量一致，符合预期） |
| MO 空翻译条目 | **0**（2.0.4 的回归没有复现） |
| 未翻译条目 | 各 3：Author 用户名、Author URI、隐私政策正文 |

> MO 缺失的 3 条正好都是 PO 里 `msgstr` 为空的条目 —— 编译器有意跳过空翻译，这是正确的（空翻译进 MO 会让 gettext 返回空串而不是回落到原文，正是 2.0.4 那次白屏的成因）。判据已据此修正。

## 4. readme

| 项 | 数值 | 判定 |
|---|---|---|
| 体积 | **21,947 字节** | **fail**（上限 10k） |
| changelog | 21 条 / 跨 2 个 major | pass（但体积主因） |
| 截图声明 | 4 张 | **fail**（`assets/` 里没有对应文件） |
| `Tested up to` | `7.1.4` | warn（目录只认 major） |
| Tags | 5 个 | pass |
| 短描述 | 54 字符 | pass |
| Stable tag | `3.0.14` = 头 `Version` | pass |

## 5. 本机环境约束（影响测试设计）

| 事实 | 影响 |
|---|---|
| `wp_local` 仅有 `wordpress_local.*` 权限，不能建库 | 测试隔离只能靠同库不同前缀 `wptests_` |
| `fileinfo` / `zip` / `intl` / `pdo_mysql` 未加载 | 内容嗅探用例必须 skip 并记账；MO 校验需自写读取器 |
| 站点 8081 有真实数据，`wp-mail-smtp` 会真发信 | 测试另起 8099 + `wptests_`，`upload_dir` 重定向 |
| `move_uploaded_file()` 在进程内必然失败 | 附件只能走真实 HTTP 测 |
| `$_SERVER['REMOTE_ADDR']` = 127.0.0.1（保留段） | 限流在 HTTP 层测的是 cookie 分支 |
