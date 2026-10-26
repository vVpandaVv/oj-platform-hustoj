<?php
/**
 * Report and migration boundaries reject malformed fields without PHP warnings.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

$ojai_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $ojai_root . DIRECTORY_SEPARATOR );
require_once $ojai_root . '/src/Core/Autoloader.php';
( new Autoloader( $ojai_root . '/src' ) )->register();

$ojai_canonical = new Canonical_Json();
$ojai_profiles  = new Report_Snapshot_Profile( $ojai_canonical );
$ojai_filter    = Report_Filter::defaults();
$ojai_profile   = $ojai_profiles->filter( 'group', 'group:10', 'revision-1', $ojai_filter );
$ojai_manifest  = array(
	'schema_id'      => Report_Snapshot_Profile::SOURCE_MANIFEST_SCHEMA,
	'schema_version' => Report_Snapshot_Profile::SOURCE_MANIFEST_VERSION,
	'items'          => array(),
);
$ojai_collected = array(
	'items'                => array(),
	'source_manifest'      => $ojai_manifest,
	'source_manifest_hash' => hash( 'sha256', $ojai_canonical->encode( $ojai_manifest ) ),
);
$ojai_snapshot  = static fn ( array $profile, array $collected ): array => $ojai_profiles->snapshot(
	str_repeat( '0', 25 ) . '1',
	'2026-09-12 00:00:00',
	'group',
	'group:10',
	'revision-1',
	$ojai_filter,
	$profile,
	$collected
);
$ojai_table     = array(
	'logical_table' => 'worksheets',
	'source_table'  => 'wp_worksheets',
	'disposition'   => 'migrate',
	'rows'          => 2,
);
$ojai_legacy    = static fn ( array $table, array $counts ): Legacy_Source_Snapshot => new Legacy_Source_Snapshot(
	'legacy:0.1.2',
	'0.1.2',
	str_repeat( 'a', 64 ),
	array( $table ),
	0,
	0,
	array(
		'context' => 0,
		'user'    => 0,
	),
	$counts,
	array()
);

if ( 0 !== $ojai_snapshot( $ojai_profile, $ojai_collected )['row_count']
	|| 2 !== $ojai_legacy( $ojai_table, array() )->row_count() ) {
	throw new RuntimeException( 'Valid source profiles were changed.' );
}

$ojai_plans                    = new Legacy_Migration_Plan( $ojai_canonical );
$ojai_plan                     = $ojai_plans->build( 'fixture-site', $ojai_legacy( $ojai_table, array() ) );
$ojai_previous_plan            = $ojai_plan;
$ojai_previous_plan['profile'] = Legacy_Migration_Plan::LEGACY_PROFILE;
unset( $ojai_previous_plan['mapping_manifest'], $ojai_previous_plan['plan_hash'] );
$ojai_previous_plan['plan_hash'] = hash( 'sha256', $ojai_canonical->encode( $ojai_previous_plan ) );
$ojai_plans->assert_valid( $ojai_plan );
$ojai_plans->assert_valid( $ojai_previous_plan );

$ojai_rejected = 0;
set_error_handler(
	static function ( int $severity, string $message ): never {
		throw new ErrorException( $message, 0, $severity );
	}
);
try {
	foreach ( array( array(), new stdClass(), null, 23 ) as $ojai_invalid ) {
		$ojai_cases = array(
			static fn () => $ojai_plans->assert_valid( array_replace( $ojai_plan, array( 'plan_hash' => $ojai_invalid ) ) ),
			static fn () => $ojai_plans->assert_valid( array_replace( $ojai_previous_plan, array( 'plan_hash' => $ojai_invalid ) ) ),
			static fn () => $ojai_snapshot( array_replace( $ojai_profile, array( 'json' => $ojai_invalid ) ), $ojai_collected ),
			static fn () => $ojai_snapshot( array_replace( $ojai_profile, array( 'hash' => $ojai_invalid ) ), $ojai_collected ),
			static fn () => $ojai_snapshot( $ojai_profile, array_replace( $ojai_collected, array( 'source_manifest_hash' => $ojai_invalid ) ) ),
			static fn () => $ojai_legacy( array_replace( $ojai_table, array( 'logical_table' => $ojai_invalid ) ), array() ),
			static fn () => $ojai_legacy( array_replace( $ojai_table, array( 'source_table' => $ojai_invalid ) ), array() ),
			static fn () => $ojai_legacy(
				$ojai_table,
				array(
					array(
						'code'  => $ojai_invalid,
						'count' => 1,
					),
				)
			),
		);
		foreach ( $ojai_cases as $ojai_case ) {
			try {
				$ojai_case();
				throw new RuntimeException( 'Malformed profile was accepted.' );
			} catch ( InvalidArgumentException ) {
				++$ojai_rejected;
			}
		}
	}
} finally {
	restore_error_handler();
}
if ( 32 !== $ojai_rejected ) {
	throw new RuntimeException( 'Malformed profile coverage is incomplete.' );
}
echo "Report and legacy profile shapes: valid profiles and 32 malformed fields passed.\n";
