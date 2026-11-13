# The Hack Repair Guy's PHP Checker: project notes

Developer notes for contributors. For what the plugin does, see [README.md](README.md "What the plugin does and how to install it").

Current release: **1.2.4**, tag `v1.2.4`, November 12, 2026. See `releases/v1.2.4.md`.

## Scope

An in-process, read-only static PHP 8.1–8.5 compatibility analyzer for installed WordPress plugins, must-use plugins, themes, and (optionally) pending update zip packages, combined with live server PHP lifecycle and security-patch checks, runtime PHP deprecation notice logging, and WordPress.org newer-version fix verification. It never executes untrusted plugin/theme code, never shells out via `exec()`, and never modifies site files. Scan results and report history stay on the site unless an administrator exports or emails a report or enables the scheduled readiness email.

## Architecture

- `index.php`: plugin header (`Version: 1.2.4`), constants (`THRG_PHP_CHECKER_VERSION`, `THRG_PHP_CHECKER_FILE`, `THRG_PHP_CHECKER_DIR`), GitHub release updater bootstrap, class includes, and deactivation cleanup for scheduled hooks and transient scan state.
- `includes/class-thrg-php-checker.php`: main controller (`Tools > PHP Checker`), admin request handlers and page bootstrap; `includes/trait-thrg-pc-report-methods.php` contains the `collect()` and `evaluate()` pipeline, server PHP EOL & security advisory checks (`PHP_SUPPORT`, `PHP_ADVISORIES`, `PATCH_LIST_AS_OF`), client-first priority ordering (incompatible components first, warning components second, server/update checks third, clean components last), allowlisted link-token system (`LINKS`, `href()`, `linkify()`, `strip_links()`), plain-text report builder (`plain_text()`), and admin-post/AJAX handlers.
- `includes/class-thrg-pc-context.php`: token-stream context analyzer (`THRG_PC_Context`).
  - `guarded_lines()`: inspects PHP token streams for `version_compare()`, `PHP_VERSION_ID`, `PHP_MAJOR_VERSION`, `function_exists()`, `class_exists()`, `interface_exists()`, `trait_exists()`, `defined()`, and `extension_loaded()` guards (both enclosing `if`/`elseif`/`else` blocks and early-return negation guards) plus conditional polyfill declarations.
  - `dynamic_property_findings()`: detects PHP 8.2+ undeclared dynamic property creations (`PHPCompatibility.Classes.ForbiddenDynamicProperties.Found`) while honoring all PHP 8.2 migration guide exceptions: declared properties across inheritance, PHP 8.0+ constructor-promoted properties, `#[AllowDynamicProperties]` attributes (direct or inherited), `stdClass` instances and subclasses, and classes implementing `__set()`.
- `includes/class-thrg-pc-engine.php`: in-process wrapper around bundled `PHP_CodeSniffer` 4.0.4 + `PHPCompatibilityWP` 3.0.0-alpha2 (`vendor/thrg-scan-engine/`). Scans files in memory with a 2 MB per-file safety ceiling, applies `THRG_PC_Context` guard downgrades (`error`/`warning` -> `note`), and merges PHP 8.2+ dynamic property findings.
- `includes/class-thrg-pc-scanner.php`: discovers installed plugins, must-use plugins, and themes (`components()`), manages the single-owner scan queue (`thrg_pc_scan_state`) with an atomic claim and serialized step lock, and executes 5-second time-boxed AJAX scan slices (`step()`) with mid-component file-level resumption so huge plugins never time out.
- `includes/class-thrg-pc-results.php`: stores component results in a single non-autoloaded option (`thrg_pc_results`) keyed by target PHP version and component key. Automatically prunes uninstalled components or outdated versions in `for_target()`, separates `skipped` oversized files from `warnings`, and classifies each finding's file path (`file_origin()`) into `'own'` (plugin/theme code) vs. `'bundled'` (`vendor/`, `freemius/`, `action-scheduler/`, `pear/`, `phpmailer/`, `tcpdf/`, `dompdf/`, `mpdf/`, `vendor-prefixed/`, etc.).
- `includes/class-thrg-pc-prescan.php`: optional in-memory zip pre-scanner for pending plugin/theme updates (`thrg_pc_update_results`), off by default, plus `check_wporg_fix()` to verify whether a failing WordPress.org plugin is fixed in a newer release on the target PHP version.
- `includes/class-thrg-pc-runtime-log.php`: opt-in live `E_DEPRECATED | E_USER_DEPRECATED` collector (with backtrace attribution for WordPress `_deprecated_function()` calls) and on-demand `wp-content/debug.log` deprecation importer (`thrg_pc_runtime_deprecations`), grouped by plugin or theme.
- `includes/class-thrg-pc-history.php`: bounded local report history (`thrg_pc_history`, max 25 reports, 600 KB ceiling, 5-minute deduplication) with target-aware diffing (`previous_for()`, `change()`).
- `includes/class-thrg-pc-email.php`: emails a saved PHP compatibility report to selected site administrators following the THRG Email Standard (`[domain] [PHP Checker] (Monthly )PHP X.Y Check: ...`). Full-width branded HTML + plain-text `AltBody`, CRLF-safe `Reply-To`, server-side administrator lookup, and 60-second per-user rate limit.
- `includes/class-thrg-pc-schedule.php`: opt-in weekly or monthly PHP readiness & update-alert email scheduler (`thrg_pc_schedule`, `thrg_pc_schedule_log`, single event `thrg_pc_scheduled_report`, 10-minute lock, `heal()` on `admin_init`).
- `includes/view-checker.php`, `includes/view-checker-data.php`, `includes/view-{header,scan,report,footer}.php`, `assets/checker.css`, `assets/js/checker.js`: the admin interface, featuring the THRG 2026 heart branding, target PHP switcher, plain-English Readiness Verdict banner, summary tiles, urgency-grouped left navigation, streamlined finding cards with collapsible technical code drawers, **Check if fixed on WP.org** buttons, runtime deprecation log grouped by plugin, and single-component rescan buttons.
- `includes/class-thrg-pc-updater.php`, `includes/class-thrg-pc-update-notices.php`, `plugin-update-checker/`: GitHub release updater and native View Details parser.
- `includes/companion-plugins.php`: adds the **Other THRG Plugins** tab to View Details.
- `uninstall.php`: removes all `thrg_pc_*` options and clears scheduled hooks on plugin deletion.

## Verification & Quality Gates

- **Unit & Integration Tests (`tests/run.php`):** 103 assertions covering engine availability, unguarded error detection, version-guard and polyfill downgrading, PHP 8.2+ dynamic property fixtures (`PlainWidget`, `#[AllowDynamicProperties]` inheritance, `stdClass`, magic `__set()`, guarded dynamic property), file origin classification (`own` vs `bundled`), skipped-file partial verdicts, orphan pruning, client-first evaluation ordering, WordPress.org newer-version fix verification, runtime PHP deprecation log parsing and plugin grouping, plain-text rule/origin formatting, history diffing, email subject/Reply-To security, and weekly/monthly schedule calculation.
  ```sh
  php tests/run.php
  ```
- **Coding Standards (`phpcs.xml.dist`):** 23/23 first-party PHP files pass `WordPress-Core`, `WordPress-Extra`, and `WordPress-Docs` with 0 errors and 0 warnings:
  ```sh
  /usr/local/bin/phpcs --standard=phpcs.xml.dist
  ```
- **Release Packaging (`scripts/build-release.py`):** Builds `dist/thrg-php-checker.1.2.1.zip` with normalized `0644` permissions on every entry, verified byte-for-byte against source files, and writes `dist/thrg-php-checker.1.2.1.zip.sha256`.

## Release 1.2.1 — November 12, 2026

- Bound update ZIP archive size, entry count, and declared uncompressed total.
- Bound scan ownership to a job token and initiating administrator, with atomic claim and serialized step progress.
- Improve runtime deprecation attribution for canonical plugin paths.
- Search all GitHub release assets for the exact versioned ZIP; remove tracked email cooldowns on uninstall.
- Extract report and view preparation/rendering into focused files; expand characterization and integration fixtures.

## Release 1.2.0 — November 11, 2026

Added all remaining "Pro"-tier features free out of the box:
- Added **Weekly** (default, with day-of-week selector) or **Monthly** scheduled rescans against the next PHP version and pending updates, sending an email alert when an offered update or the next PHP version has known compatibility problems.
- Added **"Fixed in a newer WordPress.org version" verification** (`THRG_PC_Prescan::check_wporg_fix()`), stream-scanning newer WordPress.org plugin releases in memory against the target PHP version and badging cards (`Fixed in vX.Y on WordPress.org`, `Fatal errors fixed in vX.Y`, or `Still fails in vX.Y`).
- Added **Runtime PHP Deprecation Notices (Grouped by Plugin)** (`THRG_PC_Runtime_Log`), with an opt-in live `E_DEPRECATED | E_USER_DEPRECATED` collector and an on-demand `wp-content/debug.log` deprecation importer.
- Added a recommendation in **Support & Companion Tools** for **The Hack Repair Guy's Plugin Detective**.

## Release 1.1.0 — November 11, 2026

Full UX and hierarchy overhaul following live testing on the Zendogs WordPress sandbox (`localhost:8081`):
- Reordered `THRG_PHP_Checker::evaluate()` so incompatible plugins/themes (`breaks`) appear first at the top of the report above the fold, followed by warning components, server lifecycle checks, and clean components.
- Added the plain-English Readiness Verdict banner at the top of `includes/view-checker.php`.
- Removed the redundant `scan-overview` card when components have been scanned (fixing double-counting in the red Summary Tile, History, and Email subject lines) and hid `update-checks` when Update Pre-Scan is off.
- Added urgency sub-groups and count badges to the left navigation, streamlined plugin cards with a highlighted **What to do** box and header origin pills, and wrapped technical `file:line` tables in collapsible `<details>` drawers (collapsed by default, capped at 8 initial rows when opened with an overflow drawer, plus an **Expand all code tables** toggle).
- Expanded `THRG_PC_Results::file_origin()` to detect bundled `Pear`, `PHPMailer`, `TCPDF`, `Dompdf`, `mPDF`, and `vendor-prefixed` libraries, and fixed CSS word-wrapping and `[hidden]` button specificity.
