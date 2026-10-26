<?php
/**
 * Plugin uninstall data-preservation boundary.
 *
 * All add-on tables, options, private files, and platform assignments are
 * intentionally preserved. Permanent deletion is available only through the
 * approved purge flow while the add-on is active.
 *
 * @package OJAI\Dashboard
 * @see A-DS15-D22
 * @see G5-IMP-08-S01
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Intentionally no mutation: uninstall is always preservation-only.
