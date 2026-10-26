<?php
/**
 * G5-IMP-07-S03-D3-D8 complete owner matrix and production graph contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Process_Legacy_Migration_Batch;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Migration_Owner_Matrix;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Assignment_Writer;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Batch_Factory;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Batch_Repository;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Evaluation_Writer;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Submission_Writer;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Zip_Writer;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Source_Blob_Reader;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Assignment_Writer_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Evaluation_Writer_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Submission_Writer_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Zip_Writer_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Target_Batch_Lifecycle_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Student\Infrastructure\Clamav_Instream_Submission_Attachment_Scanner;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 d3d8 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d8_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 d3d8 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d8_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 d3d8 unsupported.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d8_unsupported( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Target_Transformation_Failure $failure ) {
		g5_d3d8_same( Legacy_Target_Transformation_Failure::UNSUPPORTED, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5 d3d8 property.
 *
 * @param object $object Object.
 * @param string $property Property.
 * @return mixed
 */
function g5_d3d8_property( object $object, string $property ) {
	$reflection = new ReflectionProperty( $object, $property );
	return $reflection->getValue( $object );
}

/**
 * G5 d3d8 item.
 *
 * @param Legacy_Source_Profile_0_1_2 $profile Profile.
 * @param Canonical_Json              $canonical Canonical JSON encoder used for stable digests.
 * @param string                      $source_type Source type.
 * @param int                         $item_no Item no.
 * @param int                         $source_id Source id.
 * @return Legacy_Source_Item
 */
function g5_d3d8_item(
	Legacy_Source_Profile_0_1_2 $profile,
	Canonical_Json $canonical,
	string $source_type,
	int $item_no,
	int $source_id
): Legacy_Source_Item {
	$record       = array_fill_keys( $profile->columns( $source_type ), null );
	$record['id'] = (string) $source_id;
	$checksum     = hash(
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
	return new Legacy_Source_Item( $item_no, $source_type, $source_id, $checksum, $record );
}

/**
 * G5-IMP-07-S03-D3-D8 complete owner matrix and production graph contract.
 */
final class G5_D3D8_Owners implements
	Legacy_Migration_Assignment_Writer_Port_Interface,
	Legacy_Migration_Submission_Writer_Port_Interface,
	Legacy_Migration_Evaluation_Writer_Port_Interface,
	Legacy_Migration_Zip_Writer_Port_Interface,
	Legacy_Target_Batch_Lifecycle_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var array<int,string>
	 */
	public array $calls = array();
	/**
	 * Begins.
	 *
	 * @var int
	 */
	public int $begins = 0;
	/**
	 * Commits.
	 *
	 * @var int
	 */
	public int $commits = 0;
	/**
	 * Rollbacks.
	 *
	 * @var int
	 */
	public int $rollbacks = 0;
	/**
	 * Ids.
	 *
	 * @var Public_Id_Generator
	 */
	private Public_Id_Generator $ids;

	/**
	 * Initialize g5 d3 d8 owners dependencies and state.
	 */
	public function __construct() {
		$this->ids = new Public_Id_Generator();
	}
	/**
	 * Begin batch.
	 *
	 * @return void
	 */
	public function begin_batch(): void {
		++$this->begins;
	}
	/**
	 * Commit batch.
	 *
	 * @return void
	 */
	public function commit_batch(): void {
		++$this->commits;
	}
	/**
	 * Rollback batch.
	 *
	 * @return void
	 */
	public function rollback_batch(): void {
		++$this->rollbacks;
	}

	/**
	 * Write.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 */
	public function write( int $run_database_id, Legacy_Source_Item $item, int $actor_user_id, string $created_at_utc ): Legacy_Target_Write_Result {
		return $this->receipt( 'write', $item );
	}

	/**
	 * Verify revision.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 */
	public function verify_revision( int $run_database_id, Legacy_Source_Item $item, int $actor_user_id, string $created_at_utc ): Legacy_Target_Write_Result {
		return $this->receipt( 'verify_revision', $item );
	}

	/**
	 * Verify attachment.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 */
	public function verify_attachment( int $run_database_id, Legacy_Source_Item $item, int $actor_user_id, string $created_at_utc ): Legacy_Target_Write_Result {
		return $this->receipt( 'verify_attachment', $item );
	}

	/**
	 * Receipt.
	 *
	 * @param string             $operation Operation.
	 * @param Legacy_Source_Item $item Item.
	 * @return Legacy_Target_Write_Result
	 */
	private function receipt( string $operation, Legacy_Source_Item $item ): Legacy_Target_Write_Result {
		$this->calls[] = $operation . ':' . $item->source_type();
		return new Legacy_Target_Write_Result( 'legacy_owner', $this->ids->create()->value(), $item->source_checksum() );
	}
}

/**
 * G5-IMP-07-S03-D3-D8 complete owner matrix and production graph contract.
 */
final class G5_D3D8_Private_Files implements Private_File_Store_Port_Interface {
	/**
	 * Put quarantined.
	 *
	 * @param string $source_path Source path.
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return bool
	 */
	public function put_quarantined( string $source_path, string $storage_key, string $sha256 ): bool {
		return true;
	}
	/**
	 * Delete g5 d3 d8 private files.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	public function delete( string $storage_key ): void {
	}
}

$canonical = new Canonical_Json();
$profile   = new Legacy_Source_Profile_0_1_2();
$matrix    = new Legacy_Migration_Owner_Matrix( $profile );
$entries   = $matrix->entries();
foreach ( $entries as $entry ) {
	if ( 'mapping' === $entry['disposition'] ) {
		g5_d3d8_same( Legacy_Migration_Owner_Matrix::HANDLER_MAPPING, $entry['handler'], 'Mapping source must use the mapping handler.' );
	} elseif ( 'exclude' === $entry['disposition'] ) {
		g5_d3d8_same( Legacy_Migration_Owner_Matrix::HANDLER_EXCLUDED, $entry['handler'], 'Excluded source must use the excluded handler.' );
	} else {
		g5_d3d8_assert( ! in_array( $entry['handler'], array( Legacy_Migration_Owner_Matrix::HANDLER_MAPPING, Legacy_Migration_Owner_Matrix::HANDLER_EXCLUDED ), true ), 'Migrated source must use a migration handler.' );
	}
}
g5_d3d8_same( 35, count( $entries ), 'Owner matrix did not freeze exactly 35 source tables.' );
g5_d3d8_same( array_keys( $profile->execution_tables() ), array_keys( $entries ), 'Owner matrix drifted from dependency execution order.' );
g5_d3d8_same( 22, count( $matrix->migration_source_types() ), 'Owner matrix did not freeze exactly 22 migration source types.' );

$dispositions = array_count_values( array_column( $entries, 'disposition' ) );
ksort( $dispositions );
g5_d3d8_same(
	array(
		'exclude' => 7,
		'mapping' => 6,
		'migrate' => 22,
	),
	$dispositions,
	'The 35-table disposition split drifted.'
);
$handlers = array_count_values( array_column( $entries, 'handler' ) );
ksort( $handlers );
g5_d3d8_same(
	array(
		'assignment' => 1,
		'direct'     => 6,
		'evaluation' => 10,
		'excluded'   => 7,
		'mapping'    => 6,
		'submission' => 3,
		'zip'        => 2,
	),
	$handlers,
	'Owner handler coverage drifted.'
);

$delegated_types = array_keys(
	array_filter(
		$entries,
		static fn ( array $entry ): bool => in_array(
			$entry['handler'],
			array(
				Legacy_Migration_Owner_Matrix::HANDLER_ASSIGNMENT,
				Legacy_Migration_Owner_Matrix::HANDLER_SUBMISSION,
				Legacy_Migration_Owner_Matrix::HANDLER_ZIP,
				Legacy_Migration_Owner_Matrix::HANDLER_EVALUATION,
			),
			true
		)
	)
);
g5_d3d8_same( 16, count( $delegated_types ), 'Delegated owner source coverage drifted.' );

$owners = new G5_D3D8_Owners();
$target = new WordPress_Legacy_Target_Writer(
	$canonical,
	null,
	new Public_Id_Generator(),
	$profile,
	null,
	$owners,
	$owners,
	$owners,
	$owners,
	$matrix
);
$target->begin_batch();
for ( $index = 0; $index < 250; ++$index ) {
	$source_type = $delegated_types[ $index % count( $delegated_types ) ];
	$target->write( 41, g5_d3d8_item( $profile, $canonical, $source_type, $index + 1, $index + 1 ), 77, '2026-08-26 10:00:00' );
}
$target->commit_batch();
g5_d3d8_same( 250, count( $owners->calls ), 'The bounded 250-item owner dispatch lost an item.' );
g5_d3d8_same( 1, $owners->begins, 'A shared lifecycle owner began more than once.' );
g5_d3d8_same( 1, $owners->commits, 'A shared lifecycle owner did not commit exactly once.' );
foreach ( $delegated_types as $source_type ) {
	g5_d3d8_assert(
		count( array_filter( $owners->calls, static fn ( string $call ): bool => str_ends_with( $call, ':' . $source_type ) ) ) > 0,
		'Production dispatch did not reach owner source type: ' . $source_type
	);
}
g5_d3d8_assert( in_array( 'verify_revision:submission_revisions', $owners->calls, true ), 'Submission revision was not verification-only.' );
g5_d3d8_assert( in_array( 'verify_attachment:submission_attachments', $owners->calls, true ), 'Submission attachment was not verification-only.' );

$target->begin_batch();
$target->rollback_batch();
g5_d3d8_same( 2, $owners->begins, 'Retry batch did not begin its owner lifecycle.' );
g5_d3d8_same( 1, $owners->rollbacks, 'Rollback did not reach the shared lifecycle owner exactly once.' );
g5_d3d8_unsupported(
	static fn () => $target->write( 41, g5_d3d8_item( $profile, $canonical, 'academic_years', 251, 251 ), 77, '2026-08-26 10:01:00' ),
	'Mapping-only source reached a target owner.'
);
g5_d3d8_unsupported(
	static fn () => $target->write( 41, g5_d3d8_item( $profile, $canonical, 'activity_events', 252, 252 ), 77, '2026-08-26 10:01:00' ),
	'Excluded source reached a target owner.'
);

$private_files = new G5_D3D8_Private_Files();
$processor     = WordPress_Legacy_Migration_Batch_Factory::create( $canonical, $private_files, new stdClass(), new Public_Id_Generator() );
g5_d3d8_assert( $processor instanceof Process_Legacy_Migration_Batch, 'Production factory did not return the batch application service.' );
$repository = g5_d3d8_property( $processor, 'repository' );
g5_d3d8_assert( $repository instanceof WordPress_Legacy_Migration_Batch_Repository, 'Production graph lost the atomic batch repository.' );
$production_target = g5_d3d8_property( $repository, 'targets' );
g5_d3d8_assert( $production_target instanceof WordPress_Legacy_Target_Writer, 'Production graph lost the complete target writer.' );
$assignment = g5_d3d8_property( $production_target, 'assignment_writer' );
$submission = g5_d3d8_property( $production_target, 'submission_writer' );
$evaluation = g5_d3d8_property( $production_target, 'evaluation_writer' );
$zip        = g5_d3d8_property( $production_target, 'zip_writer' );
g5_d3d8_assert( $assignment instanceof WordPress_Legacy_Migration_Assignment_Writer, 'Production graph lost the assignment owner.' );
g5_d3d8_assert( $submission instanceof WordPress_Legacy_Migration_Submission_Writer, 'Production graph lost the submission owner.' );
g5_d3d8_assert( $evaluation instanceof WordPress_Legacy_Migration_Evaluation_Writer, 'Production graph lost the evaluation owner.' );
g5_d3d8_assert( $zip instanceof WordPress_Legacy_Migration_Zip_Writer, 'Production graph lost the ZIP owner.' );
g5_d3d8_assert( g5_d3d8_property( $submission, 'private_files' ) === $private_files, 'Submission migration did not use the injected private store.' );
g5_d3d8_assert( g5_d3d8_property( $zip, 'private_files' ) === $private_files, 'ZIP migration did not use the injected private store.' );
g5_d3d8_assert( g5_d3d8_property( $submission, 'source_blobs' ) === g5_d3d8_property( $zip, 'source_blobs' ), 'Private owners did not share the safe source blob reader.' );
g5_d3d8_assert( g5_d3d8_property( $submission, 'source_blobs' ) instanceof WordPress_Legacy_Source_Blob_Reader, 'Production graph lost the uploads-root source reader.' );
g5_d3d8_assert( g5_d3d8_property( $submission, 'malware_scanner' ) === g5_d3d8_property( $zip, 'malware' ), 'Private owners did not share one scanner policy.' );
g5_d3d8_assert( g5_d3d8_property( $zip, 'malware' ) instanceof Clamav_Instream_Submission_Attachment_Scanner, 'Production graph lost the current malware scanner.' );
g5_d3d8_assert( g5_d3d8_property( $production_target, 'owners' ) instanceof Legacy_Migration_Owner_Matrix, 'Production graph did not bind dispatch to the frozen owner matrix.' );

foreach (
	array(
		'src/Operations/LegacyMigration/Infrastructure/Legacy_Migration_Owner_Matrix.php',
		'src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Batch_Factory.php',
		'src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Target_Writer.php',
	) as $relative
) {
	$source = file_get_contents( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
	g5_d3d8_assert( is_string( $source ), 'D3-D8 runtime source could not be read: ' . $relative );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_d3d8_assert( ! str_contains( $source, $forbidden ), 'D3-D8 runtime crossed a frozen boundary: ' . $relative . ' -> ' . $forbidden );
	}
}

fwrite( STDOUT, "G5-IMP-07-S03-D3-D8 passed: exact 35-table owner coverage, 250-item dispatch, complete production graph, lifecycle rollback, and zero platform writes are frozen.\n" );
