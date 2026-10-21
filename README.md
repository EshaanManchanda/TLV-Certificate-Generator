# Certificate Generator

A WordPress plugin for issuing certificates in bulk to students, teachers and schools: template-based PDF generation in any language, CSV import with a check step, email delivery with a queue and templates, QR-code verification with unguessable codes, certificate history, reissue, serial numbers and analytics. **No limits, and nothing locked.**

- **Version:** 7.9.3 (see [`doc/CHANGELOG.md`](doc/CHANGELOG.md))
- **Requires:** WordPress 6.0+, PHP 8.2+
- **Tested up to:** WordPress 7.1
- **License:** GPL-2.0-or-later ([`LICENSE.txt`](LICENSE.txt))
- **Author:** [Eshaan Manchanda](https://www.linkedin.com/in/eshaan-manchanda/) · [techlovev.in](https://techlovev.in)
- **WordPress.org readme:** [`readme.txt`](readme.txt)
- **Source:** <https://github.com/EshaanManchanda/TLV-Certificate-Generator>

## Free plugin and Pro add-on

This repository is the **free plugin** (WordPress.org). It has no usage limits, no license code, and never contacts a license server. An optional, separate **Certificate Generator Pro** add-on adds integrations with other plugins and a few extras. Full matrix and pricing: [`doc/guide/PLAN-COMPARISON.md`](doc/guide/PLAN-COMPARISON.md).

| Free (this plugin) | Pro add-on ($3.5/mo or $35/yr) |
|---|---|
| Certificate templates, PDF generation, 27 built-in fonts, names in any language | Everything in Free, plus: |
| Students / teachers / schools, CSV import (with mapping, a check before saving, duplicate flagging, import history and undo) & export | LMS auto-issue: Tutor LMS, LearnDash, LifterLMS, LearnPress, Sensei |
| Bulk generation in the background (progress, failed items, retry), PDF status filter, diagnostics, WP-CLI | REST API with scoped keys, signed webhooks, bulk jobs over the API |
| | n8n templates and Zapier guide (**Automation** page) |
| Email sending (wp_mail, SMTP, Sender.net, Mandrill) and email templates | WooCommerce auto-issue on order completion |
| Bulk send with background queue, rate limits, email logs, resend | "Issue to past completions" for every LMS |
| QR verification with unguessable codes, search shortcodes, LinkedIn "Add to Profile" | Custom font upload (`.ttf`) |
| Serial numbers, certificate history, revoke / reissue, analytics, badges, events, renewal reminders | REST API, school bulk ZIP shortcode, priority support |
| School Coordinator role: a school's staff issue certificates for their own school only | |

How the two plugins fit together: [`doc/dev/ARCHITECTURE.md`](doc/dev/ARCHITECTURE.md#free-plugin-and-pro-add-on). Business plan: everything in Pro on custom terms ([contact](mailto:techlovev@gmail.com)).

## Features (free)

- **Certificate templates** — upload a background image (e.g. from Canva), drag-position fields, give each field its own font, size, colour and UPPERCASE setting, and add custom-text fields that mix your words with `{field}` tags ("Awarded to {student_name}"). Long names shrink to fit.
- **PDF generation** with the bundled [FPDF](lib/fpdf) engine and open-licensed fonts. Certificates with text outside Western European (Hindi, Marathi, Bengali, Gujarati, Punjabi, Tamil, Telugu, Kannada, Malayalam, Odia, Urdu, Arabic, Ł, Cyrillic, …) switch automatically to [mPDF](https://github.com/mpdf/mpdf) with bundled fonts, so conjuncts join and Arabic connects.
- **QR-code verification** — every certificate gets an unguessable 12-character verification code; its QR code opens the public verification page (`[certificate_generator_verify_certificate]`). Checking by serial number alone still works but masks the name.
- **Certificate history, revoke and reissue** — every certificate keeps a history (issued, name corrected, revoked, reinstated, reissued, verified). Issued certificates keep the layout they were issued with; reissue applies a corrected template while keeping the serial and code, and the verify page shows "Corrected on …".
- **Serial numbers** — configurable format (`PREFIX-{YEAR}-{SEQ}`), bulk assignment for existing records.
- **Roles** — a **School Coordinator** role (the admin picks the school on the user's profile) can view, edit, import, download and email certificates for their own school's students and teachers only; templates, settings, revoke and analytics stay with admins. Four capabilities (`certificate_generator_view`, `_issue`, `_manage_templates`, `_manage_settings`) for role-editor plugins; admins have them all. See [Roles and School Coordinators](doc/guide/documentation.md#roles-and-school-coordinators) *(7.9.0)*.
- **Bulk generation jobs** *(7.9.3)* — **Generate certificates** on the Students, Teachers or Schools list runs in the background, 25 at a time, with no per-run cap: a progress page (**Certificate Generator → Bulk Jobs**) shows done / failed / pending, lists each failed record with its reason, and **Retry failed** re-runs only those. Re-running never issues a second certificate or changes a serial. A job that WP-Cron isn't advancing is shown as *stalled* (the open page keeps it going). **Regenerate PDFs** re-renders up-to-date PDFs, same serials. A **PDF status** filter finds records with no PDF yet or whose PDF file is missing.
- **Import history and undo** *(7.9.3)* — every CSV import is listed under **Bulk Import → Import history**. Rows that match an existing record can be **flagged for review** instead of updated or skipped, then applied, added as new or dismissed. **Undo import** previews first, deletes the rows the import created and restores the fields it changed, and keeps (and lists, with the reason) any record that got a certificate or an email, or was edited, after the import.
- **Diagnostics and WP-CLI** *(7.9.3)* — **Tools → Cert Gen Diagnostics** checks folders, fonts, PDF and image libraries, the database version, overdue scheduled tasks, the email queue and stalled jobs, with the fix for each problem. `wp certificate-generator generate | import | jobs | diagnostics` does the same work from the command line ([commands](doc/guide/documentation.md#wp-cli)).
- **Students / Teachers / Schools** — manual entry or CSV import/export, no row limits. Imports are checked before anything is saved: column mapping (common header names are recognised), new / updated / duplicate / skipped counts, date order, comma / semicolon / tab and Excel's Windows encoding. Ready-to-edit samples for students, teachers, schools and templates ship in [`assets/samples/`](assets/samples/).
- **Email** — your own SMTP, Sender.net or Mandrill (or WordPress's mail), per-type email templates with placeholders (including `{verification_code}` and `{certificate_link}`, which opens the certificate's verify page), CC/BCC.
- **Bulk send** — filter by school, certificate type or import source; background queue; ZIP bundling when a recipient has several certificates; hourly rate limits; email logs with resend.
- **Analytics** — delivery charts, expiration reports, recipient renewal reminders.
- **Search shortcodes** — `[certificate_generator_student_search]`, `[certificate_generator_teacher_search]`, `[certificate_generator_school_search]` let recipients find and download their certificates.
- **My Certificates** — `[certificate_generator_my_certificates]`: recipients sign in with an emailed link (no account needed) to download their certificates, share them and choose whether their name shows on the verify page. A setting makes the student search require the link too. See [My Certificates](doc/guide/documentation.md#my-certificates-recipients) *(7.9.0)*.
- **Certificate register** — every issued certificate with its serial, verification code, school and status, filtered and downloaded as CSV or a printable PDF (non-Latin names included). School Coordinators see their own school *(7.9.0)*.
- **Sharing** — the verify page offers LinkedIn Add to Profile (optionally linked to your LinkedIn organization; issue and expiry dates filled in, with a hint for attaching the PDF as LinkedIn media), X, WhatsApp and copy link, and shared links get a preview card.
- **Privacy tools** — WordPress's Export / Erase Personal Data cover the plugin's records; erased certificates are anonymised so printed ones still verify.
- **Badges and image fields** — optional companion PNG badge; image fields for a signature or seal.

The in-plugin **Certificate Generator → Documentation** page has the full interactive guide (Getting Started, User Guide, Email & Bulk Send, Troubleshooting, FAQ). [`doc/guide/documentation.md`](doc/guide/documentation.md) has the same content plus every developer reference in one file.

## Installation

1. Copy this folder to `wp-content/plugins/` (or install the release ZIP via **Plugins → Add New → Upload Plugin**).
2. Activate **Certificate Generator** — this creates the custom database tables.
3. Open **Certificate Generator → Dashboard** and follow the four setup steps: create a template, email yourself a test certificate, add a record, issue.
4. For reliable email, use an SMTP plugin such as WP Mail SMTP, or set SMTP / Sender.net / Mandrill under **Settings → Email Templates**.
5. Legacy sites only: if you still keep data in the old `students`/`teachers`/`schools`/`certificates` post types, run **Certificate Generator → SQL Migration** once.

Works standalone — records live in the plugin's own `wp_cg_*` tables ([`doc/dev/DATABASE.md`](doc/dev/DATABASE.md)). No other plugin is required.

## Quick directory map

| Path | What's there |
|---|---|
| `certificate-generator.php` | Bootstrap: constants, includes, activation, admin menu, add-on hooks |
| `includes/` | Legacy procedural code — still does most of the work (PDF generation, settings, email, import, WP-CLI in `includes/Cli/`) |
| `src/` | PSR-4 `CertificateGenerator\` OOP code — the migration target |
| `lib/` | Bundled FPDF (PDF, built-in fonts) and tFPDF (uploaded `.ttf` fonts) |
| `vendor/` | Composer autoloader, `endroid/qr-code` and `mpdf/mpdf` (non-Latin certificates) |
| `assets/` | CSS, JS, images, bundled template backgrounds, Chart.js, `fonts/unicode/` (fonts for Indian scripts and Arabic, with their licences) |
| `templates/` | Front-end template (student certificate page) |
| `doc/`, `tests/`, `bin/` | Developer docs, test suites, build scripts (not in the release ZIP) |

Full breakdown: [`doc/dev/ARCHITECTURE.md`](doc/dev/ARCHITECTURE.md).

## Documentation

All docs live in [`doc/`](doc/README.md), grouped as `guide/` (using the plugin) and `dev/` (developing and releasing). Index: [`doc/README.md`](doc/README.md).

**Using the plugin**

| Doc | Covers |
|---|---|
| [`doc/guide/documentation.md`](doc/guide/documentation.md) | Full guide — overview, admin guide, email & bulk send, shortcodes, REST API, configuration, database, plans, troubleshooting, FAQ |
| [`doc/guide/PLAN-COMPARISON.md`](doc/guide/PLAN-COMPARISON.md) | Free vs Pro vs Business, pricing, how the add-on plugs in |
| [`doc/guide/FAQ.md`](doc/guide/FAQ.md) | Sending, templates, backups, plans, debugging |
| [`doc/guide/SHORTCODES.md`](doc/guide/SHORTCODES.md) | Front-end shortcodes |
| [`doc/guide/CONFIGURATION.md`](doc/guide/CONFIGURATION.md) | Settings and options reference, feature flags, integrations, uninstall |
| [`doc/guide/LIMITS.md`](doc/guide/LIMITS.md) | Server limits, batch sizes, large imports and sends |

**Developing**

| Doc | Covers |
|---|---|
| [`doc/dev/ARCHITECTURE.md`](doc/dev/ARCHITECTURE.md) | Free/Pro split, dual codebase (`includes/` vs `src/`), directory structure, feature flags, key files |
| [`doc/dev/DATABASE.md`](doc/dev/DATABASE.md) | Custom table schema, legacy tables, migration path |
| [`doc/dev/REST-API.md`](doc/dev/REST-API.md) | REST endpoints (free verify route, Pro integration API) |
| [`doc/dev/TESTING.md`](doc/dev/TESTING.md) | PHPUnit, static analysis, Test Center, Playwright E2E |
| [`doc/dev/MANUAL-TEST-CHECKLIST.md`](doc/dev/MANUAL-TEST-CHECKLIST.md) | Click-through release checklist |
| [`doc/dev/PERFORMANCE.md`](doc/dev/PERFORMANCE.md) | Measured performance and tuning |
| [`doc/CHANGELOG.md`](doc/CHANGELOG.md) | Version history |
| [`SECURITY.md`](SECURITY.md) | Reporting a vulnerability, verifying a release ZIP |

## Support

Use the in-plugin **Documentation → Troubleshooting / FAQ** tabs for common issues (emails not sending, PDF generation failures, ZIP attachments, "0 certificates queued", debug logging), or [`doc/guide/FAQ.md`](doc/guide/FAQ.md). Contact: [techlovev@gmail.com](mailto:techlovev@gmail.com).

## Security

Report vulnerabilities privately to **techlovev@gmail.com** — not in the public support forum. See [`SECURITY.md`](SECURITY.md) for the process and for how to verify a release ZIP with its SHA-256 checksum.
