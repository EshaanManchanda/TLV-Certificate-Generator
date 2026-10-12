# REST API

[← Back to README](../../README.md) · Full guide: [`documentation.md`](../guide/documentation.md#rest-api) · Plans: [`PLAN-COMPARISON.md`](../guide/PLAN-COMPARISON.md) · Options: [`CONFIGURATION.md`](../guide/CONFIGURATION.md)

| Route | Plugin | Plan |
|---|---|---|
| `GET certificate-generator/v1/verify/{code or serial}` | Free | Every plan, no auth |
| `certificate-generator/v1/*` (issue, health, keys, lookup) | Pro add-on | Pro or Business |
| `POST cg/v1/verify-license` | Pro add-on | Admin only |

## `certificate-generator/v1/verify/{serial}` (free)

Public verification (`GET`, no auth — `permission_callback: __return_true`). Implementation: `SerialNumberService::verify()` in `src/Services/SerialNumberService.php`. The old duplicate in `includes/Services/serial-generator.php::register_api_endpoints()` is never called (see [`ARCHITECTURE.md`](ARCHITECTURE.md#known-cleanup-items)).

The path segment can be either:

- **a verification code** (12 characters, Crockford Base32 with a check character, shown as `XXXX-XXXX-XXXX`; `src/Services/VerificationCodeService.php`). This is what the QR code carries. Case, spaces and dashes don't matter, and I/L/O are read as 1/1/0. A code with a wrong check character is rejected before any database query. A code gets the **full** answer.
- **a serial number**. Serials run in sequence and could be stepped through, so a serial alone gets the status and dates with a **masked** name (`R**** S****`), unless the option `certificate_generator_verify_serial_full_details` is `1`.

Response: `valid`, `message`, and `data` with `student_name` (masked for serial-only lookups), `certificate_type`, `issued_at`, `expires_at`, `serial_number`, `issuer_name` (`get_bloginfo( 'name' )`), `masked`, `corrected_at` (set after a reissue) and `verification_code` (full answers only). A revoked certificate returns `valid: false`, `revoked: true` and `revoked_at`; the reason is included for full answers. Expired ones return `expired: true`. No PDF URL or internal ids are returned. Lookups by visitors (not admins) are recorded as `verified` events in the certificate's history, without IP addresses; requests are rate-limited per IP by `SecurityHelper::check_rate_limit()`.

## `certificate-generator/v1` integration API (Pro add-on)

Defined in `certificate-generator-pro/includes/rest-api.php`; key and on/off switch on **Settings → API Settings** (`certificate-generator-pro/includes/api-settings.php`). Requires a bearer token (`certificate_generator_api_key` option, enabled with `certificate_generator_api_key_enabled`) unless noted. Calls on the Free plan, or with the add-on inactive, get `plan_required` / 404.

| Method | Route | Auth | Notes |
|---|---|---|---|
| `POST` | `/issue-certificate` | Bearer token | Generates and emails/zips certificates by student email. |
| `GET` | `/health` | None | Liveness check. |
| `POST` | `/validate-key` | Bearer token | Validates an API key. |
| `GET` | `/certificates-by-email` | Bearer token | Read-only lookup — does not regenerate certificates. |

## `cg/v1/verify-license` (Pro add-on)

`POST`, admin only (`manage_options`). Checks the site's license key against the license server (`https://tlvapi.techlovev.in`). Defined in `certificate-generator-pro/includes/payment-endpoints.php`.
