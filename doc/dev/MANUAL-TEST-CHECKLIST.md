# Manual Test Checklist

[← Back to README](../../README.md) · Full guide: [`documentation.md`](../guide/documentation.md) · [Free vs Pro](../guide/PLAN-COMPARISON.md)

A click-through check of every feature. Tick each box as you go. If a step fails, note the
section number and what you saw (a screenshot helps). Automated tests are covered separately in
[`TESTING.md`](TESTING.md).

**Before you start**
- [ ] The site is running in Local, and you are logged in as an Administrator.
- [ ] `WP_DEBUG` and `WP_DEBUG_LOG` are `true` in `wp-config.php`. Keep `wp-content/debug.log` open and look for new errors after each section.
- [ ] You have the sample CSVs in `doc/samples/` (`students.csv`, `teacher.csv`, `school.csv`, `certificates.csv`).
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
- [ ] **Tools / Migration page** (under Advanced, if shown) reports the DB version as **009**, with nothing pending.
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
- [ ] Bulk **Generate** certificates for 2 rows: PDFs are created, with no errors.
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
Use `doc/samples/*.csv` and a CSV of your own containing the test data table above.
- [ ] Import **students.csv**. The success message shows the right count, and the rows appear in Students.
- [ ] Right after the import, the **upload form is still visible**, so you can import another file straight away.
- [ ] Press **F5** after an import: the CSV is **not** imported a second time (the row count is unchanged).
- [ ] Import the same person twice with **different event dates** (Asha Multi, 10-01-2026 and 15-03-2026). You get **two rows**, and neither overwrites the other.
- [ ] Re-import the *same* person + type + date. The existing row is updated or skipped, **not** duplicated.
- [ ] Import **teacher.csv** and **school.csv**. Rows land in the correct tables.
- [ ] Import **certificates.csv** (templates). The templates appear.
- [ ] Import a CSV with a **missing required column**: you get a clear error listing the missing field names, shown as plain text (no broken HTML).
- [ ] Import a CSV with a header like `<b>name</b>`: it's shown as text, not rendered as bold.
- [ ] Dates in both `dd-mm-yyyy` and `yyyy-mm-dd` import correctly.

## 7. Generating certificates & template matching
- [ ] Generate for **Asha Multi, 10-01-2026**. The PDF uses the **10-01-2026 Participation** template.
- [ ] Generate for **Asha Multi, 15-03-2026**. The PDF uses the **15-03-2026** template (a different background).
- [ ] Generate for **Ben Winner**. The PDF uses the **Winner** template.
- [ ] Generate for **Tara Teacher**. The PDF uses the **Mentor** template.
- [ ] Generate for a person whose type/date has **no template**. You get a clear "no matching template" message, and **no** certificate with the wrong design.
- [ ] Names with accents (e.g. *José Ñúñez*) render correctly in the PDF.
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
- [ ] Enter Ben's serial. It shows **Valid** with the right name, type and date.
- [ ] Enter Asha's two serials. Each shows Asha with the correct event.
- [ ] An unknown serial shows **Not found**.
- [ ] Scan or open the QR code link from a PDF. It lands on the verify page with the serial pre-filled and valid.
- [ ] The **LinkedIn – Add to Profile** button appears on a valid certificate and opens LinkedIn with the details pre-filled.
- [ ] Hit verify about 25 times within a minute. After about 20 you get a "too many attempts" message (the rate limit works).
- [ ] REST: `/wp-json/certificate-generator/v1/verify/<serial>` returns JSON with `valid: true`.

## 10. Revoke
- [ ] **Revoke Certificate**: revoke Ben's serial with a reason. It shows up in the revoked list.
- [ ] The public verify page for that serial shows **Revoked** plus the reason (not "valid").
- [ ] Click **Reinstate**. Verify shows **Valid** again.
- [ ] A wrong serial gives the error "No certificate found".

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
