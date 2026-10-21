# Manual Test Checklist

[← Back to README](../../README.md) · Full guide: [`documentation.md`](../guide/documentation.md) · [Free vs Pro](../guide/PLAN-COMPARISON.md)

A click-through check of every feature. Tick each box as you go. If a step fails, note the
section number and what you saw (a screenshot helps). Automated tests are covered separately in
[`TESTING.md`](TESTING.md).

**Before you start**
- [ ] The site is running in Local, and you are logged in as an Administrator.
- [ ] `WP_DEBUG` and `WP_DEBUG_LOG` are `true` in `wp-config.php`. Keep `wp-content/debug.log` open and look for new errors after each section.
- [ ] You have the sample CSVs from `assets/samples/` (`students.csv`, `teachers.csv`, `schools.csv`, `templates.csv`), or downloaded them from **Documentation → User Guide**.
- [ ] A test inbox is set up (e.g. a Mailtrap/MailHog SMTP account, or the WP Mail SMTP plugin in test mode). Don't email real people.

**Test data used below.** Create these as you go and reuse them:
| Name | Type | Certificate type | Event / issue date | Email |
|---|---|---|---|---|
| Asha Multi | Student | Participation | 10-01-2026 | asha@test.local |
| Asha Multi | Student | Participation | 15-03-2026 | asha@test.local |
| Ben Winner | Student | Winner | 10-01-2026 | ben@test.local |
| Tara Teacher | Teacher | Mentor | 10-01-2026 | tara@test.local |
| Green Valley School | School | Participation | 10-01-2026 | school@test.local |
| No Mail Kid | Student | Participation | 10-01-2026 | *(blank)* |

---

## 1. Activation & upgrade
- [ ] Deactivate and reactivate the plugin: no errors, and the **Certificate Generator** menu appears with an award icon.
- [ ] **Tools / Migration page** (under Advanced, if shown) reports the DB version as **012**, with nothing pending.
- [ ] Reload any admin page twice: no "database error" notices and nothing new in `debug.log`.

## 2. Dashboard
- [ ] **Certificate Generator → Dashboard** loads and shows the **Getting Started** checklist.
- [ ] The checklist items tick off as you complete them (template created, data imported, certificate generated).
- [ ] Every link on the dashboard opens a real page (no 404s or blank pages).

## 3. Templates (Design)
- [ ] Create a **Participation** template with event date **10-01-2026**: upload a background, place name/school/date fields, and **Publish**.
- [ ] Create a second **Participation** template with event date **15-03-2026** and a *visibly different* background.
- [ ] Create a **Winner** template (any date) and a **Mentor** template for teachers (entity type = Teachers).
- [ ] **Preview** each template: the PDF opens, the fields sit where you placed them, and the serial area shows `PREVIEW-SERIAL`.
- [ ] **Preview with** a real record (the dropdown next to Preview Certificate): the PDF shows that record's values, and its QR code opens a valid verify result. Bulk Serials' counts don't change.
- [ ] Give field 1 its own font, size and colour, and tick UPPERCASE: the preview shows them; field 2 keeps the template defaults.
- [ ] Add a **Custom text with {fields}…** slot, e.g. `Awarded to {student_name} for {certificate_type}`: the preview fills both tags.
- [ ] After previewing, **Bulk Serials** still has the same "with serial" count as before (previews must not use up real serials).
- [ ] QR code toggle: turn it on, then preview. The QR appears at the configured position and size.
- [ ] Serial display toggle: turn it on, then preview. The serial text appears at the configured position.
- [ ] **Fonts**: pick a non-default font (e.g. Italianno) and preview. The font renders correctly, not as boxes or a fallback.
- [ ] List page: search, filter by type, paginate, and sort. Filters stay applied when changing pages.
- [ ] Bulk actions on templates: duplicate, delete one test copy. Refreshing the page (F5) afterwards must **not** repeat the action.

## 4. Students / Teachers / Schools (Entities)
Repeat this block for **Students**, then **Teachers**, then **Schools**.
- [ ] **Add new** via the form. The row appears in the list with the correct values.
- [ ] **Edit** a row, save, and check the changes stuck.
- [ ] Search by name and email. Filter by certificate type, date range, and event. Clear the filters.
- [ ] Pagination keeps the filters when moving between pages.
- [ ] Filter **template match status**: rows with a matching template vs. without one are split correctly.
- [ ] Filter **email status** (sent / not sent / no email).
- [ ] Bulk **Edit** (e.g. change school name for 2 rows): only the selected rows change.
- [ ] Bulk **Export CSV** for the selected rows, then for *"select all matching filter"*. The CSV opens and the row counts are right.
- [ ] Bulk **Generate** certificates for 2 rows: you land on the **Bulk Jobs** page, it reaches 100%, and the PDFs exist.
- [ ] Generate for *Select all matching* including a row with no template: the job finishes with that row under **Failed items** and its reason; fix the template, **Retry failed items**: only that row runs, and no other serial changes.
- [ ] **PDF status** filter: *No PDF yet* lists records never generated; delete one PDF file on the server, and it moves to *PDF file missing*.
- [ ] **Regenerate PDFs** on 2 rows: the PDFs are re-rendered (new file time), the serials are unchanged.
- [ ] Bulk **Email** 1 row: the test inbox receives it, and the list shows a green ✓ in the email column.
- [ ] Single-row **Send email** button: the status cell updates live without a page reload.
- [ ] Bulk **Duplicate to date**: copies rows to a new event date, and the originals stay unchanged.
- [ ] **Delete** one row (single) and two rows (bulk). A confirmation prompt appears, and only those rows go.
- [ ] Press **F5** right after any bulk action: the action is **not** repeated.
- [ ] Log in as a **Subscriber** and open the page URL directly: access is denied.

## 5. Events
- [ ] Create an event (name, code, date). It appears in the list.
- [ ] Edit and delete an event.
- [ ] Link students to the event, then filter the Students list by that event.

## 6. Bulk Import
Use `assets/samples/*.csv` (import `templates.csv` first) and a CSV of your own containing the test data table above.
- [ ] Each tab's **Download a sample CSV** opens in Excel with readable columns.
- [ ] **Upload and check** students.csv: the check shows the delimiter and encoding, every column's **Imports as**, the counts and the first rows, and says *Nothing has been saved yet*. The Students list is unchanged.
- [ ] Rename a header to `Pupil`: the check says *Required columns not mapped: student_name* and Import is disabled. Map the column by hand, **Check again**: Import is enabled.
- [ ] A file with dates like `03/04/2026`: the check asks for the date order. Choose day/month/year; after import the record's date is 3 April 2026.
- [ ] A semicolon-separated file saved from Excel as plain "CSV" (Windows encoding) with `José` in it: imports, and the name is right.
- [ ] **Leave the record unchanged** on a re-import: matching rows are counted as skipped and keep their old values.
- [ ] **Import**: the result counts equal the check's counts, and the rows appear in Students.
- [ ] Right after the import, the **upload form is still visible**, so you can import another file straight away.
- [ ] Press **F5** after an import: the CSV is **not** imported a second time (the row count is unchanged).
- [ ] Import the same person twice with **different event dates** (Asha Multi, 10-01-2026 and 15-03-2026). You get **two rows**, and neither overwrites the other.
- [ ] Re-import the *same* person + type + date. The existing row is updated or skipped, **not** duplicated.
- [ ] Import **teacher.csv** and **school.csv**. Rows land in the correct tables.
- [ ] Import **certificates.csv** (templates). The templates appear.
- [ ] Import a CSV with a **missing required column**: you get a clear error listing the missing field names, shown as plain text (no broken HTML).
- [ ] Import a CSV with a header like `<b>name</b>`: it's shown as text, not rendered as bold.
- [ ] Dates in both `dd-mm-yyyy` and `yyyy-mm-dd` import correctly.
- [ ] Re-import a file with **flag for review**: the rows are listed under **Import history** → the import; *Apply file values*, *Add as new record* and *Dismiss* each work.
- [ ] **Undo import** on a fresh import: the preview counts match; after undo the created rows are gone and the updated fields have their old values. Generate one of the imported rows first: undo keeps it and says *has an issued certificate*.

## 7. Generating certificates & template matching
- [ ] Generate for **Asha Multi, 10-01-2026**. The PDF uses the **10-01-2026 Participation** template.
- [ ] Generate for **Asha Multi, 15-03-2026**. The PDF uses the **15-03-2026** template (a different background).
- [ ] Generate for **Ben Winner**. The PDF uses the **Winner** template.
- [ ] Generate for **Tara Teacher**. The PDF uses the **Mentor** template.
- [ ] Generate for a person whose type/date has **no template**. You get a clear "no matching template" message, and **no** certificate with the wrong design.
- [ ] Names with accents (e.g. *José Ñúñez*) render correctly in the PDF.
- [ ] Non-Latin names render with joined letters: a Hindi name (*श्रीमती क्षत्रिय*), a Tamil name (*தமிழ்செல்வி*), an Urdu name (*محمد علی خان*, right to left) and a mixed one (*Ravi रवि Kumar*, Latin parts in the template font). Also *Łukasz* and *O’Brien*.
- [ ] Download a Hindi certificate as **PNG**: you get the PDF instead (named `.pdf`).
- [ ] A long name wraps or fits, and doesn't run off the page.
- [ ] If student photos are used: the photo appears; with no photo, the placeholder appears.

## 8. Serial numbers
- [ ] **Serial Settings**: prefix `CERT`, length 8, and no date. Save; the settings persist after a reload.
- [ ] After section 7, the certificates for Asha (×2), Ben and Tara all have **different** serials.
- [ ] Participation and Winner certificates do **not** share a number (e.g. not both `CERT-00000001`).
- [ ] In the Students list, **each** of Asha's rows has **its own** serial, and neither row's certificate type changed.
- [ ] Tara's serial is on the **Teachers** row, not on a student row with the same email.
- [ ] Re-generating the same certificate **reuses** its serial rather than creating a new one.
- [ ] **Bulk Serials** page: the stats show the "with / without serial" counts.
- [ ] Run **Generate Serial Numbers**. The progress bar completes, and only rows *without* a serial get one.
- [ ] After bulk generation, each row's **issue date is unchanged** (not reset to today).
- [ ] The **Recently Generated** table lists the new serials.
- [ ] The **Duplicate Serial Numbers** section loads. It says "No duplicate serial numbers found", or it lists older clashes showing who the verify page shows for each.
- [ ] A **Revoke…** link in the duplicate list opens Revoke Certificate with the serial already filled in.
- [ ] Try the date option: turn on "include date" with a yearly reset. New serials look like `CERT-2026-000000NN`.

## 9. Verification (public)
Create a page containing `[certificate_generator_verify_certificate]` and open it in a **logged-out / private window**.
- [ ] Scan or open the QR code link from Ben's PDF (`?code=…`). It shows **Valid** with Ben's full name, type and date, and his verification code.
- [ ] Type the code with lower case and without dashes: same result. Change one character: **Not found** (the check character rejects it).
- [ ] Enter Ben's **serial** instead: **Valid**, but the name is masked (`B** W*****`) and there's a note explaining why. No LinkedIn button.
- [ ] Enter Asha's two serials. Each shows a valid certificate with the correct event.
- [ ] An unknown serial shows **Not found**.
- [ ] The **LinkedIn – Add to Profile** button appears on a full (code) result and opens LinkedIn with the details pre-filled.
- [ ] Hit verify about 25 times within a minute. After about 20 you get a "too many attempts" message (the rate limit works).
- [ ] REST: `/wp-json/certificate-generator/v1/verify/<code>` returns JSON with `valid: true` and `masked: false`; `/verify/<serial>` returns `masked: true`. Neither contains a PDF URL.

## 10. Revoke, history and reissue
- [ ] **Revoke Certificate**: revoke Ben's serial with a reason. It shows up in the revoked list.
- [ ] The public verify page for Ben's code shows **Revoked** plus the reason (not "valid").
- [ ] Click **Reinstate**. Verify shows **Valid** again.
- [ ] A wrong serial gives the error "No certificate found".
- [ ] Open Ben's **History** (click his serial on the Students list): it lists issued, revoked, reinstated and the verifications from section 9, with dates.
- [ ] Edit Ben's template (e.g. move a field), then re-download his certificate: it is **unchanged** (issued certificates keep their layout).
- [ ] **Reissue** it with a reason: the new PDF uses the edited template, the serial and code stay the same, the history shows *reissued* with the reason, and the verify page shows "Corrected on …".

## 11. Search shortcodes (front end)
Create pages with `[certificate_generator_student_search]`, `[certificate_generator_teacher_search]`, `[certificate_generator_school_search]` and
`[school_bulk_certificate_download]`, and test them logged out.
- [ ] Student search by email or name finds Asha and lists **both** events, each downloading the right PDF.
- [ ] A certificate that isn't ready yet shows the **pending** notice instead of an error.
- [ ] Teacher search finds Tara, and the download works.
- [ ] School search finds Green Valley School, and the download works.
- [ ] School bulk download produces a ZIP of that school's certificates, and it opens.
- [ ] A search with no match shows a friendly "not found" message.

## 12. Bulk Send (email)
- [ ] **Bulk Send**: filter by certificate type, school, date and **event**. The recipient preview updates.
- [ ] The preflight shows the real recipient count **and** the "no email" count (No Mail Kid is counted there).
- [ ] Send to a filtered group of 2. Both arrive in the test inbox with the correct PDF attached or linked.
- [ ] The email placeholders (`{student_name}`, `{certificate_type}`, `{expires_at}`, …) are filled in, not shown raw.
- [ ] A second send to the same people doesn't create duplicate queue entries (or it warns first).
- [ ] **Settings → Send test email** arrives, and the log shows which mailer or SMTP sent it.

## 13. Email Logs
- [ ] **Email Logs** lists the sends from sections 4 and 12 with status, recipient and time.
- [ ] Filter and search the logs. A failed send (e.g. use an invalid address) shows as **failed** with a reason.

## 14. Bulk Export & Download
- [ ] **Bulk Export**: export students, teachers, schools and certificates to CSV. The files open, and the columns and dates are correct.
- [ ] The export includes the template column matching each row's type **and date** (Asha's two rows show two different templates).
- [ ] **Download Certs**: filter, then download selected certificates as a ZIP. The ZIP opens and the file names are readable.

## 15. Analytics
- [ ] **Analytics** shows totals: issued, issued this month, with serial. The numbers roughly match what you generated.
- [ ] Charts load, with no JS errors in the browser console.
- [ ] Previews from section 3 are **not** counted as issued certificates.

## 16. Settings (Settings → Certificate Generator)
- [ ] Every tab loads. Change a harmless option on each, save, reload, and check the value persisted.
- [ ] The email style options (title/text/button colours, border radius) change how the verify page and email look.
- [ ] The **API** tab loads.
- [ ] The license section shows the current plan. Free-plan limits match the messages seen in sections 3 and 6.

## 17. LMS / WooCommerce integrations *(only if the matching plugin is installed)*
Enable one integration (e.g. Tutor LMS), then:
- [ ] Its mapping page appears in the menu. Map a course to a template and save.
- [ ] Complete the course as a test student. A certificate is issued automatically, and a student row is created (tagged with the platform).
- [ ] Complete it again, or trigger again. **No** duplicate certificate is issued.
- [ ] Map **two courses** to the same certificate type. Completing both gives **two** certificates, each with its own row. Neither overwrites the other.
- [ ] WooCommerce: completing an order for a mapped product issues a certificate.
- [ ] A track (several courses → one certificate) issues only after all of its courses are complete.

## 18. Optional features *(only if their flag is enabled)*
- [ ] **Badges** (`CG_USE_BADGES`): a template with a badge image produces a badge PNG alongside the PDF.
- [ ] **Renewal reminders** (`CG_USE_RENEWAL_REMINDERS`): a certificate expiring in 7 days gets a reminder once the daily cron runs, and doesn't get it twice.
- [ ] **Expiry**: a certificate with a past `expires_at` shows **Expired** on the verify page.

## 19. Security spot-checks
- [ ] As a **Subscriber**: every Certificate Generator admin URL is denied (Students, Templates, Bulk Serials, Revoke, Bulk Send).
- [ ] Logged out: admin-ajax actions for bulk send, serial generation and revoke return an error, not data.
- [ ] Enter `<script>alert(1)</script>` as a student name, then view the list, the PDF, the verify page and an email. It shows as text everywhere, and no alert pops up.
- [ ] Downloading a certificate by guessing another person's file URL doesn't expose data you can't otherwise search for.

## 19b. My Certificates, sharing and privacy
- [ ] On a page with `[certificate_generator_my_certificates]`, logged out: request a link for a known and an unknown address. Both show the same message; only the known one gets an email.
- [ ] The emailed link opens that address's certificates only, and the address bar no longer shows `cg_wallet=`. **Sign out** returns to the form. A link older than 30 minutes says it has expired.
- [ ] Untick **Show my name when someone verifies this**, then open **Verify & share**: Valid, with "Hidden by the recipient".
- [ ] A full verify result shows LinkedIn, Share on X, WhatsApp and Copy link. With a LinkedIn organization ID set, the LinkedIn link carries `organizationId`.
- [ ] Paste a `?code=` verify link into WhatsApp Web: the preview reads "Verified certificate: <type>".
- [ ] Turn on **Require an email link to view certificates**: the student search page shows the email-link form instead of results.
- [ ] Tools → Export Personal Data for a test student's email: the file has their records, certificates and email logs. Tools → Erase Personal Data: their records are gone and their certificate still verifies, issued to [erased].
- [ ] **Register**: filter by a school and by Revoked; the table and the CSV / PDF downloads match. The CSV opens in Excel with Hindi names intact; the PDF repeats the header row on page 2. A School Coordinator sees only their school.
- [ ] Trash the verification page, then run the daily cron (or click **Create it now** on the Dashboard): a published verify page is back, and a new certificate's QR code opens it.
- [ ] Dashboard checklist: **Hide checklist** hides it; Documentation → Getting Started → **Show this checklist on the Dashboard again** brings it back.

## 19c. Diagnostics and WP-CLI
- [ ] **Tools → Cert Gen Diagnostics** shows every plugin check as Pass on a healthy site; with `DISABLE_WP_CRON` and no server cron, *Scheduled tasks* fails once something is overdue, and says what to do.
- [ ] `wp certificate-generator diagnostics` prints the same checks; `wp certificate-generator import <file> --dry-run` prints the counts and saves nothing; `wp certificate-generator generate --ids=<id>` shows a progress bar and finishes.

## 20. Cleanup & final checks
- [ ] `debug.log` has no new PHP warnings or fatals from this plugin.
- [ ] The browser console shows no JS errors on the admin pages you visited.
- [ ] Delete the test data (or keep it for the next run).
- [ ] Deactivate the plugin: no errors. Reactivate it: the data is still there.

---

**Result:** ___ / ___ sections passed. Tested on WP ____ · PHP ____ · plugin version ____ · date ____ · by ____.

**Failures found:**
| Section | Step | What happened |
|---|---|---|
| | | |
