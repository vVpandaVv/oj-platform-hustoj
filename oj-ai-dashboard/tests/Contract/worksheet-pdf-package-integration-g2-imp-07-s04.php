<?php
/**
 * G2-IMP-07-S04 PDF/package integration and security freeze.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Reporting\Export\Application\Export_Download;
use OJAI\Dashboard\Reporting\Export\Application\Export_Failure;
use OJAI\Dashboard\Reporting\Export\Application\Read_Export_Artifact;
use OJAI\Dashboard\Reporting\Export\Application\Read_Export_Job;
use OJAI\Dashboard\Reporting\Export\Port\Export_Query_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPackage\Domain\Worksheet_Package_Request_Snapshot;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Print_Html_Serializer;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
defined( 'OJ_AI_DASHBOARD_DIR' ) || define( 'OJ_AI_DASHBOARD_DIR', $root . DIRECTORY_SEPARATOR );
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Core/Autoloader.php';
( new AddonAutoloader( $root . '/src' ) )->register();

/**
 * G2s04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2s04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G2s04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2s04_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G2s04 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function g2s04_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	g2s04_assert( Public_Id::is_valid( $id ), 'Fixture public ID is invalid.' );
	return $id;
}

/**
 * G2s04 failure.
 *
 * @param callable $operation Operation.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2s04_failure( callable $operation, string $reason, string $message ): void {
	try {
		$operation();
	} catch ( Export_Failure $failure ) {
		g2s04_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * G2-IMP-07-S04 PDF/package integration and security freeze.
 */
final class G2S04_Export_Query implements Export_Query_Port_Interface {
	/**
	 * Initialize g2 s04 export query dependencies and state.
	 *
	 * @param array<string,array<string,mixed>> $jobs Jobs.
	 * @param array<string,array<string,mixed>> $artifacts Artifacts.
	 */
	public function __construct( public array $jobs, public array $artifacts ) {
	}

	/**
	 * Find job for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $job_id Job id.
	 * @return array|null
	 */
	public function find_job_for_actor( int $actor_user_id, string $job_id ): ?array {
		return 42 === $actor_user_id ? ( $this->jobs[ $job_id ] ?? null ) : null;
	}

	/**
	 * Find artifact for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $artifact_id Artifact id.
	 * @return array|null
	 */
	public function find_artifact_for_actor( int $actor_user_id, string $artifact_id ): ?array {
		return 42 === $actor_user_id ? ( $this->artifacts[ $artifact_id ] ?? null ) : null;
	}
}

/**
 * G2-IMP-07-S04 PDF/package integration and security freeze.
 */
final class G2S04_Private_Read implements Private_File_Read_Port_Interface {
	/**
	 * Reads.
	 *
	 * @var int
	 */
	public int $reads = 0;

	/**
	 * Initialize g2 s04 private read dependencies and state.
	 *
	 * @param array<string,string> $paths Paths.
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
		++$this->reads;
		$path = $this->paths[ $storage_key ] ?? '';
		if ( ! is_file( $path ) || ! hash_equals( $sha256, (string) hash_file( 'sha256', $path ) ) ) {
			throw new RuntimeException( 'Private fixture file is unavailable.' );
		}
		return $path;
	}
}

/**
 * G2-IMP-07-S04 PDF/package integration and security freeze.
 */
final class G2S04_Policy implements Access_Policy_Port_Interface {
	/**
	 * Allowed.
	 *
	 * @var bool
	 */
	public bool $allowed = true;
	/**
	 * Relation.
	 *
	 * @var string
	 */
	public string $relation = 'owner';
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		++$this->calls;
		if ( ! $this->allowed ) {
			return Access_Policy_Decision::denied( 403, 'test_access_denied', $request->correlation_id() );
		}
		$scope = $request->object();
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null,
			Object_Authorization_Result::allowed( null === $scope ? g2s04_id( 90 ) : $scope->id(), 'approved', $this->relation )
		);
	}
}

$canonical        = new Canonical_Json();
$revision_id      = g2s04_id( 1 );
$response_id      = g2s04_id( 2 );
$criterion_id     = g2s04_id( 3 );
$teacher_document = array(
	'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
	'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
	'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
	'document_id'    => g2s04_id( 4 ),
	'locale'         => 'ko-KR',
	'title'          => '통합 보안 활동지',
	'nodes'          => array(
		array(
			'type'        => 'oj_ai_response',
			'node_id'     => g2s04_id( 5 ),
			'response_id' => $response_id,
			'kind'        => 'long_text',
			'label'       => '관찰 결과',
			'required'    => true,
			'constraints' => array(
				'min_chars' => 1,
				'max_chars' => 500,
			),
			'grading'     => array(
				'grading_enabled'      => true,
				'max_points'           => '5',
				'example_answer'       => 'S04_PRIVATE_ANSWER',
				'rubric_criterion_ids' => array( $criterion_id ),
				'teacher_note'         => 'S04_PRIVATE_NOTE',
			),
			'children'    => array( array( 'text' => '' ) ),
		),
	),
	'settings'       => array(
		'student_navigation' => 'document',
		'print_profile'      => 'worksheet_a4@1',
	),
);
$source_hash      = hash( 'sha256', $canonical->encode( $teacher_document ) );
$print_projection = array(
	'print_projection' => array(
		'schema_id' => Worksheet_Preview_Projector::PRINT_SCHEMA,
		'payload'   => array(
			'profile'  => 'student_blank',
			'document' => array(
				'locale' => 'ko-KR',
				'title'  => $teacher_document['title'],
				'nodes'  => $teacher_document['nodes'],
			),
		),
	),
);
$html             = ( new Worksheet_Print_Html_Serializer() )->serialize( $print_projection, $revision_id );
g2s04_assert( str_contains( $html, '관찰 결과' ), 'Public PDF lost the response field.' );
g2s04_assert( ! str_contains( $html, 'S04_PRIVATE_ANSWER' ) && ! str_contains( $html, 'S04_PRIVATE_NOTE' ), 'Public PDF leaked teacher-only fields.' );

$package_snapshot = Worksheet_Package_Request_Snapshot::freeze(
	array(
		'achievement_links' => array(),
		'revision_id'       => $revision_id,
		'source_hash'       => $source_hash,
		'source_state'      => 'approved',
		'teacher_document'  => $teacher_document,
	),
	array(),
	$canonical
);
g2s04_assert( str_contains( $package_snapshot->json(), 'S04_PRIVATE_ANSWER' ) && str_contains( $package_snapshot->json(), 'S04_PRIVATE_NOTE' ), 'Private teacher package must retain authoring data.' );

$pdf_path     = tempnam( sys_get_temp_dir(), 'oj-ai-g2-s04-pdf-' );
$package_path = tempnam( sys_get_temp_dir(), 'oj-ai-g2-s04-package-' );
if ( false === $pdf_path || false === $package_path ) {
	throw new RuntimeException( 'Could not create export fixtures.' );
}

try {
	file_put_contents( $pdf_path, "%PDF-1.7\nS04 fixture\n%%EOF", LOCK_EX );
	file_put_contents( $package_path, "PK\x03\x04S04 fixture", LOCK_EX );
	$pdf_id         = g2s04_id( 10 );
	$package_id     = g2s04_id( 11 );
	$pdf_job_id     = g2s04_id( 12 );
	$package_job_id = g2s04_id( 13 );
	$pdf_key        = 'generated/pdf/' . strtolower( $pdf_id ) . '.pdf';
	$package_key    = 'generated/package/' . strtolower( $package_id ) . '.ojws';
	$pdf_size       = filesize( $pdf_path );
	$package_size   = filesize( $package_path );
	$pdf_hash       = hash_file( 'sha256', $pdf_path );
	$package_hash   = hash_file( 'sha256', $package_path );
	g2s04_assert( is_int( $pdf_size ) && is_int( $package_size ) && is_string( $pdf_hash ) && is_string( $package_hash ), 'Fixture metadata is unavailable.' );

	$jobs      = array(
		$pdf_job_id => array(
			'job_id'          => $pdf_job_id,
			'state'           => 'ready',
			'artifact_kind'   => 'worksheet_pdf',
			'source_type'     => 'worksheet_revision',
			'source_id'       => $revision_id,
			'source_hash'     => $source_hash,
			'profile_version' => 'worksheet_print.v1',
			'queued_at_utc'   => '2026-08-22 09:00:00',
			'started_at_utc'  => '2026-08-22 09:00:01',
			'finished_at_utc' => '2026-08-22 09:00:02',
			'error_code'      => null,
			'artifact_id'     => $pdf_id,
			'expires_at_utc'  => '2026-08-23 09:00:02',
		),
	);
	$artifact  = static function ( string $id, string $job_id, string $kind, string $path, string $key, string $name, string $mime, int $bytes, string $sha ) use ( $revision_id, $source_hash ): array {
		return array(
			'artifact_id'    => $id,
			'artifact_kind'  => $kind,
			'job_id'         => $job_id,
			'source_type'    => 'worksheet_revision',
			'source_id'      => $revision_id,
			'source_hash'    => $source_hash,
			'artifact_state' => 'ready',
			'expires_at_utc' => '2026-08-23 09:00:02',
			'page_count'     => 'worksheet_pdf' === $kind ? 1 : null,
			'file_id'        => $id,
			'storage_key'    => $key,
			'original_name'  => $name,
			'mime_type'      => $mime,
			'byte_size'      => $bytes,
			'sha256'         => $sha,
			'file_state'     => 'ready',
			'scan_state'     => 'clean',
			'fixture_path'   => $path,
		);
	};
	$artifacts = array(
		$pdf_id     => $artifact( $pdf_id, $pdf_job_id, 'worksheet_pdf', $pdf_path, $pdf_key, 'worksheet-s04.pdf', 'application/pdf', $pdf_size, $pdf_hash ),
		$package_id => $artifact( $package_id, $package_job_id, 'worksheet_package', $package_path, $package_key, 'worksheet-s04.ojws', 'application/vnd.oj.ai-worksheet+zip', $package_size, $package_hash ),
	);
	$query     = new G2S04_Export_Query( $jobs, $artifacts );
	$policy    = new G2S04_Policy();
	$files     = new G2S04_Private_Read(
		array(
			$pdf_key     => $pdf_path,
			$package_key => $package_path,
		)
	);

	$ready = ( new Read_Export_Job( $query, $policy, static fn (): string => '2026-08-22 10:00:00' ) )->execute( 42, $pdf_job_id, 'corr-s04-ready' );
	g2s04_same( 'ready', $ready['state'], 'Q23 changed a valid ready job.' );
	$expired = ( new Read_Export_Job( $query, $policy, static fn (): string => '2026-08-24 10:00:00' ) )->execute( 42, $pdf_job_id, 'corr-s04-expired' );
	g2s04_same( 'expired', $expired['state'], 'Q23 must hide an expired artifact.' );
	g2s04_same( null, $expired['artifact_id'], 'Q23 exposed an expired artifact ID.' );

	$pdf_download = ( new Read_Export_Artifact( $query, $files, $policy, static fn (): string => '2026-08-22 10:00:00' ) )->execute( 42, $pdf_id, 'corr-s04-pdf' );
	g2s04_same( 'application/pdf', $pdf_download->mime_type(), 'Q24 PDF MIME drifted.' );
	$package_download = ( new Read_Export_Artifact( $query, $files, $policy, static fn (): string => '2026-08-22 10:00:00' ) )->execute( 42, $package_id, 'corr-s04-package' );
	g2s04_same( 'worksheet_package', $package_download->artifact_kind(), 'Q24 package kind drifted.' );

	$reads_before    = $files->reads;
	$policy->allowed = false;
	g2s04_failure(
		static fn () => ( new Read_Export_Artifact( $query, $files, $policy ) )->execute( 42, $pdf_id, 'corr-s04-denied' ),
		Export_Failure::ACCESS_DENIED,
		'Revoked Q24 access must fail.'
	);
	g2s04_same( $reads_before, $files->reads, 'Q24 read private bytes before authorization.' );
	$policy->allowed = true;
	g2s04_failure(
		static fn () => ( new Read_Export_Artifact( $query, $files, $policy, static fn (): string => '2026-08-24 10:00:00' ) )->execute( 42, $pdf_id, 'corr-s04-expired-file' ),
		Export_Failure::EXPIRED,
		'Expired Q24 access must fail.'
	);
	g2s04_same( $reads_before, $files->reads, 'Q24 read expired private bytes.' );

	$query->artifacts[ $pdf_id ]['byte_size'] = $pdf_size + 1;
	g2s04_failure(
		static fn () => ( new Read_Export_Artifact( $query, $files, $policy, static fn (): string => '2026-08-22 10:00:00' ) )->execute( 42, $pdf_id, 'corr-s04-size' ),
		Export_Failure::STORED_DATA_INVALID,
		'Q24 byte-size corruption must fail.'
	);

	try {
		new Export_Download( $pdf_path, 'worksheet-s04.pdf', 'application/vnd.oj.ai-worksheet+zip', 'worksheet_pdf', $pdf_size, $pdf_hash );
		throw new RuntimeException( 'MIME/kind mismatch must fail.' );
	} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
		// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
	}
} finally {
	if ( is_file( $pdf_path ) ) {
		@unlink( $pdf_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of an owned temporary path must not mask the primary outcome.
	} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
	if ( is_file( $package_path ) ) {
		@unlink( $package_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of an owned temporary path must not mask the primary outcome.
	} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
}

$worker_files     = array(
	$root . '/src/Reporting/WorksheetPdf/Application/Run_Next_Worksheet_Pdf.php',
	$root . '/src/Reporting/WorksheetPackage/Application/Run_Next_Worksheet_Package.php',
);
$repository_files = array(
	$root . '/src/Reporting/WorksheetPdf/Infrastructure/WordPress_Worksheet_Pdf_Repository.php',
	$root . '/src/Reporting/WorksheetPackage/Infrastructure/WordPress_Worksheet_Package_Repository.php',
);
$runtime_files    = array_merge(
	$worker_files,
	$repository_files,
	array(
		$root . '/src/Reporting/Export/Application/Export_Download.php',
		$root . '/src/Reporting/Export/Application/Read_Export_Job.php',
		$root . '/src/Reporting/Export/Application/Read_Export_Artifact.php',
		$root . '/src/Reporting/Export/Infrastructure/WordPress_Export_Query.php',
		$root . '/src/Reporting/Export/Presentation/Export_Rest_Controller.php',
		$root . '/src/Platform/Worksheet_Workbench_Page_Handler.php',
	)
);
foreach ( $runtime_files as $file ) {
	$source = file_get_contents( $file );
	g2s04_assert( is_string( $source ), 'S04 runtime source is unreadable: ' . $file );
	g2s04_assert( ! str_contains( $source, '$wpdb->prefix' ) && ! str_contains( $source, 'wp_oj_ai_' ), 'Forbidden table prefix found: ' . $file );
	g2s04_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'OJ Platform internal dependency found: ' . $file );
}
foreach ( $worker_files as $file ) {
	$source = (string) file_get_contents( $file );
	foreach ( array( 'LEASE_SECONDS = 300', 'claim_due(', 'if ( ! $committed && $created ) { $this->files->delete( $storage_key ); }' ) as $marker ) {
		g2s04_assert( Ojai_Php_Source_Contract::marker( $source, $marker ), 'Worker concurrency marker is missing: ' . $marker );
	}
	g2s04_same( 2, substr_count( $source, '$this->authorized(' ), 'Worker must reauthorize immediately before private-file persistence.' );
}
g2s04_same( 2, substr_count( (string) file_get_contents( $worker_files[0] ), '$this->assert_projection_matches(' ), 'PDF worker must recheck the exact public projection.' );
g2s04_same( 2, substr_count( (string) file_get_contents( $worker_files[1] ), '$this->assert_same_snapshot(' ), 'Package worker must recheck the exact teacher snapshot.' );
foreach ( $repository_files as $file ) {
	$source = (string) file_get_contents( $file );
	foreach ( array( 'lease_expires_at_utc IS NOT NULL', 'LIMIT 1 FOR UPDATE', 'lock_version = %d AND state = %s AND lease_token = %s' ) as $marker ) {
		g2s04_assert( Ojai_Php_Source_Contract::marker( $source, $marker ), 'Repository lease/CAS marker is missing: ' . $marker );
	}
}

$query_source = (string) file_get_contents( $root . '/src/Reporting/Export/Infrastructure/WordPress_Export_Query.php' );
g2s04_assert( str_contains( $query_source, "'oj_ai_export_jobs'" ) && str_contains( $query_source, "'oj_ai_pdf_artifacts'" ) && str_contains( $query_source, "'oj_ai_private_files'" ), 'Literal no-prefix export tables drifted.' );
$download_source = (string) file_get_contents( $root . '/src/Reporting/Export/Application/Export_Download.php' );
foreach ( array( 'worksheet_pdf', 'worksheet_package', '104_857_600', '230_686_720' ) as $marker ) {
	g2s04_assert( str_contains( $download_source, $marker ), 'Download profile marker is missing: ' . $marker );
}
$page_source = (string) file_get_contents( $root . '/src/Platform/Worksheet_Workbench_Page_Handler.php' );
g2s04_assert( str_contains( $page_source, 'Addon_Capabilities::EXPORT_REPORTS' ) && str_contains( $page_source, 'data-role="export-controls"' ), 'Capability-gated export controls are missing.' );
$workbench_source = (string) file_get_contents( $root . '/resources/js/worksheet-authoring-workbench.js' );
foreach ( array( 'worksheetExportCommandBody', '/export-jobs/', '/artifacts/', 'X-OJ-Content-SHA256', 'export-pdf', 'export-package' ) as $marker ) {
	g2s04_assert( str_contains( $workbench_source, $marker ), 'Workbench export marker is missing: ' . $marker );
}

echo "G2-IMP-07-S04 passed: public/private data boundary, Q23/Q24 authorization-expiry-integrity, exact MIME profiles, lease reclaim, source recheck, CAS compensation, UI routes, and no-prefix isolation.\n";
