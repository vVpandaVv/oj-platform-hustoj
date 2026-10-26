<?php
/**
 * Atomic C53 cancel and C54 retry repository for every supported export job.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Export\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Reporting\Export\Application\Export_Control_Result;
use OJAI\Dashboard\Reporting\Export\Application\Export_Failure;
use OJAI\Dashboard\Reporting\Export\Port\Export_Control_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;
use OJAI\Dashboard\Reporting\ResultPdf\Domain\Result_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\WorksheetPackage\Domain\Worksheet_Package_Request_Snapshot;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Request_Snapshot;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic C53 cancel and C54 retry repository for every supported export job.
 */
final class WordPress_Export_Control_Repository implements Export_Control_Repository_Port_Interface {
	private const TABLE_JOBS   = 'oj_ai_export_jobs';
	private const TABLE_AUDIT  = 'oj_ai_audit_events';
	private const TABLE_OUTBOX = 'oj_ai_outbox_events';

	private const EVENT_CANCEL       = 'export_job.cancelled';
	private const EVENT_RETRY        = 'export_job.retry_queued';
	private const EVENT_RETRY_REUSED = 'export_job.retry_reused';
	private const OUTBOX_SCHEMA      = 'oj_ai.export_control_event';
	private const AUDIT_SCHEMA       = 'oj_ai.export_control_audit';
	private const PROFILE_VERSION    = 1;

	private const RETRYABLE_ERRORS = array(
		'worksheet_pdf'       => array(
			'worksheet_pdf_render_failed',
			'worksheet_pdf_storage_unavailable',
		),
		'worksheet_package'   => array(
			'worksheet_package_environment_unsupported',
			'worksheet_package_archive_failed',
			'worksheet_package_storage_unavailable',
		),
		'submission_pdf'      => array(
			'submission_pdf_render_failed',
			'submission_pdf_storage_unavailable',
		),
		'result_pdf'          => array(
			'result_pdf_render_failed',
			'result_pdf_storage_unavailable',
		),
		'report_csv'          => array(
			'report_export_render_failed',
			'report_export_archive_failed',
			'report_export_zip_unavailable',
			'report_export_storage_unavailable',
		),
		'report_pdf'          => array(
			'report_export_render_failed',
			'report_export_archive_failed',
			'report_export_zip_unavailable',
			'report_export_storage_unavailable',
		),
		'report_json_package' => array(
			'report_export_render_failed',
			'report_export_archive_failed',
			'report_export_zip_unavailable',
			'report_export_storage_unavailable',
		),
		'submission_bundle'   => array(
			'submission_bundle_render_failed',
			'submission_bundle_archive_failed',
			'submission_bundle_zip_unavailable',
			'submission_bundle_storage_unavailable',
		),
	);

	/**
	 * Initialize WordPress export control repository dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null    $database Database.
	 */
	public function __construct( private Canonical_Json $canonical, private ?object $database = null ) {
	}

	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @param string $operation Operation.
	 * @return Export_Control_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest, string $operation ): ?Export_Control_Result {
		$this->storage_key( $command_storage_key, 'command:' );
		$this->hash( $request_digest );
		$this->operation( $operation );
		try {
			return $this->command_receipt( $command_storage_key, $request_digest, $operation, false );
		} catch ( Export_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Cancel.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Export_Control_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function cancel( array $record ): Export_Control_Result {
		$this->assert_cancel_record( $record );
		$receipt = $this->command_receipt(
			(string) $record['command_storage_key'],
			(string) $record['request_digest'],
			Export_Control_Result::OPERATION_CANCEL,
			false
		);
		if ( $receipt instanceof Export_Control_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt(
				(string) $record['command_storage_key'],
				(string) $record['request_digest'],
				Export_Control_Result::OPERATION_CANCEL,
				true
			);
			if ( $receipt instanceof Export_Control_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$job = $this->job( (int) $record['actor_user_id'], (string) $record['job_public_id'], true );
			if ( null === $job ) {
				throw Export_Failure::because( Export_Failure::NOT_FOUND );
			}
			$active_identity = $this->validate_snapshot( $job, (int) $record['actor_user_id'] );
			$this->assert_cancel_transition( $job, $record, $active_identity );
			$changed = $this->query(
				$this->prepare(
					'UPDATE ' . self::TABLE_JOBS . ' SET state = %s, finished_at_utc = %s, active_identity_key = NULL, '
					. 'lease_token = NULL, lease_expires_at_utc = NULL, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND lock_version = %d '
					. 'AND state = %s AND source_hash = %s AND result_file_public_id IS NULL',
					array(
						'cancelled',
						$record['occurred_at_utc'],
						$record['actor_user_id'],
						$record['occurred_at_utc'],
						$job['id'],
						$job['lock_version'],
						'queued',
						$record['expected_source_hash'],
					)
				)
			);
			if ( 1 !== $changed ) {
				throw Export_Failure::because( Export_Failure::STATE_CONFLICT, $this->safe_state( $job ) );
			}
			$result = $this->result( Export_Control_Result::OPERATION_CANCEL, $job, (string) $job['public_id'], 'cancelled', (string) $record['occurred_at_utc'], false );
			$this->insert_audit( $job, $record, $result, self::EVENT_CANCEL );
			$this->insert_outbox( $record, $result, self::EVENT_CANCEL, false );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Export_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( InvalidArgumentException ) {
			if ( $transaction ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$receipt = $this->recover_receipt( $record, Export_Control_Result::OPERATION_CANCEL );
			if ( $receipt instanceof Export_Control_Result ) {
				return $receipt;
			}
			throw $this->storage_failure();
		}
	}

	/**
	 * Retry.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Export_Control_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function retry( array $record ): Export_Control_Result {
		$this->assert_retry_record( $record );
		$receipt = $this->command_receipt(
			(string) $record['command_storage_key'],
			(string) $record['request_digest'],
			Export_Control_Result::OPERATION_RETRY,
			false
		);
		if ( $receipt instanceof Export_Control_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt(
				(string) $record['command_storage_key'],
				(string) $record['request_digest'],
				Export_Control_Result::OPERATION_RETRY,
				true
			);
			if ( $receipt instanceof Export_Control_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$job = $this->job( (int) $record['actor_user_id'], (string) $record['job_public_id'], true );
			if ( null === $job ) {
				throw Export_Failure::because( Export_Failure::NOT_FOUND );
			}
			$active_identity = $this->validate_snapshot( $job, (int) $record['actor_user_id'] );
			$this->assert_retry_transition( $job, $record );

			$active = $this->active_job( (int) $record['actor_user_id'], $active_identity, true );
			if ( null !== $active ) {
				$active_evidence = $this->validate_snapshot( $active, (int) $record['actor_user_id'] );
				if ( ! hash_equals( $active_identity, $active_evidence ) || ! in_array( $active['state'], array( 'queued', 'running' ), true ) ) {
					throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
				}
				$result = $this->result( Export_Control_Result::OPERATION_RETRY, $job, (string) $active['public_id'], (string) $active['state'], (string) $record['occurred_at_utc'], true, $active );
				$this->insert_audit( $job, $record, $result, self::EVENT_RETRY_REUSED );
				$this->insert_outbox( $record, $result, self::EVENT_RETRY_REUSED, true );
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $result;
			}

			$this->insert_retry_job( $job, $record, $active_identity );
			$result = $this->result( Export_Control_Result::OPERATION_RETRY, $job, (string) $record['new_job_public_id'], 'queued', (string) $record['occurred_at_utc'], false );
			$this->insert_audit( $job, $record, $result, self::EVENT_RETRY );
			$this->insert_outbox( $record, $result, self::EVENT_RETRY, false );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Export_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( InvalidArgumentException ) {
			if ( $transaction ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$receipt = $this->recover_receipt( $record, Export_Control_Result::OPERATION_RETRY );
			if ( $receipt instanceof Export_Control_Result ) {
				return $receipt;
			}
			throw $this->storage_failure();
		}
	}

	/**
	 * Job.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $job_id Job id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function job( int $actor_user_id, string $job_id, bool $for_update ): ?array {
		$sql = $this->job_select() . ' WHERE public_id = %s AND requested_by_user_id = %d LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->row( $this->prepare( $sql, array( $job_id, $actor_user_id ) ) );
	}

	/**
	 * Active job.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $active_identity Active identity.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function active_job( int $actor_user_id, string $active_identity, bool $for_update ): ?array {
		$sql = $this->job_select() . ' WHERE active_identity_key = %s AND requested_by_user_id = %d AND state IN (%s,%s) LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->row( $this->prepare( $sql, array( $active_identity, $actor_user_id, 'queued', 'running' ) ) );
	}

	/**
	 * Job select.
	 *
	 * @return string
	 */
	private function job_select(): string {
		return 'SELECT id, public_id, lock_version, created_by_user_id, created_at_utc, updated_by_user_id, updated_at_utc, context_type, context_id, context_revision, report_snapshot_id, artifact_kind, source_type, source_public_id, source_hash, request_contract_version, request_snapshot_schema, request_snapshot_version, request_snapshot_json, request_snapshot_hash, request_hash, state, requested_by_user_id, idempotency_key, active_identity_key, print_profile_version, available_at_utc, queued_at_utc, lease_token, lease_expires_at_utc, started_at_utc, finished_at_utc, result_file_public_id, error_code FROM '
			. self::TABLE_JOBS;
	}

	/**
	 * Validate snapshot.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function validate_snapshot( array $job, int $actor_user_id ): string {
		$this->assert_job_core( $job, $actor_user_id );
		$json = (string) $job['request_snapshot_json'];
		$hash = (string) $job['request_snapshot_hash'];
		if ( ! hash_equals( $hash, (string) $job['request_hash'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		$kind = (string) $job['artifact_kind'];
		$type = (string) $job['source_type'];

		if ( 'worksheet_pdf' === $kind && in_array( $type, array( 'worksheet_revision', 'worksheet_publication' ), true ) ) {
			$snapshot = Worksheet_Pdf_Request_Snapshot::restore( $json, $hash, $this->canonical );
			$this->same_snapshot( $job, $snapshot->source_id(), $snapshot->source_hash(), $snapshot->profile_version(), Worksheet_Pdf_Request_Snapshot::SCHEMA, Worksheet_Pdf_Request_Snapshot::DATABASE_VERSION, $snapshot->version() );
			if ( ! hash_equals( $type, $snapshot->source_type() ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			if ( 'worksheet_revision' === $type ) {
				$this->no_context( $job );
				$semantic = array(
					'actor_user_id' => $actor_user_id,
					'request_hash'  => $hash,
					'source_hash'   => $snapshot->source_hash(),
					'source_id'     => $snapshot->source_id(),
				);
			} else {
				$context    = $snapshot->context();
				$assignment = $snapshot->assignment();
				if ( ! is_array( $context ) || ! is_array( $assignment ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
				}
				$this->same_context( $job, $context );
				$semantic = array(
					'actor_user_id'    => $actor_user_id,
					'assignment_id'    => (string) $assignment['id'],
					'context'          => $context,
					'publication_hash' => (string) $assignment['publication_hash'],
					'publication_id'   => (string) $assignment['publication_id'],
					'request_hash'     => $hash,
				);
			}
			return 'pdfactive:' . hash( 'sha256', $this->canonical->encode( $semantic ) );
		}

		if ( 'worksheet_package' === $kind && 'worksheet_revision' === $type ) {
			$snapshot = Worksheet_Package_Request_Snapshot::restore( $json, $hash, $this->canonical );
			$this->same_snapshot( $job, $snapshot->source_id(), $snapshot->source_hash(), Worksheet_Package_Request_Snapshot::PROFILE_VERSION, Worksheet_Package_Request_Snapshot::SCHEMA, Worksheet_Package_Request_Snapshot::DATABASE_VERSION, Worksheet_Package_Request_Snapshot::VERSION );
			$this->no_context( $job );
			return 'pkgactive:' . hash(
				'sha256',
				$this->canonical->encode(
					array(
						'actor_user_id' => $actor_user_id,
						'request_hash'  => $hash,
						'source_hash'   => $snapshot->source_hash(),
						'source_id'     => $snapshot->source_id(),
					)
				)
			);
		}

		if ( 'submission_pdf' === $kind && 'submission_revision' === $type ) {
			$snapshot = Submission_Pdf_Request_Snapshot::restore( $json, $hash, $this->canonical );
			$this->same_snapshot( $job, $snapshot->source_id(), $snapshot->source_hash(), $snapshot->profile_version(), Submission_Pdf_Request_Snapshot::SCHEMA, Submission_Pdf_Request_Snapshot::DATABASE_VERSION, $snapshot->version() );
			$context     = $snapshot->context();
			$submission  = $snapshot->submission();
			$publication = $snapshot->publication();
			$this->same_context( $job, $context );
			$semantic = array(
				'actor_user_id'    => $actor_user_id,
				'artifact_kind'    => 'submission_pdf',
				'context'          => $context,
				'publication_hash' => (string) $publication['hash'],
				'request_hash'     => $hash,
				'source_hash'      => $snapshot->source_hash(),
				'source_id'        => $snapshot->source_id(),
				'submission'       => $submission,
			);
			if ( $snapshot->is_own() ) {
				$ownership = $snapshot->ownership();
				if ( ! is_array( $ownership ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
				}
				$semantic['assignment_id'] = (string) $ownership['assignment_id'];
			}
			return 'submission-pdf-active:' . hash( 'sha256', $this->canonical->encode( $semantic ) );
		}

		if ( 'result_pdf' === $kind && 'submission_release' === $type ) {
			$snapshot = Result_Pdf_Request_Snapshot::restore( $json, $hash, $this->canonical );
			$this->same_snapshot( $job, $snapshot->source_id(), $snapshot->source_hash(), $snapshot->profile_version(), Result_Pdf_Request_Snapshot::SCHEMA, Result_Pdf_Request_Snapshot::DATABASE_VERSION, Result_Pdf_Request_Snapshot::VERSION );
			$context = $snapshot->context();
			$this->same_context( $job, $context );
			return 'result-pdf-active:' . hash(
				'sha256',
				$this->canonical->encode(
					array(
						'actor_user_id' => $actor_user_id,
						'artifact_kind' => 'result_pdf',
						'context'       => $context,
						'release_state' => $snapshot->source_state(),
						'request_hash'  => $hash,
						'source_hash'   => $snapshot->source_hash(),
						'source_id'     => $snapshot->source_id(),
					)
				)
			);
		}

		if ( in_array( $kind, array( 'report_csv', 'report_pdf', 'report_json_package' ), true ) && 'report_snapshot' === $type ) {
			$snapshot = Report_Export_Request_Snapshot::restore( $json, $hash, $this->canonical );
			$this->same_snapshot( $job, $snapshot->source_id(), $snapshot->source_hash(), $snapshot->profile(), Report_Export_Request_Snapshot::SCHEMA, Report_Export_Request_Snapshot::DATABASE_VERSION, Report_Export_Request_Snapshot::VERSION );
			if ( ! hash_equals( $kind, $snapshot->artifact_kind() ) || null === $job['report_snapshot_id'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			$this->positive_int( $job['report_snapshot_id'] );
			$context = $snapshot->context();
			$this->same_context( $job, $context );
			return 'report-export-active:' . hash(
				'sha256',
				$this->canonical->encode(
					array(
						'actor_user_id' => $actor_user_id,
						'artifact_kind' => $kind,
						'context'       => $context,
						'profile'       => $snapshot->profile(),
						'request_hash'  => $hash,
						'source_hash'   => $snapshot->source_hash(),
						'source_id'     => $snapshot->source_id(),
					)
				)
			);
		}

		if ( 'submission_bundle' === $kind && 'assignment' === $type ) {
			$snapshot = Submission_Bundle_Request_Snapshot::restore( $json, $hash, $this->canonical );
			$this->same_snapshot( $job, $snapshot->source_id(), $snapshot->source_hash(), $snapshot->profile(), Submission_Bundle_Request_Snapshot::SCHEMA, Submission_Bundle_Request_Snapshot::DATABASE_VERSION, Submission_Bundle_Request_Snapshot::VERSION );
			$context = $snapshot->context();
			$this->same_context( $job, $context );
			return 'submission-bundle-active:' . hash(
				'sha256',
				$this->canonical->encode(
					array(
						'actor_user_id' => $actor_user_id,
						'artifact_kind' => 'submission_bundle',
						'context'       => $context,
						'profile'       => $snapshot->profile(),
						'request_hash'  => $hash,
						'source_hash'   => $snapshot->source_hash(),
						'source_id'     => $snapshot->source_id(),
					)
				)
			);
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
	}

	/**
	 * Reject invalid job core.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_job_core( array $job, int $actor_user_id ): void {
		$this->positive_int( $job['id'] ?? null );
		$this->non_negative_int( $job['lock_version'] ?? null );
		new Public_Id( is_string( $job['public_id'] ?? null ) ? $job['public_id'] : '' );
		new Public_Id( is_string( $job['source_public_id'] ?? null ) ? $job['source_public_id'] : '' );
		$this->hash( is_string( $job['source_hash'] ?? null ) ? $job['source_hash'] : '' );
		$this->hash( is_string( $job['request_snapshot_hash'] ?? null ) ? $job['request_snapshot_hash'] : '' );
		$this->hash( is_string( $job['request_hash'] ?? null ) ? $job['request_hash'] : '' );
		if ( $actor_user_id !== $this->positive_int( $job['requested_by_user_id'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		if ( ! is_string( $job['request_snapshot_json'] ?? null ) || '' === $job['request_snapshot_json'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		if ( ! is_string( $job['state'] ?? null ) || ! in_array( $job['state'], array( 'queued', 'running', 'ready', 'failed', 'cancelled', 'expired' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		$this->utc( is_string( $job['queued_at_utc'] ?? null ) ? $job['queued_at_utc'] : '' );
		foreach ( array( 'started_at_utc', 'finished_at_utc', 'lease_expires_at_utc' ) as $key ) {
			if ( null !== ( $job[ $key ] ?? null ) ) {
				$this->utc( is_string( $job[ $key ] ) ? $job[ $key ] : '' );
			}
		}
		if ( null !== ( $job['result_file_public_id'] ?? null ) ) {
			new Public_Id( is_string( $job['result_file_public_id'] ) ? $job['result_file_public_id'] : '' );
		}
		if ( null !== ( $job['error_code'] ?? null ) ) {
			$this->stable_code( is_string( $job['error_code'] ) ? $job['error_code'] : '' );
		}
		if ( ! is_string( $job['print_profile_version'] ?? null ) || '' === $job['print_profile_version'] || strlen( $job['print_profile_version'] ) > 32 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		if ( null !== ( $job['report_snapshot_id'] ?? null ) && 'report_snapshot' !== ( $job['source_type'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Same snapshot.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param string              $source_id Source id.
	 * @param string              $source_hash Source hash.
	 * @param string              $profile Profile.
	 * @param string              $schema Schema.
	 * @param int                 $version Version.
	 * @param string              $contract Contract.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function same_snapshot( array $job, string $source_id, string $source_hash, string $profile, string $schema, int $version, string $contract ): void {
		if (
			! hash_equals( (string) $job['source_public_id'], $source_id ) || ! hash_equals( (string) $job['source_hash'], $source_hash )
			|| ! hash_equals( (string) $job['print_profile_version'], $profile ) || ! hash_equals( (string) $job['request_snapshot_schema'], $schema )
			|| $version !== $this->positive_int( $job['request_snapshot_version'] ) || ! hash_equals( (string) $job['request_contract_version'], $contract )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Same context.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param array<string,mixed> $context Access or execution context for this operation.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function same_context( array $job, array $context ): void {
		if (
			! in_array( $context['type'] ?? null, array( 'class', 'group' ), true )
			|| ! is_string( $context['id'] ?? null ) || ! is_string( $context['revision'] ?? null )
			|| ! hash_equals( (string) $job['context_type'], (string) $context['type'] )
			|| ! hash_equals( (string) $job['context_id'], (string) $context['id'] )
			|| ! hash_equals( (string) $job['context_revision'], (string) $context['revision'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * No context.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function no_context( array $job ): void {
		if ( null !== $job['context_type'] || null !== $job['context_id'] || null !== $job['context_revision'] || null !== $job['report_snapshot_id'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Reject invalid cancel transition.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param array<string,mixed> $record Record.
	 * @param string              $active_identity Active identity.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_cancel_transition( array $job, array $record, string $active_identity ): void {
		if (
			'queued' !== $job['state'] || ! hash_equals( (string) $record['expected_state'], (string) $job['state'] )
			|| ! hash_equals( (string) $record['expected_source_hash'], (string) $job['source_hash'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STATE_CONFLICT, $this->safe_state( $job ) );
		}
		if (
			null !== $job['result_file_public_id'] || null !== $job['error_code'] || null !== $job['started_at_utc']
			|| null !== $job['finished_at_utc'] || null !== $job['lease_token'] || null !== $job['lease_expires_at_utc']
			|| ! is_string( $job['active_identity_key'] ) || ! hash_equals( $active_identity, $job['active_identity_key'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Reject invalid retry transition.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_retry_transition( array $job, array $record ): void {
		if (
			'failed' !== $job['state'] || ! hash_equals( (string) $record['expected_state'], (string) $job['state'] )
			|| ! hash_equals( (string) $record['expected_source_hash'], (string) $job['source_hash'] )
			|| ! hash_equals( (string) $record['expected_error_code'], (string) $job['error_code'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STATE_CONFLICT, $this->safe_state( $job ) );
		}
		if ( null !== $job['result_file_public_id'] || null !== $job['active_identity_key'] || null !== $job['lease_token'] || null !== $job['lease_expires_at_utc'] || null === $job['finished_at_utc'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		$allowed = self::RETRYABLE_ERRORS[ (string) $job['artifact_kind'] ] ?? array();
		if ( ! in_array( $job['error_code'], $allowed, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::NOT_RETRYABLE, $this->safe_state( $job ) );
		}
	}

	/**
	 * Insert retry job.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param array<string,mixed> $record Record.
	 * @param string              $active_identity Active identity.
	 * @return void
	 */
	private function insert_retry_job( array $job, array $record, string $active_identity ): void {
		$this->insert_or_fail(
			self::TABLE_JOBS,
			array(
				'public_id'                => $record['new_job_public_id'],
				'lock_version'             => 0,
				'created_by_user_id'       => $record['actor_user_id'],
				'created_at_utc'           => $record['occurred_at_utc'],
				'updated_by_user_id'       => $record['actor_user_id'],
				'updated_at_utc'           => $record['occurred_at_utc'],
				'context_type'             => $job['context_type'],
				'context_id'               => $job['context_id'],
				'context_revision'         => $job['context_revision'],
				'report_snapshot_id'       => null === $job['report_snapshot_id'] ? null : $this->positive_int( $job['report_snapshot_id'] ),
				'artifact_kind'            => $job['artifact_kind'],
				'source_type'              => $job['source_type'],
				'source_public_id'         => $job['source_public_id'],
				'source_hash'              => $job['source_hash'],
				'request_contract_version' => $job['request_contract_version'],
				'request_snapshot_schema'  => $job['request_snapshot_schema'],
				'request_snapshot_version' => $this->positive_int( $job['request_snapshot_version'] ),
				'request_snapshot_json'    => $job['request_snapshot_json'],
				'request_snapshot_hash'    => $job['request_snapshot_hash'],
				'request_hash'             => $job['request_hash'],
				'state'                    => 'queued',
				'requested_by_user_id'     => $record['actor_user_id'],
				'idempotency_key'          => $record['job_idempotency_key'],
				'active_identity_key'      => $active_identity,
				'print_profile_version'    => $job['print_profile_version'],
				'available_at_utc'         => $record['occurred_at_utc'],
				'queued_at_utc'            => $record['occurred_at_utc'],
				'lease_token'              => null,
				'lease_expires_at_utc'     => null,
				'started_at_utc'           => null,
				'finished_at_utc'          => null,
				'result_file_public_id'    => null,
				'error_code'               => null,
			)
		);
	}

	/**
	 * Command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param string $operation Operation.
	 * @param bool   $for_update For update.
	 * @return Export_Control_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $key, string $digest, string $operation, bool $for_update ): ?Export_Control_Result {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM '
			. self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $key ) ) );
		if ( null === $row ) {
			return null;
		}
		$events = Export_Control_Result::OPERATION_CANCEL === $operation
			? array( self::EVENT_CANCEL )
			: array( self::EVENT_RETRY, self::EVENT_RETRY_REUSED );
		if (
			'export_job' !== ( $row['aggregate_type'] ?? null ) || ! in_array( $row['event_type'] ?? null, $events, true )
			|| self::OUTBOX_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::PROFILE_VERSION !== $this->positive_int( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		$payload = $this->decode( (string) $row['payload_json'], (string) $row['payload_hash'] );
		$this->exact( $payload, array( 'command_id', 'event', 'occurred_at_utc', 'operation', 'receipt', 'request_digest', 'source_job_id' ) );
		if ( ! hash_equals( $digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::IDEMPOTENCY_CONFLICT );
		}
		if ( ! hash_equals( $operation, (string) ( $payload['operation'] ?? '' ) ) || ! in_array( $payload['event'] ?? null, $events, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		$receipt = $payload['receipt'] ?? null;
		if ( ! is_array( $receipt ) || array_is_list( $receipt ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		$this->exact( $receipt, array( 'artifact_kind', 'job_id', 'queued_at_utc', 'request_hash', 'source_hash', 'source_id', 'source_type', 'state' ) );
		if ( ! hash_equals( (string) $row['aggregate_public_id'], (string) ( $receipt['job_id'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		return new Export_Control_Result(
			$operation,
			(string) $payload['source_job_id'],
			(string) $receipt['job_id'],
			(string) $receipt['state'],
			(string) $receipt['artifact_kind'],
			(string) $receipt['source_type'],
			(string) $receipt['source_id'],
			(string) $receipt['source_hash'],
			(string) $receipt['request_hash'],
			(string) $receipt['queued_at_utc'],
			(string) $payload['occurred_at_utc'],
			true
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param array<string,mixed>   $record Record.
	 * @param Export_Control_Result $result Result.
	 * @param string                $event Event.
	 * @param bool                  $receipt_only Receipt only.
	 * @return void
	 */
	private function insert_outbox( array $record, Export_Control_Result $result, string $event, bool $receipt_only ): void {
		$payload = $this->canonical->encode(
			array(
				'command_id'      => $record['command_id'],
				'event'           => $event,
				'occurred_at_utc' => $result->occurred_at_utc(),
				'operation'       => $result->operation(),
				'receipt'         => array(
					'artifact_kind' => $result->artifact_kind(),
					'job_id'        => $result->job_id(),
					'queued_at_utc' => $result->queued_at_utc(),
					'request_hash'  => $result->request_hash(),
					'source_hash'   => $result->source_hash(),
					'source_id'     => $result->source_id(),
					'source_type'   => $result->source_type(),
					'state'         => $result->state(),
				),
				'request_digest'  => $record['request_digest'],
				'source_job_id'   => $result->source_job_id(),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['occurred_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['occurred_at_utc'],
				'aggregate_type'       => 'export_job',
				'aggregate_public_id'  => $result->job_id(),
				'event_type'           => $event,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $payload,
				'payload_hash'         => hash( 'sha256', $payload ),
				'available_at_utc'     => $record['occurred_at_utc'],
				'published_at_utc'     => $receipt_only ? $record['occurred_at_utc'] : null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed>   $job Job.
	 * @param array<string,mixed>   $record Record.
	 * @param Export_Control_Result $result Result.
	 * @param string                $event Event.
	 * @return void
	 */
	private function insert_audit( array $job, array $record, Export_Control_Result $result, string $event ): void {
		$previous = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->hash( (string) $previous );
		}
		$before        = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'error_code'   => $job['error_code'],
					'job_id'       => $job['public_id'],
					'request_hash' => $job['request_hash'],
					'state'        => $job['state'],
				)
			)
		);
		$metadata      = $this->canonical->encode(
			array(
				'artifact_kind'  => $result->artifact_kind(),
				'command_id'     => $record['command_id'],
				'event'          => $event,
				'reason'         => $record['reason'],
				'request_digest' => $record['request_digest'],
				'request_hash'   => $result->request_hash(),
				'source_job_id'  => $result->source_job_id(),
				'target_job_id'  => $result->job_id(),
				'target_state'   => $result->state(),
			)
		);
		$metadata_hash = hash( 'sha256', $metadata );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $metadata_hash,
					'before_digest'       => $before,
					'context_id'          => $job['context_id'],
					'context_revision'    => $job['context_revision'],
					'context_type'        => $job['context_type'],
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $result->job_id(),
					'object_type'         => 'export_job',
					'occurred_at_utc'     => $record['occurred_at_utc'],
					'previous_event_hash' => $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['occurred_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => $event,
				'object_type'         => 'export_job',
				'object_public_id'    => $result->job_id(),
				'context_type'        => $job['context_type'],
				'context_id'          => $job['context_id'],
				'context_revision'    => $job['context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $before,
				'after_digest'        => $metadata_hash,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::PROFILE_VERSION,
				'metadata_json'       => $metadata,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Result.
	 *
	 * @param string                   $operation Operation.
	 * @param array<string,mixed>      $source Source.
	 * @param string                   $job_id Job id.
	 * @param string                   $state State.
	 * @param string                   $occurred Occurred.
	 * @param bool                     $replayed Replayed.
	 * @param array<string,mixed>|null $target Target.
	 * @return Export_Control_Result
	 */
	private function result( string $operation, array $source, string $job_id, string $state, string $occurred, bool $replayed, ?array $target = null ): Export_Control_Result {
		$row = $target ?? $source;
		return new Export_Control_Result(
			$operation,
			(string) $source['public_id'],
			$job_id,
			$state,
			(string) $row['artifact_kind'],
			(string) $row['source_type'],
			(string) $row['source_public_id'],
			(string) $row['source_hash'],
			(string) $row['request_hash'],
			null === ( $target ) && Export_Control_Result::OPERATION_RETRY === $operation ? $occurred : (string) $row['queued_at_utc'],
			$occurred,
			$replayed
		);
	}

	/**
	 * Reject invalid cancel record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_cancel_record( array $record ): void {
		$this->assert_common_record(
			$record,
			array(
				'actor_user_id',
				'audit_public_id',
				'command_id',
				'command_storage_key',
				'correlation_id',
				'expected_source_hash',
				'expected_state',
				'job_public_id',
				'occurred_at_utc',
				'outbox_public_id',
				'reason',
				'request_digest',
			)
		);
		if ( 'queued' !== $record['expected_state'] ) {
			throw new InvalidArgumentException( 'Export cancel expected state is invalid.' );
		}
	}

	/**
	 * Reject invalid retry record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_retry_record( array $record ): void {
		$this->assert_common_record(
			$record,
			array(
				'actor_user_id',
				'audit_public_id',
				'command_id',
				'command_storage_key',
				'correlation_id',
				'expected_error_code',
				'expected_source_hash',
				'expected_state',
				'job_idempotency_key',
				'job_public_id',
				'new_job_public_id',
				'occurred_at_utc',
				'outbox_public_id',
				'reason',
				'request_digest',
			)
		);
		if ( 'failed' !== $record['expected_state'] ) {
			throw new InvalidArgumentException( 'Export retry expected state is invalid.' );
		}
		new Public_Id( (string) $record['new_job_public_id'] );
		$this->storage_key( (string) $record['job_idempotency_key'], 'export-retry-job:' );
		$this->stable_code( (string) $record['expected_error_code'] );
	}

	/**
	 * Reject invalid common record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_common_record( array $record, array $expected ): void {
		$this->exact( $record, $expected );
		if ( ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Export control actor is invalid.' );
		}
		foreach ( array( 'audit_public_id', 'job_public_id', 'outbox_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		$this->storage_key( (string) $record['command_storage_key'], 'command:' );
		$this->hash( (string) $record['expected_source_hash'] );
		$this->hash( (string) $record['request_digest'] );
		$this->utc( (string) $record['occurred_at_utc'] );
		$this->safe_token( (string) $record['command_id'], 8, 128 );
		$this->safe_token( (string) $record['correlation_id'], 1, 100 );
		$this->reason( (string) $record['reason'] );
	}

	/**
	 * Safe state.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed>
	 */
	private function safe_state( array $job ): array {
		return array(
			'error_code'  => is_string( $job['error_code'] ?? null ) ? $job['error_code'] : null,
			'job_id'      => is_string( $job['public_id'] ?? null ) ? $job['public_id'] : null,
			'source_hash' => is_string( $job['source_hash'] ?? null ) ? $job['source_hash'] : null,
			'state'       => is_string( $job['state'] ?? null ) ? $job['state'] : null,
		);
	}

	/**
	 * Recover receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $operation Operation.
	 * @return Export_Control_Result|null
	 */
	private function recover_receipt( array $record, string $operation ): ?Export_Control_Result {
		try {
			return $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], $operation, false ); } catch ( Throwable ) {
			return null;
			}
	}

	/**
	 * Decode WordPress export control repository.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( string $json, string $hash ): array {
		$this->hash( $hash );
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			return $value;
	}

	/**
	 * Operation.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function operation( string $value ): void {
		if ( ! in_array( $value, array( Export_Control_Result::OPERATION_CANCEL, Export_Control_Result::OPERATION_RETRY ), true ) ) {
			throw new InvalidArgumentException( 'Export control operation is invalid.' );
		}
	}
	/**
	 * Hash.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Export control hash is invalid.' );
		}
	}
	/**
	 * Stable code.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stable_code( string $value ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Export control code is invalid.' );
		}
	}
	/**
	 * Utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Export control UTC timestamp is invalid.' );
		}
	}
	/**
	 * Storage key.
	 *
	 * @param string $value Value.
	 * @param string $prefix Prefix.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function storage_key( string $value, string $prefix ): void {
		if ( 1 !== preg_match( '/^' . preg_quote( $prefix, '/' ) . '[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Export control storage key is invalid.' );
		}
	}
	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( string $value, int $minimum, int $maximum ): void {
		if ( strlen( $value ) < $minimum || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Export control token is invalid.' );
		}
	}
	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function reason( string $value ): void {
		if ( $value !== trim( $value ) || strlen( $value ) < 3 || strlen( $value ) > 1000 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
			throw new InvalidArgumentException( 'Export control reason is invalid.' );
		}
	}
	/**
	 * Positive int.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_int( mixed $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		} if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		} throw new InvalidArgumentException( 'Export control positive integer is invalid.' );
	}
	/**
	 * Non negative int.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_int( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		} if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		} throw new InvalidArgumentException( 'Export control non-negative integer is invalid.' );
	}
	/**
	 * Exact.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Export control fields are invalid.' );
		}
	}

	/**
	 * Prepare.
	 *
	 * @param string           $sql Sql.
	 * @param array<int,mixed> $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->db()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw $this->storage_failure();
		}return $prepared;
	}
	/**
	 * Row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function row( string $sql ): ?array {
		$db = $this->db();
		$this->reset( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_ok( $db );
		if ( null === $row ) {
			return null;
		}if ( ! is_array( $row ) || array_is_list( $row ) ) {
			throw $this->storage_failure();
		}return $row;
	}
	/**
	 * Scalar.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function scalar( string $sql ) {
		$db = $this->db();
		$this->reset( $db );
		$value = $db->get_var( $sql );
		$this->assert_ok( $db );
		return $value;
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query( string $sql ): int {
		$db = $this->db();
		$this->reset( $db );
		$result = $db->query( $sql );
		$this->assert_ok( $db );
		if ( false === $result ) {
			throw $this->storage_failure();
		}return (int) $result;
	}
	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates failures from the database query boundary.
	 */
	private function query_or_fail( string $sql ): void {
		$this->query( $sql );
	}
	/**
	 * Insert or fail.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_or_fail( string $table, array $data ): void {
		$db = $this->db();
		$this->reset( $db );
		$result = $db->insert( $table, $data );
		$this->assert_ok( $db );
		if ( false === $result || 1 !== (int) $result ) {
			throw $this->storage_failure();
		}
	}
	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->db()->query( 'ROLLBACK' ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// Best-effort recovery must not replace the primary failure.
	 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
			}
	}
	/**
	 * Db.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function db(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) || ! method_exists( $db, 'prepare' ) || ! method_exists( $db, 'get_row' ) || ! method_exists( $db, 'get_var' ) || ! method_exists( $db, 'query' ) || ! method_exists( $db, 'insert' ) ) {
			throw $this->storage_failure();
		}return $db;
	}
	/**
	 * Reset.
	 *
	 * @param object $db Db.
	 * @return void
	 */
	private function reset( object $db ): void {
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
	}
	/**
	 * Reject invalid ok.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_ok( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			throw $this->storage_failure();
		}
	}
	/**
	 * Storage failure.
	 *
	 * @return Export_Failure
	 */
	private function storage_failure(): Export_Failure {
		return Export_Failure::because( Export_Failure::STORAGE_UNAVAILABLE );
	}
}
