=== Certificate Generator – Bulk PDF Certificates, Email & QR Verification ===
Contributors: eshaan7127
Tags: certificate, certificates, pdf certificate, bulk certificate, verification
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 7.9.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Design certificate templates, import students from CSV, and issue, email and verify PDF certificates in bulk. No limits.

== Description ==

Certificate Generator turns a WordPress site into a complete certificate system for schools, training providers and course creators.

* **Templates**: upload your design (PNG/JPG, e.g. from Canva), place fields by drag and drop, pick from 27 built-in fonts per field, and add custom text with {field} tags.
* **Any language**: names in Hindi, Marathi, Bengali, Gujarati, Punjabi, Tamil, Telugu, Kannada, Malayalam, Odia, Urdu and Arabic print with properly joined letters.
* **Records**: add students, teachers and schools one by one, or import thousands from CSV. The import shows how each column maps and what will change before anything is saved. Export any time.
* **Issue in bulk**: generate PDFs (and shareable PNGs) for everyone at once and download them as ZIP files.
* **Email**: send each recipient their certificate, with your own templates, a background queue and hourly rate limits.
* **Verification**: every certificate gets a serial number and an unguessable verification code. Its QR code opens a public verification page with an "Add to LinkedIn" button.
* **History, revoke and reissue**: see everything that happened to a certificate, revoke or reinstate it, and reissue a corrected one with the same serial and code.
* **Search shortcodes**: let students, teachers and schools find and download their own certificates.
* **My Certificates**: recipients open their own certificates with an emailed link, share them on LinkedIn, X or WhatsApp, and choose whether their name shows when someone verifies.
* **Privacy tools**: WordPress's Export and Erase Personal Data include the plugin's records.
* **Analytics, email logs and renewal reminders.**

There are no caps on certificates, emails, imports or templates, and nothing in this plugin is locked.

= Pro add-on =

An optional, separate **Certificate Generator Pro** plugin adds integrations with other plugins: auto-issue when a course is completed in Tutor LMS, LearnDash, LifterLMS, Sensei or LearnPress, or when a WooCommerce order completes. It also adds a front-end bulk ZIP download for schools, custom font (.ttf) upload, a REST API and priority support. See [plans](https://techlovev.in/certificate-generator/pricing). This plugin works fully without it.

= Source code =

Development happens in the open on GitHub: https://github.com/EshaanManchanda/TLV-Certificate-Generator

= Shortcodes =

* `[certificate_generator_student_search]`, `[certificate_generator_teacher_search]`, `[certificate_generator_school_search]`: look up and download certificates.
* `[certificate_generator_my_certificates]`: a recipient's own certificates, opened with an emailed sign-in link.
* `[certificate_generator_verify_certificate]`: public verification page.

== Installation ==

1. Upload the plugin through **Plugins → Add New → Upload Plugin**, or search for "Certificate Generator", then activate it.
2. Open **Certificate Generator → Dashboard** and follow the four setup steps: create a template, add recipients, send yourself a test certificate, issue.
3. For reliable email, use an SMTP plugin such as WP Mail SMTP, or set SMTP / Sender.net / Mandrill under **Settings → Email Templates**.

== Frequently Asked Questions ==

= Is there a limit on how many certificates I can issue? =

No. Certificates, emails, CSV rows and templates are unlimited.

= Which characters can certificates print? =

Any language. Names in Hindi, Marathi, Bengali, Gujarati, Punjabi, Tamil, Telugu, Kannada, Malayalam, Odia, Urdu and Arabic print with joined letters, as do names like Łukasz, Иван or O’Brien. The plugin switches those certificates to a Unicode PDF engine (mPDF) and bundled fonts on its own; Latin text keeps the template's font. PNG downloads of certificates with Indian or Arabic script give the PDF instead, because PNG drawing can't join those letters.

= Can I export or erase a person's data? =

Yes. Tools → Export Personal Data and Tools → Erase Personal Data include the plugin's student and teacher records, certificates and email logs. Erasing anonymises the person's certificates instead of deleting them, so a printed certificate still verifies, with the name shown as [erased].

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

== Credits ==

* [mPDF](https://github.com/mpdf/mpdf) (GPL-2.0) renders certificates with non-Latin text.
* Fonts in `assets/fonts/unicode`: GNU FreeFont FreeSerif (GPL-2.0-or-later with font exception), Lohit Telugu and Lohit Kannada (SIL Open Font License 1.1), Noto Sans Oriya (SIL Open Font License 1.1). Licence texts are next to the fonts.

== Screenshots ==

1. Template editor with drag-and-drop field placement.
2. Bulk import report.
3. Download all certificates as ZIP files.
4. Public verification page.

== Changelog ==

= 7.9.4 =
* New: QR code and Serial number are Field Slot types in the template editor. The serial gets its own font, size, colour, alignment and text before it ("Certificate No."); the QR code is always square, sized in mm. Older templates move their settings into slots when opened and saved.

= 7.9.3 =
* Fix: certificate expiry dates are saved, so verification, renewal reminders and the expiring-soon email see them.
* Fix: "Send" on Bulk Send starts sending at once in the background instead of waiting for the next 5-minute run.
* Fix: re-generating a revoked or emailed certificate's PDF no longer resets its status; emailed certificates are marked "sent".
* Fix: the email rate limit counts in your site's time zone.
* Fix: {result_link} opens your certificate search page (created if missing), and the "View Your Results Online" button is no longer added twice.
* Fix: subdirectory sites return to the right page after asking for a My Certificates link.
* New: developer hooks for recipient saved / deleted, import completed, certificate issued / reissued / expired and email logged (used by Pro webhooks).
* New: bulk generation runs in the background with a progress page, failed-item list and Retry; no 200-certificate limit. "Regenerate PDFs" bulk action.
* New: filter recipients by PDF status (none yet, file missing, present).
* New: Import history — flag duplicate rows for review, and undo an import (records changed since are kept and listed).
* New: Diagnostics page (Tools) and WP-CLI commands: generate, import, jobs, diagnostics.
* New: email tags {verification_code} and {certificate_link}; LinkedIn "Add to Profile" includes the expiry date.

= 7.9.2 =
* Fix: no more "translation loading triggered too early" notice when another plugin schedules a task early.
* New: a delivery log table and a certificate id on the "certificate generated" hook, used by the Pro add-on's WhatsApp and webhooks.

= 7.9.1 =
* Fix: two students with the same name who finished on the same day could be given the same serial number.
* Fix: Email Logs can resend a failed email sent by an LMS integration (the Pro add-on), one at a time or with "Resend all failed".
* Fix: "View Certificate" in Email Logs opens the recipient on the Students page. It used to open an unrelated page.

= 7.9.0 =
* New: a School Coordinator role. An admin picks the coordinator's school on their user profile; they can view, edit, import, download and email certificates for that school's students and teachers only. Four capabilities for role-editor plugins; admins keep everything.
* New: My Certificates page ([certificate_generator_my_certificates]). Recipients get an emailed sign-in link (no account needed) to download their certificates, share them, and choose whether their name shows when someone verifies a certificate. A setting makes the student search require the link too.
* New: share buttons on the verify page (X, WhatsApp, copy link, alongside LinkedIn), link previews when a verify link is posted, and an optional LinkedIn organization ID.
* New: Tools → Export / Erase Personal Data include this plugin's records. Erased certificates are anonymised, so printed ones still verify.
* New: Certificate Register: every issued certificate with its serial, verification code, school, dates and status, filtered and downloaded as a spreadsheet or a printable PDF (non-Latin names included).
* New: the verification page is created automatically and re-created if deleted. Before, a site without one got QR codes pointing at the home page.
* New: the setup checklist covers the verification page and checking a certificate, and can be hidden and shown again.
* Fix: text is now placed where the template editor shows it. It printed about a third of its height too high; long text wraps with even spacing. Check templates you moved up to compensate.
* Fix: PNG downloads match the PDF (text was too large and shifted right).
* Fix: less debug logging, and recipients' names and emails are no longer written to the debug log.

= 7.8.0 =
* New: reissue a certificate to correct it. It keeps its serial number and verification code, gets a new revision, and the verify page shows "Corrected on …". The reason is recorded in the certificate's history.
* New: issued certificates keep a snapshot of their template layout and data, so editing a template later no longer changes certificates already issued.
* New: each template field can have its own font, size, colour and UPPERCASE setting.
* New: custom-text fields that mix your own words with {field} tags, e.g. "Awarded to {name} for {event}".
* New: names in Indian scripts, Urdu/Arabic and any other non-Western-European text print correctly (joined conjuncts, connected Arabic). Before, they printed as "????".
* Fix: PNG downloads of templates using Helvetica, Times or Courier showed no text.
* New: bulk import checks the file before saving anything. It shows how each column maps (and lets you change it), how many rows are new, update a record, merge or will be skipped, the first rows as they'll be saved, and names too long for their template field.
* New: import reads semicolon- and tab-separated files and Excel's Windows encoding, recognises common header names ("Name", "Email Address", "School", …), and asks for the date order when dates like 03/04/2026 could mean either. You can choose to leave existing records unchanged.
* New: "Download a sample CSV" on each import tab, and Preview Certificate can use a real student's data and QR code.

= 7.7.0 =
* Security: each certificate now has an unguessable 12-character verification code, and new QR codes link to it. Checking a bare serial number still confirms the certificate but hides most of the name, because serial numbers run in sequence and could be stepped through to collect names. To show full details for serial-only checks again, set the option certificate_generator_verify_serial_full_details to 1. Existing certificates get their codes automatically on update.
* New: per-certificate history (issued, name corrected, revoked, reinstated, verified). Open it from the Revoke page or by clicking a serial number on the Students or Teachers list.
* Fix: revoking or reinstating a certificate now updates both certificate tables, and certificates recorded only in the newer table now verify.
* Fix: sent emails are now cleared from the email queue on the same schedule as email logs (90 days by default).
* Fix: "Delete data on uninstall" now removes every plugin table.
* New hooks: certificate_generator_certificate_revoked and certificate_generator_certificate_reinstated.

= 7.6.0 =
* Release as a free plugin with no usage limits. Pro features moved to a separate add-on.
* Open-licensed fonts replace bundled commercial fonts; saved templates keep working.
* Chart.js is bundled; the external QR fallback service was removed.
* LMS (Tutor LMS, LearnDash, LifterLMS, Sensei, LearnPress) and WooCommerce auto-issue moved to the Pro add-on, with LearnPress support and "Issue to past completions" added there.
* Shortcodes renamed to unique names: [student_search] is now [certificate_generator_student_search], [teacher_search] is [certificate_generator_teacher_search], [school_search] is [certificate_generator_school_search], and [cg_verify_certificate] is [certificate_generator_verify_certificate]. Existing pages are updated automatically.
* All settings, serial counters and scheduled tasks move to the certificate_generator_ prefix automatically on update.

Full history: https://github.com/EshaanManchanda/TLV-Certificate-Generator/blob/main/doc/CHANGELOG.md

== Upgrade Notice ==

= 7.9.4 =
QR code and Serial number become Field Slots with their own font, size, colour and alignment; open and save older templates to move their settings. Includes everything in 7.9.3 (bulk jobs, import history and undo, diagnostics, WP-CLI).

= 7.9.3 =
Saves certificate expiry dates, starts Bulk Send at once, and adds lifecycle hooks. Required by Certificate Generator Pro 1.8.0 (scoped API keys, webhooks v2).

= 7.9.2 =
Fixes an early-translation notice. Required by Certificate Generator Pro 1.7.0 (WhatsApp delivery, webhooks).

= 7.9.1 =
Fixes a shared serial number for same-name students finishing on the same day, and resending failed LMS emails from Email Logs. Required by Certificate Generator Pro 1.5.0.

= 7.9.0 =
Text on certificates now sits exactly where the template editor shows it, slightly lower than before: check templates you nudged up to compensate. Adds a School Coordinator role, a My Certificates page, a certificate register and privacy tools.

= 7.8.0 =
Adds certificate reissue, per-field fonts and custom-text fields. Issued certificates now keep their layout when a template is edited later.

= 7.7.0 =
Certificates get unguessable verification codes, and new QR codes use them. A bare serial number now shows a masked name, so older printed QR codes still verify but show a masked name. Codes for existing certificates are added automatically.

= 7.6.0 =
Shortcodes are renamed (e.g. [student_search] is now [certificate_generator_student_search]); your pages, settings and serial numbers are updated automatically. LMS and WooCommerce auto-issue now need the Pro add-on.
