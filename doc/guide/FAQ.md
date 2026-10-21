# FAQ

[← Back to README](../../README.md) · Full guide: [`documentation.md`](documentation.md)

Mirrors the **Certificate Generator → Documentation → FAQ** tab in wp-admin
(`includes/Admin/documentation.php`), plus a few licensing questions covered in
[`PLAN-COMPARISON.md`](PLAN-COMPARISON.md).

## Sending & recipients

**Can I send certificates to teachers and schools too, not just students?**
Yes. The Bulk Send page lets you choose the entity type (students / teachers / schools) before
filtering. Each entity type has its own email template, configurable under
**Settings → Email**.

**Will re-sending skip students who already received their certificate?**
Yes — the "Skip already sent" checkbox (checked by default) checks the email log and skips any
recipient who already has a `sent` entry for their certificate ID.

**Can I use a Gmail account to send emails?**
Yes, but Gmail requires an App Password, not your regular login. In WP Mail SMTP: choose Gmail
as the mailer, either follow the OAuth flow or use SMTP with host `smtp.gmail.com`, port 587,
and your App Password.

**What happens if an email fails to send?**
The queue retries up to 3 times with exponential backoff. After 3 failures the item is marked
`failed` in the queue and logged in Email Logs with the error message. You can reset failed
items from the queue management section.

**How long are email logs kept?**
90 days by default. A weekly cron job deletes older email logs, sent queue rows and "verified"
entries in certificate history. Change the period with the `certificate_generator_log_retention_days`
option (e.g. `wp option update certificate_generator_log_retention_days 180`).

## Templates & certificates

**How do I change the certificate PDF design?**
Edit the template in **Certificate Generator → Templates**. Upload a new background image and
reposition the text fields. Certificates issued from now on use the new design. Certificates
already issued keep the layout they were issued with (a snapshot), so re-downloading them gives
the same certificate. To apply a corrected template to an issued certificate, open its history
(**Revoke Certificate** page, or click its serial on the Students/Teachers list) and use
**Reissue**: it keeps the serial and verification code, and the verify page shows "Corrected on …".

**Can students verify their certificate online?**
Yes. Every certificate has a QR code with an unguessable 12-character verification code; it
opens the verification page and shows the full result. Typing a serial number alone also works,
but shows the name masked (serials run in sequence, so they could be stepped through to collect
names). This works on every plan — see [Licensing](#licensing--pricing) below.

**Which languages can certificates print?**
Any. Names and text in Hindi, Marathi, Bengali, Gujarati, Punjabi, Tamil, Telugu, Kannada,
Malayalam, Odia, Urdu and Arabic print with joined letters, as do Ł, Cyrillic or curly quotes.
Certificates with that kind of text switch automatically to a Unicode PDF engine (mPDF) with
bundled fonts; Latin text keeps the template's font. A PNG download of a certificate in an
Indian script or Arabic gives the PDF instead, because PNG drawing can't join those letters.

**What does the import check do?**
**Bulk Import → Upload and check** reads the whole file first and saves nothing. It shows how
each column maps (you can change it), required columns still missing, how many rows are new,
update a record, merge or will be skipped, names too long for their template field, and the
first rows as they'll be saved. Dates like 03/04/2026 must have their order chosen. Then
**Import** saves exactly what the check counted.

## Data & backups

**How do I back up all certificate data?**
Use Bulk Export (CSV) to back up all records. The custom tables (`wp_certificate_generator`,
`wp_cg_*`, `wp_cert_email_logs`) are also included in any full database backup.

**How do I update the plugin without losing data?**
Plugin updates only replace PHP/JS/CSS files — they do not drop or truncate database tables.
Certificate records, email logs, and settings are preserved across updates. Always take a
database backup before major updates regardless.

**Is the plugin multisite compatible?**
Partially. The plugin is designed for single-site use. Network activation requires the
**Business** plan; Free/Pro network-activated installs are allowed but each site tracks its own
usage independently, and this configuration hasn't been formally tested at scale.

## Licensing & pricing

**What does the free plugin include?**
Everything in this repository, with no limits: certificates, emails, CSV import/export, email
templates, QR verification, analytics, serial numbers and renewal reminders.
It never contacts the license server. See [`PLAN-COMPARISON.md`](PLAN-COMPARISON.md).

**How much does Pro cost, and what does it add?**
Pro is **$3.5/month** (or **$35/year**). It's a separate add-on plugin (Certificate Generator
Pro); install it next to the free plugin and activate a `PRO-...` key on **Settings → License**.
It adds LMS / WooCommerce auto-issue, custom `.ttf` font upload, the `[school_bulk_certificate_download]`
shortcode, the REST API and priority support.

**What does Business include, and how do I get it?**
Everything in Pro, on custom terms. It's not self-serve:
[email us](mailto:techlovev@gmail.com).

**Does this plugin work with my LMS?**
With the Pro add-on, yes, if it's Tutor LMS, LearnDash, LifterLMS, LearnPress or Sensei LMS — each has its own mapping page
(**Certificate Generator → [platform name]**) to link a course to a certificate template. There's
also a WooCommerce integration that issues a certificate on order completion. These need a Pro license;
each is off until you map at least one course/product. See
[LMS / e-commerce auto-issuance](documentation.md#lms--e-commerce-auto-issuance) in the full
guide.

**Can students add their certificate to LinkedIn?**
Yes — a full verification result (opened from the QR code) includes a "LinkedIn — Add to
Profile" button.

**Can I add a digital badge alongside the PDF certificate?**
Yes — set a badge image on a certificate template and a companion PNG badge is generated for
every certificate issued from it, on every plan.

**Does upgrading or downgrading affect my existing data?**
No. There are no limits to hit, and nothing is deleted when you downgrade or deactivate the Pro
add-on. Pro features stop working, but their data stays: LMS / WooCommerce course mappings come
back when the add-on is reactivated, and uploaded fonts keep rendering.

## Bulk jobs and imports

**I imported the wrong file. Can I undo it?**
Yes: **Bulk Import → Import history**, open the import, check the preview and click **Undo
import**. Records that got a certificate or an email, or were edited since, are kept and listed.

**A bulk job is stuck at "stalled".**
WP-Cron isn't running. Keep the job page open (it runs the job), or run
`wp certificate-generator jobs run <id>`; for a lasting fix see **Tools → Cert Gen Diagnostics**.

**Will "Retry failed items" or generating twice create duplicate certificates?**
No. A record keeps the serial of its first certificate, and an unchanged PDF is reused.

**How do students get their verification code?**
From the QR code on the certificate, the My Certificates page, or the email: add
`{verification_code}` or `{certificate_link}` to the email template.

## Debugging

**Where do I turn on debug logging?**
Add to `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```
Then check `wp-content/debug.log` after triggering a send or import — lines prefixed
`Certificate Generator:` (and `[CG Email]` for mail-specific issues) come from this plugin.

For more involved issues (emails not sending, "0 certificates queued", PDF generation failures,
ZIP problems), see the [Troubleshooting section](documentation.md#troubleshooting) of the full
guide.
