# Architecture

[← Back to README](../../README.md) · [Free vs Pro](../guide/PLAN-COMPARISON.md) · [Database](DATABASE.md) · [REST API](REST-API.md) · [Configuration](../guide/CONFIGURATION.md)

## Free plugin and Pro add-on

Certificate Generator ships as two plugins:

| Plugin | Folder / slug | Contains |
|---|---|---|
| **Certificate Generator** (free, WordPress.org) | `Certificate-Generator-V7.5/` → slug `certificate-generator` | Everything in this repository. No limits, no license code, no calls home. Email sending (wp_mail, SMTP, Sender.net, Mandrill) and email templates are free. |
| **Certificate Generator Pro** (paid, private repo) | `certificate-generator-pro/` | License manager and License tab; API Settings tab and REST API; `[school_bulk_certificate_download]`; `.ttf` font upload; all third-party integrations (Tutor LMS, LearnDash, LifterLMS, LearnPress, Sensei, WooCommerce, "Issue to past completions"). Needs the free plugin. |

Free never calls Pro code. Pro plugs in only through these hooks:

| Hook | Where in Free | What Pro does |
|---|---|---|
| `certificate_generator_settings_tabs` (filter) / `certificate_generator_settings_tab_{key}` (action) | `includes/Admin/settings.php` | Adds and renders **API Settings** and **License** |
| `certificate_generator_fonts_page_message` (filter) / `certificate_generator_fonts_page_upload_card` (action) | `src/Admin/Pages/FontsPage.php` | `.ttf` upload form and handler |
| `certificate_generator_feature_flags` (filter) | `certificate_generator_feature_flags()` in `certificate-generator.php` | Adds the six integration toggles to **Settings → Features** |
| `certificate_generator_admin_menu_integrations` (action) | admin menu in `certificate-generator.php` | Adds the LMS / WooCommerce mapping pages |
| `CG_PRO_VERSION` (constant) | `certificate-generator.php` | Hides the "Pro add-on" link on the Plugins screen |

Lifecycle actions, used by Pro webhooks (each one fires at the single function every path goes through):

| Action | Args | Fired in |
|---|---|---|
| `certificate_generator_certificate_issued` | `$cert_row_id, $serial, $data, $generated_via` | `certificate_generator_insert_certificate_record()` (new record only; also bulk serial and the email path) |
| `certificate_generator_certificate_generated` | `$post_id, $pdf_path, $new_serial, $cert_row_id` | end of `certificate_generator_generate_pdf_impl()` (PDF render, not a cache hit) |
| `certificate_generator_certificate_revoked` / `_reinstated` | `$serial[, $reason]` | `certificate_generator_set_certificate_revoked()` |
| `certificate_generator_certificate_reissued` | `$serial, $revision` | `certificate_generator_reissue_certificate()` |
| `certificate_generator_certificate_expired` | `$serial, $legacy_row_id` | daily `certificate_generator_check_expiring_certificates` cron, once per certificate |
| `certificate_generator_recipient_saved` | `$entity, $id, $is_new` | `certificate_generator_save_recipient()` (`includes/Services/recipients.php`) |
| `certificate_generator_recipients_deleted` | `$entity, int[] $ids` | single and bulk delete on the Students / Teachers / Schools lists |
| `certificate_generator_import_completed` / `_import_failed` | `array $summary` (entity, source, counts) | `certificate_generator_import_run()` once the file is fully read; `_failed` when rows failed and none were saved |
| `certificate_generator_certificate_verified` | `$serial, $by` (`code` / `serial`) | `SerialNumberService::verify()`, visitors only |
| `certificate_generator_certificates_viewed` | `$email, string[] $serials` | the student result shortcode and My Certificates, when they list the recipient's certificates |
| `certificate_generator_email_logged` | `array $data` (`status` sent / failed) | `certificate_generator_log_email()`, every delivery attempt |
| `certificate_generator_job_finished` | `array $summary` (id, status, total, done, failed, …) | `Jobs::process()` when a bulk job ends (again after a retry) |

**Bulk jobs** (`src/Services/Jobs.php`, table `wp_cg_jobs`, cron hook `certificate_generator_job_batch`): admin Generate, WP-CLI and Pro's REST `/jobs` create jobs over recipient ids; each WP-Cron event runs 25 items through `certificate_generator_generate_for_row()` (`includes/Services/recipients.php`) and schedules the next. One worker per job (`add_option()` lock `certificate_generator_job_lock_{id}`, expires after 15 min); progress is saved after every item; the hourly `certificate_generator_publish_scheduled_templates` event reschedules stalled jobs, and the Bulk Jobs progress page runs a batch on each poll. Import runs are `type = import` rows in the same table, with per-row history in `wp_cg_import_items` (`includes/Services/import-history.php`).

Pro features check `CG_License_Manager::is_pro()` (true for Pro and Business). The tables Pro features use (`wp_cg_lms_course_map`, `wp_cg_lms_tracks`, `wp_cg_lms_track_courses`, `wp_cg_custom_fonts`) are created by Free (`src/Database/CustomTables.php`), so their data survives if the add-on is deactivated. Uploaded fonts keep rendering in Free.

Pro's integration classes keep the free namespace (`CertificateGenerator\Integrations\…`, `CertificateGenerator\Admin\Pages\*LmsPage`); `certificate-generator-pro/includes/integrations.php` autoloads them from `certificate-generator-pro/src/`.

## Dual codebase: `includes/` (legacy) vs `src/` (new)

The free plugin is **mid-migration** from a procedural v6/v7 codebase to a namespaced `CertificateGenerator\` OOP architecture. Some features have both an old procedural implementation in `includes/` and a class-based one in `src/`. Which one runs is controlled by feature flags in `src/Core/Config.php` (`Config::flag()`; a `wp-config.php` constant or a toggle on **Settings → Features** turns one on):

| Flag | Effect when enabled |
|---|---|
| `CG_USE_NEW_PDF` | Routes PDF generation through `src/Services/PdfGenerator.php` instead of the legacy `certificate_generator_generate_pdf_impl()` in `certificate-search.php` |
| `CG_USE_NEW_ZIP` | Uses `src/Services/ZipService.php` |
| `CG_USE_REPOSITORIES` | Uses the `src/Database/*Repository.php` classes instead of raw `$wpdb` calls in `includes/` |
| `CG_USE_DTO` | Typed data-transfer objects for internal service calls |
| `CG_USE_EVENTS` | The `src/Listeners/` event system (`certificate_generator_email_sent`) |
| `CG_USE_BADGES` | Companion badge PNG per certificate (`src/Listeners/BadgeGenerationListener.php`) |
| `CG_USE_RENEWAL_REMINDERS` | Recipient expiry reminder emails (`includes/Cron/jobs.php`) |
| `CG_USE_*_INTEGRATION` (six flags) | Read by the **Pro add-on** only — see [`CONFIGURATION.md`](../guide/CONFIGURATION.md#lms--e-commerce-auto-issuance-v9) |
| `CG_DEBUG_PDF_TIME` / `CG_DEBUG_QUERY_TIME` / `CG_DEBUG_SERVICES` | Debug timing/logging |

`includes/legacy-shims.php` bridges old procedural calls into the new `src/` classes during the transition (`@deprecated v8`).

When touching PDF/ZIP/data-access code, check `Config.php` first to know which implementation is live.

## Autoloading and logging

- `certificate-generator.php` loads Composer's `vendor/autoload.php` (shipped in the release ZIP; it also brings `endroid/qr-code` and `mpdf/mpdf`) and falls back to a hand-rolled `spl_autoload_register` that maps `CertificateGenerator\Foo\Bar` → `src/Foo/Bar.php`.
- All logging goes through `certificate_generator_debug_log()` (`includes/Core/debug-log.php`), which writes only when `WP_DEBUG` is on, so live sites never log recipient data.

## Directory structure (free plugin)

```
Certificate-Generator-V7.5/
├── certificate-generator.php   # bootstrap: constants, includes, activation/deactivation/uninstall,
│                               #   admin menu, setup checklist, certificate_generator_feature_flags()
├── readme.txt                  # WordPress.org readme
├── includes/                   # legacy procedural architecture
│   ├── Admin/                  # settings, analytics, bulk-email, bulk-serial, cert-download-admin,
│   │                           #   cg-settings (serials), columns, documentation, email-logs,
│   │                           #   feedback-contact, filters-api, revoke-certificate, ui (cg_ui_* kit)
│   ├── Core/                   # debug-log, error-reporting, field-schema, font-manager, post-types,
│   │                           #   security-helper, server-compatibility
│   ├── Cron/                   # jobs.php (WP-Cron tasks, renewal reminders)
│   ├── Database/               # migrator.php + one-off migration-*.php scripts
│   ├── Email/                  # functions.php (send logic), log.php, queue.php, rate-limiter.php
│   ├── Public/                 # verification.php ([certificate_generator_verify_certificate])
│   ├── Services/               # certificate-search.php (PDF pipeline + search shortcodes),
│   │                           #   bulk-import (one pipeline + check step)/export/download, bulk-email-sender,
│   │                           #   background-processor, certificate-events (history), serial-generator, qr-generator
│   └── legacy-shims.php        # @deprecated v8 bridge to src/
├── src/                        # PSR-4 CertificateGenerator\ OOP architecture
│   ├── Admin/                  # TestCertificate.php ("Email me a test certificate"),
│   │                           #   Pages/ (EntityListPage base; Students/Teachers/Schools/Templates/
│   │                           #   Events/Fonts/Migration pages; TestCenterPage — dev-only, not shipped)
│   ├── Core/                   # Plugin.php (bootstrapper), Container.php (DI), Config.php (feature flags)
│   ├── Database/               # CustomTables.php (schema owner, see DATABASE.md), CptToSqlMigration,
│   │                           #   *Repository.php, Migrations/ (versioned MigrationRunner)
│   ├── Email/                  # Mailer.php, EmailResender.php, Transport/ (WpMail, Smtp, Sender, Mandrill)
│   ├── Helpers/                # DateHelper.php
│   ├── Listeners/              # LogEmail, Analytics, InvalidateStatusCache, BadgeGeneration
│   ├── Services/               # PdfGenerator, PngGenerator, MpdfCanvas (non-Latin text), ZipService,
│   │                           #   QRCodeService, SerialNumberService (verify), VerificationCodeService,
│   │                           #   EmailService, EmailStatusService, PreSendCheck, SettingsService,
│   │                           #   FieldManager, BadgeGenerator
│   └── Exception/, Interfaces/, Logging/, Models/, Traits/
├── lib/
│   ├── fpdf/                   # FPDF — PDF engine for every built-in font (+ font/ metric files, OFL/Apache)
│   └── tfpdf/                  # tFPDF — renders uploaded .ttf fonts only
├── vendor/                     # Composer: autoloader, endroid/qr-code (QR images), mpdf/mpdf (non-Latin PDFs;
│                               #   its own ttfonts/ is dropped from the release ZIP)
├── assets/                     # css/, js/, images/, templates/ (bundled backgrounds), vendor/chart.js,
│                               #   fonts/unicode/ (FreeSerif, Lohit Telugu/Kannada, Noto Sans Oriya + licences)
├── doc/                        # developer docs (not in the release ZIP)
└── tests/                      # PHPUnit + Playwright E2E (not in the release ZIP)
```

The Pro add-on's layout is in its own `README.md` (`certificate-generator-pro/README.md`).

## Bundled third-party libraries

- `lib/fpdf/fpdf.php` — [FPDF](http://www.fpdf.org/), the PDF engine. `includes/Services/certificate-search.php` subclasses it (adds `Circle()`, `Ellipse()`, alpha transparency) and implements the rendering pipeline (`certificate_generator_generate_pdf_impl()`, `certificate_generator_generate_pdf_with_data_impl()`, template selection). The built-in fonts are open-licensed (`lib/fpdf/font/FONTS-LICENSE.txt`).
- `lib/tfpdf/` — [tFPDF](https://github.com/Setasign/tFPDF), a Unicode-capable FPDF fork, used **only** for uploaded `.ttf` fonts (`wp_cg_custom_fonts`). Built-in fonts keep using `lib/fpdf/`. Text goes to it as UTF-8 while a TTF font is current (`certificate_generator_pdf_text()`); tFPDF does no OpenType shaping, so it can't join Indian scripts or Arabic.
- `mpdf/mpdf` (Composer, GPL-2.0) — used only for certificates whose text has a character outside Latin-1 (`certificate_generator_unicode_canvas()` in `certificate-search.php`). `src/Services/MpdfCanvas.php` puts it behind the slice of FPDF's API the renderers call (`AddPage`, `Image`, `SetFont`, `SetFontSize`, `GetStringWidth`, `Text`, `Output`; everything else passes through), so both renderers, fit-to-width and wrapping are unchanged. `Text()` splits each line into script runs: Latin runs use the field font's TTF (`CertificateGenerator_FontManager::get_ttf_path_for_gd()`, which maps FPDF core fonts to Arimo/Tinos), other runs use `assets/fonts/unicode` (current Noto builds use OpenType lookups mPDF 8.3 can't read). Widths are measured after shaping. Font metric cache: `uploads/cg-mpdf-tmp/`. `bin/build-zip.ps1` deletes `vendor/mpdf/mpdf/ttfonts` (88 MB). `PngGenerator` hands back the PDF for Indic/Arabic text, because GD can't shape it. Text goes to it as UTF-8 while a TTF font is current (`certificate_generator_pdf_text()`); tFPDF does no OpenType shaping, so it can't join Indian scripts or Arabic.
- `mpdf/mpdf` (Composer, GPL-2.0) — used only for certificates whose text has a character outside Latin-1 (`certificate_generator_unicode_canvas()` in `certificate-search.php`). `src/Services/MpdfCanvas.php` puts it behind the slice of FPDF's API the renderers call (`AddPage`, `Image`, `SetFont`, `SetFontSize`, `GetStringWidth`, `Text`, `Output`; everything else passes through), so both renderers, fit-to-width and wrapping are unchanged. `Text()` splits each line into script runs: Latin runs use the field font's TTF (`CertificateGenerator_FontManager::get_ttf_path_for_gd()`, which maps FPDF core fonts to Arimo/Tinos), other runs use `assets/fonts/unicode` (current Noto builds use OpenType lookups mPDF 8.3 can't read). Widths are measured after shaping. Font metric cache: `uploads/cg-mpdf-tmp/`. `bin/build-zip.ps1` deletes `vendor/mpdf/mpdf/ttfonts` (88 MB). `PngGenerator` hands back the PDF for Indic/Arabic text, because GD can't shape it.
- `endroid/qr-code` (Composer, in `vendor/`) — QR images, wrapped by `src/Services/QRCodeService.php` and `includes/Services/qr-generator.php`. There is no external QR service fallback.
- `assets/vendor/chart.js/` — Chart.js for the Analytics page, bundled locally (no CDN).

Both bundled PDF libraries are exempt from the WordPress coding-standard checks with a `phpcs:disable` header, so they can be updated from upstream unchanged.

## Key files for new contributors

| # | File | Why it matters |
|---|---|---|
| 1 | `certificate-generator.php` | Bootstrap — constants, includes, activation, admin menu, autoloader fallback, add-on hooks |
| 2 | `src/Core/Plugin.php` | New-architecture entry point (`boot()`), DI singletons, init/REST hooks |
| 3 | `src/Core/Config.php` | All feature flags — read first to know which code path is live |
| 4 | `includes/Services/certificate-search.php` (~3,500 lines) | The core — FPDF subclass, PDF pipeline, template selection, search shortcodes |
| 5 | `src/Database/CustomTables.php` | Single source of truth for the SQL schema |
| 6 | `includes/Core/post-types.php` (~2,500 lines) | Legacy meta boxes, ACF field groups, CPT↔SQL sync |
| 7 | `includes/Admin/settings.php` (~2,300 lines) | Settings UI — email/SMTP, email templates, Features; `certificate_generator_settings_tabs` for add-on tabs |
| 8 | `includes/Email/functions.php` + `src/Email/Mailer.php` | Send logic and the Mailer/Transport abstraction |
| 9 | `src/Services/SerialNumberService.php` + `VerificationCodeService.php` | Public `/verify/{code or serial}` REST route; verification codes |
| 10 | `includes/Services/bulk-import.php` | `certificate_generator_import_entities()` (per-entity config) + `certificate_generator_import_run()` (the one import pipeline, dry run = the check step) + `CertificateGenerator_Import_Writer` |
| 11 | `src/Services/MpdfCanvas.php` | mPDF behind FPDF's API, for non-Latin text |
| 12 | `certificate-generator-pro/` | Pro add-on: license, REST API, integrations, font upload, bulk ZIP |

## Known cleanup items

- `includes/Services/serial-generator.php::register_api_endpoints()` is never called (the live `/verify/{serial}` route is in `src/Services/SerialNumberService.php`); safe to delete.
- `includes/Public/verification.php` holds the public `[certificate_generator_verify_certificate]` shortcode (`CertificateGenerator_Public_Verification`). The old license-verification REST route that used to share the file now lives in Pro (`certificate-generator-pro/includes/payment-endpoints.php`).
