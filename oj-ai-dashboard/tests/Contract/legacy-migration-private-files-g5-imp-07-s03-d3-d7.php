<?php
/**
 * G5-IMP-07-S03-D3-D7 private attachment and ZIP migration contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';
use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Blob;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Submission_Writer;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Zip_Writer;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Source_Blob_Reader;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Zip_Writer_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Blob_Reader_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Target_Batch_Lifecycle_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Malware_Verdict;
use OJAI\Dashboard\Submission\Student\Port\Submission_Attachment_Malware_Scanner_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Legacy migration private files g5 imp 07 s03 d3 d7.
 *
 * @var array<int,string|false>
 */
$GLOBALS['g5_d3d7_attachment_paths'] = array();
$GLOBALS['g5_d3d7_upload_root']      = '';

if ( ! function_exists( 'get_attached_file' ) ) {
	/**
	 * Get attached file.
	 *
	 * @param int  $attachment_id Attachment id.
	 * @param bool $unfiltered Unfiltered.
	 * @return string|false
	 */
	function get_attached_file( int $attachment_id, bool $unfiltered = false ): string|false {
		unset( $unfiltered );
		return $GLOBALS['g5_d3d7_attachment_paths'][ $attachment_id ] ?? false;
	}
}
if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	/**
	 * Wp get upload dir.
	 *
	 * @return array<string,mixed>
	 */
	function wp_get_upload_dir(): array {
		return array(
			'basedir' => $GLOBALS['g5_d3d7_upload_root'],
			'error'   => false,
		);
	}
}

/**
 * G5 d3d7 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d7_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 d3d7 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d7_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 d3d7 source invalid.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d7_source_invalid( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( Legacy_Target_Transformation_Failure $failure ) {
		g5_d3d7_same( Legacy_Target_Transformation_Failure::SOURCE_INVALID, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message . ': no source failure was raised.' );
}

/**
 * G5 d3d7 invalid argument.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d7_invalid_argument( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message . ': no invalid argument was raised.' );
}

/**
 * G5-IMP-07-S03-D3-D7 private attachment and ZIP migration contract.
 */
final class G5_D3D7_Source_Blobs implements Legacy_Source_Blob_Reader_Port_Interface {
	/**
	 * Read g5 d3 d7 source blobs.
	 *
	 * @param int $file_id File id.
	 * @return Legacy_Source_Blob
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read( int $file_id ): Legacy_Source_Blob {
		unset( $file_id );
		throw new RuntimeException( 'D3-D7 source blob stub must not be invoked.' );
	}
}

/**
 * G5-IMP-07-S03-D3-D7 private attachment and ZIP migration contract.
 */
final class G5_D3D7_Private_Files implements Private_File_Store_Port_Interface {
	/**
	 * Rows.
	 *
	 * @var array<string,string>
	 */
	public array $rows = array();
	/**
	 * Deleted.
	 *
	 * @var array<int,string>
	 */
	public array $deleted = array();
	/**
	 * Put quarantined.
	 *
	 * @param string $source_path Source path.
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function put_quarantined( string $source_path, string $storage_key, string $sha256 ): bool {
		$actual = hash_file( 'sha256', $source_path );
		if ( ! is_string( $actual ) || ! hash_equals( $sha256, $actual ) ) {
			throw new RuntimeException( 'D3-D7 private store received stale bytes.' );
		}
		if ( isset( $this->rows[ $storage_key ] ) ) {
			return false;
		}
		$this->rows[ $storage_key ] = $sha256;
		return true;
	}
	/**
	 * Delete g5 d3 d7 private files.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	public function delete( string $storage_key ): void {
		unset( $this->rows[ $storage_key ] );
		$this->deleted[] = $storage_key;
	}
}

/**
 * G5-IMP-07-S03-D3-D7 private attachment and ZIP migration contract.
 */
final class G5_D3D7_Malware implements Submission_Attachment_Malware_Scanner_Port_Interface {
	/**
	 * Scan.
	 *
	 * @param string $path Path.
	 * @return Submission_Attachment_Malware_Verdict
	 */
	public function scan( string $path ): Submission_Attachment_Malware_Verdict {
		unset( $path );
		return Submission_Attachment_Malware_Verdict::clean();
	}
}

/**
 * G5-IMP-07-S03-D3-D7 private attachment and ZIP migration contract.
 */
final class G5_D3D7_Zip_Delegate implements Legacy_Migration_Zip_Writer_Port_Interface, Legacy_Target_Batch_Lifecycle_Port_Interface {
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
	 * Writes.
	 *
	 * @var array<int,string>
	 */
	public array $writes = array();
	/**
	 * Active.
	 *
	 * @var bool
	 */
	private bool $active = false;
	/**
	 * Ids.
	 *
	 * @var Public_Id_Generator
	 */
	private Public_Id_Generator $ids;
	/**
	 * Initialize g5 d3 d7 zip delegate dependencies and state.
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
		$this->active = true;
	}
	/**
	 * Commit batch.
	 *
	 * @return void
	 */
	public function commit_batch(): void {
		++$this->commits;
		$this->active = false;
	}
	/**
	 * Rollback batch.
	 *
	 * @return void
	 */
	public function rollback_batch(): void {
		++$this->rollbacks;
		$this->active = false;
	}
	/**
	 * Write.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function write( int $run_database_id, Legacy_Source_Item $item, int $actor_user_id, string $created_at_utc ): Legacy_Target_Write_Result {
		if ( ! $this->active || $run_database_id < 1 || $actor_user_id < 1 || '' === $created_at_utc ) {
			throw new RuntimeException( 'D3-D7 ZIP delegate was called outside the batch.' );
		}
		$this->writes[] = $item->source_type();
		$type           = 'zip_uploads' === $item->source_type() ? 'submission_zip' : 'submission_zip_item';
		return new Legacy_Target_Write_Result( $type, $this->ids->create()->value(), hash( 'sha256', $item->source_checksum() ) );
	}
}

/**
 * G5 d3d7 item.
 *
 * @param Canonical_Json              $canonical Canonical JSON encoder used for stable digests.
 * @param Legacy_Source_Profile_0_1_2 $profile Profile.
 * @param string                      $type Type.
 * @param int                         $id Id.
 * @param int                         $item_no Item no.
 * @return Legacy_Source_Item
 */
function g5_d3d7_item( Canonical_Json $canonical, Legacy_Source_Profile_0_1_2 $profile, string $type, int $id, int $item_no ): Legacy_Source_Item {
	$record       = array_fill_keys( $profile->columns( $type ), null );
	$record['id'] = (string) $id;
	$checksum     = hash(
		'sha256',
		$canonical->encode(
			array(
				'profile'     => Legacy_Source_Profile_0_1_2::PROFILE,
				'source_key'  => (string) $id,
				'source_type' => $type,
				'record'      => $record,
			)
		)
	);
	return new Legacy_Source_Item( $item_no, $type, $id, $checksum, $record );
}

/**
 * G5 d3d7 set created keys.
 *
 * @param object            $writer Writer.
 * @param array<int,string> $keys Keys.
 * @return void
 */
function g5_d3d7_set_created_keys( object $writer, array $keys ): void {
	$property = new ReflectionProperty( $writer, 'created_storage_keys' );
	$property->setValue( $writer, $keys );
}

$temporary_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oj-ai-d3d7-' . bin2hex( random_bytes( 8 ) );
$uploads        = $temporary_root . DIRECTORY_SEPARATOR . 'uploads';
$inside         = $uploads . DIRECTORY_SEPARATOR . 'answer.txt';
$empty          = $uploads . DIRECTORY_SEPARATOR . 'empty.txt';
$outside        = $temporary_root . DIRECTORY_SEPARATOR . 'outside.txt';
if ( ! mkdir( $uploads, 0700, true ) || false === file_put_contents( $inside, 'private answer' ) || false === file_put_contents( $empty, '' ) || false === file_put_contents( $outside, 'outside' ) ) {
	throw new RuntimeException( 'D3-D7 source file fixture could not be created.' );
}

try {
	$GLOBALS['g5_d3d7_upload_root']      = $uploads;
	$GLOBALS['g5_d3d7_attachment_paths'] = array(
		11 => $inside,
		12 => $outside,
		13 => $empty,
	);
	$reader                              = new WordPress_Legacy_Source_Blob_Reader();
	$blob                                = $reader->read( 11 );
	g5_d3d7_same( realpath( $inside ), $blob->path(), 'Source blob path was not canonicalized.' );
	g5_d3d7_same( 'answer.txt', $blob->original_name(), 'Source blob retained more than the basename.' );
	g5_d3d7_same( strlen( 'private answer' ), $blob->byte_size(), 'Source blob byte size drifted.' );
	g5_d3d7_same( hash_file( 'sha256', $inside ), $blob->sha256(), 'Source blob SHA-256 drifted.' );
	g5_d3d7_source_invalid( static fn () => $reader->read( 12 ), 'Uploads-root escape was accepted' );
	g5_d3d7_source_invalid( static fn () => $reader->read( 13 ), 'Zero-byte legacy attachment was accepted' );

	$ids = new Public_Id_Generator();
	foreach ( array(
		'original_path' => $inside,
		'original_name' => 'answer.txt',
		'file_url'      => 'https://example.invalid/private',
	) as $key => $value ) {
		g5_d3d7_invalid_argument(
			static fn () => new Legacy_Target_Write_Result( 'submission_attachment', $ids->create()->value(), hash( 'sha256', $key ), array( $key => $value ) ),
			'Migration receipt accepted private source metadata: ' . $key
		);
	}

	$canonical       = new Canonical_Json();
	$profile         = new Legacy_Source_Profile_0_1_2();
	$zip_files       = new G5_D3D7_Private_Files();
	$zip_files->rows = array(
		'zip-one' => 'a',
		'zip-two' => 'b',
	);
	$zip_writer      = new WordPress_Legacy_Migration_Zip_Writer( $canonical, new G5_D3D7_Source_Blobs(), $zip_files, new G5_D3D7_Malware() );
	$zip_writer->begin_batch();
	g5_d3d7_set_created_keys( $zip_writer, array( 'zip-one', 'zip-two' ) );
	$zip_writer->rollback_batch();
	g5_d3d7_same( array( 'zip-two', 'zip-one' ), $zip_files->deleted, 'ZIP rollback did not compensate newly created objects in reverse order.' );
	g5_d3d7_same( array(), $zip_files->rows, 'ZIP rollback left private objects behind.' );
	$zip_writer->begin_batch();
	$zip_files->rows['committed'] = 'c';
	g5_d3d7_set_created_keys( $zip_writer, array( 'committed' ) );
	$zip_writer->commit_batch();
	g5_d3d7_assert( isset( $zip_files->rows['committed'] ), 'ZIP commit deleted a committed private object.' );

	$submission_files       = new G5_D3D7_Private_Files();
	$submission_files->rows = array(
		'attachment-one' => 'a',
		'attachment-two' => 'b',
	);
	$submission_writer      = new WordPress_Legacy_Migration_Submission_Writer(
		$canonical,
		null,
		$ids,
		$profile,
		null,
		new G5_D3D7_Source_Blobs(),
		$submission_files,
		new G5_D3D7_Malware()
	);
	$submission_writer->begin_batch();
	g5_d3d7_set_created_keys( $submission_writer, array( 'attachment-one', 'attachment-two' ) );
	$submission_writer->rollback_batch();
	g5_d3d7_same( array( 'attachment-two', 'attachment-one' ), $submission_files->deleted, 'Attachment rollback did not compensate newly created objects in reverse order.' );
	g5_d3d7_same( array(), $submission_files->rows, 'Attachment rollback left private objects behind.' );

	$delegate = new G5_D3D7_Zip_Delegate();
	$target   = new WordPress_Legacy_Target_Writer( $canonical, null, $ids, $profile, null, null, null, null, $delegate );
	$target->begin_batch();
	$target->write( 51, g5_d3d7_item( $canonical, $profile, 'zip_uploads', 701, 1 ), 99, '2026-08-26 09:00:00' );
	$target->write( 51, g5_d3d7_item( $canonical, $profile, 'zip_upload_items', 702, 2 ), 99, '2026-08-26 09:00:01' );
	$target->commit_batch();
	g5_d3d7_same( array( 'zip_uploads', 'zip_upload_items' ), $delegate->writes, 'Target writer did not dispatch both ZIP source types.' );
	g5_d3d7_same( 1, $delegate->begins, 'Target writer did not start the ZIP lifecycle.' );
	g5_d3d7_same( 1, $delegate->commits, 'Target writer did not commit the ZIP lifecycle.' );
	$target->begin_batch();
	$target->rollback_batch();
	g5_d3d7_same( 1, $delegate->rollbacks, 'Target writer did not roll back the ZIP lifecycle.' );

	$zip_source        = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Zip_Writer.php' );
	$submission_source = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Submission_Writer.php' );
	$blob_source       = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Source_Blob_Reader.php' );
	foreach ( array( $zip_source, $submission_source, $blob_source ) as $runtime_source ) {
		g5_d3d7_assert( is_string( $runtime_source ), 'D3-D7 runtime source could not be read.' );
		foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ) as $forbidden ) {
			g5_d3d7_assert( ! str_contains( $runtime_source, $forbidden ), 'D3-D7 runtime crossed a frozen boundary: ' . $forbidden );
		}
	}
	foreach ( array(
		'Submission_Zip_Upload_Profile',
		'Submission_Zip_Entry_Reader',
		'Submission_Zip_Item_Content_Inspector',
		'put_quarantined',
		'source_item_manifest',
		'entry_for_path',
		"'selection' => null",
		"'owner_type' => 'submission_zip'",
		'compensate_from',
		"array( 'byte_size','compressed_entry_bytes','entry_count','sha256','uncompressed_bytes' )",
	) as $marker ) {
		g5_d3d7_assert( Ojai_Php_Source_Contract::marker( $zip_source, $marker ), 'ZIP migration safety marker is missing: ' . $marker );
	}
	foreach ( array(
		'Submission_Attachment_Upload_Profile',
		'Submission_Attachment_Content_Inspector',
		'Submission_Response_Validator',
		'put_quarantined',
		"'state' => 'ready'",
		'submission_revision_id = %d, state = %s, bound_at_utc = %s',
		'commit_attachment_finalization',
		'compensate_from',
	) as $marker ) {
			g5_d3d7_assert( Ojai_Php_Source_Contract::marker( $submission_source, $marker ), 'Attachment migration safety marker is missing: ' . $marker );
	}
	g5_d3d7_assert( ! str_contains( $zip_source, "['mapping_json']['file']" ), 'ZIP migration trusted a legacy mapping file path.' );
		g5_d3d7_assert( ! str_contains( $zip_source, 'file_url' ), 'ZIP migration retained a legacy file URL.' );
} finally {
	foreach ( array( $inside, $empty, $outside ) as $path ) {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}
	if ( is_dir( $uploads ) ) {
		rmdir( $uploads );
	}
	if ( is_dir( $temporary_root ) ) {
		rmdir( $temporary_root );
	}
}

fwrite( STDOUT, "G5-IMP-07-S03-D3-D7 passed: private source bounds, receipt privacy, attachment and ZIP compensation, current ZIP safety reuse, and owner dispatch are frozen.\n" );
