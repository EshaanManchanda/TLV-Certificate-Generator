# Limits

[← Back to README](../../README.md) · Full guide: [`documentation.md`](documentation.md) · [Free vs Pro](PLAN-COMPARISON.md)

Every hard limit, default and tested capacity in Certificate Generator 7.5.1, taken from the code. "Filter" / "constant" means you can change it.

## Plan limits

None. Since 7.6 no plan caps certificates, emails, import/export rows or templates
(see [`PLAN-COMPARISON.md`](PLAN-COMPARISON.md)).

## Certificates and templates

| Limit | Value | Notes |
|---|---|---|
| Fields per template | 50 | `CertificateGenerator_Field_Schema::MAX_FIELDS`; minimum 2 |
| Certificate-related file upload | 10 MB | `security-helper.php`; WordPress/PHP upload limits apply first |
| Template image decode cache (per request) | 64 MB | Oldest image dropped first beyond this |

## Bulk download (admin → Download Certificates)

| Limit | Value | Change with |
|---|---|---|
| Certificates per ZIP part | 200 | `CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE` constant |
| Time per job step | 20 s | `CERTIFICATE_GENERATOR_QUEUE_RUNTIME_BUDGET` constant / `certificate_generator_cert_dl_step_budget` filter |
| Job lifetime | 6 hours | — |
| Private admin ZIPs kept | 1 day | Cron cleanup |
| Public ZIPs kept | 7 days | Cron cleanup |
| Tested capacity | 2,000 certificates | 63 s first run, 10 s repeat, ~28 MB peak memory — see [Benchmarks](#benchmarks) |

No hard maximum: the job runs in steps, so the ceiling is disk space and time, not PHP timeout.

## Student search (`[certificate_generator_student_search]`)

| Limit | Value | Change with |
|---|---|---|
| "Download All" ZIP shown when certificates > | 3 | — |
| ZIP downloads per visitor | 5 per minute | `certificate_generator_student_zip_rate_limit` filter |

## Bulk import (CSV)

| Limit | Value | Change with |
|---|---|---|
| Rows written per transaction | 500 | `CertificateGenerator_Import_Writer::CHUNK` |
| Stops before PHP timeout | 10 s before `max_execution_time` | `certificate_generator_import_deadline` filter; re-upload the same file to continue |
| Issues shown on screen | First 100 | Full list downloads as CSV |
| Tested capacity | 10,000 rows | 2.2 s fresh, 4.6 s re-import — see [Benchmarks](#benchmarks) |

## Email

| Limit | Default | Allowed range | Setting |
|---|---|---|---|
| Emails per hour | 80 | 10 – 300 | Settings → Rate limits |
| Emails per minute | 10 | 1 – 50 | Settings → Rate limits |
| Emails per batch | 10 | 1 – 50 | Settings → Rate limits |
| Delay between batches | 480 s | 10 – 3,600 s | Settings → Rate limits |
| ZIP attached to email | ≤ 25 MB | — | Bigger ZIPs are sent as a download link |
| Queue rows per cron run | 50 | — | `CERTIFICATE_GENERATOR_QUEUE_BATCH_SIZE` |
| Queue attempts per email | 3 | — | `CERTIFICATE_GENERATOR_QUEUE_MAX_ATTEMPTS` |
| Queue row considered stuck after | 10 min | — | `CERTIFICATE_GENERATOR_QUEUE_STALE_MINUTES` |

## Other

| Limit | Value |
|---|---|
| License verify requests | 5 per minute |

## Benchmarks

Actual test results on a local dev site (Local, PHP 8.2, Windows 11), template #39 "Participation". **Before** = 7.5.1 code before the bulk-scale work. **After** = current code, re-run on 2026-10-03. Run them yourself (dev sites only):

```
wp eval-file bin/bench-bulk-download.php 2000
wp eval-file bin/bench-bulk-import.php 10000
```

### Bulk certificate download (lookup → PDF → ZIP)

**Cold** = first download, every PDF rendered. **Warm** = the same certificates downloaded again.

| Certificates | Cold before | Cold after | Warm before | Warm after | ZIP size |
|---|---|---|---|---|---|
| 10 | 0.50 s | 0.37 s | 0.47 s | **0.05 s** | 3.1 MB |
| 50 | 2.82 s | 1.73 s | 2.45 s | **0.25 s** | 15.5 MB |
| 100 | 5.20 s | 3.39 s | 4.19 s | **0.59 s** | 31.1 MB |
| 500 | 24.43 s | 16.10 s | 20.34 s | **2.60 s** | 155.4 MB |
| 1,000 | 45.61 s | 31.74 s | 42.19 s | **5.27 s** | 310.8 MB |
| 2,000 | 95.03 s | 63.38 s | 89.60 s | **9.98 s** | 621.8 MB |

Breakdown at 2,000 certificates:

| Measure | Before | After |
|---|---|---|
| ZIP build | 34.67 s (cold) / 38.40 s (warm) | 5.16 s (cold) / 0.40 s (warm) |
| PDF renders on repeat download | 2,000 | 0 |
| DB queries per certificate | 12 (cold) / 10 (warm) | 8 (cold) / 1 (warm) |
| Peak memory | 28 MB | 28–29 MB |
| Render time per certificate (RGBA PNG template) | 892 ms | 21 ms |

In the admin page the cold run is split into 20-second steps, so no single request runs for 63 s.

### Bulk CSV import (students, real database)

| Rows | Fresh before | Fresh after | Re-import before | Re-import after |
|---|---|---|---|---|
| 1,000 | 2.62 s · 2,059 queries | 0.29 s · 56 queries | 2.70 s · 2,025 queries | 0.45 s · 1,034 queries |
| 10,000 | 35.18 s · 20,047 queries | **2.20 s · 146 queries** | 25.28 s · 20,025 queries | **4.63 s · 10,106 queries** |

Peak memory: 22 MB before, 24–26 MB after.

Real-file check: the reported 1,000-row CSV (one shared email) saved **366** rows before, with no warning. It now saves **898**, and lists the 102 genuine duplicates by line number.

## Server requirements

| Requirement | Minimum | Recommended |
|---|---|---|
| PHP | 8.2 | 8.2+ |
| MySQL | 5.6 | 8.0 |
| PHP memory | 128 MB | 256 MB |
| `max_execution_time` | 60 s | 300 s |
| Upload size | 32 MB | 64 MB |
| Free disk | 300 MB | — |
