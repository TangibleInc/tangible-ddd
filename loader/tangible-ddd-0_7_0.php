<?php
/**
 * Version-unique Composer "files" entry of tangible/ddd 0.7.0 (register 1.1
 * and 1.5; report B1, D-2).
 *
 * Composer includes a package's "files" entries once per process across all
 * vendor trees, keyed by package name plus relative path. Every legacy copy
 * (0.2.x-0.6.x) uses the key `tangible/ddd:tangible-ddd.php`, so whichever
 * legacy vendor autoloads first suppresses all the others, and a copy that
 * shared that key would never register once a legacy copy had run. This file
 * carries the release slug in its name, so each release has its own key and
 * always reaches the version registry; two vendored copies of the SAME
 * release still dedup to one include, which is harmless.
 *
 * It only forwards. Every symbol lives in tangible-ddd.php behind
 * class_exists / function_exists guards. LoaderIdentityTest keeps the file
 * name, the plugin header, the constant, the register literal and the
 * function slugs in agreement.
 */

require_once __DIR__ . '/../tangible-ddd.php';
