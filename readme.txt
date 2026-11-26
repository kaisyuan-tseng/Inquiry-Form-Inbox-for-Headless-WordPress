=== Inquiry Form Inbox for Headless WordPress ===
Contributors: kaisyuan-tseng
Tags: inquiry, form, headless, email, spam
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 3.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect any frontend. Keep every inquiry in one place.

== Description ==

Inquiry Form Inbox is the WordPress half of a headless form. Your frontend posts to one REST endpoint; everything after that happens in the admin.

Most form plugins want to own your markup, your CSS and your JavaScript. This one does not: it archives the submission, sends the emails and keeps a delivery log, so your frontend stays yours.

**What you get**

* One public endpoint, `POST /wp-json/inquiry-form-inbox/v1/inquiry`, `multipart/form-data`, attachments included. No schema to learn, no second upload step.
* Every field the form sends, in the order it was sent, with the label your visitor actually saw.
* Pending / Contacted / Spam / Trash views, a one-click "Mark as Contacted", and a read-only detail view.
* An overview board above the list: today's inquiries, pending and 30-day spam counts, and the delivery state of both emails.
* Five form styles out of the box: page, popup, drawer, floating and sticky.
* A Block Rules screen: honeypot with an editable hidden field name, keyword and email-domain blocklists with a conservative starter list, IPv4/IPv6 and CIDR blocking, link limits, duplicate-submission windows, and a test panel that runs the rules without storing or sending anything. Rate limits (3/minute, 10/hour per visitor) are built in.
* Two transactional emails — a notification to your inbox and an optional auto-confirmation to the visitor — each with per-language templates.
* A delivery log per inquiry: success or failure, with the raw SMTP error.
* A reply template (mailto) with per-language subjects and bodies.

**How it works**

1. Your frontend collects the form and posts it to the endpoint as `multipart/form-data`.
2. The plugin validates it, applies the honeypot and spam rules, archives the record, and moves attachments into `uploads/ifi-inquiries/`.
3. The notification goes out. If auto-confirmation is enabled, the visitor gets a second email in their own browser language.
4. Both results are stored on the record, so the list shows at a glance what was delivered.

**Does it phone home?**

No. There is no account, no licence check, no remote service and no telemetry. Email goes through `wp_mail()` using whatever mailer your site already uses.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the plugin directory.
2. Activate it through the Plugins screen.
3. Open **Inquiries → Email Template** and set the recipient, subject and body.
4. Open **Inquiries → Block Rules** and tune the honeypot and blocklists.
5. Point your frontend form at the endpoint documented below.

**Installing from a zip?** Keep the top-level folder `inquiry-form-inbox` and the main file `inquiry-form-inbox.php`. Do not repackage the plugin into a versioned folder. If the install screen reports "The plugin does not have a valid header", activate from Plugins → Installed Plugins and reinstall the official zip after deleting the old copy.

Nothing is sent anywhere until a form posts to the endpoint. The plugin does not modify your theme and registers no front-end scripts.

== Frequently Asked Questions ==

= What endpoint does the frontend post to? =

`POST /wp-json/inquiry-form-inbox/v1/inquiry`, as `multipart/form-data`. Required fields are `fullName`, `email` and `message`; every other field is passed through by name. Success returns `{"sent":true}`.

= What does the plugin store? =

For each inquiry: the submitted fields and their labels, browser language, timezone, source page, IP address, and any uploaded attachments. Records live in a private post type; attachments live under `uploads/ifi-inquiries/`.

= Does it send data to third parties? =

No. The only outbound traffic is the transactional email sent through your site's own mailer.

= Why does a submission return 500 when the inquiry was saved? =

A 500 with code `inquiry_mail_failed` means the record was archived but the email failed. The inquiry keeps the SMTP error, so nothing is lost — check your mailer and resend.

= How do I block a sender or a whole network? =

Open **Inquiries → Block Rules**. Add an email domain (`example.com`), an IP or CIDR range (`203.0.113.0/24`), a keyword or a link limit: blocked submissions are never emailed and land in Spam tagged with the rule that caught them. The test panel checks a sample without storing it.

= How can I change the recipient? =

Use the `ifi_recipient` filter. The default is the site admin email.

= How do I change the text of the emails? =

**Inquiries → Email Template**. The subject and the full HTML source are editable, the preview updates as you type, and "Send test email" sends a copy to the inquiry inbox — never to a customer. Auto-confirmation and reply templates hold one group per language.

= How can I add or change UI languages? =

All UI strings use gettext with the text domain `inquiry-form-inbox`. A `zh_CN` translation ships with it; more can come from translate.wordpress.org or `wp-content/languages/plugins/`.

= Why does the install screen say "The plugin does not have a valid header"? =

That message comes from WordPress when the Activate link on the install screen points at a file that is not the plugin's main file. It usually means a stale copy is still in the plugins folder, or the zip was repackaged with a different top-level folder. Activate from Plugins → Installed Plugins, delete the old copy, and reinstall the official zip (top-level folder `inquiry-form-inbox`, main file `inquiry-form-inbox.php`). It is not a nonce or permission error.

= Are there developer hooks? =

Yes. Filters: `ifi_recipient`, `ifi_required_fields`, `ifi_max_files`, `ifi_file_groups`, `ifi_length_limits`, `ifi_dashboard_limit`, `ifi_field_manifest`, `ifi_strings`, `ifi_spam_rules`, `ifi_spam_verdict`, `ifi_honeypot_field`, `ifi_rate_limits`, `ifi_trusted_proxies`. Action: `ifi_autoreply_sent`. Full list in `docs/ARCHITECTURE.md`.

= How do I remove my data? =

Deleting an inquiry removes its attachments. The WordPress privacy tools export or erase records by email, trash included. Uninstalling removes options, records, attachments and capabilities.

== Screenshots ==

1. Inquiry list: the overview board, the Pending / Contacted / Spam / Trash views, and the inquiry table.
2. Inquiry detail dialog, showing the rendered notification alongside the inquiry metadata.
3. Block Rules screen with the blocklists and the test panel.
4. Email template editor with the live preview.
