# Manual Testing Guide

[← Back to README](../../README.md)

How to run this plugin's test suites yourself, from a terminal. Covers PHPUnit (unit/integration/functional/regression/security/performance), static analysis, the in-admin Test Center, and the Playwright E2E suite.

## 0. One-time setup

```bash
composer install   # PHP test/analysis tooling
npm install         # Playwright, only needed for E2E
```

**If `composer`/`php` aren't on your PATH (common on Local by Flywheel / Windows):** either use Local's **Site Shell** (right-click the site → *Open Site Shell*, which has PHP/Composer/WP-CLI already configured), or call the bundled binaries directly. Example for this machine:

```bash
PHP="C:/Users/eshaa/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
INI="C:/Users/eshaa/AppData/Roaming/Local/run/6QexFw-dH/conf/php/php.ini"
"$PHP" -c "$INI" vendor/bin/phpunit    # instead of: composer test
```

(That run-id in the `INI` path changes if Local restarts the site — check `%APPDATA%\Local\run\` for the current one.) Everything below assumes `composer`/`php`/`npx` work directly; substitute the above if they don't.

## 1. PHPUnit suites

| Command | Runs | Needs WP bootstrap? |
|---|---|---|
| `composer test` | Everything below except Performance | — |
| `composer test:unit` | `tests/Unit` (pure PHP, no WordPress) | No |
| `vendor/bin/phpunit --testsuite integration` | `tests/Integration` | Yes |
| `vendor/bin/phpunit --testsuite functional` | `tests/Functional` (full pipelines, e.g. CSV → serial → verify) | Yes |
| `vendor/bin/phpunit --testsuite regression` | `tests/Regression` (pinned past bugs / known dual-system behavior) | Yes |
| `vendor/bin/phpunit --testsuite security` | `tests/Security` (nonces, capabilities, REST info-disclosure, XSS/SQLi) | Yes |
| `composer test:performance` | `tests/Performance` (100/1000-row bulk import timing) — excluded from `composer test`, slow on purpose | Yes |

The WP-bootstrap suites (everything but `Unit`) run against a real WordPress core + a dedicated `wordpress_test` database — see `tests/bootstrap.php` and `tests/wp-tests-config.php` for that connection. They do **not** touch your real site's database.

Run one test class or method directly:

```bash
vendor/bin/phpunit --filter SerialNumberServiceTest
```

**Pro add-on tests** live in `certificate-generator-pro/tests/` and reuse this plugin's PHPUnit and
`wordpress_test` setup (the add-on must sit next to this folder, or set `CG_FREE_PLUGIN_DIR`). They
cover the add-on hooks and plan gates, license rules, font upload and the LMS backfill:

```bash
cd ../certificate-generator-pro && ../Certificate-Generator-V7.5/vendor/bin/phpunit
```

## 2. Static analysis

```bash
composer phpstan   # level 5, WordPress-aware via szepeviktor/phpstan-wordpress; known errors in phpstan-baseline.neon
composer phpcs     # WordPress-Extra coding standard
```

Before a release, also run WordPress.org's **Plugin Check** on the built ZIP (`composer zip`):
copy it to a test site's `wp-content/plugins/certificate-generator/`, leave it inactive, and run
`wp plugin check certificate-generator`. The target is 0 errors.

Both need `composer install` to have pulled in their packages (they're `require-dev`, not bundled).

## 3. Test Center (in wp-admin)

A dev-only dashboard that runs the suite from inside WordPress and groups results by feature (`tests/feature-registry.php`). It's hidden unless a constant is explicitly set — add this to `wp-config.php` or a file in `wp-content/mu-plugins/`:

```php
define( 'CG_TESTING_UI', true );
```

Then visit **Certificate Generator → 🧪 Test Center** and click **Run Tests**.

**If every row shows 0/0:** the site is running under php-fpm, so `PHP_BINARY` (what the dashboard shells out with) points at php-fpm itself, not a usable CLI PHP. Add the CLI binary explicitly next to `CG_TESTING_UI`:

```php
define( 'CG_TESTING_PHP_BINARY', 'C:/path/to/php.exe' );
define( 'CG_TESTING_PHP_INI', 'C:/path/to/php.ini' );   // only if the CLI php needs a specific ini (e.g. for mbstring)
```

Remove these constants (or the whole file, if you added a dedicated one) when you're done — this page shells out to a CLI tool and shouldn't stay enabled on a shared/production site.

## 4. Playwright E2E

```bash
npx playwright install chromium   # once
```

```bash
CG_E2E_BASE_URL="https://your-site.local" \
CG_E2E_ADMIN_USER="admin" \
CG_E2E_ADMIN_PASS="your-password" \
npx playwright test
```

Notes:
- `CG_E2E_LOGIN_PATH` — override if the login page has been moved (defaults to `/wp-login.php`). For example, if your site's login lives at `/gema-login`: add `CG_E2E_LOGIN_PATH="/gema-login"`.
- Bulk import/export has no row limit on any plan, so the bulk-import spec runs on a plain free install.
- On Windows Git Bash, a leading `/` in an env var can get mangled into a filesystem path (e.g. `/gema-login` → `C:/Program Files/Git/gema-login`). If you hit that, prefix the command with `MSYS_NO_PATHCONV=1`.
- This suite logs in and creates/imports real data on whatever site `CG_E2E_BASE_URL` points at — never point it at a shared or production site. Clean up any rows it creates (e.g. in `wp_cg_students`) afterward if you ran it against a real site rather than a disposable one.

## 5. CI

`.github/workflows/tests.yml` runs on every push to `main` and every PR, against a fresh WordPress core + MySQL service container:

- `composer test` (PHPUnit) — blocking.
- `composer phpstan` — blocking for **new** errors only. Existing ones are listed in `phpstan-baseline.neon`; when you fix one, regenerate it with `vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon`.
- `composer phpcs` — report only (the legacy code has ~1,500 WordPress-Extra violations).

The Playwright E2E suite does **not** run in CI: its WP-CLI bridge (`tests/E2E/support/wp.js`) needs host access to the site's PHP and files, as Local provides. Run it locally (section 4) before releasing.
