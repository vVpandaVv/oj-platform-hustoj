<?php
/**
 * Loads provider-owned public API definitions for static analysis only.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

// Mirror constants supplied by the plugin entrypoint without booting WordPress or the plugin.
$addon_root = dirname( __DIR__ );
define( 'OJ_AI_DASHBOARD_FILE', $addon_root . '/oj-ai-dashboard.php' );
define( 'OJ_AI_DASHBOARD_DIR', $addon_root . '/' );
define( 'OJ_AI_DASHBOARD_BASENAME', basename( $addon_root ) . '/oj-ai-dashboard.php' );
define( 'OJ_AI_DASHBOARD_URL', 'https://example.invalid/wp-content/plugins/' . basename( $addon_root ) . '/' );

$loader = require dirname( __DIR__ ) . '/vendor/autoload.php';
$api    = dirname( __DIR__, 2 ) . '/oj-integration-rebuild/oj-platform/src/AddonApi/V1';
if ( ! is_dir( $api ) ) {
	throw new RuntimeException( 'Static analysis requires the checked-out OJ Platform public API.' );
}
$loader->addPsr4( 'CodingPanda\\OjPlatform\\AddonApi\\V1\\', $api );
