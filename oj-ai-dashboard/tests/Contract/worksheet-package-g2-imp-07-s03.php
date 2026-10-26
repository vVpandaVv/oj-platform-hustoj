<?php
/**
 * G2-IMP-07-S03 deterministic worksheet package and private download contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Reporting\Export\Application\Export_Failure;
use OJAI\Dashboard\Reporting\Export\Application\Read_Export_Artifact;
use OJAI\Dashboard\Reporting\Export\Port\Export_Query_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPackage\Application\Build_Worksheet_Package_Source;
use OJAI\Dashboard\Reporting\WorksheetPackage\Application\Enqueue_Worksheet_Package;
use OJAI\Dashboard\Reporting\WorksheetPackage\Application\Enqueue_Worksheet_Package_Command;
use OJAI\Dashboard\Reporting\WorksheetPackage\Application\Enqueue_Worksheet_Package_Result;
use OJAI\Dashboard\Reporting\WorksheetPackage\Domain\Worksheet_Package_Request_Snapshot;
use OJAI\Dashboard\Reporting\WorksheetPackage\Infrastructure\Deterministic_Zip_Worksheet_Package_Writer;
use OJAI\Dashboard\Reporting\WorksheetPackage\Port\Worksheet_Package_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPackage\Port\Worksheet_Package_Repository_Port_Interface;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Application\Read_Worksheet_Asset_Content;
use OJAI\Dashboard\Storage\Domain\Worksheet_Asset_Upload_Profile;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Lifecycle_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Source_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Target_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Zip_Worksheet_Template_Package_Reader;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Package_Source_Query_Port_Interface;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
defined( 'OJ_AI_DASHBOARD_DIR' ) || define( 'OJ_AI_DASHBOARD_DIR', $root . DIRECTORY_SEPARATOR );
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Core/Autoloader.php';
( new AddonAutoloader( $root . '/src' ) )->register();

/**
 * G2s03 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2s03_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
/**
 * G2s03 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2s03_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}
/**
 * G2s03 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function g2s03_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	g2s03_assert( Public_Id::is_valid( $id ), 'Fixture public ID is invalid.' );
	return $id;
}
/**
 * G2s03 element.
 *
 * @param string     $type Type.
 * @param int        $id Id.
 * @param array      $properties Properties.
 * @param array|null $children Children.
 * @return array
 */
function g2s03_element( string $type, int $id, array $properties = array(), ?array $children = null ): array {
	return array_merge(
		array(
			'type'     => $type,
			'node_id'  => g2s03_id( $id ),
			'children' => $children ?? array( array( 'text' => '' ) ),
		),
		$properties
	);
}

/**
 * G2-IMP-07-S03 deterministic worksheet package and private download contract.
 */
final class G2S03_Source_Query implements Worksheet_Package_Source_Query_Port_Interface {
	/**
	 * Initialize g2 s03 source query dependencies and state.
	 *
	 * @param array $source Source.
	 */
	public function __construct( public array $source ) {
	}
	/**
	 * Find owned package source.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return array|null
	 */
	public function find_owned_package_source( int $actor_user_id, string $revision_id ): ?array {
		return 42 === $actor_user_id && $revision_id === ( $this->source['revision_id'] ?? null ) ? $this->source : null;
	}
}
/**
 * G2-IMP-07-S03 deterministic worksheet package and private download contract.
 */
final class G2S03_Asset_Repository implements Worksheet_Asset_Lifecycle_Port_Interface {
	/**
	 * Initialize g2 s03 asset repository dependencies and state.
	 *
	 * @param string $asset_id Asset id.
	 * @param string $path Path.
	 * @param string $hash Hash.
	 * @param int    $bytes Bytes.
	 */
	public function __construct( private string $asset_id, private string $path, private string $hash, private int $bytes ) {
	}
	/**
	 * Find by public id.
	 *
	 * @param string $asset_id Asset id.
	 * @return array|null
	 */
	public function find_by_public_id( string $asset_id ): ?array {
		return $asset_id === $this->asset_id ? array(
			'public_id'          => $asset_id,
			'created_by_user_id' => 42,
			'owner_type'         => 'worksheet',
			'owner_public_id'    => g2s03_id( 1 ),
			'purpose'            => 'worksheet_asset',
			'storage_key'        => 'worksheet/assets/material.txt',
			'original_name'      => 'material.txt',
			'mime_type'          => 'text/plain',
			'byte_size'          => $this->bytes,
			'sha256'             => $this->hash,
			'state'              => 'ready',
			'scan_state'         => 'clean',
			'purged_at_utc'      => null,
		) : null;
	}
	/**
	 * Mark quarantined purged.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $asset_id Asset id.
	 * @param string $purged_at_utc Purged at utc.
	 * @return bool
	 */
	public function mark_quarantined_purged( int $actor_user_id, string $worksheet_id, string $asset_id, string $purged_at_utc ): bool {
		return false;
	}
}
/**
 * G2-IMP-07-S03 deterministic worksheet package and private download contract.
 */
final class G2S03_Asset_Source implements Worksheet_Asset_Source_Port_Interface {
	/**
	 * Initialize g2 s03 asset source dependencies and state.
	 *
	 * @param string $revision_id Revision id.
	 * @param string $asset_id Asset id.
	 */
	public function __construct( private string $revision_id, private string $asset_id ) {
	}
	/**
	 * Owned source references asset.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $source_type Source type.
	 * @param string $source_id Source id.
	 * @param string $asset_id Asset id.
	 * @return bool
	 */
	public function owned_source_references_asset( int $actor_user_id, string $source_type, string $source_id, string $asset_id ): bool {
		return 42 === $actor_user_id && 'revision' === $source_type && $source_id === $this->revision_id && $asset_id === $this->asset_id;
	}
}
/**
 * G2-IMP-07-S03 deterministic worksheet package and private download contract.
 */
final class G2S03_Target implements Worksheet_Asset_Target_Port_Interface {
	/**
	 * Current owned target.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array|null
	 */
	public function current_owned_target( int $actor_user_id, string $worksheet_id ): ?array {
		return null;
	}
}
/**
 * G2-IMP-07-S03 deterministic worksheet package and private download contract.
 */
final class G2S03_Private_Read implements Private_File_Read_Port_Interface {
	/**
	 * Initialize g2 s03 private read dependencies and state.
	 *
	 * @param array $paths Paths.
	 */
	public function __construct( private array $paths ) {
	}
	/**
	 * Readable path.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function readable_path( string $storage_key, string $sha256 ): string {
		$path = $this->paths[ $storage_key ] ?? '';
		if ( ! is_file( $path ) || ! hash_equals( $sha256, (string) hash_file( 'sha256', $path ) ) ) {
			throw new RuntimeException( 'Private file mismatch.' );
		}return $path;
	}
}
/**
 * G2-IMP-07-S03 deterministic worksheet package and private download contract.
 */
final class G2S03_Package_Repository implements Worksheet_Package_Repository_Port_Interface {
	/**
	 * Record.
	 *
	 * @var array|null
	 */
	public ?array $record = null;
	/**
	 * Receipt.
	 *
	 * @var Enqueue_Worksheet_Package_Result|null
	 */
	public ?Enqueue_Worksheet_Package_Result $receipt = null;
	/**
	 * Key.
	 *
	 * @var string
	 */
	private string $key = '';
	/**
	 * Digest.
	 *
	 * @var string
	 */
	private string $digest = '';
	/**
	 * Find command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Enqueue_Worksheet_Package_Result|null
	 */
	public function find_command_receipt( string $key, string $digest ): ?Enqueue_Worksheet_Package_Result {
		return $this->key === $key && $this->digest === $digest ? $this->receipt : null;
	}
	/**
	 * Enqueue.
	 *
	 * @param array $record Record.
	 * @return Enqueue_Worksheet_Package_Result
	 */
	public function enqueue( array $record ): Enqueue_Worksheet_Package_Result {
		$this->record  = $record;
		$this->key     = $record['command_storage_key'];
		$this->digest  = $record['request_digest'];
		$this->receipt = new Enqueue_Worksheet_Package_Result( $record['job_public_id'], 'queued', $record['source_public_id'], $record['source_hash'], $record['queued_at_utc'], false );
		return $this->receipt;
	}
}
/**
 * G2-IMP-07-S03 deterministic worksheet package and private download contract.
 */
final class G2S03_Queue implements Worksheet_Package_Queue_Wakeup_Port_Interface {
	/**
	 * Wakes.
	 *
	 * @var int
	 */
	public int $wakes = 0;
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void {
		++$this->wakes;
	}
}
/**
 * G2-IMP-07-S03 deterministic worksheet package and private download contract.
 */
final class G2S03_Export_Query implements Export_Query_Port_Interface {
	/**
	 * Initialize g2 s03 export query dependencies and state.
	 *
	 * @param array $artifact Artifact.
	 */
	public function __construct( public array $artifact ) {
	}
	/**
	 * Find job for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $job_id Job id.
	 * @return array|null
	 */
	public function find_job_for_actor( int $actor_user_id, string $job_id ): ?array {
		return null;
	}
	/**
	 * Find artifact for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $artifact_id Artifact id.
	 * @return array|null
	 */
	public function find_artifact_for_actor( int $actor_user_id, string $artifact_id ): ?array {
		return 42 === $actor_user_id && $artifact_id === ( $this->artifact['artifact_id'] ?? null ) ? $this->artifact : null;
	}
}
/**
 * G2-IMP-07-S03 deterministic worksheet package and private download contract.
 */
final class G2S03_Policy implements Access_Policy_Port_Interface {
	/**
	 * Initialize g2 s03 policy dependencies and state.
	 *
	 * @param bool $allowed Allowed.
	 */
	public function __construct( public bool $allowed = true ) {
	}
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		if ( ! $this->allowed ) {
			return Access_Policy_Decision::denied( 403, 'test_access_denied', $request->correlation_id() );
		}
		$scope = $request->object();
		return Access_Policy_Decision::allowed( $request->correlation_id(), null, Object_Authorization_Result::allowed( null === $scope ? g2s03_id( 2 ) : $scope->id(), 'approved', 'owner' ) );
	}
}

$asset_path = tempnam( sys_get_temp_dir(), 'oj-ai-g2-s03-asset-' );
if ( false === $asset_path || false === file_put_contents( $asset_path, "portable worksheet material\n", LOCK_EX ) ) {
	throw new RuntimeException( 'Fixture asset could not be created.' );
}
$archive_paths = array();
try {
	$worksheet_id = g2s03_id( 1 );
	$revision_id  = g2s03_id( 2 );
	$asset_id     = g2s03_id( 3 );
	$response_id  = g2s03_id( 4 );
	$criterion_id = g2s03_id( 5 );
	$document     = array(
		'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
		'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
		'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
		'document_id'    => g2s03_id( 6 ),
		'locale'         => 'ko-KR',
		'title'          => 'Portable worksheet',
		'nodes'          => array(
			g2s03_element( 'h1', 10, array(), array( array( 'text' => 'Portable worksheet' ) ) ),
			g2s03_element(
				'file',
				11,
				array(
					'asset_id' => $asset_id,
					'label'    => 'Material',
				)
			),
			g2s03_element(
				'oj_ai_response',
				12,
				array(
					'response_id' => $response_id,
					'kind'        => 'long_text',
					'label'       => 'Answer',
					'required'    => true,
					'constraints' => array(
						'min_chars' => 1,
						'max_chars' => 500,
					),
					'grading'     => array(
						'grading_enabled'      => true,
						'max_points'           => '5',
						'example_answer'       => 'Teacher answer',
						'rubric_criterion_ids' => array( $criterion_id ),
						'teacher_note'         => 'Teacher-only note',
					),
				)
			),
		),
		'settings'       => array(
			'student_navigation' => 'document',
			'print_profile'      => 'worksheet_a4@1',
		),
	);
	$canonical    = new Canonical_Json();
	$ids          = new Public_Id_Generator();
	$receipt      = ( new Worksheet_Document_Canonicalizer( $canonical, $ids ) )->canonicalize( $document );
	$document     = $receipt->document();
	$asset_bytes  = filesize( $asset_path );
	$asset_hash   = hash_file( 'sha256', $asset_path );
	g2s03_assert( is_int( $asset_bytes ) && is_string( $asset_hash ), 'Fixture asset metadata is unavailable.' );
	$source     = array(
		'achievement_links' => array(),
		'revision_id'       => $revision_id,
		'source_hash'       => $receipt->document_hash(),
		'source_state'      => 'approved',
		'teacher_document'  => $document,
	);
	$asset_read = new Read_Worksheet_Asset_Content(
		new G2S03_Asset_Repository( $asset_id, $asset_path, $asset_hash, $asset_bytes ),
		new G2S03_Asset_Source( $revision_id, $asset_id ),
		new G2S03_Target(),
		new G2S03_Private_Read( array( 'worksheet/assets/material.txt' => $asset_path ) ),
		static fn(): string=>'2026-08-22 10:00:00'
	);
	$builder    = new Build_Worksheet_Package_Source( new G2S03_Source_Query( $source ), $asset_read, new Worksheet_Document_Id_Rewriter(), $canonical );
	$repository = new G2S03_Package_Repository();
	$queue      = new G2S03_Queue();
	$enqueue    = new Enqueue_Worksheet_Package( $builder, $repository, $queue, $ids, $canonical, static fn(): string=>'2026-08-22 10:00:01' );
	$command    = new Enqueue_Worksheet_Package_Command( 42, $revision_id, 'cmd-package-0001', $revision_id, $receipt->document_hash(), 'approved', 1, hash( 'sha256', 'package-idempotency' ), 'corr-package-0001', $revision_id, 'approved', 'owner', $canonical );
	$queued     = $enqueue->execute( $command );
	g2s03_same( 'queued', $queued->state(), 'C66 must enqueue a package job.' );
	g2s03_same( 1, $queue->wakes, 'Committed package queue must wake once.' );
	$replayed = $enqueue->execute( $command );
	g2s03_same( $queued->job_id(), $replayed->job_id(), 'Same C66 command must replay the same job.' );
	g2s03_same( 1, $queue->wakes, 'Receipt replay must not wake the queue.' );
	$snapshot = Worksheet_Package_Request_Snapshot::restore( $repository->record['snapshot_json'], $repository->record['snapshot_hash'], $canonical );
	g2s03_same( $revision_id, $snapshot->source_id(), 'Package snapshot lost the exact revision.' );
	g2s03_assert( str_contains( $snapshot->json(), 'Teacher-only note' ), 'Teacher package must retain teacher-only authoring data inside the private artifact.' );

	$writer        = new Deterministic_Zip_Worksheet_Package_Writer( $canonical );
	$bundle        = $builder->build( 42, $revision_id, $receipt->document_hash(), 'approved' );
	$first         = $writer->write( $bundle );
	$second        = $writer->write( $bundle );
	$archive_paths = array( $first->path(), $second->path() );
	g2s03_same( $first->sha256(), $second->sha256(), 'Same frozen source must produce byte-identical .ojws archives.' );
	g2s03_same( $first->byte_size(), $second->byte_size(), 'Deterministic archive byte size drifted.' );

	$zip = new ZipArchive();
	g2s03_same( true, $zip->open( $first->path(), ZipArchive::CHECKCONS ), 'Generated .ojws must be a consistent ZIP.' );
	$names = array();for ( $index = 0;$index < $zip->numFiles;++$index ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
		$names[] = $zip->getNameIndex( $index );
	} $sorted = $names;
	sort( $sorted, SORT_STRING );
	g2s03_same( $sorted, $names, 'Archive entries must be written in lexical order.' );
	$manifest_raw = $zip->getFromName( 'manifest.json' );
	$zip->close();
	$manifest = json_decode( (string) $manifest_raw, true, 128, JSON_THROW_ON_ERROR );
	g2s03_same( 'oj_ai_worksheet_package', $manifest['format'], 'Package format marker drifted.' );
	g2s03_same( 'assets/' . $asset_hash . '.txt', $manifest['assets'][0]['path'], 'Asset content-addressed path drifted.' );

	$reader   = new Zip_Worksheet_Template_Package_Reader( $canonical, new Worksheet_Asset_Upload_Profile() );
	$imported = $reader->read(
		array(
			'name'     => 'worksheet.ojws',
			'type'     => 'application/vnd.oj.ai-worksheet+zip',
			'tmp_name' => $first->path(),
			'error'    => UPLOAD_ERR_OK,
			'size'     => $first->byte_size(),
		)
	);
	g2s03_same( $revision_id, $imported->source_revision_id(), 'Existing importer rejected or changed the exported revision.' );
	g2s03_same( $receipt->document_hash(), $imported->source_revision_hash(), 'Export/import document hash drifted.' );
	g2s03_same( $canonical->encode( $document ), $canonical->encode( $imported->teacher_document() ), 'Export/import teacher document drifted.' );
	$imported->release();

	$file_id = g2s03_id( 20 );
	$job_id  = g2s03_id( 21 );$package_row = array(
		'artifact_id'    => $file_id,
		'artifact_kind'  => 'worksheet_package',
		'job_id'         => $job_id,
		'source_type'    => 'worksheet_revision',
		'source_id'      => $revision_id,
		'source_hash'    => $receipt->document_hash(),
		'artifact_state' => 'ready',
		'expires_at_utc' => '2026-08-23 10:00:00',
		'page_count'     => null,
		'file_id'        => $file_id,
		'storage_key'    => 'generated/package/' . $file_id . '.ojws',
		'original_name'  => 'worksheet-' . $revision_id . '.ojws',
		'mime_type'      => 'application/vnd.oj.ai-worksheet+zip',
		'byte_size'      => $first->byte_size(),
		'sha256'         => $first->sha256(),
		'file_state'     => 'ready',
		'scan_state'     => 'clean',
	);
	$query    = new G2S03_Export_Query( $package_row );
	$policy   = new G2S03_Policy();
	$private  = new G2S03_Private_Read( array( $package_row['storage_key'] => $first->path() ) );
	$download = ( new Read_Export_Artifact( $query, $private, $policy, static fn(): string=>'2026-08-22 11:00:00' ) )->execute( 42, $file_id, 'corr-download' );
	g2s03_same( 'application/vnd.oj.ai-worksheet+zip', $download->mime_type(), 'Q24 must preserve the worksheet package MIME.' );
	try {
		( new Read_Export_Artifact( $query, $private, $policy, static fn(): string=>'2026-08-24 11:00:00' ) )->execute( 42, $file_id, 'corr-expired' );
		throw new RuntimeException( 'Expired package download must fail.' );
	} catch ( Export_Failure $failure ) {
		g2s03_same( Export_Failure::EXPIRED, $failure->reason(), 'Expired package failure drifted.' );
	}
	$policy->allowed = false;
	try {
		( new Read_Export_Artifact( $query, $private, $policy, static fn(): string=>'2026-08-22 11:00:00' ) )->execute( 42, $file_id, 'corr-denied' );
		throw new RuntimeException( 'Revoked package download must fail.' );
	} catch ( Export_Failure $failure ) {
		g2s03_same( Export_Failure::ACCESS_DENIED, $failure->reason(), 'Revoked package failure drifted.' );
	}
	$policy->allowed = true;
	++$query->artifact['byte_size'];
	try {
		( new Read_Export_Artifact( $query, $private, $policy, static fn(): string=>'2026-08-22 11:00:00' ) )->execute( 42, $file_id, 'corr-corrupt' );
		throw new RuntimeException( 'Corrupt package metadata must fail.' );
	} catch ( Export_Failure $failure ) {
		g2s03_same( Export_Failure::STORED_DATA_INVALID, $failure->reason(), 'Corrupt package failure drifted.' );
	}

	$runtime_files = array(
		$root . '/src/Reporting/WorksheetPackage/Infrastructure/WordPress_Worksheet_Package_Repository.php',
		$root . '/src/Storage/Infrastructure/WordPress_Worksheet_Package_Private_File_Metadata.php',
		$root . '/src/Reporting/Export/Infrastructure/WordPress_Export_Query.php',
		$root . '/src/Reporting/WorksheetPackage/Presentation/Worksheet_Package_Rest_Controller.php',
		$root . '/src/Reporting/Export/Presentation/Export_Rest_Controller.php',
	);
	foreach ( $runtime_files as $file ) {
		$code = file_get_contents( $file );
		g2s03_assert( is_string( $code ), 'Runtime source is unreadable.' );
		g2s03_assert( ! str_contains( $code, '$wpdb->prefix' ) && ! str_contains( $code, 'wp_oj_ai_' ), 'Forbidden table prefix found: ' . $file );
	}
	$repository_source = file_get_contents( $runtime_files[0] );
	g2s03_assert( str_contains( $repository_source, "'oj_ai_export_jobs'" ) && str_contains( $repository_source, "'worksheet_package'" ) && str_contains( $repository_source, "'pkgactive:'" ), 'Package storage or semantic idempotency marker drifted.' );
	$package_controller = file_get_contents( $runtime_files[3] );
	g2s03_assert( str_contains( $package_controller, '/commands/export-package' ) && str_contains( $package_controller, "'format_version'" ), 'C66 REST marker drifted.' );
	$export_controller = file_get_contents( $runtime_files[4] );
	foreach ( array( '/export-jobs/', '/artifacts/', 'rest_pre_serve_request', 'attachment; filename*=UTF-8' ) as $marker ) {
		g2s03_assert( str_contains( $export_controller, $marker ), 'Q23/Q24 marker missing: ' . $marker );
	}
} finally {
	foreach ( $archive_paths as $path ) {
		if ( is_string( $path ) && is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of an owned temporary path must not mask the primary outcome.
		} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
	}
	if ( is_file( $asset_path ) ) {
		@unlink( $asset_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of an owned temporary path must not mask the primary outcome.
	} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
}

echo "G2-IMP-07-S03 passed: C66 exact snapshot, deterministic .ojws, importer compatibility, private artifact MIME, expiry, authorization, corruption, and no-prefix boundaries.\n";
