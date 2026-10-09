# Performance & Capacity Limits

[← Back to README](../../README.md) · Full guide: [`documentation.md`](../guide/documentation.md) · [Free vs Pro](../guide/PLAN-COMPARISON.md)

What this plugin can actually handle today, where it breaks first, and what
would move each ceiling. Based on the current codebase (v7.5), not
aspirational numbers.

## 1. Current capacity per entity

| Area | Free tier | Pro | Business | What actually enforces it |
|---|---|---|---|---|
| Template fields | 50 | 50 | 50 | `CG_Field_Schema::MAX_FIELDS` — `includes/Core/field-schema.php:38`. Arbitrary constant, not a storage limit (`field_config` is JSON, could hold far more). |
| Certificate generation | 1 request = 1 PDF | same | same | `_cg_generate_pdf_impl()` (`includes/Services/certificate-search.php:1600`) is fully synchronous. |
| Bulk PDF generation (background) | 10 certs/chunk, chunks scheduled 60s apart | same | same | `Certificate_Background_Processor::BATCH_SIZE = 10` — `includes/Services/background-processor.php:25`, chunked with `array_chunk()` + `wp_schedule_single_event(time() + 60*$i, ...)` (line 87). |
| Admin ZIP export | 200 certs generated synchronously per "part" | same | same | `CG_ADMIN_EXPORT_ZIP_PART_SIZE = 200` — `includes/Admin/cert-download-admin.php:18`. Browser fires one POST per part for larger exports. |
| Email sending | ~10/min, 80/hour effective | same | same | `includes/Email/rate-limiter.php:18-31` (`emails_per_hour=80`, `emails_per_minute=10`, `batch_delay=480s`) + hardcoded `sleep(2)` per email (`bulk-email-sender.php:139`). Comment states this exists "to prevent hitting Hostinger's email rate limits" (`rate-limiter.php:4`). |
| Email queue throughput | 50 items/batch, 20s runtime budget per 5-min cron tick | same | same | `CG_QUEUE_BATCH_SIZE = 50`, `CG_QUEUE_RUNTIME_BUDGET = 20`s — `src/Core/Config.php:43`, `bulk-email-sender.php:103-109`. In practice ~5-9 emails/tick actually send once the rate limiter and sleep are applied. |
| QR verification | No stated cap | same | same | 2 DB round-trips per scan (`SerialNumberService::verify()`, `src/Services/SerialNumberService.php:38`), no caching — scales with traffic, not data size. |

## 2. Actual bottlenecks, ranked by what fails first

### 1. Bulk import is O(n) database round-trips, not O(1)
`includes/Services/bulk-import.php` streams the CSV row-by-row (`fopen`/`fgetcsv`,
no chunking, no AJAX batching). Every row does: one `SELECT ... LIMIT 1` for
the school lookup, then one `INSERT`/`UPDATE` upsert — **2+ queries per row**,
all in a single PHP request with no `set_time_limit()` override in this file.

This is the limit that bites first — well before the 500-row *license*
cap does. A 5,000-row CSV on a slow host will hit PHP's default execution
timeout long before it hits any intentional cap.

**Why it's built this way:** simplicity — each row is validated and upserted
independently, so partial failures don't corrupt the whole batch. That's a
reasonable tradeoff at 500 rows; it stops being one past a few thousand.

**Fix that doesn't change behavior:** batch the upserts into multi-row
`INSERT ... ON DUPLICATE KEY UPDATE` statements (e.g. 100 rows per query)
instead of one query per row. Cuts query count by ~100x with no change to
what gets imported or how conflicts resolve.

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
`Certificate_Background_Processor` (already used elsewhere in this codebase
for chunked bulk PDF jobs) instead of a bigger synchronous part size. Same
chunking pattern, no new dependency.

### 4. QR verification: 2 round-trips, zero caching
`SerialNumberService::verify()` does a `wpdb->get_row` lookup by serial, then
a second query for `pdf_url`. Fine at low volume; if a verification page
gets shared publicly and scanned at volume, this is 2x the DB load it needs
to be.

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
`Certificate_Background_Processor` chunk-and-cron pattern that already
exists in this codebase for ZIP jobs. That's the difference between
"times out somewhere past N rows depending on host" and "no practical
ceiling, just takes longer."

## 4. Summary table — fix effort vs. payoff

| Fix | Effort | Payoff | Touches |
|---|---|---|---|
| Batch bulk-import upserts | Small | Large (10-100x fewer queries) | `includes/Services/bulk-import.php` |
| Cache font/embeddability lookups | Small | Medium-large for bulk jobs | `includes/Core/font-manager.php` |
| Chunk ZIP export via background processor | Medium | Removes a timeout failure mode | `includes/Admin/cert-download-admin.php`, `includes/Services/background-processor.php` |
| Single-query QR verification + short cache | Small | Small unless verification traffic is high | `src/Services/SerialNumberService.php` |
| Route bulk email through `Mailer`/`Transport` + provider swap | Medium (code) / external (provider) | Only real way to raise send-rate ceiling | `includes/Services/bulk-email-sender.php`, `src/Email/Mailer.php` |
