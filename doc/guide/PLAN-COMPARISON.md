# Certificate Generator — Plan Comparison

[← Back to README](../../README.md) · Full guide: [`documentation.md`](documentation.md) · [`FAQ.md`](FAQ.md#licensing--pricing)

Since 7.6 the plugin comes in two parts:

- **Certificate Generator** (free, WordPress.org): the complete plugin. Nothing in it is capped
  or locked, and it never contacts the license server.
- **Certificate Generator Pro** (paid add-on, separate plugin `certificate-generator-pro`): adds
  the Pro and Business features below and holds the license code
  (`certificate-generator-pro/includes/license-manager.php`, `CG_License_Manager::get_plan_features()`).
  If you change what a plan includes, update that method and this file.

No plan has a monthly certificate, email or import/export limit.

## Pricing

| Plan         | Price          | How to get it |
|--------------|----------------|---------------|
| **Free**     | $0             | Install Certificate Generator. No license key. |
| **Pro**      | **$3.5/month** ($35/year) | Install the Pro add-on and activate a key starting `PRO-` on **Settings → License** |
| **Business** | Custom — contact for pricing | [Contact us](mailto:techlovev@gmail.com). Pro add-on with a key starting `BIZ-` |

Pricing page: [`https://techlovev.in/certificate-generator/pricing`](https://techlovev.in/certificate-generator/pricing).
Inside the Pro add-on every "Upgrade" link uses `CG_License_Manager::get_upgrade_url()`
(filter `cg_upgrade_url`). The free plugin only links there from its row on the Plugins screen,
and hides that link once the add-on is active. The license server (`cg_license_server_url`,
default `https://tlvapi.techlovev.in`) is the backend API and has no pricing page.

## Feature matrix

✔ = included, — = not included.

| Feature | Free | Pro | Business |
|---|:---:|:---:|:---:|
| PDF generation, manual issue, 27 built-in fonts | ✔ | ✔ | ✔ |
| Students / teachers / schools, CSV import & export (no row limit) | ✔ | ✔ | ✔ |
| Certificate email, bulk send and queue (no monthly limit) | ✔ | ✔ | ✔ |
| Email templates (per type, placeholders, CC/BCC) | ✔ | ✔ | ✔ |
| Recipient renewal reminders (`CG_USE_RENEWAL_REMINDERS`) | ✔ | ✔ | ✔ |
| Search shortcodes (`[student_search]`, `[teacher_search]`, `[school_search]`) | ✔ | ✔ | ✔ |
| Verification / QR lookup (`[cg_verify_certificate]`) | ✔ | ✔ | ✔ |
| Analytics, serial numbers | ✔ | ✔ | ✔ |
| Digital badges, image / e-signature fields | ✔ | ✔ | ✔ |
| Admin "Download Certificates" ZIP page | ✔ | ✔ | ✔ |
| Rendering and removing uploaded custom fonts | ✔ | ✔ | ✔ |
| LMS / e-commerce auto-issue (Tutor, LearnDash, LifterLMS, Sensei, LearnPress, WooCommerce), incl. "Issue to past completions" | — | ✔ | ✔ |
| School bulk ZIP shortcode `[school_bulk_certificate_download]` | — | ✔ | ✔ |
| REST API (`certificate-generator/v1`) and the API Settings tab | — | ✔ | ✔ |
| Priority support | — | ✔ | ✔ |
| Custom font upload (.ttf) | — | ✔ | ✔ |

## How the add-on plugs in

The free plugin exposes these hooks; the Pro add-on uses them and nothing else:

| Hook | Free plugin | Pro add-on |
|---|---|---|
| `cg_settings_tabs` (filter) | Settings page tab list | Adds **API Settings** and **License** |
| `cg_settings_tab_{key}` (action) | Renders any tab it doesn't own | Renders the API and License tabs |
| `cg_fonts_page_message` (filter) | Fonts page notice | Handles a .ttf upload, returns the result |
| `cg_fonts_page_upload_card` (action) | Fonts page, above the lists | Upload form (Pro) |
| `cg_feature_flags` (filter) | Feature toggles on Settings → Features | Adds the six integration toggles (Pro license) |
| `cg_admin_menu_integrations` (action) | Fires in the plugin menu, before the Advanced items | Adds the LMS / WooCommerce mapping pages (Pro license) |
| `CG_PRO_VERSION` (constant) | Hides the "Pro add-on" link on the Plugins screen | Defined when the add-on loads |

## License keys (local/offline fallback — development only)

Keys are always checked against the license server (default `https://tlvapi.techlovev.in`).
Clearing the server URL in the admin no longer switches to offline keys; it falls back to
the default server. Offline prefix keys work only when `define( 'CG_LICENSE_LOCAL_KEYS', true );`
is set in `wp-config.php`, for development. Licenses already activated offline before this
change keep working. In that mode, keys are validated locally by prefix:

| Prefix | Plan | Expiry |
|---|---|---|
| `PRO-...` | Pro | +1 year from activation (or `2099-12-31` if key ends `-LIFETIME`/`-LOCAL`) |
| `BIZ-...` | Business | +1 year from activation (or `2099-12-31` if key ends `-LIFETIME`/`-LOCAL`) |

When a remote license server *is* configured, keys are validated against
`POST {server}/api/payments/activate-remote` instead, and the plan/expiry come from that response.
The add-on re-checks the key twice a day (`cg_license_heartbeat` cron, with a 12-hour
`admin_init` fallback). It no longer reports usage counts to the server.
