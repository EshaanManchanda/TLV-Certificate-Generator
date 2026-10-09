# Certificate Generator — Full Documentation

[← Back to README](../../README.md) · [Free vs Pro](PLAN-COMPARISON.md)

This is the single consolidated reference for the plugin — it merges the in-admin
**Certificate Generator → Documentation** guide (Getting Started / User Guide / Email & Bulk
Send / Troubleshooting / FAQ) with the developer-facing `/doc` references, so everything is
findable from one file. The other `/doc/*.md` files remain the deep-dive for their topic; this
file is the overview + everything a day-to-day admin needs.

## Table of contents

1. [Overview](#overview)
2. [Requirements & soft dependencies](#requirements--soft-dependencies)
3. [Installation](#installation)
4. [Admin menu map](#admin-menu-map)
5. [Getting Started — 4-step quick start](#getting-started--4-step-quick-start)
6. [User Guide](#user-guide)
7. [Email & Bulk Send Guide](#email--bulk-send-guide)
8. [Shortcodes](#shortcodes)
9. [REST API](#rest-api)
10. [Configuration reference](#configuration-reference)
11. [Database schema](#database-schema)
12. [Licensing & plans](#licensing--plans)
13. [Architecture notes](#architecture-notes)
14. [Troubleshooting](#troubleshooting)
15. [FAQ](#faq)
16. [Further reading](#further-reading)

---

## Overview

**Certificate Generator** is a WordPress plugin for managing, generating, and bulk-sending
certificates for students, teachers, and schools — template-based PDF generation, QR-code
verification, serial numbering, bulk CSV import/export, email delivery with a background queue,
and usage analytics.

| | |
|---|---|
| **Version** | 7.6.0 (see [`CHANGELOG.md`](../CHANGELOG.md)) |
| **Requires** | WordPress 6.0+, PHP 8.2+ |
| **Tested up to** | WordPress 7.1 |
| **License** | GPL-2.0-or-later |
| **Author** | [Eshaan Manchanda](https://www.linkedin.com/in/eshaan-manchanda/) |
| **Text domain** | `certificate-generator` |

### Feature list

- **Certificate templates** — upload a background image, drag-position Name/Type/School/Date
  (and other) fields, set font/size/colour per field, per certificate type.
- **PDF generation** via a bundled [FPDF](../../lib/fpdf) engine — no TCPDF/mPDF dependency.
- **QR-code verification** — every certificate gets a QR code (`endroid/qr-code`, bundled in `vendor/`)
  linking to a public `/verify-certificate/` page.
- **Serial numbers** — auto-generated, configurable format (`PREFIX-{YEAR}-{SEQ}`), with a
  bulk-assignment tool for existing records that don't have one yet.
- **Students / Teachers / Schools management** — manual entry, or bulk CSV import/export.
- **Bulk email sending** — filter by school/certificate type/source, background queue
  processing, automatic ZIP bundling when a recipient has multiple certificates, configurable
  rate limits, retry with exponential backoff.
- **Email logs & analytics** — per-send delivery status, dashboard charts, breakdown by
  certificate type and school, expiration reports.
- **Public verification & search shortcodes** for front-end use (no login required), including a **LinkedIn "Add to Profile"** button on the verification result.
- **LMS / e-commerce auto-issuance** (Pro add-on) — automatically issues a certificate on course completion (Tutor LMS, LearnDash, LifterLMS, LearnPress, Sensei) or order completion (WooCommerce), no manual import needed. See [LMS auto-issuance](#lms--e-commerce-auto-issuance).
- **Digital badges** — an optional companion PNG badge alongside the PDF certificate.
- **E-signature / image fields** on templates — place a signature or seal image independent of student data.
- **Recipient renewal reminders** — emails certificate holders before expiry, distinct from the admin-only expiring-certificates digest.
- **Custom font upload** (Pro add-on) — upload your own `.ttf`, rendered via a bundled tFPDF engine.
- **REST API** (Pro add-on) for external integrations (issuing certificates, health checks, lookups).
- **Licensing tiers** (Free / Pro / Business) — see [Licensing & plans](#licensing--plans).

## Requirements & soft dependencies

Works standalone: students, teachers, schools, templates and certificates live in the plugin's
own `wp_cg_*` tables, managed from its own admin pages. Sites that still have the legacy
`students`/`teachers`/`schools`/`certificates` post types (registered elsewhere, optionally with
[ACF](https://www.advancedcustomfields.com/) field groups) keep working, and **Certificate
Generator → SQL Migration** moves that data into the tables.

For production email delivery, the **WP Mail SMTP** plugin is recommended (the plugin also ships
its own SMTP transport as a fallback — see `src/Email/Transport/SmtpTransport.php`).

## Installation

1. Copy the plugin folder to `wp-content/plugins/`.
2. Activate **Certificate Generator** from the WordPress Plugins screen — this creates the
   custom database tables (see [Database schema](#database-schema)).
3. Open **Certificate Generator → Dashboard** (or **Documentation → Getting Started**) and follow
   the [4-step checklist](#getting-started--4-step-quick-start).
4. Legacy sites only: if you still keep data in the old post types, run **Certificate Generator →
   SQL Migration** once (see [Database schema](#database-schema)).
5. Optional: install the **Certificate Generator Pro** add-on for LMS / WooCommerce auto-issue,
   font upload, the REST API and the school bulk ZIP shortcode ([Free vs Pro](PLAN-COMPARISON.md)).

## Admin menu map

Registered under the **Certificate Generator** top-level menu (`cg-dashboard`) in
`certificate-generator.php`, plus a few pages registered by their own modules:

| Menu item | Slug | What it does |
|---|---|---|
| Dashboard | `cg-dashboard` | Landing page |
| Students / Teachers / Schools | `cg-students` etc. | List + Add/Edit records (`src/Admin/Pages/*Page.php`) |
| Templates | `cg-templates` | Certificate template designer (`src/Admin/Pages/TemplatesPage.php`) |
| Bulk Import | `cg-bulk-import` | CSV import for students/teachers/schools/certificate templates |
| Bulk Export | `cg-bulk-export` | CSV export for the same four entities |
| Download Certs | `cg-cert-download` | Admin-side certificate download tool |
| Bulk Send | `certificate-bulk-send` | Filtered bulk email sending with background queue |
| Email Logs | `certificate-email-logs` | Per-send delivery history |
| Analytics | `cg-analytics` | Charts and breakdowns (`includes/Admin/analytics.php`) |
| Serial Settings | `cg-serial-settings` | Serial number format configuration |
| Bulk Serials | (via `includes/Admin/bulk-serial.php`) | Assign serials to existing records missing one |
| Migration | (via `src/Admin/Pages/MigrationPage.php`) | CPT → SQL data migration |
| Fonts | `cg-fonts` | Built-in font library + sample PDF; `.ttf` upload with the Pro add-on |
| Tutor LMS / LearnDash / LifterLMS / Sensei LMS / LearnPress | `cg-tutor-lms`, `cg-learndash`, `cg-lifterlms`, `cg-sensei`, `cg-learnpress` | **Pro add-on**: map courses → certificate templates (each shown only when its toggle is on) |
| WooCommerce | `cg-woocommerce` | **Pro add-on**: map products → certificate templates (issues on order completion) |
| 🧪 Test Center | (via `src/Admin/Pages/TestCenterPage.php`) | Dev-only test runner dashboard — hidden unless `CG_TESTING_UI` is set, see [`TESTING.md`](../dev/TESTING.md) |
| Documentation | `cg-documentation` | This guide's in-admin counterpart |
| Settings | `certificate_generator_settings` (Settings menu) | General / Templates / Email / Rate Limits tabs; the Pro add-on adds **API Settings** and **License** |

The LMS / e-commerce pages come from the Pro add-on — see [Licensing & plans](#licensing--plans)
and [`CONFIGURATION.md`](CONFIGURATION.md#lms--e-commerce-auto-issuance-v9).

## Getting Started — 4-step quick start

The same checklist appears on the Dashboard (`cg_setup_steps()`) and ticks itself off.

1. **Create a certificate template** — go to **Templates**, upload your background image, and
   position the text fields (name, certificate type, date, etc.).
2. **Email yourself a test certificate** — in the template editor, use *Email me a test
   certificate*. For reliable delivery, set up SMTP first (WP Mail SMTP, or **Settings → Email**).
3. **Add a record** — go to **Students / Teachers / Schools** and add one manually, or use
   **Bulk Import** (required columns: `student_name`, `email`, `school_name`,
   `certificate_type`, `issue_date`).
4. **Issue your first certificate** — go to **Bulk Send**, filter by school / certificate type,
   and click *Send Certificates*.

## User Guide

### Certificate templates

1. Go to **Certificate Generator → Templates → Add New**.
2. Upload your certificate background image (PNG or JPG, A4 landscape recommended).
3. Use the field position editor to drag Name, Certificate Type, School, and Date fields onto
   the canvas — up to 15 custom field slots per certificate type (`CG_Field_Schema`).
4. Set font, size, and colour for each field.
5. Click **Save Template**.

A name wider than its field is printed smaller to fit, down to 60% of the template's font size; past that it wraps onto a second line. Set each field's **W** (width, in mm) to the space the text may use.

Each student's certificate type is matched against the template name — if no match is found,
the default template is used. Editing a template only affects *future* PDF generations;
previously generated PDFs are not regenerated automatically.

### Managing Students, Teachers & Schools

- **Manually**: go to **Students / Teachers / Schools → Add New** and fill in Name, Email,
  School, Certificate Type, Issue Date.
- **Bulk import via CSV**: go to **Bulk Import**, download the sample CSV to see the required
  columns, fill in your data, upload, and review the import summary. See
  [Configuration reference](#required-csv-columns) for the exact column list, and
  [Licensing & plans](#licensing--plans).
- **Bulk export**: go to **Bulk Export** to download all records as CSV — useful for backups or
  migrating data.

### Serial numbers

Serial numbers are generated automatically when a certificate is created. Format is configured
under **Serial Settings**:

- Format: `PREFIX-{YEAR}-{SEQ}` — e.g. `CERT-2025-00142`.
- Prefix, year inclusion, sequence padding, and reset period are all configurable
  (`cg_serial_prefix`, `cg_serial_length`, `cg_serial_suffix`, `cg_serial_reset_period`,
  `cg_serial_include_date`).
- Use **Bulk Serials** to assign serial numbers to existing records that don't have one yet.

This feature is **not plan-gated** — it works identically on Free, Pro, and Business.

### QR codes

QR codes are embedded in every certificate automatically during PDF creation and link to the
public verification page (`/verify-certificate/`) — no manual setup required, the QR library
ships with the plugin.

### Analytics

**Certificate Generator → Analytics** shows:

- Total certificates issued
- Emails sent / failed / pending
- Send volume over time (chart)
- Breakdown by certificate type and school

Data is pulled from the email-logs table, so it's only as complete as your send history. This
feature is **not plan-gated** — it works identically on Free, Pro, and Business.

### Verification

Every certificate's QR code (and the `[cg_verify_certificate]` shortcode) resolves to a public
authenticity check. Visitors can also search by serial number or email on the results page,
which also shows a **"LinkedIn — Add to Profile"** button so recipients can add the certificate
to their LinkedIn profile directly. This feature is **not plan-gated**.

### LMS / e-commerce auto-issuance

*Needs the Certificate Generator Pro add-on with a Pro license.*

Instead of manually adding a student and generating their certificate, the plugin can issue one
automatically when a student finishes a course — no bulk import needed:

1. Go to the relevant integration's admin page: **Tutor LMS**, **LearnDash**, **LifterLMS**,
   **Sensei LMS**, **LearnPress**, or **WooCommerce**.
2. Map a course (or, for WooCommerce, a product) to a certificate template, and pick a trigger
   (course complete / quiz pass / both, where applicable).
3. Enable the mapping. Each integration has its own on/off flag, so you can turn on just the
   platform(s) you actually use.

**Issue to past completions**: each course mapping has an *Issue to past completions* button.
It checks every user in the background (25 per WP-Cron run), issues the certificate to those
who already finished the course, and emails it. Anyone who already has it is skipped, so it is
safe to run twice. It is not offered for quiz-only Tutor mappings or WooCommerce.

When a student completes the mapped course (or, for WooCommerce, completes an order), the plugin
looks up or auto-creates their student record (tagged with an `import_source` like `tutor_lms` so
it's filterable on Bulk Send), generates the certificate, and sends it — the same duplicate-prevention rules apply as any
other issuance path. Needs a Pro license; each integration is also off until you enable it under
**Settings → Features**.

**Track certificates**: to issue one certificate only after a student finishes *several* courses
(rather than one certificate per course), group those courses into a "track" on the same mapping
page. Available for the four course-based integrations (not WooCommerce, which is order-based).

### Digital badges & signature fields

- **Badges**: set a badge image on a certificate template (**Templates → Add/Edit → Badge
  Image**) and a companion PNG badge is generated alongside the PDF for every certificate issued
  from that template. Not plan-gated.
- **Signature / image fields**: certificate templates can include image-type fields (not just
  text) — useful for a signature or seal that doesn't change per recipient. Configure it the same
  way as a text field, in the template's field editor.

### Renewal reminders

Beyond the existing admin-only "expiring certificates" digest, the plugin can email
**recipients** directly before their certificate expires — configurable day-offsets (default 30,
7, and 1 days before expiry). Each recipient is only reminded once per stage. Certificates issued
through some LMS auto-issuance paths may not have a recipient email on file yet, in which case no
reminder is sent for that certificate.

### Custom fonts (Business)

**Certificate Generator → Custom Fonts** lists the built-in fonts on every plan, with how many templates use each. Click **Download sample PDF** to see every font exactly as it prints on a certificate.

Business-plan sites can upload their own `.ttf` font file under **Certificate Generator → Custom
Fonts**, which then becomes selectable on any certificate template alongside the plugin's
built-in fonts. Uploaded fonts render through a separate PDF engine (tFPDF) from the built-in
fonts, but the certificate output is otherwise identical.

## Email & Bulk Send Guide

### Setting up SMTP (required for production)

The plugin sends via WordPress's `wp_mail()`. For reliable delivery you must configure a real
SMTP mailer:

1. Install the free **WP Mail SMTP** plugin.
2. Go to **WP Mail SMTP → Settings**.
3. Set *From Email* to your authenticated sender address — it **must exactly match** your SMTP
   account login, or you'll get "Sender address rejected" bounces.
4. Choose your mailer (Other SMTP / Gmail / SendGrid / Mailgun).
5. Enter host, port, username, and password from your provider.
6. Send a test email from WP Mail SMTP to verify.

**Hostinger / cPanel example**: host `smtp.hostinger.com`, port `587` (TLS) or `465` (SSL),
username = full email address.

### Email templates & placeholders

Configure per-entity templates under **Settings → Email**. WordPress shortcodes (e.g.
`[site_name]`) are processed *after* placeholder substitution, so both can be mixed.

| Placeholder | Replaced with |
|---|---|
| `{name}` | Recipient's full name |
| `{certificate_title}` | Certificate type / title |
| `{email}` | Recipient's email address |
| `{serial_number}` | Certificate serial number |
| `{expires_at}` | Expiry date (or "Never") |
| `{certificate_count}` | Number of certificates in this send |
| `{result_link}` | Link to online results page |
| `{verify_link}` | Link to certificate verification page |
| `{zip_link}` | Download link (used when the ZIP attachment would exceed 25 MB) |

### Sending bulk emails

1. Go to **Certificate Generator → Bulk Send**.
2. Filter by *School*, *Certificate Type*, *Source* (which CSV batch / LMS origin a record came
   from), or *Post Type*.
3. Preview the recipient list.
4. Leave *Skip already sent* checked (default) to avoid duplicate sends.
5. Click **Send Certificates** — processing happens via a background queue, so the page doesn't
   need to stay open.

If a recipient has more than one certificate, all are bundled into a single ZIP attached to one
email (or, if the ZIP would exceed 25 MB, a download link is sent instead).

Rate limits (defaults: 60 emails/hour, 10 emails/minute) are configured under
**Settings → Rate Limits** and enforced automatically by the queue processor.

### Email Logs

**Certificate Generator → Email Logs** shows every send attempt: recipient name & email,
certificate type, status (`sent` / `failed` / `pending`), timestamp, and error message on
failure. Filter by status, date range, or email address.

**Resend** on a failed row sends that email again right away. **Resend all failed** (top of the page) queues every failed email that hasn't been delivered since, and the queue sends them in the background. Logs are retained 90 days, then
auto-cleaned by a weekly cron job.

## Shortcodes

Registered in `includes/Services/certificate-search.php`, `includes/Services/bulk-download.php`,
and `includes/Public/verification.php`.

| Shortcode | Purpose |
|---|---|
| `[student_search]` | Front-end search form for a student to look up their own certificate(s) by email. |
| `[teacher_search]` | Same lookup flow for teachers. |
| `[school_search]` | Same lookup flow for schools (by school name and place). |
| `[school_bulk_certificate_download]` | (Pro add-on) Lets a school download all of its certificates in bulk (ZIP) from the front end. |
| `[cg_verify_certificate]` | Public certificate authenticity verification form — reachable via a certificate's QR code (`/verify-certificate/`); visitors can also search by serial number or email on the results page (`/result/`). |

### Customizing search-form text

The three search shortcodes accept optional attributes to override title, subtitle, button
text, and help text on a specific page:

```
[student_search title="Custom Title" subtitle="Custom subtitle" button_text="Find Mine" help_text="Custom help text"]
```

To set a sitewide default instead of editing every page, use
**Settings → Shortcode Text**. Resolution order: shortcode attribute → sitewide setting →
built-in default.

Drop any shortcode into a page/post via the block or classic editor, or via `do_shortcode()` in
a template.

## REST API

**Pro add-on.** Namespace `certificate-generator/v1`, defined in
`certificate-generator-pro/includes/rest-api.php`. Requires a bearer token
(`certificate_generator_api_key` option) unless noted.

| Method | Route | Auth | Notes |
|---|---|---|---|
| `POST` | `/issue-certificate` | Bearer token | Gated to **Pro / Business**. Generates and emails/zips certificates by student email. |
| `GET` | `/health` | None | Liveness check. |
| `POST` | `/validate-key` | Bearer token | Validates an API key. |
| `GET` | `/certificates-by-email` | Bearer token | Read-only lookup — does not regenerate certificates. |

**`/verify/{serial}`** — public serial-number verification, **free**. Registered in
`src/Services/SerialNumberService.php` (the legacy duplicate in
`includes/Services/serial-generator.php` was removed — see [`CHANGELOG.md`](../CHANGELOG.md)).

**`cg/v1/verify-license`** — Pro add-on, admin-only license check
(`certificate-generator-pro/includes/payment-endpoints.php`).

Full detail: [`REST-API.md`](../dev/REST-API.md).

## Configuration reference

Canonical settings are owned by `src/Services/SettingsService.php` (option prefix `cg_`), with
migration from the legacy serialized option `certificate_generator_settings_email`. Main UI:
**Certificate Generator → Settings** (General / Templates / Email / License / Rate Limits tabs).

### Email / SMTP

| Option | Purpose |
|---|---|
| `cg_email_transport` | `wp_mail` or `smtp` |
| `cg_email_from_name` / `cg_email_from_email` | Sender identity |
| `cg_email_subject` / `cg_email_body` | HTML template with placeholders |
| `cg_smtp_host` / `cg_smtp_port` / `cg_smtp_username` / `cg_smtp_password` (encrypted) / `cg_smtp_encryption` | SMTP connection details |

### Serial numbers

| Option | Purpose |
|---|---|
| `cg_serial_prefix` | Prefix string, e.g. `CERT` |
| `cg_serial_length` | Sequence digit padding |
| `cg_serial_suffix` | Optional suffix |
| `cg_serial_reset_period` | When the sequence counter resets |
| `cg_serial_include_date` | Whether to embed the year, e.g. `CERT-2025-00142` |

### Licensing (Pro add-on only)

The free plugin never reads these; the Pro add-on stores them.

| Option | Purpose |
|---|---|
| `cg_plan` | `free` \| `pro` \| `business` |
| `cg_license_key` / `cg_license_expiry` | License credentials |
| `cg_license_server_url` | External license validation endpoint |

See [Licensing & plans](#licensing--plans) below for the full plan matrix.

### Misc

| Option | Purpose |
|---|---|
| `certificate_generator_version` | Installed version tracking |
| `certificate_generator_settings` | Installation mode, max memory usage, etc. |
| `certificate_generator_api_key` / `certificate_generator_api_key_enabled` | REST API key + enable flag |
| `cg_keep_data_on_uninstall` | If false, uninstall drops all custom tables and deletes plugin options |
| `cg_extra_fields_{cert_type_key}` | Per-certificate-type custom field registry (max 15 extra slots) |

### Required CSV columns

**Bulk Import** requires: `student_name`, `email`, `school_name`, `certificate_type`,
`issue_date` (teachers/schools use the equivalent name field). Sample files live in
`doc/samples/` (`students.csv`, `teacher.csv`, `school.csv`, `certificates.csv`).

There is no row limit on import or export, on any plan.

**How rows are matched.** A CSV row updates an existing record instead of adding a new one
when it is the same record:

| Import | Same record when these all match |
|---|---|
| Students | `email` + `student_name` + `school_name` + `certificate_type` + `issue_date` |
| Teachers | `email` + `teacher_name` + `school_name` + `certificate_type` + `issue_date` |
| Schools | `school_name` + `certificate_type` + `issue_date` (also claims a school row the student or teacher import created without a type) |

Matching ignores letter case and trailing spaces. Rows with no email match on the name and school (plus type and date) against existing rows that also have no email.
Two rows in the same file that match each other are kept as one record; the later row's
values win. So re-importing the same file updates records instead of duplicating them.
(Up to 7.5.1, students and teachers were matched without the school. A file where many
students share a parent email kept only one row per name, while still reporting every row
as imported.)

**After every import** the page shows what happened to each row: rows read → records saved
(new / updated), merged, skipped and failed. Each merged, skipped or failed row is listed
with its CSV row number, and the full list can be downloaded as a CSV. Rows are skipped when:
- the name or `certificate_type` is empty;
- the row has more values than the header (usually an unquoted comma);
- the plan's row limit is reached. At the limit, re-imports still update existing rows.

A file that's too large for the server's time limit stops at a row boundary and says which
row. Upload the same, unchanged file again and it continues from that row (for up to a
day). An edited file is imported from the top; rows already imported are updated, not
duplicated. Rows are written 500 at a time in one transaction each. 10,000 students take
about 2–3 s on a local site.

### Uninstall

Gated by `cg_keep_data_on_uninstall`. If disabled: drops both legacy and `wp_cg_*` tables,
deletes ~25 named options, and clears all scheduled cron hooks.

## Database schema

Schema owner: `src/Database/CustomTables.php` (tables created via `dbDelta`). Legacy tables are
created directly in the activation hook in `certificate-generator.php`.

### Modern schema (v7+), prefix `wp_cg_`

| Table | Purpose |
|---|---|
| `wp_cg_students` | Student records — linked to `wp_posts` via `wp_post_id`, `extra_fields` JSON column |
| `wp_cg_teachers` | Teacher records — same shape as students |
| `wp_cg_schools` | School records — same shape as students |
| `wp_cg_certificate_templates` | Template config: orientation, font, QR position/size, serial display, expiration rules, `field_config` JSON, status |
| `wp_cg_certificates` | Issued certificates: recipient info, serial number, `pdf_path`/`pdf_url`, `certificate_data` JSON, status |
| `wp_cg_email_logs` | Per-send delivery records — feeds Email Logs and Analytics |
| `wp_cg_email_queue` | Background email queue — feeds the bulk-send processor |
| `wp_cg_student_certificates` / `wp_cg_teacher_certificates` | Join tables: students/teachers ↔ certificates |
| `wp_cg_settings` | Structured settings storage |
| `wp_cg_migrations` | Tracks which versioned migrations have run |
| `wp_cg_lms_course_map` | Course → certificate-template mapping for LMS integrations (Tutor LMS) |

`wp_cg_students`/`teachers`/`schools` also carry an `import_source` column — the uploaded CSV
filename from Bulk Import, or a fixed tag like `tutor_lms` for LMS-auto-created records. Powers
the Bulk Send "Source" filter.

### Legacy tables (kept for back-compat)

| Table | Purpose |
|---|---|
| `wp_certificate_generator` | Original certificate records table |
| `wp_cert_email_logs` | Original email log table — what the in-admin Analytics reads |
| `wp_cert_email_queue` | Original email queue table |

### Migration path (CPT → custom SQL tables)

The v7 rewrite moved primary certificate storage from `wp_posts`/`wp_postmeta` (on the
`students`/`teachers`/`schools`/`certificates` CPTs) into the custom `wp_cg_*` tables. CPTs are
kept only for admin-UI editing (meta boxes/ACF) and back-compat linkage via `wp_post_id`.

Run the migration from **Certificate Generator → Migration**. If Bulk Send reports "Successfully
queued 0 certificates" or "No certificate records found", the migration usually hasn't been run
yet for existing CPT data.

Full detail: [`DATABASE.md`](../dev/DATABASE.md).

## Licensing & plans

Full breakdown (pricing, features, license-key format): [`PLAN-COMPARISON.md`](PLAN-COMPARISON.md).

The plugin itself has no limits and no license code. The paid **Certificate Generator Pro**
add-on is a separate plugin:

| Plan | Price | Adds |
|---|---|---|
| **Free** | $0 | — (unlimited certificates, emails and imports; every feature in this guide) |
| **Pro** | $3.5/mo ($35/yr) | LMS / WooCommerce auto-issue, custom `.ttf` font upload, `[school_bulk_certificate_download]`, REST API, priority support |
| **Business** | [Contact us](mailto:techlovev@gmail.com) | Everything in Pro, on custom terms |

The add-on holds `CG_License_Manager` and the **Settings → License** and **API Settings** tabs.
Pro source: `certificate-generator-pro/` (private repo, see its `README.md`).

## Architecture notes

Full detail: [`ARCHITECTURE.md`](../dev/ARCHITECTURE.md).

- **Dual codebase**: the plugin is mid-migration from a procedural `includes/` codebase to a
  namespaced `CertificateGenerator\` OOP architecture in `src/`. Which implementation actually
  runs for PDF/ZIP/data-access is controlled by feature flags in `src/Core/Config.php`
  (`CG_USE_NEW_PDF`, `CG_USE_NEW_ZIP`, `CG_USE_REPOSITORIES`, `CG_USE_DTO`, `CG_USE_EVENTS`).
- **Autoloading** — Composer's `vendor/autoload.php` (shipped in the release ZIP), with a
  hand-rolled `spl_autoload_register` fallback for `CertificateGenerator\` → `src/`.
- **Bundled libraries**: `lib/fpdf/` (PDF rendering, all built-in fonts), `lib/tfpdf/` (renders
  uploaded `.ttf` fonts), and `endroid/qr-code` in `vendor/` (QR images).
- **Free / Pro split**: the free plugin exposes hooks (`cg_settings_tabs`, `cg_fonts_page_*`,
  `cg_feature_flags`, `cg_admin_menu_integrations`) and the Pro add-on plugs in through them.
  The LMS / WooCommerce integrations (`AbstractLmsListener` / `AbstractLmsMapper` plus one
  Listener+Mapper pair per platform and their mapping pages) live in
  `certificate-generator-pro/src/`. See [`ARCHITECTURE.md`](../dev/ARCHITECTURE.md#free-plugin-and-pro-add-on).
- Key files for new contributors: `certificate-generator.php` (bootstrap), `src/Core/Plugin.php`
  (new-arch entry point), `src/Core/Config.php` (feature flags),
  `includes/Services/certificate-search.php` (~3,500 lines — PDF rendering pipeline + 3 search
  shortcodes), `src/Database/CustomTables.php` (schema), `includes/Core/post-types.php`
  (~2,500 lines — meta boxes/ACF/save handlers), `includes/Admin/settings.php` (~2,800 lines — main settings UI).

## Troubleshooting

### Emails not being received

1. Check **Email Logs** for `failed` entries — the error column shows the exact reason.
2. Verify SMTP config via WP Mail SMTP → Tools → Email Test.
3. **From Email mismatch** is the most common cause — it must exactly match your SMTP login.
4. Check spam folder — usually means missing SPF/DKIM records.
5. If sending in bulk, check whether the queue is paused on a rate limit (Email Logs error
   containing "rate limit").

### "Successfully queued 0 certificates"

The selected filter matched recipients but no rows exist yet in the certificate table.

1. Go to **Certificate Generator → Migration** and run the data migration.
2. Retry the bulk send.

Check record count directly: `SELECT COUNT(*), SUM(email='') FROM wp_certificate_generator;` —
if the email count is high, use the email backfill AJAX action.

### Certificate PDF not generating

1. Make sure the template has **all field positions set** (X/Y for every visible field) — a
   missing position aborts generation.
2. Confirm the WordPress uploads directory is writable.
3. Confirm `lib/fpdf/fpdf.php` is present (bundled with the plugin).
4. Enable `WP_DEBUG` / `WP_DEBUG_LOG` in `wp-config.php` and check `wp-content/debug.log` for
   FPDF errors.

### ZIP file not attaching / download link missing

ZIPs are created when a recipient has more than one certificate. Requires PHP's `ZipArchive`
extension and a writable uploads directory. If the ZIP exceeds 25 MB, a download link is sent
in the email body instead of an attachment.

### Large downloads (1,000+ certificates)

- **Download Certificates** runs as a job in the browser. Each request renders certificates
  for up to `CG_QUEUE_RUNTIME_BUDGET` seconds (default 20), with a progress bar. When it
  finishes it builds one ZIP per `CG_ADMIN_EXPORT_ZIP_PART_SIZE` certificates (default 200),
  each with a `manifest.csv`. A dropped connection is retried, and restarting a job doesn't
  re-render anything that's already done. Without JavaScript the old one-ZIP-per-part form
  still works.
- **PDF cache.** Each certificate PDF records a fingerprint of everything that shapes it:
  the row's data, the template settings, the template and image files, and the serial. If
  nothing changed, the existing file is served instead of being rendered again, under the
  same URL. Editing the row or template, or replacing the template image, re-renders it.
  If a code change alters PDF output, bump `CG_PDF_RENDER_REV` so cached PDFs re-render. To
  turn the cache off: `define( 'CG_DISABLE_PDF_CACHE', true );` in `wp-config.php`.
- **Admin ZIPs are private.** They are written to `uploads/cg_certificates/private/`
  (deny-all rule, random file names), are only downloadable by the admin who built them, and
  are deleted after a day by the daily `cg_cleanup_old_zips` cron. Public ZIPs (student
  search, emails) are kept 7 days.
- **`[student_search]`** shows "Download All (ZIP)" for more than 3 certificates. The ZIP is
  built when the link is clicked (`admin-ajax.php?action=cg_student_zip`, which hide-login
  plugins leave reachable; limited to 5 per minute per visitor), not on every page view.
- Benchmark: `wp eval-file bin/bench-bulk-download.php 1000` (dev sites only; it seeds and
  removes its own data).

### Bulk send shows "No certificate records found"

The custom table has no rows for the filtered recipients — run the Migration page, or import via
Bulk Import (which populates the table directly).

### Debug mode

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Check `wp-content/debug.log` after triggering a send — lines prefixed `[CG Email]` (and similar
`Certificate Generator:` prefixes throughout `includes/`) are from this plugin.

## FAQ

**Can I send certificates to teachers and schools too, not just students?**
Yes — the bulk send page lets you choose the entity type before filtering. Each entity type has
its own email template under Settings → Email.

**Will re-sending skip students who already received their certificate?**
Yes — "Skip already sent" (checked by default) checks the email log and skips recipients with a
`sent` entry for their certificate ID.

**Can I use a Gmail account to send emails?**
Yes, but Gmail requires an App Password. In WP Mail SMTP: choose Gmail as mailer, use
`smtp.gmail.com`, port 587, and your App Password.

**How do I change the certificate PDF design?**
Edit the template in Templates — upload a new background and reposition fields. Future PDFs use
the new design; already-generated PDFs are unchanged.

**What happens if an email fails to send?**
The queue retries up to 3 times with exponential backoff, then marks the item `failed` and logs
the error in Email Logs. Failed items can be reset from the queue management section.

**How long are email logs kept?**
90 days, auto-cleaned weekly (`certificate_generator_cleanup_email_logs()` in
`includes/Email/log.php`).

**Can students verify their certificate online?**
Yes — every certificate's QR code links to `/verify-certificate/`; visitors can also search by
serial number or email on `/result/`.

**Is the plugin multisite compatible?**
Partially — designed for single-site use. Network activation works, but each site keeps its own
data and settings, and it hasn't been tested at scale.

**How do I back up all certificate data?**
Use Bulk Export (CSV), or include the custom tables in a full database backup.

**How do I update the plugin without losing data?**
Plugin updates only replace PHP/JS/CSS files — they never drop or truncate database tables.
Always take a backup before major updates regardless.

## Further reading

| Doc | Covers |
|---|---|
| [`PLAN-COMPARISON.md`](PLAN-COMPARISON.md) | Free vs Pro vs Business, pricing, add-on hooks |
| [`ARCHITECTURE.md`](../dev/ARCHITECTURE.md) | Free/Pro split, dual codebase, directory structure, feature flags, key files |
| [`DATABASE.md`](../dev/DATABASE.md) | Full schema, legacy tables, migration path |
| [`REST-API.md`](../dev/REST-API.md) | REST endpoints, auth, plan gating |
| [`SHORTCODES.md`](SHORTCODES.md) | Front-end shortcodes and usage |
| [`CONFIGURATION.md`](CONFIGURATION.md) | Settings/options reference, uninstall behavior |
| [`FAQ.md`](FAQ.md) | Standalone FAQ (sending, templates, backups, licensing, debugging) |
| [`TESTING.md`](../dev/TESTING.md) | How to run PHPUnit, static analysis, Test Center, Playwright E2E |
| [`MANUAL-TEST-CHECKLIST.md`](../dev/MANUAL-TEST-CHECKLIST.md) | Click-through release checklist |
| [`LIMITS.md`](LIMITS.md) / [`PERFORMANCE.md`](../dev/PERFORMANCE.md) | Server limits, batch sizes, measured performance |
| [`../SECURITY.md`](../../SECURITY.md) | Reporting a vulnerability, verifying a release ZIP |
| [`CHANGELOG.md`](../CHANGELOG.md) | Version history |
