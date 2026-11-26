# Normalization report — 3.0.14 → 3.0.15

What changed, why, and what it looked like before. Every item was found by reading the code
or by running one of the suites described in `TEST-REPORT.md`.

## Summary

| Metric | Before | After |
|---|---|---|
| PHPCS (plugin source) | 9 errors / 28 warnings | **0 / 0** |
| String dictionary | 223 keys, 26 unused (11.7%) | **214 keys, 0 unused** |
| Missing string keys | none, but a miss rendered as empty text | **none, and a miss is now visible** |
| Bare English UI strings | 16 sites | **0** |
| Full-width CJK punctuation in output | 4 sites | **0** (`：` / `（）` now come from translated format strings) |
| Untranslatable default-template copy | 5 Chinese HTML comments | **0** |
| gettext chain | POT stale by 2 strings | **POT == source, PO == POT, MO == translated PO** |
| Untranslated strings (zh_CN) | 3 metadata strings | **3 metadata strings** (author name, author URI, plugin URI — none should be translated) |
| readme.txt | 21,947 bytes, 2 fails | **9,352 bytes, 0 fails** |
| Changelog in readme | 21 entries | **2 entries**, full history in `changelog.txt` |

## P0 — defects that were visible to users or reviewers

### 1. The main list screen rendered a table with no headers and no cells

`includes/trait-ifi-cpt.php` (`admin_columns`), `includes/class-inquiry-form-inbox.php`

The column filter was registered **only** on `manage_ifi_inquiry_posts_columns`, which the
native `edit.php` screen uses. The self-rendered list page (`admin.php?page=ifi-inquiry`)
resolves columns through `get_column_headers()`, which applies
`manage_edit-ifi_inquiry_columns` instead — a hook nobody was listening on. The result was an
empty column set: `<thead><tr></tr></thead>`, and every row rendered zero `<td>`.

Worse, `admin_columns()` began by looking for a `title` key in the incoming array, so even
once the filter fired it could only produce columns if something upstream had supplied a base
set. On the self-rendered page nothing had.

Fixed by (a) registering the filter on both hooks and (b) building the column set from
scratch, preserving any extra column another plugin adds.

Before: `<thead><tr></tr></thead>`, rows with `colspan="0"`. After: seven headers, seven
cells per row.

### 2. Every inquiry reported one attachment, even with none

`includes/trait-ifi-cpt-actions.php` (`attachments_html`), `includes/trait-ifi-cpt.php`,
`includes/trait-ifi-upload.php`

```php
$files = (array) get_post_meta( $post_id, 'ifi_inquiry_attachments', true );
if ( ! $files ) { return '—'; }          // never true
```

`get_post_meta( …, true )` returns `''` when the key is absent, and `(array) ''` is
`array( '' )` — a non-empty array. So the guard never fired, the loop ran once over an empty
string, and `$file['name'] ?? ( 'file-' . $i )` produced `file-0`. Every inquiry without an
attachment showed **"1 File"** in the list and **"file-0 (0 B)"** in the detail dialog.

Fixed with a single `record_attachments()` helper that normalises to "list of arrays", used
by the list column, the detail dialog and the file purge.

### 3. A malformed email address was reported as a missing field

`includes/trait-ifi-rest.php`

The route declared `email` with `'sanitize_callback' => 'sanitize_email'`. `sanitize_email()`
returns an empty string for `not-an-email`, so by the time the handler ran, the required-field
check fired first and the response was `inquiry_missing_field`. INTEGRATION.md documents
`inquiry_invalid_email` for that case — the front end was told the wrong thing.

Fixed by transporting the address as plain text, validating with `is_email()`, and only then
normalising with `sanitize_email()`.

### 4. Auto-confirmation switched language without switching language

`includes/trait-ifi-i18n.php`, `includes/trait-ifi-mailer.php`, `includes/trait-ifi-template.php`

The per-language groups swapped the template source, but every fixed string inside it —
the fallback subject, `Attachments`, `Page form` — still resolved through `__()` under the
**site** locale. A `$render_locale` property existed for this purpose and was read by the
dictionary but never assigned anywhere: dead scaffolding left over from the 3.0.0 gettext
migration.

Fixed with `with_locale()`, which switches the WordPress locale around the render, plus
`with_textdomain()` for the case `switch_to_locale()` refuses: it only accepts locales
installed site-wide, and a Chinese site answering an English visitor is exactly that case.

Verified end to end: on a `zh_CN` site, `en` → `We received your inquiry`, `zh-hans` →
`已收到您的询盘`, `zh-hant` → `已收到您的詢盤` (which previously fell back to Simplified).

### 5. Privacy export and erase skipped the trash

`includes/class-ifi-privacy.php`

`post_status => 'any'` does not include `trash`. A data subject whose inquiry an administrator
had thrown away got nothing back from the exporter, and the eraser left the record in place.
Fixed by listing the statuses explicitly.

### 6. Privacy export and erase did not paginate

Same class. Both callbacks ignored `$page` and used `posts_per_page => -1` with `done => true`.
Core calls these repeatedly until `done`; returning the whole inbox in one request ties the
response to the size of the table. Both now page in batches of 500, and the eraser re-reads
page one each round because it deletes what it reads.

### 7. The exporter did not export the data

Same class. `meta_keys()` covered seven of seventeen stored fields and exported `fields` — a
list of *field names* — as if it were the submission. Fixed: every stored meta field is
included, and `field_values` is expanded into one row per submitted field using the label the
visitor saw.

### 8. Uninstall left an option behind

`uninstall.php`. `ifi_labels_manual` is written by the migration, not by the settings screen,
so it was missing from the cleanup list.

### 9. `$_SERVER` was read without sanitising

`includes/trait-ifi-rate-limiter.php`. Three reads fed the trusted-proxy decision. All now go
through one `server_string()` helper.

### 10. A duplicate plugin header in a subdirectory

`includes/class-inquiry-form-inbox.php` carried a second `Plugin Name` / `Version: 3.0.7`
block — seven versions stale. Plugin scanners treat that as another plugin. Replaced with a
normal file docblock.

### 11. Chinese punctuation in the English admin

`includes/trait-ifi-cpt-actions.php`, `includes/trait-ifi-dashboard.php`. Four sites built
`label：value` and `name（variant）` with full-width punctuation, so an English admin read
`Inquiry notification：Sent`. Replaced with the translatable format strings `%1$s: %2$s` and
`%1$s (%2$s)`, which render as ASCII in English and full-width in Chinese — the behaviour the
hard-coded punctuation was reaching for, done properly.

## P1 — copy, structure and consistency

| Item | Before | After |
|---|---|---|
| `'Insufficient permissions.'` | 10 hard-coded sites | one `error_forbidden` key |
| JSON error payloads | `{'message': 'forbidden'}` | `{'code': 'ifi_forbidden', 'message': <translated>}` |
| REST / upload / migration errors | 16 bare English strings | translated, with `%s` placeholders documented by translator comments |
| Default email template | 5 Chinese HTML comments shown in the editor | English, matching the readme's "English copy" claim |
| Plugin header | 60 lines of Chinese design notes | a short docblock; the notes moved to `docs/ARCHITECTURE.md` |
| Terminology | `Pending` in code, `New` in INTEGRATION.md, `view_new` as a key | `Pending` everywhere, key renamed `view_pending` |
| `ifi_strings` overlay | returning one key wiped the other 213 | merged over the base map |
| Source-title prefixes | `文章\|Article\|Post` hard-coded | `ifi_source_title_prefixes` filter |
| `readable_label('SKU')` | `S K U` | `SKU` — acronyms survive, `HTTPServer` still becomes `HTTP Server` |
| Capacity / capability grants | six `update_option()` calls per admin request | zero when the caps are present |
| Template cache | method-level `static`, invisible to `update_option` | class-level, flushed on option update |

Two documentation claims were **false** and are now true:

- the header said the plugin ships a "three-language dictionary (zh_CN / zh_TW / en)"; the
  zh arrays were always empty and gettext did the work. zh_TW was removed entirely in the
  terminology pass; only zh_CN ships now
- INTEGRATION.md said `FILE_GROUPS` was editable — it is a `private const`

## P2 — packaging

- `.distignore` listed two files that no longer existed and missed `*.py`; rewritten with
  sections, now also excludes `wporg-assets/` (SVN-only) and `build-zip.php`.
- `build-zip.php` added: builds the distribution zip from `.distignore`, refuses to ship
  `tests/`, `tools/`, `vendor/` or `composer.json`, and uses `PharData` because the `zip`
  extension is not installed here.
- `tools/update-translations.py` (a hand-rolled MO compiler, and the cause of the 2.0.4
  blank-`msgstr` incident) deleted; `composer pot` / `composer mo` now call WP-CLI.
- `php-stubs/wordpress-stubs` removed from `require-dev` — there is no PHPStan config using it.
- Screenshots moved out of `assets/` (which ships CSS/JS) into `wporg-assets/`, which maps to
  the SVN `/assets` directory.

## Corrected finding

An earlier draft of this report claimed the ungated capability grant wrote `wp_user_roles`
six times per admin request. **Measured: zero database writes.** `WP_Role::add_cap()` does call
`update_option()` unconditionally, but `update_option()` compares before writing, and the value
never changed. The real cost was six `update_option()` round-trips per admin request, each
re-running the `pre_update_option_*` filters and re-serialising the whole role table — wasteful,
not harmful. The fix stands; the severity claim does not.

## Deliberately not done

- No refactor into standalone classes or an autoloader. The test net comes first; the nine pure
  helpers can be extracted mechanically once it is in place.
- No data-model changes. Post type, meta keys and option names are untouched.
- No 4.0-style renames.
- `assets/` filenames unchanged.
- Tag-count and `Tested up to` conflicts between the guidelines and the FAQ are reported
  side by side in `WPORG-AUDIT.md` rather than silently resolved.
