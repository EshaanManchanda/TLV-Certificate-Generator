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
90 days. A weekly cron job automatically deletes older entries. Change this by editing the
`certificate_generator_cleanup_email_logs()` call in `includes/Email/log.php`.

## Templates & certificates

**How do I change the certificate PDF design?**
Edit the template in **Certificate Generator → Templates**. Upload a new background image and
reposition the text fields. Future PDFs use the updated design — previously generated PDFs are
not changed.

**Can students verify their certificate online?**
Yes. Every certificate has a QR code linking to `/verify-certificate/`. Visitors can also search
by serial number or email on the results page (`/result/`). This works on every plan — see
[Licensing](#licensing--pricing) below.

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
Yes — the public verification result page includes a "LinkedIn — Add to Profile" button.

**Can I add a digital badge alongside the PDF certificate?**
Yes — set a badge image on a certificate template and a companion PNG badge is generated for
every certificate issued from it, on every plan.

**Does upgrading or downgrading affect my existing data?**
No. There are no limits to hit, and nothing is deleted when you downgrade or deactivate the Pro
add-on. Pro features stop working, but their data stays: LMS / WooCommerce course mappings come
back when the add-on is reactivated, and uploaded fonts keep rendering.

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
