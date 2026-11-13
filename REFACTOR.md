# Refactor Review — *The Hack Repair Guy's PHP Checker* (`v1.2.4`)

**Date:** November 12, 2026  
**Baseline:** `v1.2.4` (`phpcs` 23/23 passing, `tests/run.php` 113/113 assertions passing)

Following rapid feature delivery across `1.1.0`–`1.2.4` (Readiness Verdict UX, WordPress.org fix verification, runtime deprecation logging, scheduled rescans, and `v1.2.4` guard/severity/triage accuracy), core functionality is solid and well-tested, but several files have grown past maintainable single-responsibility boundaries.

This document outlines the structural hotspots observed in the `v1.2.4` codebase and proposes a phased, behavior-preserving refactor plan.

---

## 1. Current Footprint & Hotspots

| File | Lines | Primary Responsibility / Smell |
| :--- | ---: | :--- |
| `includes/class-thrg-pc-context.php` | 1,442 | Combines two distinct static-analysis engines: guard/polyfill context analysis + PHP 8.2+ dynamic property analysis (`43` methods). |
| `includes/view-report.php` | 1,154 | Monolithic procedural template mixing layout, navigation, cards, duplicated finding-table markup, runtime logs, history, and email UI. |
| `assets/checker.css` | 1,080 | Single stylesheet covering layout, navigation, cards, opacity tiers, tooltips, modals, and responsive rules. |
| `includes/trait-thrg-pc-report-methods.php` | 1,030 | Trait used solely to split `THRG_PHP_Checker`; contains `evaluate()` (`348` lines) and `component_finding()` (`367` lines). |
| `includes/class-thrg-pc-prescan.php` | 911 | Pending-update ZIP pre-scanner + WP.org API client (`check_wporg_fix()` is `204` lines; `step()` is `151` lines; `zip_open()` is `78` lines). |
| `includes/class-thrg-php-checker.php` | 788 | Admin controller mixing page bootstrap, AJAX handlers, and `admin_post` handlers with repeated auth/nonce/redirect boilerplate. |
| `tests/run.php` | 669 | Single procedural test runner (`113` assertions). |
| `includes/class-thrg-pc-email.php` | 575 | Email builder + sender (`html()` is `100` lines, `text()` is `60` lines). |
| `includes/class-thrg-pc-runtime-log.php` | 543 | Live deprecation hook + `debug.log` importer (`import_debug_log()` is `64` lines). |
| `includes/class-thrg-pc-scanner.php` | 540 | Component discovery + time-boxed AJAX scan queue (`components()` `88` lines, `step_locked()` `103` lines). |
| `assets/js/checker.js` | 538 | Monolithic admin script (scan polling, expand/collapse drawers, WP.org fix checks, tooltips, email/history interactions). |

---

## 2. Prioritized Refactoring Recommendations

### Priority 1 — Replace `trait-thrg-pc-report-methods.php` with Focused Services
* **Current State:** `THRG_PC_Report_Methods` (`1,030` lines) exists as a PHP `trait` mixed into `THRG_PHP_Checker`. Inside it:
  * `evaluate()` spans **348 lines** (L197–L544), handling component sorting, server PHP lifecycle/advisory checks, update-check summaries, and verdict aggregation.
  * `component_finding()` spans **367 lines** (L545–L911), handling finding sorting, fatal vs. non-fatal counts, "What to do" guidance generation, and WP.org badge state.
* **Target Design:**
  * `THRG_PC_Server_Advisory`: Encapsulate `PHP_SUPPORT`, `PHP_ADVISORIES`, `PATCH_LIST_AS_OF`, and server lifecycle/security evaluation.
  * `THRG_PC_Evaluator`: Dedicated class with `evaluate()` decomposed into small helpers (`build_component_rows()`, `sort_findings()`, `build_action_guidance()`, `summarize_counts()`).
  * `THRG_PC_Text_Report`: Dedicated plain-text formatter (`plain_text()`).
* **Benefit:** Eliminates 350-line methods and replaces an artificial trait split with testable, single-purpose classes.

### Priority 2 — Split `includes/view-report.php` (`1,154` lines) into Partials & Eliminate Duplicated Table Markup
* **Current State:** `view-report.php` renders the entire report page in one template and duplicates the finding row `<tr>...</tr>` rendering across the initial 8-row table (`L300–L333`) and the overflow drawer (`L350+`).
* **Target Design:**
  * Extract a small row-rendering helper (or partial `includes/partials/finding-row.php`) shared by `$initial_issues`, `$overflow_issues`, and `$guarded_notes`.
  * Split `view-report.php` into focused partials under `includes/views/`:
    * `report-nav.php` (left urgency navigation + tools/settings tooltips)
    * `report-verdict.php` (Readiness Verdict banner + summary tiles)
    * `report-card.php` (component finding card + technical code drawer)
    * `report-sections.php` (runtime deprecations, update pre-scan, history, email panel)
* **Benefit:** Cuts template size by ~70% per file and guarantees column/badge consistency across initial and overflow finding tables.

### Priority 3 — Separate Dynamic Property Analysis from `THRG_PC_Context` (`1,442` lines)
* **Current State:** `class-thrg-pc-context.php` houses both:
  1. Guard/polyfill token-stream analysis (`guarded()`, `eval_guard()`, `collect_dead_branch_vars()`, etc.)
  2. PHP 8.2+ dynamic property analysis (`dynamic_property_findings()`, `104` lines; `extract_class_info()`, `126` lines)
* **Target Design:** Move `dynamic_property_findings()`, `extract_class_info()`, and their class/attribute/property helpers into `includes/class-thrg-pc-dynamic-properties.php` (`THRG_PC_Dynamic_Properties`).
* **Benefit:** Keeps `THRG_PC_Context` focused purely on version/existence guard evaluation (~1,000 lines -> cleaner scope) and isolates PHP 8.2+ AST/token rules.

### Priority 4 — Decompose `THRG_PC_Prescan::check_wporg_fix()` (`204` lines) & Unify ZIP/File Scanning
* **Current State:** `check_wporg_fix()` (`L685–L888`) mixes slug normalization, transient cache lookup, `wp_remote_get()` WP.org API querying, ZIP download, ZIP entry scanning, and response shaping (repeating the `status/version/verdict/errors/warnings/message` array structure across early returns).
* **Target Design:**
  * Add a `wporg_result( $status, $message, $extra = array() )` helper to standardize return payloads.
  * Split into `resolve_wporg_package()`, `download_and_scan_zip()`, and `summarize_wporg_fix()`.
  * Audit shared file-filtering / PHP-extension checks between `THRG_PC_Prescan` and `THRG_PC_Scanner` so exclusions and size ceilings live in one helper (`THRG_PC_Engine` or `THRG_PC_Scanner`).

### Priority 5 — Dry Up Controller Boilerplate in `THRG_PHP_Checker` (`788` lines)
* **Current State:** `ajax_scan_start()` (`64` lines), `handle_start_post()` (`66` lines), `handle_save_settings()` (`66` lines), and `handle_email_report()` (`76` lines) repeat nonce verification, `manage_options` capability checks, target PHP sanitization, and redirect/JSON error responses.
* **Target Design:** Introduce `verify_admin_post( $action )` and `verify_ajax_request( $action )` helpers to centralize auth/nonce checks and reduce handler length by ~30%.

### Priority 6 — Modularize `assets/checker.css` & `assets/js/checker.js` + Keep `PROJECT.md` Synced
* **Assets:** Group `checker.css` (`1,080` lines) with clear section TOCs (or split into `layout.css`, `cards.css`, `nav.css`) and organize `checker.js` (`538` lines) into small IIFE/module sections (`scanController`, `drawers`, `wporgCheck`, `tooltips`).
* **Documentation:** Update `PROJECT.md` to reflect current `v1.2.4` test assertion counts (`113` assertions, currently listed as `103`) and build artifact version (`1.2.4`, currently listed as `1.2.1`), and reference `REFACTOR.md` for contributor architecture guidance.

---

## 3. Guardrails for Executing Refactors

1. **Behavior-Preserving Steps:** Refactor one module at a time (e.g., `view-report.php` partials first, then `THRG_PC_Dynamic_Properties`, then `THRG_PC_Evaluator`).
2. **Quality Gates After Every Step:**
   ```sh
   /usr/local/bin/phpcs --standard=phpcs.xml.dist
   php tests/run.php
   ```
3. **Preserve Public Contracts:** Keep `THRG_PC_Results::for_target()`, `THRG_PC_Engine::scan_content()`, `THRG_PC_Context::guarded_lines()`, and `THRG_PC_Context::dynamic_property_findings()` signatures intact (or provide thin delegating wrappers) so `tests/run.php` and WordPress hooks remain backwards-compatible.

---

## 4. Execution Log (Completed)

- **Stage 1 — `view-report.php` Modularization:** Extracted `includes/views/{report-nav,report-cards,finding-row,report-clean,report-share-schedule,report-tools}.php` and deduplicated finding-row rendering across initial and overflow tables.
- **Stage 2 — Dynamic Property Extraction:** Extracted `THRG_PC_Dynamic_Properties` (`includes/class-thrg-pc-dynamic-properties.php`) from `class-thrg-pc-context.php`, keeping `THRG_PC_Context::dynamic_property_findings()` as a backwards-compatible wrapper.
- **Stage 3 — `THRG_PC_Prescan::check_wporg_fix()` Decomposition:** Split into `wporg_result()`, `resolve_wporg_package()`, `scan_wporg_package()`, and `summarize_wporg_fix()`.
- **Stage 4 — Controller Capability Helpers:** Added `require_admin_ajax()` and `require_admin_post()` in `includes/class-thrg-php-checker.php` to deduplicate capability checks across all AJAX and `admin_post` handlers.
- **Stage 5 — Report Trait Replacement:** Replaced `includes/trait-thrg-pc-report-methods.php` with `THRG_PC_Server_Advisory` (`includes/class-thrg-pc-server-advisory.php`), `THRG_PC_Evaluator` (`includes/class-thrg-pc-evaluator.php`), and `THRG_PC_Text_Report` (`includes/class-thrg-pc-text-report.php`), keeping thin delegators on `THRG_PHP_Checker`.
- **Stage 6 — `PROJECT.md` Sync & Final Verification:** Updated `PROJECT.md` to reflect the new architecture, `32/32` PHPCS files, `113` assertions, and `1.2.4` build artifacts.

