# Performance & Capacity Limits

[← Back to README](../../README.md) · Full guide: [`documentation.md`](../guide/documentation.md) · [Free vs Pro](../guide/PLAN-COMPARISON.md)

What this plugin can actually handle today, where it breaks first, and what
would move each ceiling. Written for v7.5 and updated for 7.8.0 where things changed; measured
numbers live in [`LIMITS.md`](../guide/LIMITS.md#benchmarks).

## 1. Current capacity per entity

| Area | Free tier | Pro | Business | What actually enforces it |
|---|---|---|---|---|
| Template fields | 50 | 50 | 50 | `CertificateGenerator_Field_Schema::MAX_FIELDS` — `includes/Core/field-schema.php:38`. Arbitrary constant, not a storage limit (`field_config` is JSON, could hold far more). |
| Certificate generation | 1 request = 1 PDF | same | same | `certificate_generator_generate_pdf_impl()` (`includes/Services/certificate-search.php:1600`) is fully synchronous. |
| Bulk PDF generation (background) | 10 certs/chunk, chunks scheduled 60s apart | same | same | `CertificateGenerator_Background_Processor::BATCH_SIZE = 10` — `includes/Services/background-processor.php:25`, chunked with `array_chunk()` + `wp_schedule_single_event(time() + 60*$i, ...)` (line 87). |
| Admin ZIP export | 200 certs generated synchronously per "part" | same | same | `CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE = 200` — `includes/Admin/cert-download-admin.php:18`. Browser fires one POST per part for larger exports. |
| Email sending | ~10/min, 80/hour effective | same | same | `includes/Email/rate-limiter.php:18-31` (`emails_per_hour=80`, `emails_per_minute=10`, `batch_delay=480s`) + hardcoded `sleep(2)` per email (`bulk-email-sender.php:139`). Comment states this exists "to prevent hitting Hostinger's email rate limits" (`rate-limiter.php:4`). |
| Email queue throughput | 50 items/batch, 20s runtime budget per 5-min cron tick | same | same | `CERTIFICATE_GENERATOR_QUEUE_BATCH_SIZE = 50`, `CERTIFICATE_GENERATOR_QUEUE_RUNTIME_BUDGET = 20`s — `src/Core/Config.php:43`, `bulk-email-sender.php:103-109`. In practice ~5-9 emails/tick actually send once the rate limiter and sleep are applied. |
| QR verification | No stated cap | same | same | 1–2 indexed lookups per scan (`SerialNumberService::verify()`: by verification code, falling back to the second certificate table), plus one history insert for visitors; rate-limited per IP. No caching — scales with traffic, not data size. |
| Non-Latin certificates | ~115 ms each vs ~45 ms Latin | same | same | Rendered by mPDF (`src/Services/MpdfCanvas.php`). mPDF leaves ~2 MB of circular references per certificate, reclaimed by PHP's cycle collector (at most ~24 MB pending; measured flat over 40 certificates). |

## 2. Actual bottlenecks, ranked by what fails first

### 1. Bulk import — resolved (7.6.0, 7.8.0)
Was: 2+ queries per row in one request. Now `CertificateGenerator_Import_Writer` writes 500 rows
per transaction (one preload query, one multi-row `INSERT`, updates), schools are cached per run,
and the import stops before the time limit and continues from the stop row. 10,000 rows import in
~2 s. Since 7.8.0 the three importers share `certificate_generator_import_run()`, whose dry run is
the check step (0.7 s for 10,000 rows). What's left: a re-import of an unchanged file still
issues one `UPDATE` per matched row (~10,000 queries, ~5 s).

### 2. Font lookups and TTF validation re-run on every single PDF
`CertificateGenerator_FontManager::get_custom_fonts()`
(`includes/Core/font-manager.php:267`) hits `wp_custom_fonts` once per
request and memoizes only for that request's lifetime — no transient or
object cache survives across requests. `is_font_embeddable()` (line 300)
re-parses the TTF's `OS/2` table from disk on every render to work around
tFPDF's uncatchable `die()` on non-embeddable fonts.

For a single certificate this is invisible. For a 500-certificate bulk job
using a custom font, that's 500 redundant DB queries and 500 redundant file
parses of the exact same font.

**Fix:** cache both the custom-font list and the embeddability verdict per
font ID in a transient (invalidated when fonts are added/removed via
`custom_fonts` table hooks). One lookup per font per cache lifetime instead
of one per certificate.

### 3. Admin ZIP export masks a timeout risk instead of solving it
`cert-download-admin.php:69-72` handles the 200-certs-per-request load by
calling `@set_time_limit(0)` and bumping `memory_limit` to `512M` if the
current limit is lower. That works until the host enforces a hard cap PHP
can't override (common on shared hosting, including the Hostinger target
implied by the rate-limiter comment) — at which point the request just dies
with no partial-progress recovery for that part.

**Fix:** route ZIP export through the existing
`CertificateGenerator_Background_Processor` (already used elsewhere in this codebase
for chunked bulk PDF jobs) instead of a bigger synchronous part size. Same
chunking pattern, no new dependency.

### 4. QR verification: no caching
`SerialNumberService::verify()` looks the code (or serial) up in the legacy table and falls back
to `wp_cg_certificates`; the second `pdf_url` query is gone (7.7.0 no longer returns a PDF URL).
Visitor lookups also insert a `verified` history row. Fine at normal volume; a widely shared
verify link scanned at volume would benefit from a short cache.

**Fix:** single JOIN query instead of two lookups, plus a short-lived
(1-5 min) object-cache/transient keyed by serial number — verification
results don't change once issued.

### 5. Email throughput is a hosting constraint, not a code bottleneck
The queue architecture itself (table + WP-Cron batches, retry with backoff,
stale-row reclaim) is sound and already exists in
`includes/Email/queue.php` and `includes/Services/bulk-email-sender.php`.
The low throughput (~10/min, 80/hr, plus `sleep(2)` per send) is a
deliberate, documented choice to stay under a shared host's SMTP rate limit
— not something inefficient code is causing.

**No algorithm fixes this.** The actual levers are: raise the configured
rate-limit constants if the current host allows it, or switch the transport
to a transactional email API (SES, Mailgun, Postmark, SendGrid) via the
existing `src/Email/Mailer.php`/`Transport` abstraction — which is already
built but currently bypassed by the bulk queue path (it calls `wp_mail()`
directly instead of going through `Mailer`).

## 3. Where "better algorithms" helps vs. doesn't

**Helps (removes redundant work, same output):**
- Batch bulk-import upserts → fewer queries, same import semantics.
- Cache font/embeddability lookups → same PDFs, generated faster in bulk.
- Cache QR verification lookups → same verification result, less DB load.
- Single JOIN instead of two queries in `SerialNumberService::verify()`.

**Doesn't help (bounded by something outside the code):**
- Email send rate — bounded by the mail server/host, not the loop.
- PDF render quality — bounded by the uploaded template image's native
  resolution; there's no downsampling logic to optimize, and no DPI ceiling
  to raise, because none is applied either direction.

**Biggest lever overall, architectural not algorithmic:** move bulk import
and bulk PDF generation off "one big synchronous PHP request" onto the
`CertificateGenerator_Background_Processor` chunk-and-cron pattern that already
exists in this codebase for ZIP jobs. That's the difference between
"times out somewhere past N rows depending on host" and "no practical
ceiling, just takes longer."

## 4. Summary table — fix effort vs. payoff

| Fix | Effort | Payoff | Touches |
|---|---|---|---|
| ~~Batch bulk-import upserts~~ | Done in 7.6.0 | 10,000 rows: 35 s → 2 s | `includes/Services/bulk-import.php` |
| Cache font/embeddability lookups | Small | Medium-large for bulk jobs | `includes/Core/font-manager.php` |
| Chunk ZIP export via background processor | Medium | Removes a timeout failure mode | `includes/Admin/cert-download-admin.php`, `includes/Services/background-processor.php` |
| Single-query QR verification + short cache | Small | Small unless verification traffic is high | `src/Services/SerialNumberService.php` |
| Route bulk email through `Mailer`/`Transport` + provider swap | Medium (code) / external (provider) | Only real way to raise send-rate ceiling | `includes/Services/bulk-email-sender.php`, `src/Email/Mailer.php` |
