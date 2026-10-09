# Shortcodes

[← Back to README](../../README.md) · Full guide: [`documentation.md`](documentation.md)

Registered in `includes/Services/certificate-search.php`, `includes/Services/bulk-download.php`, and `includes/Public/verification.php`.

| Shortcode | Registered in | Purpose |
|---|---|---|
| `[student_search]` | `includes/Services/certificate-search.php` | Front-end search form for looking up a student's certificate(s). |
| `[teacher_search]` | `includes/Services/certificate-search.php` | Front-end search form for looking up a teacher's certificate(s). |
| `[school_search]` | `includes/Services/certificate-search.php` | Front-end search form for looking up a school's certificates. |
| `[school_bulk_certificate_download]` | Pro add-on: `certificate-generator-pro/includes/bulk-zip.php` | Lets a school download all of its certificates in bulk (ZIP) from the front end. The visitor must type the exact school name (case doesn't matter). |
| `[cg_verify_certificate]` | `includes/Public/verification.php` (`CG_Public_Verification` class) | Public certificate authenticity verification form — also reachable by scanning a certificate's QR code, which links to `/verify-certificate/`. Visitors can also search by serial number or email on the results page (`/result/`), which now also shows a "LinkedIn — Add to Profile" button (see [`REST-API.md`](../dev/REST-API.md#certificate-generatorv1verifyserial-free)). |

Drop any of these into a page or post via the block/classic editor, or via `do_shortcode()` in a template.
