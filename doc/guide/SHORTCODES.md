# Shortcodes

[← Back to README](../../README.md) · Full guide: [`documentation.md`](documentation.md)

Registered in `includes/Services/certificate-search.php`, `includes/Services/bulk-download.php`, and `includes/Public/verification.php`.

| Shortcode | Registered in | Purpose |
|---|---|---|
| `[certificate_generator_student_search]` | `includes/Services/certificate-search.php` | Front-end search form for looking up a student's certificate(s). |
| `[certificate_generator_teacher_search]` | `includes/Services/certificate-search.php` | Front-end search form for looking up a teacher's certificate(s). |
| `[certificate_generator_school_search]` | `includes/Services/certificate-search.php` | Front-end search form for looking up a school's certificates. |
| `[certificate_generator_my_certificates]` | `includes/Public/my-certificates.php` | A recipient's own certificates. The visitor types their email and gets a sign-in link (30 minutes); the list has Download, Verify & share, Download all (ZIP) and a "Show my name when someone verifies this" switch per certificate. The form answers the same for every address. See [My Certificates](documentation.md#my-certificates-recipients). |
| `[school_bulk_certificate_download]` | Pro add-on: `certificate-generator-pro/includes/bulk-zip.php` | Lets a school download all of its certificates in bulk (ZIP) from the front end. The visitor must type the exact school name (case doesn't matter). |
| `[certificate_generator_verify_certificate]` | `includes/Public/verification.php` (`CertificateGenerator_Public_Verification` class) | Public certificate authenticity verification form. The QR code on each certificate opens it with `?code=` (the certificate's 12-character verification code) and shows the full result: name, type, dates, "Corrected on …" after a reissue, and share buttons (LinkedIn Add to Profile, X, WhatsApp, copy link), with link-preview tags for the shared `?code=` link. A serial number typed alone also verifies, but the name is masked (`R**** S****`) because serials run in sequence; set the option `certificate_generator_verify_serial_full_details` to `1` to show full details for serials. Lookups are rate-limited per visitor. See [`REST-API.md`](../dev/REST-API.md#certificate-generatorv1verifyserial-free). |

Drop any of these into a page or post via the block/classic editor, or via `do_shortcode()` in a template.
