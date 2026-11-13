=== The Hack Repair Guy's PHP Checker ===
Contributors: hackrepair, tvcnet
Donate link: https://hackrepair.com/donations/buy-jim-a-coffee
Tags: php, compatibility, php 8.3, php 8.4, upgrade
Requires at least: 6.4
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 1.2.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your friendly WordPress PHP compatibility checker. Tests plugins and themes for PHP 8.1–8.5, filters out false alarms, and puts real blockers first.

== Description ==

Upgrading PHP is one of the best ways to keep WordPress fast and secure, right up until an older plugin throws a fatal error on upgrade day. Most PHP compatibility scanners either flood you with hundreds of false warnings from safe polyfills and `version_compare()` guards, or bury the one broken plugin under pages of technical noise.

The Hack Repair Guy's PHP Checker runs entirely inside WordPress (no `exec()` or shell access needed), automatically filters out runtime version guards and polyfills, and puts any plugin or theme that will actually break your site right at the top of the report.

Open **Tools > PHP Checker**, pick your target PHP version (**8.1, 8.2, 8.3, 8.4, or 8.5**), and click **Scan**. It checks:

* **Active and inactive plugins, must-use plugins, and themes** using an embedded build of PHP_CodeSniffer and PHPCompatibilityWP
* **Runtime version guards and polyfills** (`version_compare()`, `PHP_VERSION_ID`, `function_exists()`, `class_exists()`, `defined()`, `extension_loaded()`), automatically downgrading safe backward-compatibility code to informational notes so you only see real issues
* **PHP 8.2+ dynamic property deprecations** with built-in false-positive protection for `#[AllowDynamicProperties]` (including inheritance), `stdClass`, magic `__set()` handlers, constructor-promoted properties, and declared properties
* **Exact `file:line`, target PHP version, rule code, and code origin** — clearly distinguishing between the **plugin or theme's own code** and a **bundled third-party library** (`vendor/`, `freemius/`, `action-scheduler/`, `pear/`, `phpmailer/`, etc.)
* **Server PHP support lifecycle and security patch status**, including end-of-life countdowns and known PHP security advisories (such as PHP2Shell, CVE-2026-109618 and CVE-2026-109619)
* **Optional in-memory Pending Update Pre-Scan** (off by default) to inspect offered plugin and theme update zip packages against your running PHP version before you install them

**Designed for first-time users and seasoned developers alike.** A plain-English readiness banner at the top of the report immediately tells you whether it is safe to upgrade or which plugin needs attention first. Fatal incompatibilities sit at the very top with their `file:line` error tables open; minor deprecation warnings are grouped below with collapsible technical drawers so you are never overwhelmed by a wall of stack traces.

**Your scans build a local history.** Completed scans are saved on your own site (up to 25 reports within a 600,000-byte limit), showing what is new and what you have fixed since your previous check for that PHP version. Pause, reopen, delete, or clear history anytime.

**Share or email a report.** Copy the plain-text report for a developer or support ticket, download a `.txt` file, print a clean summary, or email a formatted HTML and plain-text PHP readiness briefing to site administrators with `[domain] [PHP Checker]` subject tags.

**Optional scheduled PHP readiness email.** Off by default. Choose weekly (with a day of the week) or monthly (day 1 to 28) to automatically email site administrators a PHP readiness report, or only when errors or warnings are found.

= Privacy =

Static scans inspect local PHP source files on your server in 5-second AJAX steps without executing plugin/theme code or sending source code or report contents to any outside service. Optional Update Pre-Scan (off by default) downloads pending update zip packages from their configured update URLs to a temporary file, inspects PHP entries in memory, and deletes the temporary file immediately.

Scan results and report history stay in your site's database and automatically prune uninstalled plugins/themes. Reports leave your site only when an administrator copies, downloads, prints, or emails them, or enables the monthly email.

See External services below for the few requests PHP Checker makes, and when.

= What it is not =

Static analysis parses PHP source tokens without executing your site's code. While version-guard filtering removes common false alarms, static analysis cannot replace testing critical workflows (like WooCommerce checkout or membership forms) on a staging server before switching PHP versions in production. If you want a deeper look or hands-on help with a tricky PHP upgrade, [The Hack Repair Guy](https://hackrepair.com/ "The Hack Repair Guy: WordPress security and hack repair") can help.

= Credits =

Static analysis uses [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) 4.0.4 (BSD-3-Clause), [PHPCSUtils](https://github.com/PHPCSStandards/PHPCSUtils) 1.2.3 (LGPL-3.0-or-later), [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) (development snapshot, commit a0fd248812fd, LGPL-3.0-or-later), [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP) 3.0.0-alpha2 (LGPL-3.0-or-later) and [PHPCompatibilityParagonie](https://github.com/PHPCompatibility/PHPCompatibilityParagonie) 2.0.0-alpha2 (LGPL-3.0-or-later). They are bundled in `vendor/thrg-scan-engine/` as runtime code, loaded only when an administrator runs a scan; unused tools and tests are removed and each folder keeps its license.

== External services ==

PHP Checker contacts outside services only when an administrator asks it to. Opening the report or running a normal scan sends nothing.

**WordPress.org plugin directory** (`api.wordpress.org` and `downloads.wordpress.org`). Used when you click **Check if fixed on WP.org** on a plugin card. PHP Checker asks the WordPress.org Plugin Information API about that plugin's slug, then downloads the newer release ZIP from `downloads.wordpress.org` to a temporary file, scans it in memory and deletes it. Only the plugin slug is sent; as with any WordPress request, your site address appears in the standard WordPress user-agent header. Lookups are cached for 12 hours. Service: https://wordpress.org/ - Privacy policy: https://wordpress.org/about/privacy/

**Update packages from plugin and theme vendors** (optional Update Pre-Scan, off by default). When you turn it on, PHP Checker downloads the update packages that WordPress already lists as available, from the update address each plugin or theme vendor provides, so it can scan them before you install. Nothing beyond the ordinary download request is sent, and the package is deleted right after the scan. These servers belong to the vendors, so their own terms and privacy policies apply.

PHP Checker has no account, license server, analytics or advertising requests. The PHP support dates and security advisories it shows are built into the plugin and need no request.

== Installation ==

1. In WordPress, open Plugins > Add New, search for "The Hack Repair Guy's PHP Checker" and choose Install Now. You can also upload the `thrg-php-checker` folder to `wp-content/plugins/`.
2. Activate The Hack Repair Guy's PHP Checker.
3. Open Tools > PHP Checker, select your target PHP version (defaults to the recommended next PHP branch above your server's current PHP), and click Scan.
4. Review the plain-English Readiness Verdict banner at the top and any red "Action Needed" plugin cards first.
5. Optional: open Share, Download, Print, or Email This Report to send a copy to your developer or other administrators, or set up the Scheduled Readiness Email.

== Support ==

For questions, ideas and help, visit the plugin's support forum on WordPress.org:
https://wordpress.org/support/plugin/thrg-php-checker/

== Frequently Asked Questions ==

= What is the difference between an Incompatible error and a Deprecation warning? =

* **Incompatible (Fatal Error — Red):** The plugin or theme uses a function, class, or syntax that was removed or fatally changed in the target PHP version (without a runtime version guard). If that code path runs on the target PHP version, PHP will halt the request with a fatal error. Update, replace, or deactivate red items before upgrading PHP.
* **Deprecation Warning (Amber):** The plugin or theme uses a feature that still works in the target PHP version, but PHP logs an `E_DEPRECATED` notice to let developers know it will be removed in a future PHP version. Your site can still run on the target PHP version today (keep `WP_DEBUG_DISPLAY` off on live production sites and update the plugin as new releases arrive).

= Why does PHP Checker report fewer false positives than other scanners? =

Many WordPress plugins support both older and newer PHP versions by wrapping legacy calls in `version_compare( PHP_VERSION, '8.0', '<' )` or `if ( ! function_exists( 'str_contains' ) )` polyfills. Basic regex or raw PHPCS scanners flag those lines as errors even though they never run on newer PHP versions. PHP Checker's token-stream analyzer (`THRG_PC_Context`) inspects surrounding `if`/`elseif`/`else` conditions and early-return guards, automatically downgrading safe fallbacks to collapsed informational notes.

= How does the PHP 8.2+ dynamic property check avoid noisy false alarms? =

PHP 8.2 deprecated creating undeclared properties on class instances, with official exceptions documented in the PHP 8.2 Migration Guide. PHP Checker inspects class declarations and inheritance chains in each file and skips classes with `#[AllowDynamicProperties]` (direct or inherited), `stdClass` and its subclasses, classes implementing magic `__set()`, constructor-promoted properties, and declared properties.

= What do the "Plugin code" and "Bundled dependency" badges mean? =

Every `file:line` finding shows whether the file lives in the plugin/theme's own code (`Plugin code` / `Theme code`) or inside a bundled third-party library (`Bundled dependency`, such as `vendor/`, `freemius/`, `action-scheduler/`, `pear/`, or `phpmailer/`). This helps developers and site owners know immediately whether an issue is in custom code they control or an upstream SDK that will be updated by the vendor.

= Does scanning slow down visitors or time out on large plugins? =

No. Scanning runs only when an administrator starts it (or turns on the scheduled readiness email) and processes files in 5-second time-boxed AJAX slices. If a single large plugin has thousands of files, PHP Checker saves file-level progress after 5 seconds and resumes on the next slice automatically. Files larger than 2 MB are skipped safely and marked as partial rather than crashing PHP memory.

= What happens when I uninstall or delete a plugin that had errors? =

Unlike scanners that leave orphaned rows from deleted plugins in custom database tables forever, PHP Checker automatically prunes uninstalled plugins/themes and outdated component versions whenever results are loaded. Uninstalling PHP Checker itself removes all of its options and scheduled hooks cleanly.

== Changelog ==

= 1.2.5 =
* WordPress.org package checks now accept only HTTPS downloads from downloads.wordpress.org.
* Cached WordPress.org lookups are removed when the plugin is deleted.
* The scheduled email intro no longer says "monthly" for weekly emails.
* Reorganized report and evaluation code into smaller classes. Nothing changes in the report you see.
* New store banners.

= 1.2.4 =
* Excluded PHPCS `Internal.*` formatting/line-ending sniffs (such as `Internal.LineEndings.Mixed`) from compatibility scan results.
* Expanded `THRG_PC_Context` guard analysis to recognize `if (function_exists(...))` when checking core built-in functions guaranteed on the target PHP version (such as `error_clear_last` on PHP 7.0+), downgrading their dead `else` branches and variables only assigned inside guarded branches (`isset($var)`).
* Calibrated error severity wording so non-fatal behavioral/INI removal rules (`RemovedIniDirectives`, `ArgumentFunctionsReportCurrentValue`) are distinguished from hard fatal-error rules in card summaries and the Readiness Verdict banner.
* Displayed each finding's originating PHP version (e.g. `since PHP 7.0`, `since PHP 8.2`) in finding tables and deprioritized `ArgumentFunctionsReportCurrentValue.NeedsInspection` warnings below actionable findings.
* Broadened `THRG_PC_Results::file_origin()` bundled dependency detection to include `libraries`, `library`, `lib`, `libs`, `sdk`, `sdks`, `mailer`, and `imap2` directories.

= 1.2.3 =
* Lowered non-blocking, compatible, panel, and inactive card opacity to 60% (restoring to 100% on hover/focus) and applied 60% opacity to the left-column Warnings & Review list so active fatal incompatibilities stand in front.
* Added `(?)` pop-out tooltips next to each item in the left-column Tools & Settings navigation explaining what each section is for.
* Fixed Expand all code tables / Collapse all code tables to toggle and sync all finding card detail drawers (including overflow and guarded-note tables).

= 1.2.2 =
* Softened visual weight of non-blocking and inactive items so fatal incompatibilities stand in front.
* Updated footer assistance contact link to point to HackGuard.
* Simplified header subtitle.

= 1.2.1 =
* Added limits for update ZIP archive size, entry count, and declared uncompressed bytes.
* Bound scans to an owner and job token; added atomic scan claims and serialized scan steps.
* Improved runtime deprecation path attribution and release ZIP selection.
* Removed tracked email cooldowns during uninstall and reorganized report/controller view files.

= 1.2.0 =
* Added **Weekly** (default, with day-of-week selector) or **Monthly** (day 1–28) scheduled rescans against the next PHP version and pending updates, sending an email alert when an update or the next PHP version has known compatibility problems.
* Added **"Fixed in a newer WordPress.org version" verification** (`THRG_PC_Prescan::check_wporg_fix()`), checking whether a plugin from WordPress.org that fails a scan has a newer release, stream-scanning the newer package in memory against the target PHP version, and badging the card (`Fixed in vX.Y on WordPress.org`, `Fatal errors fixed in vX.Y`, or `Still fails in vX.Y`) with a one-click **Check if fixed on WP.org** button.
* Added **Runtime PHP Deprecation Notices (Grouped by Plugin)** (`THRG_PC_Runtime_Log`), with an opt-in live `E_DEPRECATED` / `E_USER_DEPRECATED` collector (including backtrace attribution for WordPress `_deprecated_function()` calls) plus an on-demand `wp-content/debug.log` deprecation importer.
* Added a recommendation in **Support & Companion Tools** for pairing PHP Checker with our free **The Hack Repair Guy's Plugin Detective** plugin while testing plugins and PHP upgrades.

= 1.1.0 =
* Client-first report hierarchy: incompatible plugins and themes (`breaks`) now appear at the very top of Checks & Findings above the fold, followed by deprecation warnings, server lifecycle status, and a collapsible table of compatible components.
* Added a plain-English Readiness Verdict banner right below the scan toolbar that immediately tells first-time users whether it is safe to upgrade or which specific plugin blocks the upgrade.
* Removed the redundant scan-coverage meta card once components have been scanned, fixing double-counting in the red Summary Tile, Report History, and Email subject lines.
* Suppressed the Pending Update Compatibility card when Update Pre-Scan is disabled and no update packages have been checked.
* Streamlined plugin and theme cards with header origin badges (`in plugin code` vs. `in bundled library`), a highlighted **What to do** callout, and sorted tables that place `ERROR` rows and first-party code at the top.
* Added collapsible technical code drawers (collapsed by default to keep cards clean, capped at 8 initial rows when opened with an overflow toggle for large plugins, plus an **Expand all code tables** toggle).
* Reorganized the left navigation into urgency groups (**Action Needed**, **Warnings & Review**, **Passed Checks**, and **Tools & Settings**) with compact issue-count badges and auto-expanding section links.
* Expanded bundled dependency detection to recognize `Pear`, `PHPMailer`, `TCPDF`, `Dompdf`, `mPDF`, `Strauss`, `Mozart`, and `vendor-prefixed` directories.
* Fixed WordPress core `.button` CSS specificity so the `Stop Scan` button stays hidden when no scan is running, and improved long rule-code wrapping inside finding tables.

= 1.0.0 =
* Initial release of The Hack Repair Guy's PHP Checker (`Tools > PHP Checker`).
* In-process PHP 8.1, 8.2, 8.3, 8.4, and 8.5 static compatibility analyzer powered by embedded `PHP_CodeSniffer` 4.0.4 and `PHPCompatibilityWP` 3.0.0-alpha2 with zero `exec()` calls.
* Token-stream runtime guard and polyfill analyzer (`THRG_PC_Context`) that downgrades version-guarded code to informational notes.
* PHP 8.2+ dynamic property deprecation analyzer respecting `#[AllowDynamicProperties]` inheritance, `stdClass`, magic `__set()`, and constructor property promotion.
* Actionable finding metadata with `file:line`, target PHP version, triggered rule code, and `Plugin code` vs. `Bundled dependency` origin classification.
* Resumable 5-second AJAX batch scanner with file-level progress, single-component rescanning, and automatic orphan pruning.
* Server PHP lifecycle, EOL countdown, and PHP2Shell (CVE-2026-109618 / CVE-2026-109619) security patch checks.
* Optional in-memory Pending Update Pre-Scan, bounded 25-report local history with target-aware diffs, branded administrator report emails, and opt-in monthly PHP readiness email schedule.
