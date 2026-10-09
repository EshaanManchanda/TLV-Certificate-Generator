# REST API

[← Back to README](../../README.md) · Full guide: [`documentation.md`](../guide/documentation.md#rest-api) · Plans: [`PLAN-COMPARISON.md`](../guide/PLAN-COMPARISON.md) · Options: [`CONFIGURATION.md`](../guide/CONFIGURATION.md)

| Route | Plugin | Plan |
|---|---|---|
| `GET certificate-generator/v1/verify/{serial}` | Free | Every plan, no auth |
| `certificate-generator/v1/*` (issue, health, keys, lookup) | Pro add-on | Pro or Business |
| `POST cg/v1/verify-license` | Pro add-on | Admin only |

## `certificate-generator/v1/verify/{serial}` (free)

Public serial-number verification (`GET`, no auth — `permission_callback: __return_true`). Implementation: `src/Services/SerialNumberService.php`. The old duplicate in `includes/Services/serial-generator.php::register_api_endpoints()` is never called (see [`ARCHITECTURE.md`](ARCHITECTURE.md#known-cleanup-items)).

Response: `id`, `student_name`, `certificate_type`, `issued_at`, `expires_at`, `serial_number`, `created_at`, plus `pdf_url` (the certificate's PDF, looked up from `wp_cg_certificates` by serial) and `issuer_name` (`get_bloginfo( 'name' )`). The last two power the verification page's **"LinkedIn — Add to Profile"** button.

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
