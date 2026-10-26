<?php
/**
 * G5-IMP-03-S03 C69 exact report JSON package enqueue contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Report\Application\Read_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Reporting\ReportExport\Application\Enqueue_Report_Export;
use OJAI\Dashboard\Reporting\ReportExport\Application\Enqueue_Report_Export_Command;
use OJAI\Dashboard\Reporting\ReportExport\Application\Report_Export_Failure;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;
use OJAI\Dashboard\Reporting\ReportExport\Infrastructure\WordPress_Report_Export_Repository;
use OJAI\Dashboard\Reporting\ReportExport\Presentation\Report_Export_Rest_Controller;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

require __DIR__ . '/report-export-enqueue-g5-imp-03-s01.php';

/**
 * G5 json package command.
 *
 * @param array          $fixture Fixture.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $command_id Command identifier used for idempotency.
 * @param string         $fingerprint_seed Fingerprint seed.
 * @param string|null    $expected_hash Expected hash.
 * @return Enqueue_Report_Export_Command
 */
function g5_json_package_command(
	array $fixture,
	Canonical_Json $canonical,
	string $command_id,
	string $fingerprint_seed,
	?string $expected_hash = null
): Enqueue_Report_Export_Command {
	$hash = $expected_hash ?? $fixture['snapshot_hash'];
	return new Enqueue_Report_Export_Command(
		77,
		'report_json_package',
		$fixture['record']['public_id'],
		$command_id,
		$fixture['record']['public_id'],
		$hash,
		'ready',
		'ko-KR',
		Report_Export_Request_Snapshot::JSON_PACKAGE_PROFILE,
		hash( 'sha256', $fingerprint_seed ),
		'correlation-' . $command_id,
		$hash,
		'ready',
		'educator',
		'class',
		'class-g5-snapshot',
		'class-g5-snapshot-r2',
		'educator',
		$canonical
	);
}

$json_canonical  = new Canonical_Json();
$json_fixture    = g5_snapshot_q22_record( $json_canonical );
$json_query      = new G5_Snapshot_Q22_Query( $json_fixture['record'], $json_canonical );
$json_objects    = new G5_Snapshot_Q22_Objects();
$json_reader     = new Read_Report_Snapshot( $json_query, $json_objects, new Report_Snapshot_Profile( $json_canonical ), $json_canonical );
$json_context    = Context_Authorization_Result::allowed( 'class', 'class-g5-snapshot', 'active', 'class-g5-snapshot-r2', 'educator', 'g5-json-package' );
$json_repository = new G5_Report_Export_Memory_Repository();
$json_queue      = new G5_Report_Export_Queue();
$json_enqueue    = new Enqueue_Report_Export(
	$json_reader,
	$json_repository,
	$json_queue,
	new Public_Id_Generator(),
	$json_canonical,
	static fn (): string => '2026-08-25 16:00:00'
);

$json_command = g5_json_package_command( $json_fixture, $json_canonical, 'command-g5-report-json', 'json-package-key' );
$json_result  = $json_enqueue->execute( $json_command, $json_context );
g5_report_export_same( 'report_json_package', $json_result->artifact_kind(), 'C69 returned the wrong artifact kind.' );
g5_report_export_same( 1, count( $json_repository->records ), 'C69 did not enqueue exactly one job.' );
g5_report_export_same( 1, $json_queue->calls, 'C69 did not wake the private export queue once.' );

$json_record   = $json_repository->records[0];
$json_snapshot = Report_Export_Request_Snapshot::restore( $json_record['snapshot_json'], $json_record['snapshot_hash'], $json_canonical );
g5_report_export_same( Report_Export_Request_Snapshot::JSON_PACKAGE_PROFILE, $json_snapshot->profile(), 'C69 request profile drifted.' );
g5_report_export_same( $json_fixture['projection'], $json_snapshot->projection(), 'C69 changed the exact report projection.' );
g5_report_export_same( $json_fixture['record']['filter_hash'], $json_snapshot->filter_hash(), 'C69 filter hash linkage drifted.' );
g5_report_export_same( $json_fixture['record']['source_manifest_hash'], $json_snapshot->source_manifest_hash(), 'C69 source manifest hash linkage drifted.' );
g5_report_export_same( $json_fixture['record']['projection_hash'], $json_snapshot->projection_hash(), 'C69 projection hash linkage drifted.' );
g5_report_export_same( 'class-g5-snapshot-r2', $json_snapshot->context()['revision'], 'C69 lost current Context authorization evidence.' );
g5_report_export_same( 'class-g5-snapshot-r1', $json_snapshot->projection()['context']['revision'], 'C69 changed the immutable source Context revision.' );

$manifest = $json_reader->read_source_manifest( 77, $json_fixture['record']['public_id'], $json_context );
g5_report_export_same( $json_snapshot->source_manifest_hash(), $manifest->source_manifest_hash(), 'C69 request does not identify the exact internal source manifest.' );
g5_report_export_same( $json_fixture['record']['source_manifest_json'], $manifest->source_manifest_json(), 'C69 canonical manifest bytes drifted before enqueue.' );
g5_report_export_same( $json_snapshot->source_hash(), $manifest->snapshot_hash(), 'C69 manifest is not linked to the exact report snapshot.' );

$authorized_before_replay = count( $json_objects->calls );
$json_replay              = $json_enqueue->execute( $json_command, $json_context );
g5_report_export_assert( $json_replay->replayed(), 'C69 durable retry was not marked as replayed.' );
g5_report_export_same( $json_result->job_id(), $json_replay->job_id(), 'C69 replay changed the job ID.' );
g5_report_export_same( $authorized_before_replay, count( $json_objects->calls ), 'C69 exact replay re-read mutable report sources.' );
g5_report_export_same( 1, $json_queue->calls, 'C69 exact replay woke the queue again.' );

$stale_hash = hash( 'sha256', 'stale-json-package-snapshot' );
g5_report_export_failure(
	static fn () => $json_enqueue->execute( g5_json_package_command( $json_fixture, $json_canonical, 'command-g5-json-stale', 'json-stale-key', $stale_hash ), $json_context ),
	Report_Export_Failure::SOURCE_STALE,
	'C69 accepted an expected snapshot hash that no longer matched Q22.'
);

$json_objects->deny_id = $json_fixture['worksheet_ids'][0];
g5_report_export_failure(
	static fn () => $json_enqueue->execute( g5_json_package_command( $json_fixture, $json_canonical, 'command-g5-json-revoked', 'json-revoked-key' ), $json_context ),
	Report_Export_Failure::SOURCE_ACCESS_DENIED,
	'C69 exported after one source worksheet permission was revoked.'
);
$json_objects->deny_id = null;

$json_enqueue->execute( g5_json_package_command( $json_fixture, $json_canonical, 'command-g5-json-conflict-a', 'json-conflict-key' ), $json_context );
g5_report_export_failure(
	static fn () => $json_enqueue->execute( g5_json_package_command( $json_fixture, $json_canonical, 'command-g5-json-conflict-b', 'json-conflict-key' ), $json_context ),
	Report_Export_Failure::IDEMPOTENCY_CONFLICT,
	'C69 accepted a reused Idempotency-Key with a different command digest.'
);

$controller_reflection = new ReflectionClass( Report_Export_Rest_Controller::class );
$controller            = $controller_reflection->newInstanceWithoutConstructor();
$input                 = $controller_reflection->getMethod( 'input' );
$request               = new class( $json_fixture ) {
	/**
	 * Initialize report-json-package-enqueue-g5-imp-03-s03 dependencies and state.
	 *
	 * @param array $fixture Fixture.
	 */
	public function __construct( private array $fixture ) {
	}
	/**
	 * Get json params.
	 *
	 * @return array
	 */
	public function get_json_params(): array {
		return array(
			'command_id' => 'command-g5-rest-c69',
			'expected'   => array(
				'expected_snapshot_id'    => $this->fixture['record']['public_id'],
				'expected_snapshot_hash'  => $this->fixture['snapshot_hash'],
				'expected_snapshot_state' => 'ready',
			),
			'payload'    => array(
				'locale'  => 'ko-KR',
				'profile' => Report_Export_Request_Snapshot::JSON_PACKAGE_PROFILE,
			),
		);
	}
};
$parsed                = $input->invoke( $controller, $request, 'report_json_package' );
g5_report_export_same( Report_Export_Request_Snapshot::JSON_PACKAGE_PROFILE, $parsed['profile'], 'C69 REST parser changed the JSON package profile.' );
try {
	$input->invoke( $controller, $request, 'report_csv' );
	throw new RuntimeException( 'C69 JSON package request was accepted by C46.' );
} catch ( ReflectionException | InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
}

$registered = $controller_reflection->getProperty( 'registered' );
$registered->setValue( $controller, false );
$GLOBALS['g5_snapshot_q22_routes'] = array();
$controller->register_routes();
g5_report_export_same( 3, count( $GLOBALS['g5_snapshot_q22_routes'] ), 'C69 changed the additive report export route count.' );
g5_report_export_same( Report_Export_Rest_Controller::ROUTE_JSON_PACKAGE, $GLOBALS['g5_snapshot_q22_routes'][2][1], 'C69 REST route drifted.' );

$json_database       = new G5_Report_Export_Database( $json_fixture['record']['public_id'] );
$json_storage        = new WordPress_Report_Export_Repository( $json_canonical, $json_database );
$json_storage_result = $json_storage->enqueue( $json_record );
$stored_job          = $json_database->tables['oj_ai_export_jobs'][0];
g5_report_export_same( 1, count( $json_database->tables['oj_ai_export_jobs'] ), 'C69 storage did not create one export job.' );
g5_report_export_same( 1, count( $json_database->tables['oj_ai_audit_events'] ), 'C69 storage did not create one audit event.' );
g5_report_export_same( 1, count( $json_database->tables['oj_ai_outbox_events'] ), 'C69 storage did not create one durable receipt.' );
g5_report_export_same( 7, $stored_job['report_snapshot_id'], 'C69 did not bind the exact report snapshot foreign key.' );
g5_report_export_same( 'report_snapshot', $stored_job['source_type'], 'C69 source owner drifted.' );
g5_report_export_same( 'report_json_package', $stored_job['artifact_kind'], 'C69 stored the wrong artifact kind.' );
g5_report_export_same( Report_Export_Request_Snapshot::JSON_PACKAGE_PROFILE, $stored_job['print_profile_version'], 'C69 stored the wrong package profile.' );
g5_report_export_same( $json_record['snapshot_json'], $stored_job['request_snapshot_json'], 'C69 storage changed immutable request bytes.' );
g5_report_export_same( 'report_json_package.queued', $json_database->tables['oj_ai_audit_events'][0]['action_key'], 'C69 audit action drifted.' );
$durable = $json_storage->find_command_receipt( $json_record['command_storage_key'], $json_record['request_digest'] );
g5_report_export_assert( null !== $durable && $durable->replayed(), 'C69 durable receipt was not restored.' );
g5_report_export_same( $json_storage_result->job_id(), $durable->job_id(), 'C69 durable replay changed the job ID.' );

$rollback_database                    = new G5_Report_Export_Database( $json_fixture['record']['public_id'] );
$rollback_database->fail_insert_table = 'oj_ai_outbox_events';
$rollback_storage                     = new WordPress_Report_Export_Repository( $json_canonical, $rollback_database );
g5_report_export_failure(
	static fn () => $rollback_storage->enqueue( $json_record ),
	Report_Export_Failure::STORAGE_UNAVAILABLE,
	'C69 did not surface a transactional outbox failure.'
);
g5_report_export_same( 0, count( $rollback_database->tables['oj_ai_export_jobs'] ), 'C69 rollback left a partial export job.' );
g5_report_export_same( 0, count( $rollback_database->tables['oj_ai_audit_events'] ), 'C69 rollback left a partial audit event.' );
g5_report_export_same( 0, count( $rollback_database->tables['oj_ai_outbox_events'] ), 'C69 rollback left a partial outbox receipt.' );

$runtime_files  = array(
	dirname( __DIR__, 2 ) . '/src/Reporting/ReportExport/Domain/Report_Export_Request_Snapshot.php',
	dirname( __DIR__, 2 ) . '/src/Reporting/ReportExport/Infrastructure/WordPress_Report_Export_Repository.php',
	dirname( __DIR__, 2 ) . '/src/Reporting/ReportExport/Presentation/Report_Export_Rest_Controller.php',
);
$runtime_source = '';
foreach ( $runtime_files as $runtime_file ) {
	$runtime_source .= (string) file_get_contents( $runtime_file );
}
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
	g5_report_export_assert( ! str_contains( $runtime_source, $forbidden ), 'C69 runtime contains a forbidden boundary marker: ' . $forbidden );
}
foreach ( array( "'report_json_package'", "'report_json_package.v1'", '/commands/export-json-package', "'oj_ai_export_jobs'", "'oj_ai_report_snapshots'" ) as $marker ) {
	g5_report_export_assert( str_contains( $runtime_source, $marker ), 'C69 runtime marker is missing: ' . $marker );
}

fwrite( STDOUT, "G5-IMP-03-S03 passed: C69 exact report snapshot JSON package profile, canonical manifest linkage, current authorization, durable idempotency, report FK, and atomic enqueue are frozen.\n" );
