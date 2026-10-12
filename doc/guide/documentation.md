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
| **Version** | 7.8.0 (see [`CHANGELOG.md`](../CHANGELOG.md)) |
| **Requires** | WordPress 6.0+, PHP 8.2+ |
| **Tested up to** | WordPress 7.1 |
| **License** | GPL-2.0-or-later |
| **Author** | [Eshaan Manchanda](https://www.linkedin.com/in/eshaan-manchanda/) |
| **Text domain** | `certificate-generator` |

### Feature list

- **Certificate templates** — upload a background image, drag-position Name/Type/School/Date
  (and other) fields, give each field its own font, size, colour and UPPERCASE setting, and add
  custom-text fields that mix your own words with `{field}` tags.
- **PDF generation** via a bundled [FPDF](../../lib/fpdf) engine. Certificates whose text is
  outside Western European (Indian scripts, Urdu/Arabic, Ł, Cyrillic, …) render through
  [mPDF](https://github.com/mpdf/mpdf) with bundled fonts, so letters join correctly — see
  [Languages](#languages-and-non-latin-text).
- **QR-code verification** — every certificate gets an unguessable verification code; its QR code
  (`endroid/qr-code`, bundled in `vendor/`) opens the public verification page. A serial number
  typed alone shows a masked name.
- **Certificate history, revoke, reinstate and reissue** — a per-certificate history, and issued
  certificates keep the layout they were issued with (see [Certificate history](#certificate-history-revoke-and-reissue)).
- **Serial numbers** — auto-generated, configurable format (`PREFIX-{YEAR}-{SEQ}`), with a
  bulk-assignment tool for existing records that don't have one yet.
- **Students / Teachers / Schools management** — manual entry, or bulk CSV import/export with a
  check step before anything is saved (column mapping, counts, date order, encoding).
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
| Register | `cg-register` | Every issued certificate (serial, code, school, status), filtered and downloaded as CSV or PDF. School Coordinators see their own school only |
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

## Getting Started — setup checklist

The same checklist appears on the Dashboard (`certificate_generator_setup_steps()`) and ticks itself off.
Hide it with **Hide checklist**; **Documentation → Getting Started** shows it again.

1. **Publish the certificate verification page** — created automatically on activation (or adopted,
   if a page already has `[certificate_generator_verify_certificate]`), and re-created daily if it's
   deleted. QR codes and the `{verify_link}` email placeholder point to it.
2. **Create a certificate template** — go to **Templates**, upload your background image, and
   position the text fields (name, certificate type, date, etc.).
3. **Email yourself a test certificate** — in the template editor, use *Email me a test
   certificate*. For reliable delivery, set up SMTP first (WP Mail SMTP, or **Settings → Email**).
4. **Add records** — go to **Students / Teachers / Schools** and add one manually, or use
   **Bulk Import** with one of the sample CSVs (required columns: `student_name`, `email`,
   `school_name`, `certificate_type`, `issue_date`).
5. **Issue your first certificate** — go to **Bulk Send**, filter by school / certificate type,
   and click *Send Certificates*.
6. **Verify a certificate** — scan its QR code or enter its code on the verification page.

## User Guide

### Certificate templates

1. Go to **Certificate Generator → Templates → Add New**.
2. Upload your certificate background image (PNG or JPG, A4 landscape recommended).
3. Use the field position editor to drag Name, Certificate Type, School, and Date fields onto
   the canvas — up to 15 custom field slots per certificate type (`CertificateGenerator_Field_Schema`).
4. Set the template's default font, size and colour. Each field can override them: pick a
   **Font** and **Size**, tick **Colour** to give it its own colour, and **UPPERCASE** to print it
   in capitals. Fields without overrides use the template defaults.
5. Optional: set a field's type to **Custom text with {fields}…** and type a sentence such as
   `Awarded to {student_name} for {certificate_type}`. Tags take any column of the record
   (including extra CSV fields); dates print as dd-mm-yyyy; an empty tag leaves no double space.
6. Click **Save Template**. **Preview Certificate** renders it with sample data, or pick a real
   record of that certificate type in **Preview with** to see its own values and a QR code that
   verifies. A preview never issues a serial.

A name wider than its field is printed smaller to fit, down to 60% of the template's font size; past that it wraps onto a second line. Set each field's **W** (width, in mm) to the space the text may use.

Each student's certificate type is matched against the template name — if no match is found,
the default template is used. Editing a template only affects certificates issued afterwards:
each issued certificate stores a snapshot of its layout and values, so re-downloads stay the
same. To apply a correction to an issued certificate, reissue it (see
[Certificate history](#certificate-history-revoke-and-reissue)).

### Managing Students, Teachers & Schools

- **Manually**: go to **Students / Teachers / Schools → Add New** and fill in Name, Email,
  School, Certificate Type, Issue Date.
- **Bulk import via CSV**: go to **Bulk Import**, download the sample CSV from the tab, fill in
  your data, then **Upload and check**. Review the mapping and counts, then **Import**. See
  [Required CSV columns](#required-csv-columns) for the columns and how the check works.
- **Bulk export**: go to **Bulk Export** to download all records as CSV — useful for backups or
  migrating data.

### Serial numbers

Serial numbers are generated automatically when a certificate is created. Format is configured
under **Serial Settings**:

- Format: `PREFIX-{YEAR}-{SEQ}` — e.g. `CERT-2025-00142`.
- Prefix, year inclusion, sequence padding, and reset period are all configurable
  (`certificate_generator_serial_prefix`, `certificate_generator_serial_length`, `certificate_generator_serial_suffix`, `certificate_generator_serial_reset_period`,
  `certificate_generator_serial_include_date`).
- Use **Bulk Serials** to assign serial numbers to existing records that don't have one yet.

This feature is **not plan-gated** — it works identically on Free, Pro, and Business.

### QR codes

QR codes are embedded in every certificate automatically during PDF creation, once enabled on
the template. Each QR carries the certificate's verification code (`?code=XXXXXXXXXXXX`) and opens
the public verification page — no manual setup required, the QR library ships with the plugin.
QR codes printed before 7.7.0 carry the serial number and still verify, with a masked name.

### Analytics

**Certificate Generator → Analytics** shows:

- Total certificates issued
- Emails sent / failed / pending
- Send volume over time (chart)
- Breakdown by certificate type and school

Data is pulled from the email-logs table, so it's only as complete as your send history. This
feature is **not plan-gated** — it works identically on Free, Pro, and Business.

### Verification

Every certificate's QR code (and the `[certificate_generator_verify_certificate]` shortcode) resolves to a public
authenticity check:

- **With the verification code** (from the QR code, or typed as `XXXX-XXXX-XXXX`): the full result
  — name, certificate type, dates, "Corrected on …" if it was reissued, and a **"LinkedIn — Add to
  Profile"** button.
- **With a serial number alone**: valid / revoked / expired and the dates, but the name masked
  (`R**** S****`). Serials run in sequence, so a full answer by serial would let anyone step
  through them and collect names. To show full details for serials anyway, set the option
  `certificate_generator_verify_serial_full_details` to `1`.
- Revoked certificates show **Revoked** (with the reason on a full answer); expired ones show
  **Expired**.

Lookups are rate-limited per visitor, and recorded as `verified` in the certificate's history
(no IP addresses). This feature is **not plan-gated**.

### Certificate register

**Certificate Generator → Register** lists every issued certificate: serial number, verification
code, recipient, email, school, certificate type, event, issue and expiry dates, status (Valid,
Expired or Revoked) and the revoke reason. Filter by certificate type, school, event, issue dates
and status, then **Download CSV** (opens in Excel with non-English names intact) or **Download PDF**
(a printable A4 table; Hindi, Arabic and other scripts print correctly). The page shows the 100
newest matches; the downloads include all of them.

School Coordinators can open it too, and only ever see their own school. The register is a record
of what was issued, so it has no delete button: to withdraw a certificate, revoke it. It then
shows as Revoked here and on the verify page.

### Certificate history, revoke and reissue

**Certificate Generator → Revoke Certificate** revokes a certificate with a reason, or reinstates
it; both certificate tables are updated and the hooks
`certificate_generator_certificate_revoked` (`$serial, $reason`) and
`certificate_generator_certificate_reinstated` (`$serial`) fire.

Open a certificate's **History** (from the Revoke page, or by clicking a serial number on the
Students or Teachers list) to see every event: issued, name corrected, revoked, reinstated,
reissued and verified, with who did it and when. Below it, **Reissue** applies the template as it
is now to that certificate: it keeps the serial number and verification code, bumps its
revision, records the reason, and the verify page shows "Corrected on …". Use it after fixing a
spelling in the template or the record.

### My Certificates (recipients)

Put `[certificate_generator_my_certificates]` on a page. A recipient types their email and gets a
private link (valid 30 minutes) to their own certificates: download each PDF or all as a ZIP,
open the verify page to share, and choose whether their name shows when someone verifies. No
WordPress account is needed; a logged-in user whose account email has records sees their list
straight away.

- The form gives the same answer for every address ("If we have certificates for that address,
  we've emailed you a link"), so it can't be used to find out who has a certificate. Link
  requests are limited to 5 per visitor per 10 minutes and one per address every 2 minutes.
- The link is signed with your site's auth salt; nothing is stored. Opening it moves it into an
  HttpOnly cookie and removes it from the address bar. **Sign out** clears it.
- The email goes through your configured transport (WordPress mail, SMTP, Sender.net or
  Mandrill). Filters: `certificate_generator_wallet_email_subject` (`$subject, $email`) and
  `certificate_generator_wallet_email_body` (`$html, $email, $link`).
- **Show my name when someone verifies this** (one switch per certificate): when off, the verify
  page still answers Valid / Revoked / Expired with the dates but shows "Hidden by the recipient".
  The change is recorded in the certificate's history (`name_visibility`).

`[certificate_generator_student_search]` shows certificates to anyone who types an email. To
require the emailed link there too, tick **Settings → General → Recipients & sharing → Require an
email link to view certificates** (off by default, so existing pages keep working). The student
search page then shows the My Certificates form, and its ZIP download only works for the
signed-in address.

### Sharing

A full verify result (by verification code) shows **Add to LinkedIn Profile**, **Share on X**,
**WhatsApp** and **Copy link**. Every button shares the `?code=` link, so whoever opens it sees the
full, correct result; serial-only answers show no share buttons. Set **LinkedIn organization ID**
(Settings → General → Recipients & sharing, the number in your LinkedIn company page's admin URL)
to link "Add to Profile" to your organization's page.

A shared verify link gets a link preview on WhatsApp, LinkedIn and X: "Verified certificate:
Participation — Issued to R**** S**** by Your School" (no name when the recipient hid it). The
tags are printed only for a well-formed code, within the verify rate limit, and are left to your
SEO plugin when Yoast, Rank Math or All in One SEO is active.

### Personal data export and erasure

The plugin registers with **Tools → Export Personal Data** and **Tools → Erase Personal Data**.

- **Export** includes the person's student and teacher records, their certificates and the
  emails sent or queued to them.
- **Erase** deletes their student and teacher records, email logs, queued emails and cached PDFs.
  Their certificates are **anonymised, not deleted**: name `[erased]`, no email or stored values,
  history details removed, while the serial number, verification code, dates and status stay so a
  printed certificate still verifies. WordPress shows a message saying so.

### Languages and non-Latin text

Certificates print any language. When a certificate's text has any character outside
Western European (ISO-8859-1), it is drawn with mPDF instead of FPDF:

| Script | Font (in `assets/fonts/unicode`) |
|---|---|
| Devanagari (Hindi, Marathi, Sanskrit, Nepali), Bengali, Gurmukhi (Punjabi), Gujarati, Tamil, Malayalam, Arabic / Urdu, and other non-Latin letters (Ł, Greek, Cyrillic, …) | FreeSerif (GNU FreeFont, GPL-2.0-or-later with font exception) |
| Telugu | Lohit Telugu (OFL) |
| Kannada | Lohit Kannada (OFL) |
| Odia | Noto Sans Oriya (OFL) |

Each line is split into runs by script: Latin parts keep the field's own font, so "Ravi रवि Kumar"
prints "Ravi" and "Kumar" in the template font and रवि in FreeSerif. Conjuncts, vowel signs and
Arabic joining are shaped by mPDF; field positions, fit-to-width and wrapping work as for Latin
text. Certificates with only Western European text keep using FPDF and come out exactly as
before. A non-Latin certificate takes about 115 ms to render, against about 45 ms for Latin.

PNG downloads use GD, which can't shape these scripts, so a PNG request for a certificate with
Indian-script or Arabic text returns the PDF instead (other Unicode text, such as Cyrillic, still
gets a PNG).

Limits: Indian-script runs always use the regular weight; a mostly-Urdu line with Latin words
inside may put those words in the wrong order (whole Urdu names are fine); the import check's
"too long" warning skips non-Latin names.

### Roles and School Coordinators

Admins (anyone who can `manage_options`) can do everything. To let a school's staff issue their
own certificates without seeing other schools:

1. **Users → Add New**: create the user with the role **School Coordinator**.
2. On that user's profile, under **Certificate Generator → School**, pick their school (the list
   holds every school name on your students, teachers and schools).

A School Coordinator sees **Students**, **Teachers**, **Bulk Import** (students and teachers),
**Download Certs**, **Bulk Send** and **Documentation**, all limited to their school: lists, filters,
counts, bulk actions, emails, downloads and imports. Every record they add or import is saved to
their school, and they can't move a record to another school. Templates, schools, events, revoke,
analytics, email logs, export and settings stay with admins. A coordinator with no school assigned
sees nothing.

Capabilities, for role-editor plugins: `certificate_generator_view` (see students and teachers),
`certificate_generator_issue` (edit, import, generate, download, email),
`certificate_generator_manage_templates`, `certificate_generator_manage_settings`. A user who can
view but not manage settings is limited to the school on their profile.

If another plugin keeps non-editors out of wp-admin, the coordinator role has
`view_admin_dashboard`, which WooCommerce honours; plugins that only check `edit_posts` need to
allow `certificate_generator_view` too.

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

### Fonts

**Certificate Generator → Fonts** lists the built-in fonts on every plan, with how many templates use each. Click **Download sample PDF** to see every font exactly as it prints on a certificate.

With the Pro add-on you can upload your own `.ttf` font file there, which then becomes selectable
on any certificate template alongside the built-in fonts. Uploaded fonts render through a
separate PDF engine (tFPDF), which takes UTF-8 text, so an uploaded font that covers a script
prints it. You don't need an uploaded font for Indian scripts or Arabic — see
[Languages](#languages-and-non-latin-text).

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
| `[certificate_generator_student_search]` | Front-end search form for a student to look up their own certificate(s) by email. |
| `[certificate_generator_teacher_search]` | Same lookup flow for teachers. |
| `[certificate_generator_school_search]` | Same lookup flow for schools (by school name and place). |
| `[certificate_generator_my_certificates]` | A recipient's own certificates, opened with an emailed sign-in link: download, verify & share, show or hide their name. See [My Certificates](#my-certificates-recipients). |
| `[school_bulk_certificate_download]` | (Pro add-on) Lets a school download all of its certificates in bulk (ZIP) from the front end. |
| `[certificate_generator_verify_certificate]` | Public certificate authenticity verification form — reachable via a certificate's QR code (`/verify-certificate/`); visitors can also search by serial number or email on the results page (`/result/`). |

### Customizing search-form text

The three search shortcodes accept optional attributes to override title, subtitle, button
text, and help text on a specific page:

```
[certificate_generator_student_search title="Custom Title" subtitle="Custom subtitle" button_text="Find Mine" help_text="Custom help text"]
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

**`/verify/{code or serial}`** — public verification, **free**. A verification code gets the full
answer; a serial alone gets a masked name. Registered in `src/Services/SerialNumberService.php`
(the legacy duplicate in `includes/Services/serial-generator.php` is never called — see
[`REST-API.md`](../dev/REST-API.md)).

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
| `certificate_generator_email_transport` | `wp_mail` or `smtp` |
| `certificate_generator_email_from_name` / `certificate_generator_email_from_email` | Sender identity |
| `certificate_generator_email_subject` / `certificate_generator_email_body` | HTML template with placeholders |
| `certificate_generator_smtp_host` / `certificate_generator_smtp_port` / `certificate_generator_smtp_username` / `certificate_generator_smtp_password` (encrypted) / `certificate_generator_smtp_encryption` | SMTP connection details |

### Serial numbers

| Option | Purpose |
|---|---|
| `certificate_generator_serial_prefix` | Prefix string, e.g. `CERT` |
| `certificate_generator_serial_length` | Sequence digit padding |
| `certificate_generator_serial_suffix` | Optional suffix |
| `certificate_generator_serial_reset_period` | When the sequence counter resets |
| `certificate_generator_serial_include_date` | Whether to embed the year, e.g. `CERT-2025-00142` |

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
| `certificate_generator_keep_data_on_uninstall` | If false, uninstall drops all custom tables and deletes plugin options |
| `certificate_generator_extra_fields_{cert_type_key}` | Per-certificate-type custom field registry (max 15 extra slots) |
| `certificate_generator_verify_serial_full_details` | `1` shows full details (unmasked name) when verifying by serial number alone. Default `0` |
| `certificate_generator_log_retention_days` | Days to keep email logs, sent queue rows and "verified" history events (weekly cleanup). Default 90 |
| `certificate_generator_search_requires_link` | `1`: `[certificate_generator_student_search]` emails a sign-in link instead of showing certificates for any typed email. Default `0` |
| `certificate_generator_linkedin_org_id` | Your LinkedIn organization ID (digits); "Add to LinkedIn Profile" then links to your organization's page. Default empty |
| `certificate_generator_verify_page_id` | The verification page that QR codes and `{verify_link}` use. Set automatically and re-created daily if it's deleted |

### Required CSV columns

**Bulk Import** requires: `student_name`, `email`, `school_name`, `certificate_type`,
`issue_date` (teachers use `teacher_name`; schools need `school_name`, `place`,
`issue_date`, `certificate_type`). Optional: `phone`, `event_code`, `send_email`
(`no`/`0`/`false` opts the row out of emails), and `photo_url` for students. Any other column is
an extra field: saved with the record and, for students, registered as a template field for that
row's certificate type. Ready-to-edit [sample files](#sample-files) for all four imports ship in
`assets/samples/`, and each import tab offers **Download a sample CSV**.

There is no row limit on import or export, on any plan.

**How an import works.** **Bulk Import → Students / Teachers / Schools → Upload and check**
reads the whole file and saves nothing yet. The check shows:

- the file's format: comma, semicolon or tab separated (detected from the header line) and its
  encoding. UTF-8 (with or without BOM), UTF-16 and Excel's Windows-1252 "CSV" are converted to
  UTF-8; a file that still doesn't decode is refused with "save it as CSV UTF-8", so garbled
  names are never saved;
- **Imports as** — one dropdown per column. Headers are matched by name, ignoring case and
  spaces ("Student Name" = `student_name`), then by common aliases (`Name`, `Email Address`,
  `School`, `Date`, `Type`, `Mobile`, `City`, …). Unknown columns become extra fields (see
  below). Pick a field by hand to override; Import stays disabled while a required column is
  unmapped;
- **Issue dates are written as** — day/month/year, month/day/year, or detect each date. It is
  guessed from the file; when values like 03/04/2026 fit both, you must choose;
- **Rows that match an existing record** — update it with the file's values, or leave it
  unchanged;
- the counts the import will produce (new, update existing, merge into an earlier row of the
  file, skipped), every issue by CSV row number, names too wide for their template field even at
  the smallest font size, and the first 5 rows as they will be stored.

**Import** then runs the same code with writes on, so the result matches the check. Each tab has
**Download a sample CSV**, built from the importer's own field list plus the extra fields your
templates already use.

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
- you chose **Leave the record unchanged** and the row matches an existing record.

A file too large for the server's time limit stops at a row boundary; the page then continues
from that row automatically (or click **Continue now**). The uploaded file is kept privately in
`uploads/cg_certificates/private/` between the steps, deleted after the import, and removed by the
daily cron after 2 hours if an import is abandoned. Rows are written 500 at a time in one
transaction each. 10,000 students: check 0.7 s, import 2.1 s, re-import 4.8 s on a local site.

Scripts can still post `submit_students` / `submit_teachers` / `submit_schools` with a file to
import in one step without the check (`bin/bench-bulk-import.php` does).

### Sample files

Ready-to-edit samples ship with the plugin in `assets/samples/` (also linked from **Documentation → User Guide → Managing Students, Teachers & Schools** in wp-admin). Download one, replace the example rows with your own in Excel or Google Sheets (keep the header row), save as CSV and import it on the matching **Bulk Import** tab.

| File | Import on | Columns |
|---|---|---|
| [`students.csv`](../../assets/samples/students.csv) | Students | `student_name`, `email`, `phone`, `school_name`, `issue_date`, `certificate_type`, `event_code`, `send_email`, `photo_url`, and `grade` as an example extra field |
| [`teachers.csv`](../../assets/samples/teachers.csv) | Teachers | `teacher_name`, `email`, `phone`, `school_name`, `issue_date`, `certificate_type`, `event_code`, `send_email`, and `subject` as an extra field |
| [`schools.csv`](../../assets/samples/schools.csv) | Schools | `school_name`, `place`, `issue_date`, `certificate_type`, `event_code`, `send_email` |
| [`templates.csv`](../../assets/samples/templates.csv) | Certificates | `template_name`, `certificate_type`, `entity_type`, `event_date` (blank = any date), `template_url`, `orientation`, `page_size`, `font_size`, `font_color`, `font_style`, `qr_enabled`, `serial_number_display`, `template_field_count`, then per field `field_N_name` (what it shows), `field_N_position_x`/`_y` (mm from the top-left; x is the field's centre), `field_N_visible`, `field_N_width`, `field_N_alignment` (L, C, R) |

The four files fit together: `templates.csv` has a template for every certificate type used in the other three. Import it first, after replacing each `template_url` with your own background image's URL (Media Library → Copy URL). Dates are day-month-year (`25-08-2026`); the files are UTF-8 with a BOM so Excel shows the Hindi name correctly. Each import tab also offers **Download a sample CSV**, generated from the importer's field list plus the extra fields your templates already use.

### Uninstall

Gated by `certificate_generator_keep_data_on_uninstall`. If disabled: drops both legacy and `wp_cg_*` tables,
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
| `wp_cg_certificates` | Issued certificates: recipient info, serial number, verification code, `show_name` (recipient's choice), `pdf_path`/`pdf_url`, `certificate_data` JSON, status, revision and layout snapshot |
| `wp_cg_certificate_events` | Per-certificate history (issued, name corrected, revoked, reinstated, reissued, verified), no IP addresses |
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
  uploaded `.ttf` fonts), `endroid/qr-code` in `vendor/` (QR images), and `mpdf/mpdf` in `vendor/`
  (certificates with non-Latin text, through `src/Services/MpdfCanvas.php`; fonts in
  `assets/fonts/unicode`).
- **Free / Pro split**: the free plugin exposes hooks (`certificate_generator_settings_tabs`, `cg_fonts_page_*`,
  `certificate_generator_feature_flags`, `certificate_generator_admin_menu_integrations`) and the Pro add-on plugs in through them.
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
   FPDF or mPDF errors. mPDF keeps its font cache in `uploads/cg-mpdf-tmp/`, which must be
   writable.

### Names print as "????"

Fixed in 7.8.0: text outside Western European now renders through mPDF. If a name still shows
boxes or question marks, check that `vendor/mpdf/` and `assets/fonts/unicode/` exist (they ship
in the release ZIP) and that the PDF was generated after updating — cached PDFs re-render once
because the render revision changed.

### ZIP file not attaching / download link missing

ZIPs are created when a recipient has more than one certificate. Requires PHP's `ZipArchive`
extension and a writable uploads directory. If the ZIP exceeds 25 MB, a download link is sent
in the email body instead of an attachment.

### Large downloads (1,000+ certificates)

- **Download Certificates** runs as a job in the browser. Each request renders certificates
  for up to `CERTIFICATE_GENERATOR_QUEUE_RUNTIME_BUDGET` seconds (default 20), with a progress bar. When it
  finishes it builds one ZIP per `CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE` certificates (default 200),
  each with a `manifest.csv`. A dropped connection is retried, and restarting a job doesn't
  re-render anything that's already done. Without JavaScript the old one-ZIP-per-part form
  still works.
- **PDF cache.** Each certificate PDF records a fingerprint of everything that shapes it:
  the row's data, the template settings, the template and image files, and the serial. If
  nothing changed, the existing file is served instead of being rendered again, under the
  same URL. Editing the row or template, or replacing the template image, re-renders it.
  If a code change alters PDF output, bump `CERTIFICATE_GENERATOR_PDF_RENDER_REV` so cached PDFs re-render. To
  turn the cache off: `define( 'CERTIFICATE_GENERATOR_DISABLE_PDF_CACHE', true );` in `wp-config.php`.
- **Admin ZIPs are private.** They are written to `uploads/cg_certificates/private/`
  (deny-all rule, random file names), are only downloadable by the admin who built them, and
  are deleted after a day by the daily `certificate_generator_cleanup_old_zips` cron. Public ZIPs (student
  search, emails) are kept 7 days.
- **`[certificate_generator_student_search]`** shows "Download All (ZIP)" for more than 3 certificates. The ZIP is
  built when the link is clicked (`admin-ajax.php?action=certificate_generator_student_zip`, which hide-login
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
Edit the template in Templates — upload a new background and reposition fields. Certificates
issued afterwards use the new design; issued ones keep their snapshot. Use **Reissue** on a
certificate's history to apply the new design to it.

**What happens if an email fails to send?**
The queue retries up to 3 times with exponential backoff, then marks the item `failed` and logs
the error in Email Logs. Failed items can be reset from the queue management section.

**How long are email logs kept?**
90 days by default, cleaned weekly together with sent queue rows and "verified" history events.
Change it with the `certificate_generator_log_retention_days` option.

**Can students verify their certificate online?**
Yes — every certificate's QR code opens the verification page with its verification code and
shows the full result. A serial number typed alone shows a masked name.

**Which languages can certificates print?**
Any — see [Languages and non-Latin text](#languages-and-non-latin-text).

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
