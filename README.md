# Certificate Generator

A WordPress plugin for issuing certificates in bulk to students, teachers and schools: template-based PDF generation, CSV import/export, email delivery with a queue and templates, QR-code verification, serial numbers and analytics. **No limits, and nothing locked.**

- **Version:** 7.6.0 (see [`doc/CHANGELOG.md`](doc/CHANGELOG.md))
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
| Certificate templates, PDF generation, 27 built-in fonts | Everything in Free, plus: |
| Students / teachers / schools, CSV import & export | LMS auto-issue: Tutor LMS, LearnDash, LifterLMS, LearnPress, Sensei |
| Email sending (wp_mail, SMTP, Sender.net, Mandrill) and email templates | WooCommerce auto-issue on order completion |
| Bulk send with background queue, rate limits, email logs, resend | "Issue to past completions" for every LMS |
| QR verification, search shortcodes, LinkedIn "Add to Profile" | Custom font upload (`.ttf`) |
| Serial numbers, revoke, analytics, badges, events, renewal reminders | REST API, school bulk ZIP shortcode, priority support |

How the two plugins fit together: [`doc/dev/ARCHITECTURE.md`](doc/dev/ARCHITECTURE.md#free-plugin-and-pro-add-on). Business plan: everything in Pro on custom terms ([contact](mailto:techlovev@gmail.com)).

## Features (free)

- **Certificate templates** — upload a background image (e.g. from Canva), drag-position fields, set font/size/colour per field. Long names shrink to fit.
- **PDF generation** with the bundled [FPDF](lib/fpdf) engine and open-licensed fonts.
- **QR-code verification** — every certificate gets a QR code linking to a public verification page (`[cg_verify_certificate]`).
- **Serial numbers** — configurable format (`PREFIX-{YEAR}-{SEQ}`), bulk assignment for existing records, revoke and reinstate.
- **Students / Teachers / Schools** — manual entry or CSV import/export, no row limits.
- **Email** — your own SMTP, Sender.net or Mandrill (or WordPress's mail), per-type email templates with placeholders, CC/BCC.
- **Bulk send** — filter by school, certificate type or import source; background queue; ZIP bundling when a recipient has several certificates; hourly rate limits; email logs with resend.
- **Analytics** — delivery charts, expiration reports, recipient renewal reminders.
- **Search shortcodes** — `[student_search]`, `[teacher_search]`, `[school_search]` let recipients find and download their certificates.
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
| `includes/` | Legacy procedural code — still does most of the work (PDF generation, settings, email) |
| `src/` | PSR-4 `CertificateGenerator\` OOP code — the migration target |
| `lib/` | Bundled FPDF (PDF, built-in fonts) and tFPDF (uploaded `.ttf` fonts) |
| `vendor/` | Composer autoloader and `endroid/qr-code` |
| `assets/` | CSS, JS, images, bundled template backgrounds, Chart.js |
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
