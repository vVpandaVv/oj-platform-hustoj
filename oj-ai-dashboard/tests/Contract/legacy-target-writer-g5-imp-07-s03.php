<?php
/**
 * G5-IMP-07-S03 production legacy target writer contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 s03t assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03t_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s03t same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03t_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 s03t transform failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03t_transform_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Target_Transformation_Failure $failure ) {
		g5_s03t_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no transform failure was raised.' );
}

/**
 * G5 s03t storage failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03t_storage_failure( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Migration_Failure $failure ) {
		g5_s03t_same( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no storage failure was raised.' );
}

/**
 * G5-IMP-07-S03 production legacy target writer contract.
 */
final class G5_S03T_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Insert id.
	 *
	 * @var int
	 */
	public int $insert_id = 0;
	/**
	 * Fail table.
	 *
	 * @var string|null
	 */
	public ?string $fail_table = null;
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'oj_ai_import_items'          => array(),
		'oj_ai_curriculums'           => array(),
		'oj_ai_achievement_standards' => array(),
	);

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		$this->queries[] = $sql;
		return 'G5S03T:' . base64_encode( serialize( array( $sql, $values ) ) );
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param string $output Output.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $prepared, string $output ): ?array {
		if ( ! str_starts_with( $prepared, 'G5S03T:' ) ) {
			throw new RuntimeException( 'Target query was not prepared.' );
		}
		$value  = unserialize( base64_decode( substr( $prepared, 7 ), true ), array( 'allowed_classes' => false ) );
		$sql    = $value[0] ?? null;
		$values = $value[1] ?? null;
		if ( ! is_string( $sql ) || ! is_array( $values ) ) {
			throw new RuntimeException( 'Target prepared query is invalid.' );
		}
		if ( str_contains( $sql, 'FROM oj_ai_import_items WHERE import_run_id = %d' ) ) {
			foreach ( $this->tables['oj_ai_import_items'] as $row ) {
				if (
					(string) ( $row['import_run_id'] ?? '' ) === (string) ( $values[0] ?? '' )
					&& (string) ( $row['source_type'] ?? '' ) === (string) ( $values[1] ?? '' )
					&& (string) ( $row['source_key'] ?? '' ) === (string) ( $values[2] ?? '' )
				) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_curriculums WHERE public_id = %s' ) ) {
			foreach ( $this->tables['oj_ai_curriculums'] as $row ) {
				if ( (string) ( $row['public_id'] ?? '' ) === (string) ( $values[0] ?? '' ) ) {
					return $row;
				}
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected target row query: ' . $sql );
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return bool
	 */
	public function insert( string $table, array $data ): bool {
		if ( $table === $this->fail_table || ! array_key_exists( $table, $this->tables ) ) {
			$this->last_error = 'forced target insert failure';
			return false;
		}
		$this->insert_id          = count( $this->tables[ $table ] ) + 1;
		$data['id']             ??= $this->insert_id;
		$this->tables[ $table ][] = $data;
		return true;
	}
}

/**
 * G5 s03t item.
 *
 * @param Legacy_Source_Profile_0_1_2 $profile Profile.
 * @param Canonical_Json              $canonical Canonical JSON encoder used for stable digests.
 * @param string                      $source_type Source type.
 * @param int                         $source_id Source id.
 * @param array<string,mixed>         $overrides Overrides.
 * @param bool                        $valid_checksum Valid checksum.
 * @return Legacy_Source_Item
 */
function g5_s03t_item(
	Legacy_Source_Profile_0_1_2 $profile,
	Canonical_Json $canonical,
	string $source_type,
	int $source_id,
	array $overrides = array(),
	bool $valid_checksum = true
): Legacy_Source_Item {
	$record                   = array_fill_keys( $profile->columns( $source_type ), null );
	$record['id']             = (string) $source_id;
	$record['tenant_context'] = 'legacy-site-7';
	$record['created_by']     = '7';
	$record['updated_by']     = '7';
	$record['created_at']     = '2025-01-01 00:00:00';
	$record['updated_at']     = '2025-01-01 00:00:00';
	$record['deleted_at']     = null;
	$record['source_type']    = '';
	$record['source_key']     = '';
	$record['source_id']      = '';
	foreach ( $overrides as $key => $value ) {
		$record[ $key ] = $value;
	}
	$checksum = hash(
		'sha256',
		$canonical->encode(
			array(
				'profile'     => Legacy_Source_Profile_0_1_2::PROFILE,
				'source_key'  => (string) $source_id,
				'source_type' => $source_type,
				'record'      => $record,
			)
		)
	);
	if ( ! $valid_checksum ) {
		$checksum = str_repeat( 'f', 64 );
	}
	return new Legacy_Source_Item( 1, $source_type, $source_id, $checksum, $record );
}

/**
 * G5 s03t success item row.
 *
 * @param int                $run_id Run id.
 * @param Legacy_Source_Item $source Source.
 * @param string             $target_id Target id.
 * @param string             $content_hash Content hash.
 * @param Canonical_Json     $canonical Canonical JSON encoder used for stable digests.
 * @return array<string,mixed>
 */
function g5_s03t_success_item_row( int $run_id, Legacy_Source_Item $source, string $target_id, string $content_hash, Canonical_Json $canonical ): array {
	$payload = array(
		'profile'    => 'oj_ai.legacy_item_payload@1.0.0',
		'source'     => array(
			'type'     => $source->source_type(),
			'key'      => $source->source_key(),
			'checksum' => $source->source_checksum(),
		),
		'state'      => 'success',
		'target'     => array(
			'type'         => 'curriculum',
			'public_id'    => $target_id,
			'content_hash' => $content_hash,
			'metadata'     => array(),
		),
		'error_code' => null,
	);
	$json    = $canonical->encode( $payload );
	return array(
		'import_run_id'    => $run_id,
		'source_type'      => 'curriculums',
		'source_key'       => $source->source_key(),
		'state'            => 'success',
		'target_type'      => 'curriculum',
		'target_public_id' => $target_id,
		'payload_schema'   => 'oj_ai.legacy_item_payload',
		'payload_version'  => 1,
		'payload_json'     => $json,
		'payload_hash'     => hash( 'sha256', $json ),
	);
}

$canonical = new Canonical_Json();
$profile   = new Legacy_Source_Profile_0_1_2();
$database  = new G5_S03T_Database();
$writer    = new WordPress_Legacy_Target_Writer( $canonical, $database, null, $profile );

$curriculum_source = g5_s03t_item(
	$profile,
	$canonical,
	'curriculums',
	10,
	array(
		'curriculum_key' => 'national-2022',
		'title'          => 'National Curriculum 2022',
		'description'    => 'Verified legacy curriculum description.',
		'version'        => '2022.1',
		'active'         => '1',
	)
);
$curriculum        = $writer->write( 41, $curriculum_source, 77, '2026-08-25 14:00:00' );
g5_s03t_same( 'curriculum', $curriculum->target_type(), 'Curriculum source was written as the wrong target type.' );
g5_s03t_same( 1, count( $database->tables['oj_ai_curriculums'] ), 'Curriculum target was not inserted exactly once.' );
$curriculum_row = $database->tables['oj_ai_curriculums'][0];
g5_s03t_same( 'curriculum:10', $curriculum_row['source_key'], 'Curriculum target source key is not collision-safe.' );
g5_s03t_same( 'active', $curriculum_row['state'], 'Legacy active flag did not map to active state.' );
g5_s03t_same( hash( 'sha256', $curriculum_row['metadata_json'] ), $curriculum_row['metadata_hash'], 'Curriculum metadata hash is invalid.' );
g5_s03t_same( $curriculum->content_hash(), $curriculum_row['content_hash'], 'Curriculum receipt content hash drifted from storage.' );

$database->tables['oj_ai_import_items'][] = g5_s03t_success_item_row(
	41,
	$curriculum_source,
	$curriculum->target_public_id(),
	$curriculum->content_hash(),
	$canonical
);
$standard_source                          = g5_s03t_item(
	$profile,
	$canonical,
	'achievement_standards',
	20,
	array(
		'curriculum_id' => '10',
		'standard_key'  => 'MATH-01',
		'title'         => 'Understand whole numbers',
		'description'   => 'Legacy standard description.',
		'metadata_json' => '{"grade":"3"}',
		'active'        => '1',
	)
);
$standard                                 = $writer->write( 41, $standard_source, 77, '2026-08-25 14:01:00' );
g5_s03t_same( 'achievement_standard', $standard->target_type(), 'Standard source was written as the wrong target type.' );
g5_s03t_same( $curriculum->target_public_id(), $standard->metadata()['curriculum_public_id'], 'Standard receipt lost its curriculum dependency.' );
g5_s03t_same( 1, count( $database->tables['oj_ai_achievement_standards'] ), 'Standard target was not inserted exactly once.' );
g5_s03t_same( $curriculum_row['id'], $database->tables['oj_ai_achievement_standards'][0]['curriculum_id'], 'Standard linked to a guessed curriculum instead of the successful item receipt.' );

$before_missing = count( $database->tables['oj_ai_achievement_standards'] );
$missing_source = g5_s03t_item(
	$profile,
	$canonical,
	'achievement_standards',
	21,
	array(
		'curriculum_id' => '999',
		'standard_key'  => 'MATH-02',
		'title'         => 'Missing parent',
		'description'   => '',
		'metadata_json' => null,
		'active'        => '1',
	)
);
g5_s03t_transform_failure(
	static fn () => $writer->write( 41, $missing_source, 77, '2026-08-25 14:02:00' ),
	Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING,
	'Standard writer guessed a missing curriculum dependency.'
);
g5_s03t_same( $before_missing, count( $database->tables['oj_ai_achievement_standards'] ), 'Missing dependency left a partial standard target.' );

$invalid_checksum = g5_s03t_item(
	$profile,
	$canonical,
	'curriculums',
	11,
	array(
		'curriculum_key' => 'tampered',
		'title'          => 'Tampered',
		'description'    => null,
		'version'        => '',
		'active'         => '1',
	),
	false
);
g5_s03t_transform_failure(
	static fn () => $writer->write( 41, $invalid_checksum, 77, '2026-08-25 14:03:00' ),
	Legacy_Target_Transformation_Failure::SOURCE_INVALID,
	'Target writer accepted a source checksum mismatch.'
);

$unsupported = g5_s03t_item(
	$profile,
	$canonical,
	'worksheet_assignments',
	30
);
g5_s03t_transform_failure(
	static fn () => $writer->write( 41, $unsupported, 77, '2026-08-25 14:04:00' ),
	Legacy_Target_Transformation_Failure::UNSUPPORTED,
	'Target writer silently guessed an unsupported source transform.'
);

$corrupt_database = clone $database;
$corrupt_database->tables['oj_ai_import_items'][0]['payload_json'] = '{}';
$corrupt_writer = new WordPress_Legacy_Target_Writer( $canonical, $corrupt_database, null, $profile );
g5_s03t_storage_failure(
	static fn () => $corrupt_writer->write( 41, $standard_source, 77, '2026-08-25 14:05:00' ),
	'Corrupt dependency receipt was treated as an ordinary item failure.'
);

$failing_database             = clone $database;
$failing_database->fail_table = 'oj_ai_curriculums';
$failing_writer               = new WordPress_Legacy_Target_Writer( $canonical, $failing_database, null, $profile );
g5_s03t_storage_failure(
	static fn () => $failing_writer->write( 41, $curriculum_source, 77, '2026-08-25 14:06:00' ),
	'Target SQL failure was not surfaced as a batch-fatal storage failure.'
);

foreach ( $database->queries as $sql ) {
	foreach ( array( 'wp_oj_ai_', '$wpdb->prefix', '$wpdb->base_prefix', 'CodingPanda\\OjPlatform\\Kernel\\', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE' ) as $forbidden ) {
		g5_s03t_assert( ! str_contains( $sql, $forbidden ), 'Target writer SQL crossed a frozen boundary: ' . $forbidden );
	}
}
$source_code = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Target_Writer.php' );
foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
	g5_s03t_assert( ! str_contains( (string) $source_code, $forbidden ), 'Target writer runtime crossed a frozen boundary: ' . $forbidden );
}
foreach ( array( "'oj_ai_import_items'", "'oj_ai_curriculums'", "'oj_ai_achievement_standards'" ) as $literal_table ) {
	g5_s03t_assert( str_contains( (string) $source_code, $literal_table ), 'Target writer lost a literal owner table: ' . $literal_table );
}

fwrite( STDOUT, "G5-IMP-07-S03 target writer passed: source checksum, curriculum and standard owner writes, exact dependency receipts, fail-before-write transforms, and no platform/prefix writes are frozen.\n" );
