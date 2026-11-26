# Tests

Five layers, cheapest first. A syntax error should never cost a database round trip, so the
static checks gate everything else.

```
php tests/bin/gate.php --fast      # static + unit          (~6s)
php tests/bin/gate.php             # everything             (~25s)
```

Results land in `tests/reports/run-<timestamp>/summary.txt`. Nothing depends on reading
terminal scrollback.

| Layer | Command | Needs | Runtime |
|---|---|---|---|
| L0 static | `php tests/static/php-lint.php` | PHP | 2s |
| | `php vendor/bin/phpcs` | Composer dev deps | 3s |
| | `php tests/static/lint-strings.php` | PHP | 0.1s |
| | `php tests/static/i18n-parity.php` | PHP | 0.1s |
| | `php tests/static/readme-check.php` | PHP | 0.1s |
| L1 unit | `php vendor/bin/phpunit -c tests/phpunit.unit.xml` | nothing else | 0.2s |
| L2 integration | `IFI_ALLOW_TEST_WRITES=1 php vendor/bin/phpunit -c tests/phpunit.integration.xml` | scratch schema | 9s |
| L3 HTTP | `IFI_ALLOW_TEST_WRITES=1 node tests/e2e/run.mjs` | scratch schema | 3s |
| L4 UI | `IFI_ALLOW_TEST_WRITES=1 node tests/ui/run.mjs` | real site + Chromium | 10s |

## One-time setup

```bash
composer install
php tests/bin/install-wp.php          # creates the wptests_* schema
php tests/bin/install-wp.php --fresh  # drop and rebuild it
```

L2/L3 run against a **scratch schema in the same database**, under the `wptests_` prefix.
`wp_local` has no `CREATE DATABASE` grant, so a separate database was never an option. The
bootstrap refuses to run if the prefix is anything other than `wptests_`, and it redirects
`wp_upload_dir()` into `tests/runtime/uploads` before anything can write a file. Those two
guards are the reason a test run cannot touch the real inbox.

L4 cannot use the scratch schema: wp-admin needs a full admin bootstrap. It runs against the
real local site, authenticates by injecting session cookies (never a password), and reads
whatever inquiries the site already holds. The detail screenshot is skipped when the inbox
is empty.

## Environment

| Variable | Default | Meaning |
|---|---|---|
| `IFI_ALLOW_TEST_WRITES` | unset | Opt-in required by L2–L4. Without it they skip rather than silently pass. |
| `IFI_TEST_DB_NAME` | `wordpress_local` | Scratch database. |
| `IFI_TEST_DB_USER` | `wp_local` | |
| `IFI_TEST_DB_PASSWORD` | unset | Set it in the environment; no credential is committed. |
| `IFI_TEST_DB_HOST` | `localhost` | |

## What each layer is for

**L0** catches what a reviewer would: coding standards, a string key that would render an
empty label, a translation catalogue that fell behind the source, a readme that would be
rejected.

**L1** covers the pure functions with no WordPress in the way — CIDR matching, language-tag
canonicalization, plain-text conversion, spam-rule normalization. Private statics are reached
through `tests/lib/Reflect.php`, which throws if a method disappears rather than quietly
testing nothing.

**L2** covers behaviour: submission, validation, spam rules, rate limiting, auto-confirmation
language groups, privacy export/erase, capabilities, uninstall. WordPress hooks
are snapshotted and restored per test — without that, one test's `add_filter()` breaks every
test after it.

**L3** covers the wire: real multipart encoding, real file uploads, status codes, headers and
the visitor cookie. Only HTTP can exercise `move_uploaded_file()`.

**L4** covers rendering: no PHP notice, no console error, no failed subresource, and no empty
heading, table header or field label. That last check is the one that matters most here — the
plugin resolves every UI string through a dictionary key, and a mistyped key renders as
nothing at all.

## Known limits

- PHP's `max_file_uploads` (20) drops extra files before `stage_attachments()` sees them, so
  the plugin's own "too many files" branch is covered in L2 (with the cap lowered through the
  filter) and not over HTTP. L3 asserts the ceiling a real site actually experiences.
- L4 screenshots are regenerated every run, not compared. See `docs/TEST-REPORT.md`.

## Helpers

| Script | Purpose |
|---|---|
| `tests/bin/install-wp.php` | Create or rebuild the scratch schema. |
| `tests/bin/run-uninstall.php` | Run `uninstall.php` in a subprocess, with the upload sandbox asserted first. |
| `tests/bin/issue-auth-cookie.php` | Mint the `auth` + `logged_in` cookie pair. Add `--destroy=<token> --user=<id>` to revoke. |
| `tests/bin/gate.php` | Run every layer in order. |
