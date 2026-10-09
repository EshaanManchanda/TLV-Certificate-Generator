# Changelog

[← Back to README](../README.md)

All notable changes to this plugin should be documented here. Format loosely follows [Keep a Changelog](https://keepachangelog.com/).

## 7.6.0 — 2026-10-09

### Changed
- **LMS and WooCommerce integrations moved to the Pro add-on.** The listeners, mappers, "Issue to past completions" and the six mapping pages now live in `certificate-generator-pro/src/` and need a Pro license. The free plugin keeps email sending (wp_mail, SMTP, Sender.net, Mandrill) and email templates. The mapping tables stay in the free plugin's schema, so existing mappings come back when the add-on is activated. New extension points: `cg_feature_flags` (filter) and `cg_admin_menu_integrations` (action).
- **Custom font (.ttf) upload is now part of Pro**, not only Business (`certificate-generator-pro/includes/custom-fonts.php`).

### Added
- **LearnPress integration** (`src/Integrations/LearnPress/`, **Certificate Generator → LearnPress**): issue a certificate when a student finishes a mapped LearnPress course, with track certificates. Off until enabled under Settings → Tools (`CG_USE_LEARNPRESS_INTEGRATION`).
- **Issue to past completions** (`src/Integrations/LmsBackfill.php`): a button on each course mapping (Tutor LMS, LearnDash, LifterLMS, Sensei, LearnPress) that issues the certificate to students who finished before the course was mapped. Runs in WP-Cron batches of 25 and skips anyone who already has it.

- **Built-in font library on the Fonts page** (`src/Admin/Pages/FontsPage.php`): every plan now sees the 27 bundled fonts, how many templates use each, and a note on fonts that print as Helvetica. **Download sample PDF** renders one line per font through the same FPDF path certificates use.
- **Email me a test certificate** (template editor, every plan): renders the saved template with your name and emails the PDF to your admin address. It isn't logged, so it doesn't use the monthly email allowance (`src/Admin/TestCertificate.php`).
- **One setup checklist**: the Dashboard and Documentation → Getting Started now show the same four steps (`cg_setup_steps()`), including the test certificate. Removed the "Run migration" step for new installs, and fixed the WP Mail SMTP link (`wp-mail-smtp`).
- **`{site_name}` email placeholder**. The default email is now signed with the site name instead of "The GEMA Team" (new installs; saved templates are unchanged).
- **Resend all failed** (Email Logs): one button queues every failed email that hasn't since been delivered, one per certificate and address (`src/Email/EmailResender.php`).

### Fixed
- `secure_log()` sanitized messages through a helper that no longer exists after the security-helper cleanup; it now uses `sanitize_text_field()` directly.
- The certificate-generation progress poll (`check_certificate_progress`) now requires `manage_options`; both progress handlers unslash and sanitize their nonce. Bulk-edit input is unslashed before use. Settings field labels, the email-log pagination and the bulk-download counter are escaped.
- Removed the global admin `$_POST`/`$_GET` rewrite and the security headers sent from `admin_head` (too late to take effect).
- (Pro add-on) The school bulk ZIP list called a `cg_format_date()` function that doesn't exist, so it crashed for any student with an issue date. Removed two unused REST helpers, one of which called `generate_certificate_pdf()` with the wrong arguments.
- **Accented names lost their accents when they wrapped** ("José" printed as "Jos?"): wrapped lines were converted to Latin-1 a second time.
- **Email Logs → Resend did nothing**: it called an AJAX action that had been removed. It now sends again immediately through the normal send path and shows the reason if it fails. Rows logged by LMS auto-issue say to resend from the student's row instead.
- **Bulk Send skipped new certificates for anyone already emailed.** "Already sent" was decided per email address, so a second certificate for the same person never appeared under "Not sent yet". It is now per address + certificate type (`cg_recipient_set_sql()` in `includes/Admin/filters-api.php`). Log rows from older versions with no type still count for the whole address, so upgrading doesn't re-send anything.
- **Corrected names now reach the verification page.** Fixing a recipient's name and sending again re-rendered the PDF with the same serial, but `/verify` kept the old name, because the record for that serial was never updated. It now follows the corrected name (`cg_sync_certificate_record_name()` in `includes/Services/certificate-search.php`). Teacher and school records no longer get a blank name from an empty student field.
- **LMS auto-issue emails** left `{result_link}`, `{verify_link}` and `{email}` unreplaced. They now use the same placeholders as every other send.
- **Large imports could stall forever on retry.** After a time-limit stop, re-uploading re-updated every earlier row, which is slower than inserting, so each retry stopped sooner. The stop row is now remembered for that exact file, and the next upload continues from it (`CG_Import_Writer::resume()`).
- **Re-importing duplicated rows that have no email.** They now match on name + school (+ type and date) against existing rows without an email.
- **Plan-gating bypass closed.** Blanking the license-server URL switched on offline `PRO-`/`BIZ-` prefix keys, so `BIZ-anything-LIFETIME` gave a free Business plan. Offline keys now need the `CG_LICENSE_LOCAL_KEYS` constant (development only), and a blank URL falls back to the default server. Licenses already activated offline keep working.
- **Certificate PDFs could be found by counting.** Files were named `certificate_students_row_1000.pdf`, `…1001.pdf` and so on, and the folder could be listed. Names now end in a 12-character keyed hash (`cg_certificate_file_stem()`), and `cg_certificates/` has an `index.php`. Each certificate's old file is deleted the next time it renders. **After updating, run Settings → Tools → Clear Cache once** to remove the rest.
- **Rate limits could be dodged with a fake `X-Forwarded-For` header.** Forwarded headers are now trusted only when the request comes from a proxy on a private network. Sites behind Cloudflare without real-IP restoration can return the visitor IP through the new `cg_client_ip` filter.
- **School bulk download showed a school's student list for a partial name** (one letter was enough). It now needs the exact school name, and the ZIP link carries a nonce and `rel="nofollow"`, so a guessed or crawled URL can't start rendering every PDF.
- **Email Logs → Export CSV** failed with an SQL error (`LIMIT -1`).

### Changed
- **Moved to techlovev.in**: the license server default is now `https://tlvapi.techlovev.in` and Upgrade links go to `https://techlovev.in/certificate-generator/pricing`. Sites that saved the old `tlvapi.myimage.fun` host are switched to the new one automatically. The Business "Contact us" link now opens an email to techlovev@gmail.com.
- **Free plugin + Pro add-on (WordPress.org).** The plugin no longer has plan limits or license code: the 250 certificates/month, 250 emails/month, 500-row import/export and 10-template caps are gone on every plan, and the free plugin never contacts the license server. Email templates and recipient renewal reminders are now free. The paid features moved to a separate **Certificate Generator Pro** plugin (`certificate-generator-pro/`): the License and API Settings tabs, `CG_License_Manager`, the REST API (`certificate-generator/v1`), the `[school_bulk_certificate_download]` shortcode, and custom font upload (Business). Uploaded fonts still render and can be removed without it. Usage counting and its monthly reset cron are removed; the multisite plan notice is gone. New hooks for the add-on: `cg_settings_tabs`, `cg_settings_tab_{key}`, `cg_fonts_page_message`, `cg_fonts_page_upload_card`.
- **GEMA integration removed from the plugin.** The integration-status dashboard widget moved to a private add-on (`certificate-generator-gema/`). The one-time v7 migration from `cg_gema_api_url` and the unused `cg_gema_api_key` bearer header (Pro) are gone. License options are now cleaned up by the Pro add-on's `uninstall.php`, not the free plugin.
- Plugin header: `Requires PHP: 8.2` (the bundled QR library needs it), `License: GPL-2.0-or-later`, Plugin/Author URI on techlovev.in. Dropped the unused `Domain Path` and `load_plugin_textdomain()` (WordPress.org loads translations itself).
- **Open-licensed fonts replace the commercial ones** (needed for WordPress.org). Arial, Times New Roman, Georgia, Verdana Bold, Comic Sans, Garamond and Rumble Brave Script were bundled without a redistribution license. They're now Arimo, Tinos, Gelasio, Noto Sans Bold, Comic Neue, EB Garamond and Great Vibes (Apache 2.0 / OFL, `lib/fpdf/font/FONTS-LICENSE.txt`). Saved templates keep their font setting (`FONT_FILE_ALIASES` in `includes/Core/font-manager.php`). Arimo, Tinos and Gelasio share the old fonts' metrics, so layouts don't move. Georgia used to print as Helvetica; it now prints as Gelasio. Cached PDFs re-render once (`CG_PDF_RENDER_REV` 3).
- **No third-party requests in the admin**: Chart.js 4.4.0 is bundled in `assets/vendor/chart.js/` instead of loading from jsDelivr. The QR code no longer falls back to `api.qrserver.com`, which received certificate data; the bundled QR library is always used.
- **`SECURITY.md`**: how to report a vulnerability privately. `bin/build-zip.ps1` now writes a `.sha256` checksum next to each release ZIP.
- **Long names shrink to fit their field.** Text wider than its field is drawn smaller, down to 60% of the template's font size (never below 8 pt). Only text that still doesn't fit wraps onto more lines (`cg_fit_font_size()`). Cached PDFs re-render once (`CG_PDF_RENDER_REV` 2).
- The Business plan's "19,000+ Font Library" label is now "Custom Font Upload (.ttf)". Every plan already gets the same 27 built-in fonts, so custom upload is the only font feature that is Business-only.

## 7.5.2

Admin UI refresh. Every admin screen now uses one shared, WordPress-native component kit, so pages look the same and give the same cues: what the page does, what is happening, what to do next, and what a destructive action will do.

### Added
- **Admin UI kit** (`assets/css/cg-ui.css`, `assets/js/cg-ui.js` → `window.CGUI`, `includes/Admin/ui.php` → `cg_ui_*()` helpers): page header with a one-line explanation, cards, stat tiles, tables, badges, empty states with a next-step button, notices, native `<progress>` (including an indeterminate mode), spinner, native `<dialog>`, tabs with status pills, and busy buttons. Loaded on every plugin screen; colour tokens scoped to a `cg-admin` body class.
- **Confirmation dialog for destructive actions** (`data-cg-confirm` or `CGUI.confirm()`) replaces all browser `confirm()` pop-ups. Messages say what will happen, e.g. "Revoke CG-123? Verification will show it as revoked." Esc cancels and focus returns to the button.
- **Double-submit protection**: long-running forms (bulk import, ZIP download, Test Center, test email) and AJAX buttons show a busy state until the request finishes.
- **Empty states** on every list: "No students yet → Import CSV", "No email logs match these filters → Clear filters", and so on.
- **Clear Cache** (Settings → Tools) now asks for confirmation. It deletes every generated PDF/ZIP and previously ran on a single click.

### Changed
- Migrated every admin screen: Dashboard, Students, Teachers, Schools, Templates (list and editor), Events, Bulk Import, Bulk Export, Bulk Serials, Download Certificates, Bulk Send, Email Logs, Analytics, Revoke, Serial Settings, Custom Fonts, LMS mappings, SQL Migration, Test Center, Documentation, Feedback/Contact, Server Compatibility, License, and all seven Settings tabs.
- The uninstall "keep data?" prompt on the Plugins screen is a native `<dialog>`: Esc and Cancel both cancel, and the page behind it is inert.
- Over 400 inline `style=""` attributes moved into stylesheets. Deleted `analytics-style.css` and `bulk-serial-style.css`.

### Fixed
- **Bulk Serials** results table showed "undefined" IDs, wrote 5 cells into a 6-column table, and inserted recipient names as raw HTML.
- **Revoke Certificate** inserted server messages as HTML; they are now shown as text.
- **Download Certificates without JavaScript**: the duplicate-click guard disabled the clicked "Part N" button inside `submit`, so `cg_zip_part` was not sent.
- **Documentation and Settings pages** redefined shared CSS classes (`.cg-badge`, `.cg-card`, `.cg-grid`), which broke the shared styling on those pages.
- **Server Compatibility** page had two buttons that did nothing ("Re-run Activation" had no handler; "View Activation Status" linked to a page that does not exist). Both removed.
- **Analytics** no longer throws a JavaScript error when there are no certificates yet.
- Bulk Send assets are versioned by file time, so browsers pick up changes without a hard refresh.

### Tests
- E2E: `confirmKit()` fixture helper for the kit dialog; specs 03, 10 and 12 confirm through it. Spec 03 locators fixed (list dates shown as d-m-Y; notice assertions scoped to `.notice-success`).
- PHPUnit assertions updated for the new markup (Events linked records, bulk-import issue table). PHPStan baseline is one entry smaller.

## 7.5.1

- **Bulk Send shows accurate numbers.** "Certificates matched" was capped at the preview page size (100), identical rows were merged by `UNION`, and the count query errored to 0. List, count and statistics now share one recipient set (`cg_recipient_set_sql()` in `includes/Admin/filters-api.php`). The email-status ticks no longer overlap, unticking every recipient type now shows nothing instead of everyone, and filter values with apostrophes match again (`wp_unslash`).
- **Bulk Send redesign** (`includes/Admin/bulk-email.php`, `assets/js/admin-filters.js`, `assets/css/admin-filters.css`): queue and sending-rate cards that refresh in place (no 30-second page reload), a filter sidebar, summary tiles (certificates matched, emails to send, no email, already emailed), "Showing X of Y", a full CSV export, and a "Queue N emails" send bar. "Already sent" is now unticked by default to avoid duplicate emails.
- **Bulk Send emails only what matched.** Each queued email stores a `scope` (new `wp_cert_email_queue.scope` column, `cg_migrate_queue_scope_column()`) and attaches only those certificates. Before, it attached every certificate for the address. The 5,000-row cap per send is removed.
- **Correct email template for bulk sends.** The certificate-type template (Priority 0) and the `{certificate_title}`/`{serial_number}` placeholders now come from the certificate being sent, not the address's oldest record.
- **Download Certificates page:** new "Certificates For" (Students / Teachers / Schools) and "Event" filters.
- **PDFs for records with no WordPress post** are named `certificate_{type}_row_{id}.pdf` and no longer read or write postmeta on an unrelated post with the same ID.
- E2E: `11-bulk-send.spec.js` (stats accuracy) and `12-bulk-send-scope.spec.js` (scope and template priority, checked via Mailpit).

## [Unreleased] — folder version V7.5

- **v9 feature set added** (competitor-parity pass, no drag-and-drop template builder):
  - **Multi-LMS/ecommerce auto-issuance**: extracted a shared `AbstractLmsListener`/`AbstractLmsMapper` base from the existing Tutor LMS integration (`src/Integrations/`), then added **LearnDash**, **LifterLMS**, **Sensei LMS**, and **WooCommerce** (order-completion) integrations on the same pattern — each behind its own `CG_USE_*_INTEGRATION` flag and admin mapping page. Tutor's behavior is unchanged (refactored onto the shared base, not rewritten).
  - **Multi-course "track" certificates**: new `wp_cg_lms_tracks` / `wp_cg_lms_track_courses` tables let an admin group several courses under one certificate template, issued once every course in the track is complete for a student. Available on all four course-based integrations (not WooCommerce).
  - **E-signature / image fields on templates**: certificate template fields now support `type: image` (in addition to the existing text fields) for placing a static image — e.g. a signature — independent of student data. `src/Admin/Pages/TemplatesPage.php` field panel updated; both PDF render paths in `includes/Services/certificate-search.php` updated.
  - **Digital badge PNGs**: optional per-template `badge_template_url`; when set, a companion badge image is generated via GD (`src/Services/BadgeGenerator.php`) alongside every certificate, through a new `CG_USE_BADGES`-gated listener on the existing `cg_certificate_generated` action.
  - **LinkedIn "Add to Profile" button** on the public verification result, using the certificate's PDF URL/issuer/dates (verify endpoints enriched with `pdf_url`/`issuer_name`).
  - **Recipient-facing renewal reminders**: new `CG_Cron_Jobs::send_recipient_renewal_reminders()` (daily cron, `CG_USE_RENEWAL_REMINDERS`-gated, Pro/Business) emails certificate holders at configurable day-offsets before expiry — distinct from the existing admin-only expiring-certificates digest. New `wp_cg_renewal_reminders_sent` table prevents duplicate sends.
  - **Custom font upload (Business)**: bundled [tFPDF](https://github.com/Setasign/tFPDF) (`lib/tfpdf/`) alongside the existing classic FPDF engine; new **Certificate Generator → Custom Fonts** page (`src/Admin/Pages/FontsPage.php`) lets a Business-plan admin upload a `.ttf`, which then renders via tFPDF while every bundled font keeps using the unmodified classic FPDF path.
  - **Fixed the known duplicate `/verify/{serial}` REST route registration** (see [`ARCHITECTURE.md`](dev/ARCHITECTURE.md#known-cleanup-items)) — removed the legacy `includes/Services/serial-generator.php` registration call so `src/Services/SerialNumberService.php` is the single source of truth.
  - New migration `Migration005_AddBadgeColumns.php` (`badge_template_url` on templates, `badge_path` on certificates); `wp_cg_lms_tracks`, `wp_cg_lms_track_courses`, `wp_cg_renewal_reminders_sent`, `wp_cg_custom_fonts` added directly in `CustomTables.php` (same precedent as `wp_cg_lms_course_map`). `Config::DB_TARGET_VERSION` bumped to `005`.
  - Multisite: verified the existing Business-plan exemption in `cg_multisite_plan_notice()` already satisfies "allow network activation for Business" — no code change needed.
- **Known discrepancy:** the plugin folder is named `Certificate-Generator-V7.5`, but the `certificate-generator.php` header and `certificate_generator_version` constant still report **7.0.0**. Reconcile these before the next tagged release, or document the intended difference (e.g. V7.5 as a working/beta label ahead of a 7.5.0 version bump).
- Documentation added: root `README.md` and `/doc` folder (`ARCHITECTURE.md`, `DATABASE.md`, `REST-API.md`, `SHORTCODES.md`, `CONFIGURATION.md`, `ROADMAP-V8.md`, this file) — no prior documentation existed.
- **Tutor LMS auto-issuance (v8, Part A) added and verified locally.** New `src/Integrations/TutorLms/` module hooks `tutor_course_complete_after` / `tutor_quiz_finished`, a new **Certificate Generator → Tutor LMS** admin page maps courses to templates, and a new `wp_cg_lms_course_map` table stores the mapping. Gated behind the `CG_USE_TUTOR_LMS_INTEGRATION` flag (off by default; on in this local `wp-config.php`). See `ROADMAP-V8.md`.
- Added `import_source` column to `wp_cg_students`/`teachers`/`schools` (`Migration004_AddImportSourceColumn.php`) and wired Bulk Import to populate it with the uploaded CSV filename.
- **Bulk Send "Source" filter (v8, Part B) added and verified.** New "Filter by Source" multi-select on the Bulk Send screen (`includes/Admin/bulk-email.php`) lets admins narrow recipients to a specific CSV import batch or LMS-tagged origin (e.g. `tutor_lms`). Backed by `certificate_generator_get_unique_import_sources()` and a new `sources` fragment in `cg_build_recipient_filter_sql()` (`includes/Admin/filters-api.php`). Verified live in-browser: selecting a source correctly narrowed the recipient preview. See `ROADMAP-V8.md`.

## 7.0.0

- Baseline documented version per plugin header (`certificate-generator.php`). Introduced the custom `wp_cg_*` SQL schema (`src/Database/CustomTables.php`) alongside the legacy CPT-based storage, plus the in-progress `src/` OOP migration behind feature flags in `src/Core/Config.php`. See [`ARCHITECTURE.md`](dev/ARCHITECTURE.md) for details.

---

*This file was seeded retroactively — earlier version history was not tracked in a changelog before this point. Add new entries above `[Unreleased]` going forward, newest first.*
