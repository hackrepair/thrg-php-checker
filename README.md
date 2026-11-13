<p align="center">
  <img src=".github/branding/thrg-heart-animated.gif" alt="The Hack Repair Guy heart and wrench logo" width="144" height="144">
</p>

<h1 align="center">The Hack Repair Guy's PHP Checker</h1>

Your friendly WordPress PHP compatibility checker. Tests your plugins, must-use plugins, themes, and pending updates against PHP 8.1 through 8.5, filters out version-guarded false alarms, and keeps a history of every report — all in my voice, Jim Walker, The Hack Repair Guy.

Upgrading PHP is one of the easiest ways to make WordPress faster and safer, right up until an older plugin throws a fatal error and leaves you with a white screen. PHP Checker helps you find that plugin before you switch. PHP Checker runs entirely inside WordPress without `exec()`, automatically filters out guarded fallback code, and puts any plugin that will actually break your site right at the very top of the report.

Current release: **1.2.4**; **1.2.5** is in preparation. See [release notes](releases/v1.2.5.md).

<p align="center">
  <a href="https://github.com/hackrepair/thrg-php-checker/releases/latest" title="Download the latest PHP Checker plugin zip from GitHub"><img src="https://img.shields.io/badge/%E2%AC%87%20%20Download%20the%20latest%20release-b34700?style=for-the-badge&labelColor=b34700" alt="Download the latest release" height="46"></a>
  <br>
  <sub>Free. Upload the zip in WordPress, and later updates arrive on the Plugins screen.</sub>
</p>

## What it does

**Tools → PHP Checker** checks:

- **Target PHP 8.1, 8.2, 8.3, 8.4, and 8.5 compatibility** across active and inactive plugins, must-use plugins, and themes using an embedded, in-process build of `PHP_CodeSniffer` and `PHPCompatibilityWP` (no shell commands or external services required)
- **Version-guard and polyfill context filtering** (`THRG_PC_Context`), automatically recognizing `version_compare()`, `PHP_VERSION_ID`, `function_exists()`, `class_exists()`, `interface_exists()`, `trait_exists()`, `defined()`, and `extension_loaded()` guards so safe backward-compatible code is downgraded to informational notes instead of false errors
- **PHP 8.2+ dynamic property deprecation analysis** with built-in false-positive prevention for `#[AllowDynamicProperties]` (including class inheritance), `stdClass` (and subclasses), magic `__set()` handlers, constructor-promoted properties, and declared class properties
- **Actionable finding metadata** on every match: exact `file:line`, target PHP version, triggered rule code (`source`), and whether the file belongs to the **plugin/theme's own code** or a **bundled third-party dependency** (`vendor/`, `freemius/`, `action-scheduler/`, `pear/`, `phpmailer/`, etc.)
- **"Fixed in a newer WordPress.org version" verification** (`THRG_PC_Prescan::check_wporg_fix()`), checking whether a plugin from WordPress.org that fails a scan has a newer release, stream-scanning the newer package in memory against your target PHP version, and badging the card (`Fixed in vX.Y on WordPress.org`, `Fatal errors fixed in vX.Y`, or `Still fails in vX.Y`)
- **Runtime PHP deprecation notice log, grouped by plugin** (`THRG_PC_Runtime_Log`), with an opt-in live `E_DEPRECATED` / `E_USER_DEPRECATED` collector (including backtrace attribution for WordPress `_deprecated_function()` calls) and an on-demand `wp-content/debug.log` deprecation importer
- **Server PHP version, end-of-life countdown, and missing PHP security fixes** (including PHP2Shell, CVE-2026-109618 and CVE-2026-109619)
- **Optional in-memory Pending Update Pre-Scan** (off by default): inspects offered plugin and theme update zip packages in memory against your running PHP version before you apply them

Each finding is ordered by urgency: **fatal incompatibilities appear first** right at the top of the report, followed by deprecation warnings (with collapsible technical code tables so you aren't overwhelmed), server lifecycle checks, and a collapsed list of compatible components.

**Report history.** When history is enabled, completed scans are saved locally on your own site (up to 25 reports within a 600,000-byte storage limit), tracking what is new and what has been resolved for that PHP target since the previous check. Reopen, delete, clear, or pause history at any time.

**Share a report.** Copy it as plain text for a developer or support ticket, download a `.txt` report, print a clean summary, or email a formatted "PHP readiness" briefing to some or all of the site's administrators.

**Weekly or monthly PHP readiness & update-alert email.** Off until you turn it on. Choose a **Weekly** day of the week (default) or **Monthly** day of the month (1–28): PHP Checker rescans against your chosen PHP target (and pre-scans pending updates) and emails administrators on schedule or only when an update or the next PHP version has errors or warnings.

**Support.** A friendly Support container at the bottom of the screen invites questions and feedback (link to the WordPress.org support forum, notional until the plugin is approved) and has a buy-Jim-a-coffee link.

## Email a report to administrators

1. Under **Tools → PHP Checker**, open **Share, Download, Print, or Email This Report** and expand **Email Report to Site Administrators**.
2. Review the administrator recipients (the first 25 administrator accounts by display name with valid email addresses are listed).
3. Optionally add a personal note (up to 1,000 characters) and click **Email PHP Report**.

Subjects follow the THRG email standard: `[domain] [PHP Checker] PHP X.Y Check: …`, for example `[example.com] [PHP Checker] PHP 8.3 Check: 1 issue needs attention`. Both the subject and body identify the current home URL domain, including subdomains and explicit local ports (such as `localhost:8081`), omitting scheme and path with a site URL fallback.

The branded HTML email fills the available width and includes a plain-text alternative. It contains a personal greeting, optional note, readiness narrative, change comparison against the previous report for the same PHP target, findings grouped by urgency, up to eight `file:line` table rows per component, and a link to open the full report in WordPress. Each recipient gets a separate message; `Reply-To` uses the sender's validated email address. Sending is rate-limited to once per minute per user.

## Scheduled PHP readiness email

Under **Tools → PHP Checker**, open **Scheduled Readiness Email** and expand the schedule and recipients settings. Choose **Weekly** (the default, with a day of the week) or **Monthly**. Tick **Send a monthly PHP compatibility report email**, choose your target PHP version (or **Recommended next PHP**), pick a day of the month from 1 to 28 (around 8:00 AM site time), choose whether to send every month or **Only send the monthly email when errors or warnings are found**, and select **All site administrators** or specific administrators.

- Off by default; nothing is scheduled until an administrator enables and saves it.
- Uses WordPress's own scheduler with a single queued event (`thrg_pc_scheduled_report`), a 10-minute run lock, and automatic schedule healing on `admin_init`.
- Deactivating cancels the queued run; uninstalling removes all plugin options and cached scan results.

## PHP security fixes

The Server PHP lifecycle check compares the running PHP version against official `php.net` security support dates (PHP 7.4 through 8.5) and a built-in list of critical PHP security releases (starting with PHP2Shell, CVE-2026-109618 and CVE-2026-109619, fixed in PHP 8.2.35, 8.3.36, 8.4.27, and 8.5.12 on October 21, 2026). No external requests are made during lifecycle checks, and the report notes when the built-in patch list is more than 90 days old.

## Privacy and scope

Static compatibility scans read local PHP files inside `wp-content/plugins`, `wp-content/mu-plugins`, and `wp-content/themes` in 5-second time-boxed AJAX slices without executing plugin or theme code, applying fixes, or sending file contents to external services. Optional Update Pre-Scan (off by default) downloads pending update zip packages from their configured update URLs to a temporary file, streams PHP entries in memory, and deletes the temporary archive immediately.

Scan results and report history are stored locally in non-autoloaded WordPress options and automatically prune uninstalled plugins/themes or outdated versions. Reports leave the site only when an administrator copies, downloads, prints, or emails them, or enables the monthly readiness email. Plugin update discovery for PHP Checker itself contacts GitHub independently of scans.

## Install

Upload the plugin ZIP under **Plugins → Add New → Upload Plugin**, or place the `thrg-php-checker` folder in `wp-content/plugins/`, then activate it and open **Tools → PHP Checker**.

The plugin lives in the `thrg-php-checker` folder and starts from `index.php`. Updates come from this repository's [GitHub releases](https://github.com/hackrepair/thrg-php-checker/releases "All PHP Checker releases and download files").

### WordPress.org

Submitted to the WordPress.org plugin directory on November 12, 2026 and awaiting review, with the slug `thrg-php-checker`. Once approved, the listing will be at `https://wordpress.org/plugins/thrg-php-checker/`. Copies installed from WordPress.org update through WordPress.org; copies installed from this repository keep updating from GitHub releases. The two editions share one source tree; see [PROJECT.md](PROJECT.md#wordpressorg-edition) for how the WordPress.org build differs.

## Author and support

**[Jim Walker](https://hackrepair.com/)** — The Hack Repair Guy. Hi, I'm Jim. I built PHP Checker and I'm happy to help if you get stuck; questions and ideas are always welcome.

[Questions, ideas and community support](https://github.com/hackrepair/thrg-php-checker/discussions) · [Report a bug](https://github.com/hackrepair/thrg-php-checker/issues) · [Security reporting](SECURITY.md) · [Buy Jim a coffee](https://hackrepair.com/donations/buy-jim-a-coffee)

## License and credits

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html "Read the GNU General Public License, version 2").

Static analysis uses [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) (BSD-3-Clause), [PHPCSUtils](https://github.com/PHPCSStandards/PHPCSUtils) (LGPL-3.0-or-later), [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) (LGPL-3.0-or-later), and [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP) (LGPL-3.0-or-later). Update checks use [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker "Plugin Update Checker on GitHub") by Jānis Elsts (MIT license), included unmodified.

Made by [The Hack Repair Guy](https://hackrepair.com/ "The Hack Repair Guy: WordPress security and hack repair").

## Changelog

See the **Changelog** section of [`readme.txt`](readme.txt).
