<?php
/**
 * G5-IMP-07-S03 deterministic legacy source item reader contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Prefixed_Legacy_Source_Item_Reader;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 s03r assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03r_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s03r same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03r_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 s03r failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03r_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Migration_Failure $failure ) {
		g5_s03r_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5-IMP-07-S03 deterministic legacy source item reader contract.
 */
final class G5_S03R_Database {
	/**
	 * Prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array();
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		$this->queries[] = $sql;
		return 'G5S03R:' . base64_encode( serialize( array( $sql, $values ) ) );
	}

	/**
	 * Get results.
	 *
	 * @param string $prepared Prepared.
	 * @param string $output Output.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_results( string $prepared, string $output ): array {
		if ( ! str_starts_with( $prepared, 'G5S03R:' ) ) {
			throw new RuntimeException( 'Reader query was not prepared.' );
		}
		$value = unserialize( base64_decode( substr( $prepared, 7 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $value ) || ! is_string( $value[0] ?? null ) || ! is_array( $value[1] ?? null ) ) {
			throw new RuntimeException( 'Reader prepared query is invalid.' );
		}
		$sql    = $value[0];
		$values = $value[1];
		if ( 1 !== preg_match( '/FROM `([A-Za-z0-9_]+)` WHERE `id` > %d ORDER BY `id` ASC LIMIT %d/D', $sql, $matches ) ) {
			throw new RuntimeException( 'Reader SQL shape drifted: ' . $sql );
		}
		$after = (int) ( $values[0] ?? -1 );
		$limit = (int) ( $values[1] ?? -1 );
		$rows  = array_values(
			array_filter(
				$this->tables[ $matches[1] ] ?? array(),
				static fn ( array $row ): bool => (int) $row['id'] > $after
			)
		);
		usort( $rows, static fn ( array $left, array $right ): int => (int) $left['id'] <=> (int) $right['id'] );
		return array_slice( $rows, 0, $limit );
	}
}

/**
 * G5 s03r row.
 *
 * @param Legacy_Source_Profile_0_1_2 $profile Profile.
 * @param string                      $logical_table Logical table.
 * @param int                         $id Id.
 * @param string                      $title Title.
 * @return array<string,mixed>
 */
function g5_s03r_row( Legacy_Source_Profile_0_1_2 $profile, string $logical_table, int $id, string $title = '' ): array {
	$row       = array_fill_keys( $profile->columns( $logical_table ), null );
	$row['id'] = (string) $id;
	if ( array_key_exists( 'title', $row ) ) {
		$row['title'] = '' === $title ? $logical_table . '-' . $id : $title;
	}
	if ( array_key_exists( 'tenant_context', $row ) ) {
		$row['tenant_context'] = 'legacy-site-7';
	}
	return $row;
}

$canonical      = new Canonical_Json();
$profile        = new Legacy_Source_Profile_0_1_2();
$database       = new G5_S03R_Database();
$template_table = 'wp_oj_ai_worksheet_templates';
$question_table = 'wp_oj_ai_worksheet_questions';
for ( $id = 1; $id <= 251; ++$id ) {
	$database->tables[ $template_table ][] = g5_s03r_row( $profile, 'worksheet_templates', $id );
}
$database->tables[ $question_table ] = array(
	g5_s03r_row( $profile, 'worksheet_questions', 1 ),
	g5_s03r_row( $profile, 'worksheet_questions', 2 ),
);
$reader                              = new WordPress_Prefixed_Legacy_Source_Item_Reader( $canonical, $profile, static fn (): object => $database );

$first = $reader->read( Legacy_Source_Profile_0_1_2::PROFILE, 0, 0, 1, 250 );
g5_s03r_same( 250, $first->count(), 'Reader did not enforce the 250-item batch boundary.' );
g5_s03r_same( 'worksheet_templates', $first->items()[0]->source_type(), 'Reader did not skip mapping/exclude tables.' );
g5_s03r_same( 1, $first->items()[0]->item_no(), 'Reader first item number drifted.' );
g5_s03r_same( '250', $first->items()[249]->source_key(), 'Reader source key is not the exact legacy ID.' );
g5_s03r_same( 250, $first->next_after_source_id(), 'Reader restart source cursor drifted.' );
g5_s03r_same( 251, $first->next_item_no(), 'Reader restart item number drifted.' );
g5_s03r_same( false, $first->exhausted(), 'A full first batch was falsely marked exhausted.' );

$restart = $reader->read( Legacy_Source_Profile_0_1_2::PROFILE, 0, 0, 1, 250 );
g5_s03r_same( $first->items()[0]->source_checksum(), $restart->items()[0]->source_checksum(), 'Reader checksum is not deterministic across restart.' );
g5_s03r_same( $first->next_table_index(), $restart->next_table_index(), 'Reader table cursor is not deterministic across restart.' );

$second = $reader->read(
	Legacy_Source_Profile_0_1_2::PROFILE,
	$first->next_table_index(),
	$first->next_after_source_id(),
	$first->next_item_no(),
	250
);
g5_s03r_same( 3, $second->count(), 'Reader did not continue across the next migrate table.' );
g5_s03r_same( array( 'worksheet_templates', 'worksheet_questions', 'worksheet_questions' ), array_map( static fn ( $item ): string => $item->source_type(), $second->items() ), 'Reader dependency order drifted.' );
g5_s03r_same( array( 251, 252, 253 ), array_map( static fn ( $item ): int => $item->item_no(), $second->items() ), 'Reader item numbers are not contiguous across tables.' );
g5_s03r_same( true, $second->exhausted(), 'Reader did not mark the fixed source profile exhausted.' );
g5_s03r_same( count( $profile->tables() ), $second->next_table_index(), 'Reader terminal table cursor drifted.' );
g5_s03r_same( 254, $second->next_item_no(), 'Reader terminal item cursor drifted.' );

$dependency_database                                   = new G5_S03R_Database();
$dependency_database->tables['wp_oj_ai_curriculums'][] = g5_s03r_row( $profile, 'curriculums', 9 );
$dependency_database->tables[ $template_table ][]      = g5_s03r_row( $profile, 'worksheet_templates', 3 );
$dependency_reader                                     = new WordPress_Prefixed_Legacy_Source_Item_Reader( $canonical, $profile, static fn (): object => $dependency_database );
$dependency_batch                                      = $dependency_reader->read( Legacy_Source_Profile_0_1_2::PROFILE, 0, 0, 1, 2 );
g5_s03r_same(
	array( 'curriculums', 'worksheet_templates' ),
	array_map( static fn ( $item ): string => $item->source_type(), $dependency_batch->items() ),
	'Reader did not execute owner dependencies before worksheet aggregates.'
);
g5_s03r_same( 'worksheet_templates', array_keys( $profile->tables() )[8], 'Physical source inventory order was unexpectedly rewritten.' );
g5_s03r_same( 'curriculums', array_keys( $profile->execution_tables() )[8], 'Dependency execution profile does not start owner records before worksheets.' );

foreach ( $database->queries as $sql ) {
	g5_s03r_assert( ! str_contains( $sql, 'SELECT *' ), 'Reader used SELECT * instead of the exact profile column allowlist.' );
	g5_s03r_assert( ! str_contains( $sql, 'OFFSET' ), 'Reader used unstable OFFSET pagination.' );
	foreach ( array( 'oj_ai_classes', 'oj_ai_groups', 'oj_ai_group_members', 'oj_ai_activity_events', 'oj_ai_audit_logs', 'oj_ai_reports', 'oj_ai_import_runs', 'oj_ai_import_items' ) as $forbidden_table ) {
		g5_s03r_assert( ! str_contains( $sql, '`wp_' . $forbidden_table . '`' ), 'Reader queried a mapping/exclude table as a migration item source: ' . $forbidden_table );
	}
}

$changed_database                                        = clone $database;
$changed_database->queries                               = array();
$changed_database->tables[ $template_table ][0]['title'] = 'changed';
$changed_reader = new WordPress_Prefixed_Legacy_Source_Item_Reader( $canonical, $profile, static fn (): object => $changed_database );
$changed        = $changed_reader->read( Legacy_Source_Profile_0_1_2::PROFILE, 0, 0, 1, 1 );
g5_s03r_assert( $first->items()[0]->source_checksum() !== $changed->items()[0]->source_checksum(), 'Reader checksum ignored an allowlisted source value change.' );

g5_s03r_failure( static fn () => $reader->read( 'unknown@0.0.0', 0, 0, 1, 250 ), Legacy_Migration_Failure::EXECUTION_INVALID, 'Reader accepted an unsupported source profile.' );
g5_s03r_failure( static fn () => $reader->read( Legacy_Source_Profile_0_1_2::PROFILE, 0, 0, 1, 251 ), Legacy_Migration_Failure::EXECUTION_INVALID, 'Reader accepted a batch above 250 items.' );
g5_s03r_failure( static fn () => $reader->read( Legacy_Source_Profile_0_1_2::PROFILE, count( $profile->tables() ), 1, 1, 1 ), Legacy_Migration_Failure::EXECUTION_INVALID, 'Reader accepted a nonterminal cursor after profile exhaustion.' );

$column_drift_database          = clone $database;
$column_drift_database->queries = array();
unset( $column_drift_database->tables[ $template_table ][0]['title'] );
$column_drift_reader = new WordPress_Prefixed_Legacy_Source_Item_Reader( $canonical, $profile, static fn (): object => $column_drift_database );
g5_s03r_failure( static fn () => $column_drift_reader->read( Legacy_Source_Profile_0_1_2::PROFILE, 0, 0, 1, 1 ), Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH, 'Reader accepted a row outside the exact column allowlist.' );

$collision_database         = clone $database;
$collision_database->prefix = '';
$collision_reader           = new WordPress_Prefixed_Legacy_Source_Item_Reader( $canonical, $profile, static fn (): object => $collision_database );
g5_s03r_failure( static fn () => $collision_reader->read( Legacy_Source_Profile_0_1_2::PROFILE, 0, 0, 1, 1 ), Legacy_Migration_Failure::SOURCE_COLLISION, 'Reader treated an unprefixed legacy source as a target-safe source.' );

$source_code = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Prefixed_Legacy_Source_Item_Reader.php' );
g5_s03r_assert( is_string( $source_code ), 'Reader runtime source could not be read.' );
foreach ( array( 'SELECT *', ' OFFSET ', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'oj_ai_import_runs', 'oj_ai_import_items', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
	g5_s03r_assert( ! str_contains( $source_code, $forbidden ), 'Reader crossed a frozen source-only boundary: ' . $forbidden );
}

fwrite( STDOUT, "G5-IMP-07-S03 reader passed: exact-profile migrate-only columns, keyset cursor, 250-item bound, deterministic checksum, and source/target prefix separation are frozen.\n" );
