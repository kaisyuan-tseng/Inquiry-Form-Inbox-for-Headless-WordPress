# Inquiry Form Inbox for Headless WordPress · 前端对接说明

一份给**任何前端**看的接入文档。插件只认 HTTP 与 multipart，不关心你用 React、Vue、Svelte、Astro、jQuery 还是纯 HTML 表单。

> **3.0.0 破坏性变更（请先读这一段）**
>
> - 端点已改为 `POST /wp-json/inquiry-form-inbox/v1/inquiry`。
> - 旧命名空间 `email-log-notifications/v1` 与 `site-inquiries/v1` **已彻底移除**，继续调用会得到 `404 rest_no_route`。
> - 所有 PHP 钩子从 `eln_` 改名为 `ifi_`（见 §7）。
> - 字段契约、附件规则、限流规则、响应体均**未变**，前端只需要改 URL。

---

## 1. 设计原则

| 原则 | 具体做法 |
|---|---|
| 框架无关 | 只暴露一个 `POST` 端点，请求体是 `multipart/form-data`；不用 JSON、不用非标 header、不要求特定字段顺序 |
| 零 JS 依赖 | 插件不注册任何前端脚本；前端自己决定怎么收集表单 |
| 字段自由 | 字段名由前端定（`fullName`、`your_name`、`contact[0]` 都可以），插件按到达顺序渲染 |
| 标签由前端给 | 每个字段的显示名通过 `fieldLabels[字段名]=标签` 一起提交；插件不内置任何字段表 |
| 单请求完成 | 文本 + 附件 + 元信息一次 multipart 提交，没有分步上传、没有轮询 |

---

## 2. 接口契约

```
POST /wp-json/inquiry-form-inbox/v1/inquiry
Content-Type: multipart/form-data（浏览器自动带 boundary，不要手写）
```

### 2.1 必填字段

默认 `fullName`、`email`、`message`。可通过 filter 整组替换（见 §7）：

```php
add_filter('ifi_required_fields', fn() => ['name', 'mail', 'body']);
```

校验规则：非空（去首尾空白）；`email` 额外走 `is_email()`。

### 2.2 元信息（可选，但强烈建议）

| 字段 | 说明 |
|---|---|
| `formVariant` | 表单形态：`page`（页内嵌）/ `modal`（弹窗）/ `drawer`（抽屉）/ `floating`（悬浮）/ `sticky`（固定）；也接受自定义值，见 §2.2.1 |
| `pageUrl` | 提交页面的完整 URL |
| `pageTitle` | 当前语言下的页面标题（已去品牌后缀；文章页与普通页一样，直接传文章标题，不要带「文章：」之类的前缀） |
| `pageTitleEn` | 该页面的**英文**标题；用于后台统一来源口径（同样不要带前缀） |
| `locale` | 页面语言，如 `zh-hans` / `en` |
| `browserLanguage` | 浏览器语言，如 `zh-CN` / `en-US`（取 `navigator.languages[0]`） |
| `timezone` | 用户时区，IANA 标识如 `Asia/Shanghai`（取 `Intl.DateTimeFormat().resolvedOptions().timeZone`） |

- `browserLanguage` / `timezone` 服务端会校验（BCP47 粗校验 / PHP 时区库），不合格**静默丢弃**，不影响提交；缺失时模板里对应的行整行消失，不会留空标签。
- **不要传 `ip`**：IP 一律由服务端按可信代理规则识别（见 §2.7），前端传了也会被忽略。

### 2.2.1 表单形态（`formVariant`）

前端按自己的交互形态报一个 slug，插件用它给后台和邮件打标签。认识的五个值：

| 值 | 形态 | 后台 / 邮件里的文案 |
|---|---|---|
| `page` | 页面内嵌 | Page form · 页面表单 |
| `modal` | 弹窗 | Popup form · 弹窗表单 |
| `drawer` | 抽屉（侧滑面板） | Drawer form · 抽屉表单 |
| `floating` | 悬浮（浮标 / 浮动按钮展开） | Floating form · 悬浮表单 |
| `sticky` | 固定（吸底、吸边） | Sticky form · 固定表单 |

- 服务端只做 `sanitize_key`：保留 `[a-z0-9_-]` 并转小写，`Drawer` 与 `drawer` 等价。
- 三个消费方：列表「来源」列的括注、邮件占位符 `{{form_type}}`、看板「Form Type」卡。
- **别的值也能提交**，不会报错也不会丢记录：列表原样显示 slug；看板只统计上面五种，不显示未登记形态，也没有「其他」档。要给它一个正式名字：

```php
add_filter( 'ifi_form_variants', function ( array $variants ) {
	$variants['slide'] = 'Slide-in panel'; // 值是显示名，需要自己翻好
	return $variants;
} );
```

- 不传 / 传空 = 按页面表单显示（3.0 之前入库的老记录就是这样，邮件里不会出现空的「()」）。

### 2.3 字段标签

```
fieldLabels[fullName]=姓名
fieldLabels[outerDiameter]=外径
```

- 键必须与字段名一致；值是该字段在表单上的可见标签。
- 是普通表单字段，可以一次传多个；数组形式 `fieldLabels[]` 会按数字下标处理，不推荐。
- 后端取值优先级：**后台覆盖 > 这里的标签 > 字段名转可读文本**（`outerDiameter` → `Outer Diameter`）。

### 2.4 附件

| 字段 | 白名单 | 单文件上限 |
|---|---|---|
| `drawings[]` | pdf step stp dwg dxf igs sldprt sldasm zip rar 7z | 20 MB |
| `pictures[]` | png jpg jpeg webp | 10 MB |

- 字段名**必须带 `[]`**，否则多个文件只会留下最后一个。
- 单次总数上限 20（filter `ifi_max_files`）。
- 服务端按扩展名白名单 + `finfo` 内容嗅探（拒绝伪装成文档的 PHP/HTML）。
- 附件最终落在 `wp-content/uploads/ifi-inquiries/{年月}/{记录ID-随机}/`，meta 里存的是相对 Web 路径。
- **3.0.0 起目录名从 `eln-inquiries` 改为 `ifi-inquiries`**，升级迁移时自动搬移。

### 2.5 蜜罐与垃圾规则

隐藏字段 `companyWebsite`（默认名，可在 `Inquiries → Block Rules` 的「隐藏字段名」里改，改完前端要同步）：移出视口、`tabindex="-1"`、`aria-hidden="true"`、`autocomplete="off"`，正常用户看不到也不会填；机器人遍历表单字段时容易中招。**非空即判定机器人**：返回 `200 {"sent":true}`，不发信、不占限流配额（判定在限流之前）；默认在后台「垃圾」视图留一条记录，可在 `Inquiries → Block Rules` 关掉留档或整个蜜罐。

后台 `Inquiries → Block Rules` 还能再叠几条服务端规则（option `ifi_spam_rules`），**前端不需要任何改动**：

| 规则 | 判定方式 |
|---|---|
| 关键词 | 每行一个，不区分大小写，匹配姓名 / 消息 / 附加字段的任意位置（邮箱不参与，交给域名规则） |
| 邮箱域名 | 每行一个；`example.com` 同时命中 `mail.example.com`，子域不用单列 |
| IP / CIDR | IPv4、IPv6 与网段（`203.0.113.0/24`、`2001:db8::/32`）；**到门口就丢**，不建记录、不占限流配额 |
| 链接上限 | 所有文本字段里的 `http(s)://`、`www.` 链接总数超过上限即拦（0 = 不限） |
| 重复提交 | 窗口内同一邮箱 + 同一正文再次提交即拦；首次**成功交付**后才开始计时，发信失败重试不会被挡 |

装好后自带一份保守的起步名单（10 个一次性邮箱域名 + 9 个高确定性垃圾词），不用配置就能拦；不需要就整组清空。命中任何一条的处理与蜜罐一致：`200 {"sent":true}`，不发通知、不发自动确认；默认在「垃圾」视图留档，并标注命中的规则。想先看效果，页签右侧的测试面板会用表单里当前这份规则（含未保存改动）试跑一条假提交，不入库也不发信。

#### 2.5.1 提交频率限制

同一可信请求标识 **1 分钟最多 3 次、1 小时最多 10 次**。两个都是固定窗口，各自按该窗口内首次提交起算，到点自动清零；超限时返回 `429`，响应体带 `retry_after`、响应头带 `Retry-After`（秒）。被拒的请求本身不计数，等过窗口即可恢复。

默认只取 `REMOTE_ADDR`；只有 `REMOTE_ADDR` 是配置的可信代理时才采信 `X-Forwarded-For` / `X-Real-IP`，并从右向左跳过可信代理、取第一个不可信跳（最左侧条目由客户端提供，不能作为依据）。拿不到公网地址时退化成服务端签发的 HttpOnly cookie `ifi_vid`（带 HMAC 签名，前端脚本读不到也改不了），每个访客一个桶。需要按真实公网 IP 分桶时，请在反代层正确传递并配置可信代理：

```php
// wp-config.php
define('IFI_TRUSTED_PROXIES', ['203.0.113.10']);
```

两个次数在后台 `Inquiries → Block Rules` 的「Rate limit」卡片里直接改（`rate_minute` / `rate_hour`，默认 3 和 10，填 0 关闭该窗口），窗口长度固定为 1 分钟 / 1 小时。需要连窗口长度一起换（例如改成滚动 6 小时）时再用 `ifi_rate_limits` 过滤器，它整份覆盖设置页的值；`ttl` 小于 1 或过滤器返回非数组时退回默认值，写错不会把整站提交锁死：

```php
add_filter('ifi_rate_limits', fn($l) => [
    'minute' => ['limit' => 5,  'ttl' => 60],
    'hour'   => ['limit' => 30, 'ttl' => 3600],
]);
```

后台改完立即生效，不必等缓存过期：限流窗口按「首次提交」起算，改小之后当前窗口内已用掉的次数仍然算数。

### 2.6 询盘编号

服务端在入库时生成编号，格式 `IQ-YYYYMMDD` + 3 位当日流水（如 `IQ-20261105001`），存进记录的 `ifi_inquiry_no` meta，邮件模板里用 `{{no}}` 引用。流水用 MySQL 的 `LAST_INSERT_ID(expr)` 原子自增，并发提交不会重号；每日一个计数器（option `ifi_seq_YYYYMMDD`）。「发送测试邮件」和预览不会消耗真实编号。

插件启用后的首次进入后台时，会给**早于该功能**的旧记录按提交时间补号（option `ifi_numbers_backfilled` 记账，只跑一次），当天计数器同时推到已用最大值，保证下一封询盘接着排。

### 2.7 环境数据：IP / 浏览器语言 / 时区

三类数据各有出处，邮件模板里分别对应 `{{ip}}`、`{{lang}}`、`{{time_zone}}`：

| 数据 | 谁采集 | 说明 |
|---|---|---|
| IP | **WordPress** | 只用 `REMOTE_ADDR`；当它是可信代理时，从 `X-Forwarded-For` 右侧向左跳过可信代理、取第一个不可信跳，`X-Real-IP` 仅作单跳兜底；`FILTER_VALIDATE_IP` 通过才用。这是邮件/入库里记录的来源 IP，**限流标识另算**（见 §2.5.1） |
| 浏览器语言 `browserLanguage` | 前端 | `navigator.languages?.[0] \|\| navigator.language`，如 `zh-CN` |
| 用户时区 `timezone` | 前端 | `Intl.DateTimeFormat().resolvedOptions().timeZone`，如 `Asia/Shanghai` |

前端只需两行：

```js
const lang = navigator.languages?.[0] || navigator.language || "";
if (lang) data.append("browserLanguage", lang);
const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
if (tz) data.append("timezone", tz);
```

- **不要再传 `ip`**。服务端只采信链路识别到的来源，前端自报的 IP 一律丢弃（`normalize_env_fields()`）。
- 时区同时用于渲染 `{{time_local}}`（按访客时区换算的提交时间），拿不到就整行不显示。
- 若站点位于反代或穿透之后，请让链路正确传递客户端来源，并在插件配置中声明可信代理；否则服务端只能看到回环地址。这是链路配置问题，不是插件问题。

### 2.8 响应

| 情况 | 状态码 | 响应体 |
|---|---|---|
| 成功 | 200 | `{"sent": true}` |
| 缺必填 | 400 | `{"code":"inquiry_missing_field", ...}` |
| 邮箱非法 | 400 | `{"code":"inquiry_invalid_email", ...}` |
| 附件不合规 | 400 | `{"code":"inquiry_bad_upload","message":"File type .exe is not allowed."}` |
| 触发限流 | 429 | `{"code":"inquiry_rate_limited","retry_after":N, ...}`，响应头 `Retry-After: N` |
| 发信失败 | 500 | `{"code":"inquiry_mail_failed", ...}` |

前端区分 `2xx` / `429` / 其它非 `2xx`：429 用「提交太频繁，请稍后再试」专属提示，其余走通用失败提示。

### 2.9 后台记录与状态

每条提交会生成一条私有的 `ifi_inquiry` 记录，后台「询盘」列表按以下列展示：

`# / From（姓名+邮箱）/ Message / Attachments / Source / Automatic Status / Received`

- **Pending / Contacted**：新询盘默认属于 Pending；列表行操作「Mark as Contacted」写入 `ifi_inquiry_contacted`，之后归入 Contacted，可再点「Mark as Pending」撤销。
- **Automatic Status**：把两封邮件的投递结果压成一个徽章——`All Sent` / `Only Notification` / `Only Auto-Confirmation` / `Not Sent`。悬停可看逐封状态与 SMTP 报错原文。
- 列表顶部有一块可折叠的「Overview」看板：新询盘数、未发出邮件的条数、近 7 / 30 天提交量与环比、访客语言 Top、按表单形态统计（页内 / 弹窗 / 抽屉 / 悬浮 / 固定，只统计登记的五种形态，见 §2.2.1）、按来源统计的提交量（前 5 个来源，点击即按该来源筛选列表）；折叠状态记在浏览器里。
- WordPress 仪表盘上另有一个「最近询盘」挂件：四个指标（今日新增 / 待处理 / 垃圾 / 邮件异常）+ 最近 5 条（编号、姓名邮箱、来源、发信徽章、接收时间），只做概览，不放操作。条数用 `ifi_dashboard_limit` 调整。

### 2.10 邮件模板（询盘通知 + 自动确认）

后台「询盘 → Email template」有两个标签页，共用一套编辑器（源码 + 实时预览 + 测试发信）：

| 标签页 | option | 发给谁 | 结构 |
|---|---|---|---|
| 询盘通知 | `ifi_template` | 站长/销售（`admin_email`，filter `ifi_recipient`） | `subject` / `html` / `labels` |
| 自动确认 | `ifi_autoreply` | **访客自己填的邮箱** | `enabled` + `groups[]`，每组含 `lang` / `subject` / `html` / `labels` |

**自动确认按浏览器语言分「语言组」**：

- `groups[0]` 固定是**默认组**（`lang` 为空），其它组各自带一个语言标签（`zh-hans`、`zh-hant`、`en`）。
- 匹配顺序：**完整标签精确命中** → **主语言命中** → 默认组。
  - 语言标签在保存时会归一：`zh-CN`/`zh`/`zh-SG` → `zh-hans`，`zh-TW`/`zh-HK`/`zh-MO` → `zh-hant`，`en-*` → `en`。
  - 主语言命中意味着**繁简互通**：只配了 `zh-hans` 时，`zh-TW` 访客拿到的是简中模板而不是默认模板 —— 同语言换字形，比换语言更接近正确。
- 每组独立持有主题、源码、字段标签——等于「一种语言一份完整模板」。
- **渲染时会切换 WordPress locale**：换的不只是模板源码，「附件」「页面表单」这类固定文案以及留空时的默认主题也跟着访客语言走。若该 locale 没有站点级语言包，会退化为只替换本插件的文本域。
- 后台在「自动确认」页用标签条切换语言组，可新增/删除（默认组不可删）。
- 默认关闭，勾选「启用自动确认」才发；发信顺序固定：先通知、后确认，两者互不牵连。
- 确认邮件带 `Reply-To: <询盘收件人>`，客户直接回信落到销售邮箱；不带附件。
- 状态各记各的：通知 → `ifi_inquiry_mail_sent`，自动确认 → `ifi_inquiry_autoreply_sent`（未启用时不写，后台显示「—」）。
- 发出后可挂 `do_action('ifi_autoreply_sent', $sent, $email, $fields)`。

**3.0.0 的默认模板**改成了 WordPress 后台风格（浅灰底 + 白卡片 + `#2271b1` 色条 + 系统字体 + 英文文案）。
已经保存过自定义模板的站点不受影响；把 HTML 源码清空保存即可回到新默认。

**占位符（两套模板通用，只输出值、不带标签）**：

| 占位符 | 内容 | 谁提供 |
|---|---|---|
| `{{rows}}` | 字段表格（表头用当前模板的 `labels`，没配就用表单自己提交的 `fieldLabels`） | 前端表单 |
| `{{rows.*.label}}` | 把 `*` 换成字段名，输出该字段的**显示名**（如 `{{rows.fullName.label}}` → `Name`），与表格表头同源 | 后台 Field labels / 表单 `fieldLabels` / 字段名 |
| `{{rows.*.value}}` | 把 `*` 换成字段名，输出该字段的**值**（如 `{{rows.fullName.value}}`） | 前端表单 |
| `{{source}}` `{{form_type}}` | 来源页英文标题 / 表单形态（如「抽屉表单」） | 前端（pageTitle、pageUrl、formVariant） |
| `{{time_local}}` | 提交时间换算到访客时区 | 前端（timezone） |
| `{{lang}}` `{{time_zone}}` | 浏览器语言 / 访客时区 | 前端（browserLanguage、timezone） |
| `{{no}}` `{{time}}` `{{ip}}` | 询盘编号 / 提交时间（站点时区）/ 服务端识别的 IP | 插件 |

> **占位符不会自动加「Timezone:」这类标签**。想要带标签的行，就在源码里写 `Timezone: {{time_zone}}`。

**字段标签与行序**：`{{rows}}` 的行序 = 后台「字段标签」列表的顺序（可拖动排序），
没排过的字段再按前端提交顺序补在后面。标签表现在**手动维护**：在后台按前端字段名添加，
键名一致即可；字段级引用**只有** `{{rows.字段名.label}}` / `{{rows.字段名.value}}` 两种，裸写 `{{rows.字段名}}` 已删除，会原样留在模板里。

主题（subject）用**和正文同一套** `{{rows.*.value}}` / `{{rows.*.label}}` 字段变量；留空用语言字典的默认文案。
主题走 `sanitize_text_field`，**不要写 `<` `>`**（会被当成 HTML 标签剥掉）。

---

## 3. 最小实现（原生 JS）

```html
<form id="inquiry">
  <label for="f-name">姓名 *</label>
  <input id="f-name" name="fullName" required />

  <label for="f-mail">邮箱 *</label>
  <input id="f-mail" name="email" type="email" required />

  <label for="f-msg">留言 *</label>
  <textarea id="f-msg" name="message" required></textarea>

  <input type="file" name="drawings[]" multiple accept=".pdf,.step,.stp,.dwg,.dxf,.igs,.zip,.rar,.7z" />
  <input type="file" name="pictures[]" multiple accept="image/png,image/jpeg,image/webp" />

  <input name="companyWebsite" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" />
  <button type="submit">发送</button>
</form>

<script>
document.getElementById('inquiry').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.currentTarget;
  const data = new FormData(form);

  // 1) 带上每个字段自己的标签（可省略，省略后后端用字段名）
  form.querySelectorAll('input[name], textarea[name], select[name]').forEach((el) => {
    if (!el.name || el.name === 'companyWebsite') return;
    const label = el.id ? form.querySelector(`label[for="${CSS.escape(el.id)}"]`) : null;
    const text = (label?.textContent || '').replace(/\*/g, '').trim();
    if (text) data.append(`fieldLabels[${el.name}]`, text);
  });

  // 2) 元信息（IP 不用传，服务端自己识别）
  data.append('formVariant', 'page');
  data.append('pageUrl', location.href);
  data.append('pageTitle', document.title.replace(/\s*[-–|]\s*Brand\s*$/i, '').trim());
  data.append('locale', document.documentElement.lang || 'en');
  const lang = navigator.languages?.[0] || navigator.language;
  if (lang) data.append('browserLanguage', lang);
  const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
  if (tz) data.append('timezone', tz);

  // 3) 提交（不要手写 Content-Type）
  const res = await fetch('/wp-json/inquiry-form-inbox/v1/inquiry', { method: 'POST', body: data });
  if (res.ok) {
    form.reset();
    // 清空自定义上传组件、重置自定义下拉…
  } else {
    // 显示失败提示
  }
});
</script>
```

要点：

- `new FormData(form)` 会自动带上所有 `name` 控件；**动态控件（自定义下拉、拖拽上传）没有原生 name，需要手动 `append`**。
- 一定不要设置 `Content-Type`，浏览器要自己写 boundary。
- 提交期间禁用按钮，避免重复记录。

---

## 4. 主流框架怎么接

### React

```jsx
const formRef = useRef(null);

async function onSubmit(e) {
  e.preventDefault();
  const form = formRef.current;
  const data = new FormData(form);
  // 受控组件也在 DOM 里，FormData 同样能取到
  data.append('fieldLabels[fullName]', '姓名');
  const res = await fetch('/wp-json/inquiry-form-inbox/v1/inquiry', { method: 'POST', body: data });
  if (res.ok) form.reset();
}
```

- 别把文件塞进 state 再转 Blob；直接读 `<input type="file">` 的 `files`。
- 用组件库（Ant Design / MUI）时，标签往往不在 `<label for>` 里，**手动传 `fieldLabels`** 最省事。

### Vue 3

```vue
<form ref="form" @submit.prevent="submit">…</form>

<script setup>
const form = ref(null);
async function submit() {
  const data = new FormData(form.value);
  const res = await fetch('/wp-json/inquiry-form-inbox/v1/inquiry', { method: 'POST', body: data });
  if (res.ok) form.value.reset();
}
</script>
```

### Svelte

```svelte
<form on:submit|preventDefault={submit} bind:this={form}>…</form>
```

### Next.js / Nuxt（SSR）

- 站点与 WordPress **同源**时直接相对路径提交；不同源就配反向代理（推荐），避免 CORS 预检。
- 若必须跨域：后端已允许（REST 默认 CORS），但要确认 `multipart` 预检通过；**仍然建议同源**。

### 纯 HTML（无 JS）

```html
<form action="/wp-json/inquiry-form-inbox/v1/inquiry" method="post" enctype="multipart/form-data">…</form>
```

可提交成功（浏览器会跳转显示 JSON），但拿不到成功/失败提示。要留在页面上就必须用 `fetch`。

---

## 5. 性能与解耦

| 维度 | 现状 |
|---|---|
| 请求数 | 每次提交 **1 个** multipart 请求；附件随请求走，没有分片/二次上传 |
| 服务端开销 | 校验 → 存记录 → 移动附件 → 组邮件 → 发信，一次同步完成（SMTP 通常 0.3–1s） |
| 前端依赖 | 0；不加载任何插件脚本、不依赖 jQuery |
| 与 CMS 解耦 | 插件只写自己的 CPT 与 `wp-content/uploads/ifi-inquiries/`，不改主题、不注册短代码 |
| 迁移成本 | 换站点只需改端点地址与（可选）`pageTitleEn` 的来源 |

> 本站额外做了一个优化：构建期生成 `dist/page-titles.json`（路径 → 英文标题），前端在页面加载时预热、提交时查表带上 `pageTitleEn`。这是**站点侧**的可选增强，插件不依赖它。

---

## 6. 安全与限制

- 蜜罐 `companyWebsite`：非空静默成功，不发信，也不消耗限流配额；默认在「垃圾」视图留档。
- 垃圾规则（关键词 / 邮箱域名 / IP·CIDR / 链接上限 / 重复提交）：命中同样返回 `200 {"sent":true}`，不发信；IP 黑名单连记录都不建。
- 限流：同一访客默认 1 分钟 3 次 / 1 小时 10 次（双固定窗口，次数可在 Block Rules 页改），超限 429 + `Retry-After`；标识优先公网 IP，回环时用服务端签发的 HttpOnly `ifi_vid`（详见 §2.5.1）。
- 前端对 429 显示「提交太频繁」专属提示，其余非 2xx 用通用失败提示。
- 附件校验扩展名白名单、声明大小、真实内容 mime；落盘目录名含随机串，实际不可枚举。**内容嗅探依赖 PHP 的 `fileinfo` 扩展**：没有该扩展时 `sniff_mime()` 返回空串，这一道闸静默降级，只剩扩展名白名单。
- 默认按 `multipart/form-data` 提交。纯文本字段也可以用 `application/json`（WordPress 会把 JSON 体解析成参数），但**附件必须走 multipart**。
- 请求体上限：由 Web 服务器的请求体限制与 PHP `post_max_size` 共同决定，请按附件白名单容量配置。

---

## 7. 换一个项目要改什么

1. **必填字段**（如果不是 `fullName/email/message`）：

```php
add_filter('ifi_required_fields', fn() => ['name', 'mail', 'body']);
```

2. **收件人**（默认 `admin_email`）：

```php
add_filter('ifi_recipient', fn() => 'sales@example.com');
```

3. **附件上限 / 白名单**：`ifi_max_files` 可改数量；扩展名白名单写在插件常量 `FILE_GROUPS` 里（`private const`，改动需改源码）。

> **附件总数的双重上限**：PHP 的 `max_file_uploads`（默认 20）会在插件看到文件之前就把超出的文件丢掉，而插件自己的默认上限也是 20。也就是说默认配置下「单次提交附件过多」这条错误在 HTTP 层永远不会触发，访客传 21 个文件会成功入库 20 个。要真正限制数量，请让 `ifi_max_files` **小于** `max_file_uploads`。
4. **可信代理**（反代后要按真实 IP 限流）：`define('IFI_TRUSTED_PROXIES', [...])` 或 filter `ifi_trusted_proxies`。
5. **垃圾规则**（不用后台也可以写死）：`add_filter('ifi_spam_rules', ...)` 整份替换；要接自己的判定逻辑用 `ifi_spam_verdict`，返回 `['reason' => 'my_rule', 'hit' => '...']` 就按命中处理（同样不发信、默认留档）。
6. **蜜罐字段名**：后台 `Inquiries → Block Rules → 隐藏字段名` 直接改（默认 `companyWebsite`），前端要同步换成同名隐藏字段。代码里仍可用 `add_filter('ifi_honeypot_field', fn() => 'website')` 覆盖，过滤器优先于页面上的值。
7. **页面标题规范化**（可选）：不做的话，来源列显示前端提交的 `pageTitle`；做了就有统一英文口径。

> 3.0.0 把全部钩子从 `eln_` 改成了 `ifi_`。如果旧项目里写过 `eln_recipient` 之类的 filter，请一并改名，否则不会生效。

---

## 8. 验收清单

- [ ] 最小表单（三个必填）能提交，收到邮件且后台出现记录
- [ ] 附件：单文件、多文件、超限、非法扩展名各测一次
- [ ] 蜜罐填写后返回 200、不发信，且「垃圾」视图出现一条记录（关掉留档后不再产生记录）
- [ ] Block Rules 里加一个关键词 / 域名 / IP 后，对应提交返回 200 但被拦在「垃圾」视图，测试面板显示命中的规则
- [ ] 刷新页面后重复提交按钮被禁用（或后端限流生效）
- [ ] 移动端键盘弹出时提交按钮可点
- [ ] 失败时（断网/500）有可见提示，表单内容不丢
- [ ] 后台来源列显示的是期望的页面名
- [ ] 邮件页脚出现「Browser language / Timezone / Local time」，且换一个时区（如 America/New_York）后当地时间跟着变
- [ ] 后台记录详情的 IP 是服务端识别值（不是前端传的），浏览器语言/时区与访问环境一致
- [ ] 自动回复：开启后提交能收到第二封（收件人是填表邮箱），关闭后不再发
- [ ] 列表行操作「Mark as Contacted」后记录从 New 移到 Contacted，撤销后回到 New
- [ ] 列表顶部的概览看板显示新询盘数（点击跳 New 视图）与未发信条数，折叠按钮可用且刷新后保持
