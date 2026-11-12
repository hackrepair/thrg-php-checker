Bundled scan engine (runtime code, not development tools)
=========================================================

The Hack Repair Guy's PHP Checker runs these libraries inside WordPress, in the
same PHP request, whenever an administrator scans plugins, must-use plugins,
themes, or offered updates. The plugin loads them via require_once from
includes/class-thrg-pc-engine.php; nothing here is a command-line tool, a test
suite, or a build step.

Only files the scan loads at runtime are kept: the command-line runner, reports,
doc generators, file filters, result cache, tests, PHPUnit test helpers, docs,
and coding standards the scan does not use (PEAR, PSR1, PSR2, PSR12, Squiz,
Zend, MySource, Generic) are removed. Each folder keeps its license file.

Folder                       Package (composer.json in this folder)          Version        License
php-codesniffer/             squizlabs/php_codesniffer                       4.0.4          BSD-3-Clause
phpcsutils/                  phpcsstandards/phpcsutils                       1.2.3          LGPL-3.0-or-later
phpcompatibility/            phpcompatibility/php-compatibility              dev-develop @ a0fd248812fd080338dc1227cf6efb1d1b05f07c   LGPL-3.0-or-later
phpcompatibility-wp/         phpcompatibility/phpcompatibility-wp            3.0.0-alpha2   LGPL-3.0-or-later
phpcompatibility-paragonie/  phpcompatibility/phpcompatibility-paragonie     2.0.0-alpha2   LGPL-3.0-or-later

Sources:
- PHP_CodeSniffer: https://github.com/PHPCSStandards/PHP_CodeSniffer
- PHPCSUtils: https://github.com/PHPCSStandards/PHPCSUtils
- PHPCompatibility, PHPCompatibilityWP, PHPCompatibilityParagonie:
  https://github.com/PHPCompatibility/PHPCompatibility
  https://github.com/PHPCompatibility/PHPCompatibilityWP
  https://github.com/PHPCompatibility/PHPCompatibilityParagonie

The inner folder names (PHPCompatibility, PHPCompatibilityWP, PHPCSUtils, ...)
are the coding-standard names PHP_CodeSniffer looks up at runtime, so they
keep their upstream names.
