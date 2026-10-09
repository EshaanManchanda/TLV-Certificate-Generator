# Configuration Reference

[← Back to README](../../README.md) · Full guide: [`documentation.md`](documentation.md) · Pricing detail: [`PLAN-COMPARISON.md`](PLAN-COMPARISON.md)

Canonical settings are owned by `src/Services/SettingsService.php` (prefix `cg_`), with migration from the legacy serialized option `certificate_generator_settings_email`. The main settings UI is `includes/Admin/settings.php` (**Certificate Generator → Settings**, tabs: General / Templates / Email / License / Rate Limits).

## Email / SMTP

| Option | Purpose |
|---|---|
| `certificate_generator_email_transport` | `wp_mail` or `smtp` |
| `certificate_generator_email_from_name` / `certificate_generator_email_from_email` | Sender identity — for SMTP, `certificate_generator_email_from_email` must exactly match the authenticated SMTP account or delivery bounces ("Sender address rejected") |
| `certificate_generator_email_subject` / `certificate_generator_email_body` | Global fallback HTML template with placeholders (see below) — used when no per-certificate-type template matches |
| `certificate_generator_smtp_host` / `certificate_generator_smtp_port` / `certificate_generator_smtp_username` / `certificate_generator_smtp_password` (encrypted) / `certificate_generator_smtp_encryption` | SMTP connection details, used by `src/Email/Transport/SmtpTransport.php` |

### Per-certificate-type email templates

Beyond the single global template above, the `wp_cg_email_templates` table (see [`DATABASE.md`](../dev/DATABASE.md)) stores named templates keyed 1:1 to a `certificate_type` — subject, title, message, attach-certificate toggle, reply-to, cc, bcc. `certificate_generator_send_email()` (`includes/Email/functions.php`) checks this table first and only falls back to `certificate_generator_email_subject`/`certificate_generator_email_body` when no row matches. Managed from **Settings → Email**; this is the **Email Templates** row in the [plan comparison](PLAN-COMPARISON.md) (Pro/Business only).

### Email placeholders

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

WordPress shortcodes (e.g. `[site_name]`) are processed *after* placeholder substitution, so both can be mixed in the template.

Rate limits for bulk sending (defaults: 60 emails/hour, 10 emails/minute) are configured under **Settings → Rate Limits** and enforced by `includes/Email/rate-limiter.php`.

## Serial numbers

| Option | Purpose |
|---|---|
| `certificate_generator_serial_prefix` | Prefix string, e.g. `CERT` |
| `certificate_generator_serial_length` | Sequence digit padding |
| `certificate_generator_serial_suffix` | Optional suffix |
| `certificate_generator_serial_reset_period` | When the sequence counter resets |
| `certificate_generator_serial_include_date` | Whether to embed the year, e.g. `CERT-2025-00142` |

## Licensing

| Option | Purpose |
|---|---|
| `cg_plan` | `free` \| `pro` \| `business` |
| `cg_license_key` / `cg_license_expiry` | License credentials |
| `cg_license_server_url` | License server URL, used only by the Pro add-on (`CG_License_Manager::remote_validate()`) |

### Plans

No plan has a certificate, email or import/export limit. Every plan gets the same 27 built-in fonts.
The Pro add-on (separate plugin) adds the school bulk ZIP shortcode, the REST API and
custom `.ttf` font upload, documented under [LMS / e-commerce, badges & fonts](#lms--e-commerce-auto-issuance-v9) below.

REST API access (`/issue-certificate`) needs the Pro add-on — see [`REST-API.md`](../dev/REST-API.md). Full plan feature matrix: [`PLAN-COMPARISON.md`](PLAN-COMPARISON.md).

## LMS / e-commerce auto-issuance (v9)

Auto-issues a certificate when a student completes a course (or, for WooCommerce, completes an order) — no manual CSV import or admin action needed. Part of the **Pro add-on** (`certificate-generator-pro`, Pro license); each integration is off by default and enabled independently.

| Feature flag (`src/Core/Config.php`) | Platform | Admin mapping page |
|---|---|---|
| `CG_USE_TUTOR_LMS_INTEGRATION` | Tutor LMS | **Certificate Generator → Tutor LMS** (`cg-tutor-lms`) |
| `CG_USE_LEARNDASH_INTEGRATION` | LearnDash | **Certificate Generator → LearnDash** (`cg-learndash`) |
| `CG_USE_LIFTERLMS_INTEGRATION` | LifterLMS | **Certificate Generator → LifterLMS** (`cg-lifterlms`) |
| `CG_USE_SENSEI_INTEGRATION` | Sensei LMS | **Certificate Generator → Sensei LMS** (`cg-sensei`) |
| `CG_USE_WOOCOMMERCE_INTEGRATION` | WooCommerce (order completion) | **Certificate Generator → WooCommerce** (`cg-woocommerce`) |

Each mapping page lets an admin map a course (or, for WooCommerce, a product) to a certificate template and a trigger (`course_complete` / `quiz_pass` / `both`, where applicable), stored in the shared `wp_cg_lms_course_map` table. All of them share one implementation base in the Pro add-on — `AbstractLmsListener` / `AbstractLmsMapper` — that resolves the enrolled/purchasing WP user to a `wp_cg_students` row (auto-creating one and tagging `import_source` with the platform name if none exists), and checks for a pre-existing certificate to avoid duplicate issuance, through the same `certificate_generator_pre_generate_certificate` filter as every other issuance path.

**Multi-course "track" certificates:** group several courses under one certificate template via `wp_cg_lms_tracks` / `wp_cg_lms_track_courses` (see [`DATABASE.md`](../dev/DATABASE.md)) — the certificate issues once every course in the track is complete, instead of one per course. Available on all four course-based integrations (not WooCommerce, which is order-based).

## Digital badges & template signature images (v9)

| Option/flag | Purpose |
|---|---|
| `CG_USE_BADGES` (`src/Core/Config.php`) | When enabled, generates a companion badge PNG (via GD, `src/Services/BadgeGenerator.php`) alongside every certificate issued from a template that has a `badge_template_url` set. Not plan-gated. |
| Template field `type: image` | Certificate template fields can be `image` type (in addition to `text`) — e.g. a signature or seal — placed independently of student data. Configured in **Templates → Add/Edit** (`src/Admin/Pages/TemplatesPage.php`). Not plan-gated. |

The public verification result (`/verify/{serial}`) additionally returns `pdf_url` and `issuer_name`, powering a "LinkedIn — Add to Profile" button on the verification page — see [`REST-API.md`](../dev/REST-API.md).

## Recipient renewal reminders

| Option/flag | Purpose |
|---|---|
| `CG_USE_RENEWAL_REMINDERS` (`src/Core/Config.php`) | Enables `CertificateGenerator_Cron_Jobs::send_recipient_renewal_reminders()` (`includes/Cron/jobs.php`) — a daily cron that emails certificate **holders** (not just the admin) at configurable day-offsets before `expires_at` (default 30/7/1 days). **Gated to Pro/Business** — mirrors the Email Templates gate. Distinct from the existing admin-only expiring-certificates digest. |

`wp_cg_renewal_reminders_sent` (see [`DATABASE.md`](../dev/DATABASE.md)) records which stage has already fired per certificate so a recipient is never reminded twice for the same stage. Only certificates with a `recipient_email` on file can receive one — some SQL-first issuance paths leave that column blank. There is no self-serve "renew" purchase flow in the plugin, so the reminder email links to the existing certificate PDF rather than a renewal page.

## Misc

| Option | Purpose |
|---|---|
| `certificate_generator_version` | Installed version tracking |
| `certificate_generator_settings` | Installation mode, max memory usage, etc. |
| `certificate_generator_api_key` / `certificate_generator_api_key_enabled` | REST API key + enable flag |
| `certificate_generator_keep_data_on_uninstall` | If false, uninstall drops all custom tables and deletes plugin options |
| `certificate_generator_extra_fields_{cert_type_key}` | Per-certificate-type custom field registry (max 15 extra slots), managed by `CertificateGenerator_Field_Schema` (`includes/Core/field-schema.php`) |

## Required CSV columns (Bulk Import)

`student_name`, `email`, `school_name`, `certificate_type`, `issue_date`. Sample files live in `doc/samples/` (`students.csv`, `teacher.csv`, `school.csv`, `certificates.csv`). There is no row limit on any plan.

## Uninstall

See [`DATABASE.md`](../dev/DATABASE.md#uninstall-behavior).
