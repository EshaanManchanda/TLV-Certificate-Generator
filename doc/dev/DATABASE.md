# Database Schema

[← Back to README](../../README.md)

Schema owner: `src/Database/CustomTables.php` (all tables created via `dbDelta`). Legacy tables are created directly in the activation hook in `certificate-generator.php`.

## Modern schema (v7+), prefix `wp_cg_`

| Table | Purpose |
|---|---|
| `wp_cg_students` | Student records — linked to `wp_posts` via `wp_post_id`, `extra_fields` JSON column, status enum |
| `wp_cg_teachers` | Teacher records — same shape as students |
| `wp_cg_schools` | School records — same shape as students |
| `wp_cg_certificate_templates` | Template config: orientation, font, QR position/size, serial display position, expiration rules, `field_config` JSON, status (`draft` / `scheduled` / `published` / `archived`) |
| `wp_cg_certificates` | Issued certificates: recipient info, serial number, `pdf_path` / `pdf_url`, `certificate_data` JSON, status |
| `wp_cg_email_logs` | Per-send delivery records (status, timestamp, error message) — feeds the Email Logs admin page and Analytics |
| `wp_cg_email_queue` | Background email queue — feeds the bulk-send processor |
| `wp_cg_student_certificates` | Join table: students ↔ certificates |
| `wp_cg_teacher_certificates` | Join table: teachers ↔ certificates |
| `wp_cg_settings` | Structured settings storage (newer alternative to scattered `wp_options` rows) |
| `wp_cg_migrations` | Tracks which versioned migrations have run (see below) |
| `wp_cg_lms_course_map` | v8: course → certificate-template mapping for LMS integrations. One row per `(lms_type, course_id)`: `template_id`, `trigger_event` (`course_complete`/`quiz_pass`/`both`), `quiz_id`, `enabled`. Shared by all five LMS/e-commerce integrations — used by the Pro add-on; see [`CONFIGURATION.md`](../guide/CONFIGURATION.md#lms--e-commerce-auto-issuance-v9). Created by the free plugin so mappings survive if Pro is deactivated |
| `wp_cg_lms_tracks` | v9: a "track" groups several courses under one certificate template — the certificate issues once **every** course in the track is complete for a user, instead of one certificate per course. Columns: `lms_type`, `track_name`, `template_id`. |
| `wp_cg_lms_track_courses` | v9: join table — which course IDs belong to which `wp_cg_lms_tracks` row. Unique on `(track_id, course_id)`. |
| `wp_cg_renewal_reminders_sent` | v9: tracks which renewal-reminder "stage" (e.g. 30/7/1 days before expiry) has already been sent per certificate, so `CertificateGenerator_Cron_Jobs::send_recipient_renewal_reminders()` never double-sends. Unique on `(certificate_id, stage)`. **Pro/Business only** — see [Licensing & plans](../guide/PLAN-COMPARISON.md). |
| `wp_cg_custom_fonts` | v9: Business-plan custom `.ttf` font uploads (`font_name`, `file_path`, `uploaded_by`), rendered via the bundled [tFPDF](../../lib/tfpdf) engine instead of classic FPDF. Managed from **Certificate Generator → Custom Fonts** (`src/Admin/Pages/FontsPage.php`). |
| `wp_cg_email_templates` | Named, per-certificate-type email templates (`certificate_type` is unique) — subject, title, message, attach/cc/bcc/reply-to. `certificate_generator_send_email()` (`includes/Email/functions.php`) checks this table first, falling back to the global `certificate_generator_email_subject`/`certificate_generator_email_body` options if no per-type row matches. Managed from **Settings → Email**. Free on every plan; see [`PLAN-COMPARISON.md`](../guide/PLAN-COMPARISON.md). |

`wp_cg_certificate_templates` also carries a `badge_template_url` column (v9, nullable) — an optional companion badge image. When set, `src/Listeners/BadgeGenerationListener.php` generates a PNG via `src/Services/BadgeGenerator.php` (GD) alongside every certificate issued from that template, and stores the result in the new `badge_path` column on `wp_cg_certificates`. Added by `Migration005_AddBadgeColumns.php`; gated by the `CG_USE_BADGES` flag, not plan-gated.

`wp_cg_certificate_templates`'s `field_config`/`extra_fields` JSON can also hold fields with `type: image` (v9) — e.g. a signature image — rendered independently of student data, alongside the existing text fields.

`wp_cg_students`, `wp_cg_teachers`, `wp_cg_schools` also carry an `import_source` column (v8) — the uploaded CSV filename from Bulk Import, or a fixed tag like `tutor_lms`/`learndash`/`lifterlms`/`sensei`/`woocommerce` for LMS/e-commerce-auto-created records. Powers the Bulk Send "Source" filter.

## Legacy tables (kept for back-compat)

Created directly in the `certificate-generator.php` activation hook, still referenced by legacy code and the in-app Troubleshooting guide:

| Table | Purpose |
|---|---|
| `wp_certificate_generator` | Original certificate records table (pre-`wp_cg_certificates`) |
| `wp_cert_email_logs` | Original email log table (pre-`wp_cg_email_logs`) — also what Analytics pulls from in `includes/Admin/documentation.php` |
| `wp_cert_email_queue` | Original email queue table (pre-`wp_cg_email_queue`) |

## Migration path (CPT → custom SQL tables)

The plugin's v7 rewrite moved primary certificate storage from WordPress posts (`wp_posts` / `wp_postmeta` on the `students`/`teachers`/`schools`/`certificates` CPTs) into the custom `wp_cg_*` tables. CPTs are kept only for admin-UI editing (meta boxes / ACF) and back-compat linkage via `wp_post_id`.

- `src/Database/CptToSqlMigration.php` — moves data from posts/postmeta into the new SQL tables.
- `src/Database/Migrations/MigrationRunner.php` — runs versioned schema migrations, tracked in `wp_cg_migrations`.
- Numbered migrations: `Migration001_AddTimeColumns.php`, `Migration002_AddSendEmailColumn.php`, `Migration003_BackfillYear.php`, `Migration004_AddImportSourceColumn.php`, `Migration005_AddBadgeColumns.php` (adds `badge_template_url`/`badge_path`, see above).
- `src/Admin/Pages/MigrationPage.php` — admin UI (**Certificate Generator → Migration**) to trigger the migration manually.

If the bulk-send screen reports "Successfully queued 0 certificates" or "No certificate records found", it usually means this migration hasn't been run yet for existing CPT data — see the in-plugin Documentation → Troubleshooting tab.

## Uninstall behavior

Defined at the bottom of `certificate-generator.php`, gated by the `certificate_generator_keep_data_on_uninstall` option:
- Drops both legacy and `wp_cg_*` tables.
- Deletes ~25 named options (see [`CONFIGURATION.md`](../guide/CONFIGURATION.md)).
- Clears all scheduled cron hooks.
