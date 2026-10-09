=== Certificate Generator – Bulk PDF Certificates, Email & QR Verification ===
Contributors: eshaan7127
Tags: certificate, certificates, pdf certificate, bulk certificate, verification
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 7.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Design certificate templates, import students from CSV, and issue, email and verify PDF certificates in bulk. No limits.

== Description ==

Certificate Generator turns a WordPress site into a complete certificate system for schools, training providers and course creators.

* **Templates**: upload your design (PNG/JPG, e.g. from Canva), place fields by drag and drop, pick from 27 built-in fonts.
* **Records**: add students, teachers and schools one by one, or import thousands from CSV. Export any time.
* **Issue in bulk**: generate PDFs (and shareable PNGs) for everyone at once and download them as ZIP files.
* **Email**: send each recipient their certificate, with your own templates, a background queue and hourly rate limits.
* **Verification**: every certificate gets a serial number and a QR code that opens a public verification page, plus an "Add to LinkedIn" button.
* **Search shortcodes**: let students, teachers and schools find and download their own certificates.
* **Analytics, email logs and renewal reminders.**

There are no caps on certificates, emails, imports or templates, and nothing in this plugin is locked.

= Pro add-on =

An optional, separate **Certificate Generator Pro** plugin adds integrations with other plugins: auto-issue when a course is completed in Tutor LMS, LearnDash, LifterLMS, Sensei or LearnPress, or when a WooCommerce order completes. It also adds a front-end bulk ZIP download for schools, custom font (.ttf) upload, a REST API and priority support. See [plans](https://techlovev.in/certificate-generator/pricing). This plugin works fully without it.

= Source code =

Development happens in the open on GitHub: https://github.com/EshaanManchanda/TLV-Certificate-Generator

= Shortcodes =

* `[student_search]`, `[teacher_search]`, `[school_search]`: look up and download certificates.
* `[cg_verify_certificate]`: public verification page.

== Installation ==

1. Upload the plugin through **Plugins → Add New → Upload Plugin**, or search for "Certificate Generator", then activate it.
2. Open **Certificate Generator → Dashboard** and follow the four setup steps: create a template, add recipients, send yourself a test certificate, issue.
3. For reliable email, use an SMTP plugin such as WP Mail SMTP, or set SMTP / Sender.net / Mandrill under **Settings → Email Templates**.

== Frequently Asked Questions ==

= Is there a limit on how many certificates I can issue? =

No. Certificates, emails, CSV rows and templates are unlimited.

= Which characters can certificates print? =

The built-in fonts print Western European text (Windows-1252: accents, €). For other scripts, upload a Unicode font with the Pro add-on.

= Does the plugin send data anywhere? =

Only if you choose an external email service. See "External services" below.

= How do I report a security issue? =

Email techlovev@gmail.com. Please don't post it in the support forum.

== External services ==

This plugin does not contact any external service by default. It connects to one only when you choose that service as the email transport under **Settings → Email Templates**:

* **Sender.net** (`https://api.sender.net`): when Sender.net is selected, each certificate email (recipient address, subject, body and the PDF attachment) is sent through Sender.net's API using your API key. [Terms](https://www.sender.net/terms-of-use/), [Privacy policy](https://www.sender.net/privacy-policy/).
* **Mailchimp Transactional / Mandrill** (`https://mandrillapp.com`): when Mandrill is selected, each certificate email (same data as above) is sent through Mandrill's API using your API key. [Terms](https://mailchimp.com/legal/terms/), [Privacy policy](https://www.intuit.com/privacy/statement/).

The **Feedback** page links to Google Forms; nothing is sent unless you open the link and submit the form yourself.

QR codes are generated on your server. Fonts and the analytics chart library (Chart.js) are bundled with the plugin.

== Screenshots ==

1. Template editor with drag-and-drop field placement.
2. Bulk import report.
3. Download all certificates as ZIP files.
4. Public verification page.

== Changelog ==

= 7.6.0 =
* Release as a free plugin with no usage limits. Pro features moved to a separate add-on.
* Open-licensed fonts replace bundled commercial fonts; saved templates keep working.
* Chart.js is bundled; the external QR fallback service was removed.
* LMS (Tutor LMS, LearnDash, LifterLMS, Sensei, LearnPress) and WooCommerce auto-issue moved to the Pro add-on, with LearnPress support and "Issue to past completions" added there.

Full history: https://github.com/EshaanManchanda/TLV-Certificate-Generator/blob/main/doc/CHANGELOG.md

== Upgrade Notice ==

= 7.6.0 =
LMS and WooCommerce auto-issue now need the Certificate Generator Pro add-on. Your course mappings are kept and work again once the add-on is active.
