# Documentation

[← Back to README](../README.md)

These docs are for this repository and are not shipped in the plugin ZIP. Inside WordPress, the same user guide is at **Certificate Generator → Documentation**.

## `guide/` — using the plugin

| Doc | Covers |
|---|---|
| [documentation.md](guide/documentation.md) | **Start here.** Full guide: setup, admin pages, email & bulk send, shortcodes, REST API, configuration, database, plans, troubleshooting, FAQ |
| [PLAN-COMPARISON.md](guide/PLAN-COMPARISON.md) | Free vs Pro vs Business, pricing, how the Pro add-on plugs in, license keys |
| [FAQ.md](guide/FAQ.md) | Sending, templates, backups, plans, debugging |
| [SHORTCODES.md](guide/SHORTCODES.md) | Front-end shortcodes |
| [CONFIGURATION.md](guide/CONFIGURATION.md) | Settings and options, feature flags, LMS / WooCommerce (Pro), badges, uninstall |
| [LIMITS.md](guide/LIMITS.md) | Server requirements, batch sizes, large imports and sends |

## `dev/` — developing and releasing

| Doc | Covers |
|---|---|
| [ARCHITECTURE.md](dev/ARCHITECTURE.md) | Free/Pro split and hooks, `includes/` vs `src/`, directory structure, feature flags, key files |
| [DATABASE.md](dev/DATABASE.md) | Custom tables, legacy tables, migrations, uninstall |
| [REST-API.md](dev/REST-API.md) | Free verify route and the Pro integration API |
| [TESTING.md](dev/TESTING.md) | PHPUnit (free and Pro), static analysis, Plugin Check, Test Center, Playwright E2E |
| [MANUAL-TEST-CHECKLIST.md](dev/MANUAL-TEST-CHECKLIST.md) | Click-through release checklist (the E2E suite automates it) |
| [PERFORMANCE.md](dev/PERFORMANCE.md) | Measured performance and tuning |

## Also here

- [CHANGELOG.md](CHANGELOG.md) — version history (`readme.txt` points here).
- `samples/` — sample import CSVs: `students.csv`, `teacher.csv`, `school.csv`, `certificates.csv`.
- [../SECURITY.md](../SECURITY.md) — reporting a vulnerability, verifying a release ZIP.
