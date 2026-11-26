# WordPress.org admission audit — Inquiry Form Inbox 3.0.15

Scope: the plugin as it stands in this repository, prepared for a **first** submission to the
WordPress.org plugin directory. Verdicts: `pass` / `fail` / `gap` (evidence missing) / `n/a`
(not applicable to this distribution target).

**This audit is not a guarantee of approval.** The directory team publishes its common-issues
list as "excerpts from the team's email messages", explicitly not exhaustive, and states that
the outcome depends on the manual review it performs. Where the source material does not give
a rule, the item is marked `gap` rather than guessed.

A note on numbering: the guidelines are addressed below **by subject**. Only the items quoted
verbatim in the review material carry their published number; the rest are grouped under the
area they govern, so nothing here rests on a half-remembered list.

---

## 1. Blocking items found, and what was done

| Finding | Before | Verdict then | Fix |
|---|---|---|---|
| readme exceeds the 10k ceiling | 21,947 bytes | **fail** | rewritten to 9,265 bytes; full history moved to `changelog.txt` |
| Changelog carried 21 versions | 21 entries | **fail** | readme now has no changelog section; the full history lives in `changelog.txt` |
| Screenshots declared, none shipped | 4 declared, 0 files | **fail** | 4 PNGs generated at 1280×960 into `wporg-assets/` |
| Duplicate plugin header in `includes/` | `Plugin Name` + `Version: 3.0.7` | **fail** | replaced with a file docblock |
| `Tested up to` carried a minor version | `7.1.4` | warn | now `7.1` |
| `Plugin URI` absent | — | warn | added, pointing at the GitHub repository |
| "View details" row link pointed at the old plugin-name repository | GitHub link | warn | removed; core already provides that link |

## 2. Licence and provenance

| Item | Evidence | Verdict |
|---|---|---|
| GPL-compatible licence declared | `License: GPL-2.0-or-later` in the header, `License URI` present, `LICENSE.txt` carries the GPL v2-or-later notice | pass |
| Header and `readme.txt` declare the same licence | both `GPL-2.0-or-later` | pass |
| Every bundled file is the author's own work | no vendored libraries ship: `.distignore` excludes `vendor/`, and the zip contains only plugin source, assets, translations, readme and `docs/` | pass |
| Bundled translations are the author's own | `languages/*.po` written for this plugin | pass |
| No premium or licence-locked code | the entire plugin is functional with no key, no quota, no upsell | pass |

## 3. Human-readable code

| Item | Evidence | Verdict |
|---|---|---|
| Source ships unobfuscated | 26 PHP files, ~7,600 lines, all readable | pass |
| No minified-only JavaScript | `assets/admin.js` and `assets/template.js` are unminified source | pass |
| No build step required to read the code | no bundler, no transpiler; CSS and JS are hand-written | pass |
| Comments explain intent | extensive, in the language the project is developed in | pass |

## 4. External services, tracking, and phoning home

| Item | Evidence | Verdict |
|---|---|---|
| No outbound HTTP from plugin code | no `wp_remote_*`, no `curl_*`, no remote `file_get_contents` anywhere in `includes/` or the main file | pass |
| No remote assets | every `wp_enqueue_*` points at a local file, or at a library WordPress already bundles (`jquery`, `jquery-ui-sortable`, `buttons`, `editor-buttons`, the core code editor) | pass |
| No tracking, telemetry, or analytics | nothing is sent anywhere | pass |
| No account required | the plugin works with no registration | pass |
| No update checker of its own | updates flow through the directory | pass |
| Email leaves via the site's own mailer | `wp_mail()`, so whatever SMTP plugin the site already uses | pass |

Because there is no service, the readme needs no third-party disclosure section. That is a
consequence of the above, not an omission.

## 5. Bundled libraries

| Item | Evidence | Verdict |
|---|---|---|
| No copy of a library WordPress already ships | jQuery, jQuery UI sortable, the media modal and CodeMirror are all enqueued from core | pass |
| No third-party library shipped at all | `vendor/` is development-only and excluded from the zip; `build-zip.php` refuses to build if it sneaks in | pass |

## 6. Admin experience

| Item | Evidence | Verdict |
|---|---|---|
| No dashboard hijacking | one dashboard widget, registered through `wp_add_dashboard_widget()`, showing only this plugin's own data | pass |
| No site-wide banners | the only notices are per-screen and contextual | pass |
| Notices are dismissible or self-resolving | flash notices are one-shot query args | pass |
| No "powered by" or credit links in the admin | the plugin row link was removed | pass |
| No affiliate links | none anywhere | pass |

## 7. Readme content

| Item | Evidence | Verdict |
|---|---|---|
| Readme validates | `php tests/static/readme-check.php` → 0 fail, 0 warn | pass |
| Stable tag equals the header version | `3.0.15` in both | pass |
| Stable tag is not `trunk` | `3.0.15` | pass |
| Short description within 150 characters and free of markup | 54 characters | pass |
| Tags: at most five | five: `inquiry, form, headless, email, spam` | pass |
| Tags: no competitor plugin names | none | pass |
| Tags: no keyword stuffing | none | pass |
| `Tested up to` at or below the current release | `7.1`, current core here is 7.1.5 | pass |
| `Tested up to` does not exceed a release candidate | no RC involved | pass |
| Screenshots that exist | 4 declared, 4 present in `wporg-assets/` | pass |
| No `readme.MD` alongside | none | pass |
| No "100% compliant" claim | the readme says the plugin **assists** with an inbox; no compliance claim anywhere | pass |

**Tag note.** `inbox` was dropped: the directory hides tags used by only one plugin, so it
cost a slot and returned nothing. `spam` replaced it.

**Conflict, reported not resolved.** The guidelines treat more than five tags as spam; the FAQ
treats the extras as simply ignored. Both describe the same behaviour with different
consequences. This audit follows the stricter reading (a maximum of five) and notes that the
plugin is compliant either way.

## 8. Versioning and packaging

| Item | Evidence | Verdict |
|---|---|---|
| Main file name matches the directory slug | `inquiry-form-inbox/inquiry-form-inbox.php` | pass |
| Version incremented for this release | 3.1.2 → 3.1.3 in the header, the `VERSION` constant, the asset version and `Stable tag` | pass |
| No development files in the zip | `build-zip.php --list` → 39 files; `tests/`, `tools/`, `vendor/`, `composer.json`, `node_modules/`, `wporg-assets/` all excluded, with a guard that aborts the build if any of them appear | pass |
| `composer.json` stays in the repository | kept, so the dependency choices can be reviewed | pass |
| No `node_modules/`, no `bower_components/`, no demo folders | none | pass |
| Screenshot and banner assets live outside the zip | `wporg-assets/` maps to the SVN `/assets` directory | pass |

## 9. Slug and naming

| Item | Evidence | Verdict |
|---|---|---|
| Slug does not begin with someone else's trademark or project name | `inquiry-form-inbox` is descriptive and generic | pass |
| Display name does not imply affiliation | "Inquiry Form Inbox for Headless WordPress" describes a technique, not a product it is not | pass |
| No other project's name used as the initial term | none | pass |

## 10. Security posture

Not a directory rule in itself, but the review team treats security as the developer's
responsibility, and a plugin is closed until reported issues are fixed.

| Item | Evidence | Verdict |
|---|---|---|
| Every write path checks capability and intent | `check_admin_referer()` + `current_user_can()` on every state change; the public REST endpoint is deliberately open and documented as such | pass |
| SQL is prepared | all `$wpdb` access goes through `$wpdb->prepare()` or the query builders | pass |
| Input is sanitised per type, output escaped per context | PHPCS `WordPress.Security` sniffs are clean | pass |
| Uploads are limited by size and count; the front end owns file types | `stage_attachments()` checks upload errors, declared size and count; it no longer keeps an extension whitelist or content sniff. Sites should keep PHP execution disabled under `uploads/`. | pass (by design) |
| Uninstall removes everything it wrote | single site: covered by `tests/integration/UninstallTest.php`; multisite: `uninstall.php` loops over every site and runs the same cleanup | pass |
| Install-screen activation reports a valid main file | `build-zip.php` refuses a package without one `inquiry-form-inbox/` folder, the expected main file and exactly one `Plugin Name` header; `install_health_notice()` reports multiple headers on the plugin screens | pass |

## 11. Admission criteria beyond the numbered guidelines

The developer FAQ adds a set of "we do not accept" criteria that do not appear in the numbered
list. They are checked separately:

| Criterion | Verdict | Reasoning |
|---|---|---|
| Not a framework or boilerplate | pass | a complete plugin, not a base others build on |
| Not a library | pass | it does not require another plugin or theme to be modified |
| Not an arbitrary-code-execution tool | pass | it collects form submissions |
| Not a pure copy of an existing plugin | **gap** | the judgement is subjective and belongs to the review team; the differentiating argument is that it takes submissions from any front end over one documented REST endpoint instead of owning the form markup |
| Not an undifferentiated entry in a crowded category | **gap** | same: a human judgement, not something this audit can settle |

## 12. Evidence gaps

| Gap | Why it is not resolved here |
|---|---|
| The authoritative list of core-bundled libraries is an external link, not reproduced in the review material | only the five examples are quotable. This plugin ships no library at all, so the list is moot for it — but the gap is recorded rather than papered over |
| The readme validator is an external tool | the local `readme-check.php` encodes the field rules that are documented; the official validator still has to be run before submitting |
| Plugin Check is an external plugin | it is described as a prerequisite for submission. Running it requires installing `plugin-check` and opening its admin screen; it is not available in this environment, so it remains a manual step |
| "Meaningful differentiation" has no computable test | recorded as `gap` above |

## 13. Submission checklist

1. `php tests/bin/gate.php` → all layers green.
2. `php build-zip.php` → `dist/inquiry-form-inbox-3.1.3.zip`.
3. Upload `wporg-assets/*.png` to the SVN `/assets` directory, not to `trunk/`.
4. Push `trunk/` and create `tags/3.0.15/` from the same tree.
5. Confirm `Stable tag` in **both** `trunk/readme.txt` and `tags/3.0.15/readme.txt`.
6. Run the official readme validator against the pushed readme.
7. Run Plugin Check on a site with `WP_DEBUG` on.
8. If any `gap` above matters to you, mail `plugins@wordpress.org` before submitting — the
   guidelines say to ask when unsure, and the answer is cheaper than a rejection.

---

*Prepared against WordPress 7.1.5, PHP 8.5.9. The guideline text is quoted from the review
material available to this project; where that material is silent, the item is marked as a
gap rather than filled in from memory.*
