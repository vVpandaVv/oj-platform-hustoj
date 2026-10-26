<?php
/**
 * G5-IMP-03-S01 C46/C47 exact report export enqueue contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Report\Application\Read_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Reporting\ReportExport\Application\Enqueue_Report_Export;
use OJAI\Dashboard\Reporting\ReportExport\Application\Enqueue_Report_Export_Command;
use OJAI\Dashboard\Reporting\ReportExport\Application\Enqueue_Report_Export_Result;
use OJAI\Dashboard\Reporting\ReportExport\Application\Report_Export_Failure;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;
use OJAI\Dashboard\Reporting\ReportExport\Infrastructure\WordPress_Report_Export_Repository;
use OJAI\Dashboard\Reporting\ReportExport\Port\Report_Export_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\ReportExport\Port\Report_Export_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\ReportExport\Presentation\Report_Export_Rest_Controller;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

require __DIR__ . '/report-snapshot-query-g5-imp-02-s02.php';

/**
 * G5 report export assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_report_export_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
/**
 * G5 report export same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_report_export_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}
/**
 * G5 report export failure.
 *
 * @param callable $operation Operation.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_report_export_failure( callable $operation, string $reason, string $message ): void {
	try {
		$operation();
	} catch ( Report_Export_Failure $failure ) {
		g5_report_export_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * G5-IMP-03-S01 C46/C47 exact report export enqueue contract.
 */
final class G5_Report_Export_Memory_Repository implements Report_Export_Repository_Port_Interface {
	/**
	 * Records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $records = array();
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,result:Enqueue_Report_Export_Result}>
	 */
	private array $receipts = array();
	/**
	 * Find command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Enqueue_Report_Export_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $key, string $digest ): ?Enqueue_Report_Export_Result {
		$receipt = $this->receipts[ $key ] ?? null;
		if ( null === $receipt ) {
			return null;
		}
		if ( ! hash_equals( $receipt['digest'], $digest ) ) {
			throw Report_Export_Failure::because( Report_Export_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $receipt['result'];
		return new Enqueue_Report_Export_Result( $result->job_id(), $result->state(), $result->artifact_kind(), $result->snapshot_id(), $result->snapshot_hash(), $result->queued_at_utc(), true );
	}
	/**
	 * Enqueue.
	 *
	 * @param array $record Record.
	 * @return Enqueue_Report_Export_Result
	 */
	public function enqueue( array $record ): Enqueue_Report_Export_Result {
		$result          = new Enqueue_Report_Export_Result( $record['job_public_id'], 'queued', $record['artifact_kind'], $record['source_public_id'], $record['source_hash'], $record['queued_at_utc'], false );
		$this->records[] = $record;
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		return $result;
	}
}

/**
 * G5-IMP-03-S01 C46/C47 exact report export enqueue contract.
 */
final class G5_Report_Export_Queue implements Report_Export_Queue_Wakeup_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void {
		++$this->calls;
	}
}

/**
 * G5 report export command.
 *
 * @param string         $kind Kind.
 * @param array          $fixture Fixture.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $command Command.
 * @param string         $fingerprint_seed Fingerprint seed.
 * @param string|null    $expected_hash Expected hash.
 * @return Enqueue_Report_Export_Command
 */
function g5_report_export_command( string $kind, array $fixture, Canonical_Json $canonical, string $command, string $fingerprint_seed, ?string $expected_hash = null ): Enqueue_Report_Export_Command {
	$profile = 'report_csv' === $kind ? Report_Export_Request_Snapshot::CSV_PROFILE : Report_Export_Request_Snapshot::PDF_PROFILE;
	$hash    = $expected_hash ?? $fixture['snapshot_hash'];
	return new Enqueue_Report_Export_Command(
		77,
		$kind,
		$fixture['record']['public_id'],
		$command,
		$fixture['record']['public_id'],
		$hash,
		'ready',
		'ko-KR',
		$profile,
		hash( 'sha256', $fingerprint_seed ),
		'correlation-' . $command,
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

$export_canonical  = new Canonical_Json();
$export_fixture    = g5_snapshot_q22_record( $export_canonical );
$export_query      = new G5_Snapshot_Q22_Query( $export_fixture['record'], $export_canonical );
$export_objects    = new G5_Snapshot_Q22_Objects();
$export_reader     = new Read_Report_Snapshot( $export_query, $export_objects, new Report_Snapshot_Profile( $export_canonical ), $export_canonical );
$export_context    = Context_Authorization_Result::allowed( 'class', 'class-g5-snapshot', 'active', 'class-g5-snapshot-r2', 'educator', 'g5-report-export' );
$memory_repository = new G5_Report_Export_Memory_Repository();
$memory_queue      = new G5_Report_Export_Queue();
$enqueue           = new Enqueue_Report_Export( $export_reader, $memory_repository, $memory_queue, new Public_Id_Generator(), $export_canonical, static fn (): string => '2026-08-25 15:00:00' );

$csv_command = g5_report_export_command( 'report_csv', $export_fixture, $export_canonical, 'command-g5-report-csv', 'csv-key' );
$csv_result  = $enqueue->execute( $csv_command, $export_context );
g5_report_export_same( 'report_csv', $csv_result->artifact_kind(), 'C46 returned the wrong artifact kind.' );
g5_report_export_same( 1, count( $memory_repository->records ), 'C46 did not enqueue exactly one job.' );
g5_report_export_same( 1, $memory_queue->calls, 'C46 did not wake the private export queue once.' );
$csv_record   = $memory_repository->records[0];
$csv_snapshot = Report_Export_Request_Snapshot::restore( $csv_record['snapshot_json'], $csv_record['snapshot_hash'], $export_canonical );
g5_report_export_same( Report_Export_Request_Snapshot::CSV_PROFILE, $csv_snapshot->profile(), 'C46 request profile drifted.' );
g5_report_export_same( $export_fixture['projection'], $csv_snapshot->projection(), 'C46 changed the exact report projection.' );
g5_report_export_same( 'class-g5-snapshot-r2', $csv_snapshot->context()['revision'], 'C46 lost current Context authorization evidence.' );
g5_report_export_same( 'class-g5-snapshot-r1', $csv_snapshot->projection()['context']['revision'], 'C46 changed the immutable source Context revision.' );

$authorized_before_replay = count( $export_objects->calls );
$csv_replay               = $enqueue->execute( $csv_command, $export_context );
g5_report_export_assert( $csv_replay->replayed(), 'C46 durable retry was not marked as replayed.' );
g5_report_export_same( $csv_result->job_id(), $csv_replay->job_id(), 'C46 replay changed the job ID.' );
g5_report_export_same( $authorized_before_replay, count( $export_objects->calls ), 'C46 exact replay re-read mutable report sources.' );
g5_report_export_same( 1, $memory_queue->calls, 'C46 exact replay woke the queue again.' );

$pdf_command = g5_report_export_command( 'report_pdf', $export_fixture, $export_canonical, 'command-g5-report-pdf', 'pdf-key' );
$pdf_result  = $enqueue->execute( $pdf_command, $export_context );
g5_report_export_same( 'report_pdf', $pdf_result->artifact_kind(), 'C47 returned the wrong artifact kind.' );
g5_report_export_same( Report_Export_Request_Snapshot::PDF_PROFILE, $memory_repository->records[1]['profile_version'], 'C47 request profile drifted.' );
g5_report_export_same( 2, $memory_queue->calls, 'C47 did not wake the queue.' );

$stale_hash = hash( 'sha256', 'stale-report-snapshot' );
g5_report_export_failure(
	static fn () => $enqueue->execute( g5_report_export_command( 'report_csv', $export_fixture, $export_canonical, 'command-g5-report-stale', 'stale-key', $stale_hash ), $export_context ),
	Report_Export_Failure::SOURCE_STALE,
	'C46 accepted an expected snapshot hash that no longer matched Q22.'
);
$export_objects->deny_id = $export_fixture['worksheet_ids'][0];
g5_report_export_failure(
	static fn () => $enqueue->execute( g5_report_export_command( 'report_pdf', $export_fixture, $export_canonical, 'command-g5-report-revoked', 'revoked-key' ), $export_context ),
	Report_Export_Failure::SOURCE_ACCESS_DENIED,
	'C47 exported after one source worksheet permission was revoked.'
);
$export_objects->deny_id = null;

$conflict_first = g5_report_export_command( 'report_csv', $export_fixture, $export_canonical, 'command-g5-conflict-a', 'same-conflict-key' );
$enqueue->execute( $conflict_first, $export_context );
g5_report_export_failure(
	static fn () => $enqueue->execute( g5_report_export_command( 'report_csv', $export_fixture, $export_canonical, 'command-g5-conflict-b', 'same-conflict-key' ), $export_context ),
	Report_Export_Failure::IDEMPOTENCY_CONFLICT,
	'C46 accepted a reused Idempotency-Key with a different command digest.'
);

$controller_reflection = new ReflectionClass( Report_Export_Rest_Controller::class );
$controller            = $controller_reflection->newInstanceWithoutConstructor();
$input                 = $controller_reflection->getMethod( 'input' );
$request               = new class( $export_fixture ) {
	/**
	 * Initialize report-export-enqueue-g5-imp-03-s01 dependencies and state.
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
			'command_id' => 'command-g5-rest-c46',
			'expected'   => array(
				'expected_snapshot_id'    => $this->fixture['record']['public_id'],
				'expected_snapshot_hash'  => $this->fixture['snapshot_hash'],
				'expected_snapshot_state' => 'ready',
			),
			'payload'    => array(
				'locale'  => 'ko-KR',
				'profile' => Report_Export_Request_Snapshot::CSV_PROFILE,
			),
		);
	}
};
$parsed                = $input->invoke( $controller, $request, 'report_csv' );
g5_report_export_same( $export_fixture['snapshot_hash'], $parsed['expected_snapshot_hash'], 'C46 REST parser lost the exact snapshot hash.' );
try {
	$input->invoke( $controller, $request, 'report_pdf' );
	throw new RuntimeException( 'C47 accepted the CSV profile.' ); } catch ( ReflectionException | InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
	}
	$registered = $controller_reflection->getProperty( 'registered' );
	$registered->setValue( $controller, false );
	$GLOBALS['g5_snapshot_q22_routes'] = array();
	$controller->register_routes();
	g5_report_export_same( 3, count( $GLOBALS['g5_snapshot_q22_routes'] ), 'C46/C47 additive route set drifted.' );
	g5_report_export_same( Report_Export_Rest_Controller::ROUTE_CSV, $GLOBALS['g5_snapshot_q22_routes'][0][1], 'C46 route drifted.' );
	g5_report_export_same( Report_Export_Rest_Controller::ROUTE_PDF, $GLOBALS['g5_snapshot_q22_routes'][1][1], 'C47 route drifted.' );
	g5_report_export_same( Report_Export_Rest_Controller::ROUTE_JSON_PACKAGE, $GLOBALS['g5_snapshot_q22_routes'][2][1], 'C69 additive route drifted.' );

	/**
	 * G5-IMP-03-S01 C46/C47 exact report export enqueue contract.
	 */
	final class G5_Report_Export_Database {
		/**
		 * Last error.
		 *
		 * @var string
		 */
		public string $last_error = '';
		/**
		 * Fail insert table.
		 *
		 * @var string|null
		 */
		public ?string $fail_insert_table = null;
		/**
		 * Tables.
		 *
		 * @var array<string,array<int,array<string,mixed>>>
		 */
		public array $tables;
		/**
		 * Next ids.
		 *
		 * @var array<string,int>
		 */
		private array $next_ids = array(
			'oj_ai_export_jobs'   => 1,
			'oj_ai_audit_events'  => 1,
			'oj_ai_outbox_events' => 1,
		);
		/**
		 * Transaction.
		 *
		 * @var array|null
		 */
		private ?array $transaction = null;
		/**
		 * Initialize g5 report export database dependencies and state.
		 *
		 * @param string $snapshot_id Snapshot id.
		 */
		public function __construct( string $snapshot_id ) {
			$this->tables = array(
				'oj_ai_report_snapshots' => array(
					array(
						'id'        => 7,
						'public_id' => $snapshot_id,
						'state'     => 'ready',
					),
				),
				'oj_ai_export_jobs'      => array(),
				'oj_ai_audit_events'     => array(),
				'oj_ai_outbox_events'    => array(),
			);
		}
		/**
		 * Prepare.
		 *
		 * @param string $sql Sql.
		 * @param mixed  ...$arguments Arguments.
		 * @return string
		 */
		public function prepare( string $sql, mixed ...$arguments ): string {
			return 'G5RE:' . base64_encode( serialize( array( $sql, $arguments ) ) );
		}
		/**
		 * Get row.
		 *
		 * @param string $prepared Prepared.
		 * @param mixed  $output Output.
		 * @return array|null
		 */
		public function get_row( string $prepared, mixed $output = null ): ?array {
			unset( $output );
			$this->last_error    = '';
			[ $sql, $arguments ] = $this->statement( $prepared );
			if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
				foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
					if ( (string) $row['idempotency_key'] === (string) $arguments[0] ) {
						return $row;
					}
				} return null;
			}
			if ( str_contains( $sql, 'FROM oj_ai_report_snapshots' ) ) {
				foreach ( $this->tables['oj_ai_report_snapshots'] as $row ) {
					if ( $row['public_id'] === $arguments[0] && $row['state'] === $arguments[1] ) {
						return array( 'id' => $row['id'] );
					}
				} return null;
			}
			if ( str_contains( $sql, 'FROM oj_ai_export_jobs' ) ) {
				foreach ( $this->tables['oj_ai_export_jobs'] as $row ) {
					if ( $row['active_identity_key'] === $arguments[0] && $row['artifact_kind'] === $arguments[1] && in_array( $row['state'], array( $arguments[2], $arguments[3] ), true ) ) {
						return $row;
					}
				} return null;
			}
			$this->last_error = 'unexpected select';
			return null;
		}
		/**
		 * Get var.
		 *
		 * @param string $sql Sql.
		 * @return mixed
		 */
		public function get_var( string $sql ): mixed {
			$this->last_error = '';
			if ( ! str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
				$this->last_error = 'unexpected scalar';
				return null;
			} $rows = $this->tables['oj_ai_audit_events'];
			return array() === $rows ? null : $rows[ array_key_last( $rows ) ]['event_hash'];
		}
		/**
		 * Insert.
		 *
		 * @param string $table Table.
		 * @param array  $data Data.
		 * @return int|false
		 */
		public function insert( string $table, array $data ): int|false {
			$this->last_error = '';
			if ( ! isset( $this->tables[ $table ] ) || $this->fail_insert_table === $table ) {
				$this->last_error = 'forced insert failure';
				return false;
			}
			foreach ( $this->tables[ $table ] as $row ) {
				if ( isset( $data['public_id'] ) && ( $row['public_id'] ?? null ) === $data['public_id'] ) {
					$this->last_error = 'duplicate public id';
					return false;
				}
				if ( 'oj_ai_outbox_events' === $table && ( $row['idempotency_key'] ?? null ) === ( $data['idempotency_key'] ?? null ) ) {
					$this->last_error = 'duplicate command key';
					return false;
				}
			}
			$data['id']               = $this->next_ids[ $table ]++;
			$this->tables[ $table ][] = $data;
			return 1;
		}
		/**
		 * Query.
		 *
		 * @param string $prepared Prepared.
		 * @return int|false
		 */
		public function query( string $prepared ): int|false {
			$this->last_error = '';
			if ( 'START TRANSACTION' === $prepared ) {
				$this->transaction = unserialize( serialize( array( $this->tables, $this->next_ids ) ) );
				return 1;
			}
			if ( 'COMMIT' === $prepared ) {
				$this->transaction = null;
				return 1;
			}
			if ( 'ROLLBACK' === $prepared ) {
				if ( null !== $this->transaction ) {
						[ $this->tables, $this->next_ids ] = $this->transaction;
				} $this->transaction = null;
				return 1;
			}
			$this->last_error = 'unexpected query';
			return false;
		}
		/**
		 * Statement.
		 *
		 * @param string $prepared Prepared.
		 * @return array
		 */
		private function statement( string $prepared ): array {
			if ( ! str_starts_with( $prepared, 'G5RE:' ) ) {
				return array( $prepared, array() );
			} $value = unserialize( base64_decode( substr( $prepared, 5 ), true ), array( 'allowed_classes' => false ) );
			return array( $value[0], array_values( $value[1] ) );
		}
	}

	$database       = new G5_Report_Export_Database( $export_fixture['record']['public_id'] );
	$storage        = new WordPress_Report_Export_Repository( $export_canonical, $database );
	$storage_result = $storage->enqueue( $csv_record );
	g5_report_export_same( 1, count( $database->tables['oj_ai_export_jobs'] ), 'C46 storage did not create one export job.' );
	g5_report_export_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'C46 storage did not create one audit event.' );
	g5_report_export_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'C46 storage did not create one durable receipt.' );
	g5_report_export_same( 7, $database->tables['oj_ai_export_jobs'][0]['report_snapshot_id'], 'C46 did not bind the report snapshot foreign key.' );
	g5_report_export_same( 'report_snapshot', $database->tables['oj_ai_export_jobs'][0]['source_type'], 'C46 source owner drifted.' );
	g5_report_export_same( $csv_record['snapshot_json'], $database->tables['oj_ai_export_jobs'][0]['request_snapshot_json'], 'C46 storage changed immutable request bytes.' );
	$durable = $storage->find_command_receipt( $csv_record['command_storage_key'], $csv_record['request_digest'] );
	g5_report_export_assert( null !== $durable && $durable->replayed(), 'C46 durable receipt was not restored.' );
	g5_report_export_same( $storage_result->job_id(), $durable->job_id(), 'C46 durable replay changed the job ID.' );
	g5_report_export_failure( static fn () => $storage->find_command_receipt( $csv_record['command_storage_key'], hash( 'sha256', 'different-c46-digest' ) ), Report_Export_Failure::IDEMPOTENCY_CONFLICT, 'C46 storage accepted a reused command key with a different digest.' );

	$rollback_database                    = new G5_Report_Export_Database( $export_fixture['record']['public_id'] );
	$rollback_database->fail_insert_table = 'oj_ai_audit_events';
	$rollback_storage                     = new WordPress_Report_Export_Repository( $export_canonical, $rollback_database );
	g5_report_export_failure( static fn () => $rollback_storage->enqueue( $csv_record ), Report_Export_Failure::STORAGE_UNAVAILABLE, 'C46 did not surface a transactional audit failure.' );
	g5_report_export_same( 0, count( $rollback_database->tables['oj_ai_export_jobs'] ), 'C46 rollback left a partial export job.' );
	g5_report_export_same( 0, count( $rollback_database->tables['oj_ai_outbox_events'] ), 'C46 rollback left a partial outbox receipt.' );

	$runtime_files  = array(
		dirname( __DIR__, 2 ) . '/src/Reporting/ReportExport/Application/Enqueue_Report_Export.php',
		dirname( __DIR__, 2 ) . '/src/Reporting/ReportExport/Domain/Report_Export_Request_Snapshot.php',
		dirname( __DIR__, 2 ) . '/src/Reporting/ReportExport/Infrastructure/WordPress_Report_Export_Repository.php',
		dirname( __DIR__, 2 ) . '/src/Reporting/ReportExport/Presentation/Report_Export_Rest_Controller.php',
		dirname( __DIR__, 2 ) . '/src/Core/Composition_Root.php',
	);
	$runtime_source = '';
	foreach ( $runtime_files as $runtime_file ) {
		$runtime_source .= (string) file_get_contents( $runtime_file );
	}
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_report_export_assert( ! str_contains( $runtime_source, $forbidden ), 'C46/C47 runtime contains a forbidden boundary marker: ' . $forbidden );
	}
	foreach ( array( "'oj_ai_export_jobs'", "'oj_ai_report_snapshots'", "'oj_ai_audit_events'", "'oj_ai_outbox_events'", "'report_csv'", "'report_pdf'", "'report_json_package'", "'report_snapshot'", 'START TRANSACTION', 'FOR UPDATE', 'active_identity_key' ) as $marker ) {
		g5_report_export_assert( str_contains( $runtime_source, $marker ), 'C46/C47 persistence marker is missing: ' . $marker );
	}
	g5_report_export_assert( str_contains( $runtime_source, 'new Report_Export_Rest_Module' ), 'C46/C47 Composition Root wiring is missing.' );

	fwrite( STDOUT, "G5-IMP-03-S01 passed: strict C46/C47, exact immutable report projection, current authorization, durable idempotency, report FK, and atomic enqueue are frozen.\n" );
