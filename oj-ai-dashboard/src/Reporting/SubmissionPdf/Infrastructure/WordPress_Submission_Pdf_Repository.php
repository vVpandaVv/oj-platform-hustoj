<?php
/**
 * Submission PDF enqueue persistence.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Build_Submission_Print_Projection;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Enqueue_Submission_Pdf_Result;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Submission_Pdf_Failure;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Work_Item;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Submission_Pdf_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Submission_Pdf_Work_Repository_Port_Interface;
use OJAI\Dashboard\Storage\Port\Generated_Private_File_Metadata_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses the existing generic Reporting and Operations tables with exact Context columns.
 */
final class WordPress_Submission_Pdf_Repository implements Submission_Pdf_Repository_Port_Interface, Submission_Pdf_Work_Repository_Port_Interface {
	private const TABLE_JOBS      = 'oj_ai_export_jobs';
	private const TABLE_ARTIFACTS = 'oj_ai_pdf_artifacts';
	private const TABLE_AUDIT     = 'oj_ai_audit_events';
	private const TABLE_OUTBOX    = 'oj_ai_outbox_events';
	private const QUEUED_EVENT    = 'submission_pdf.queued';
	private const REPLAY_EVENT    = 'submission_pdf.enqueue_replayed';
	private const READY_EVENT     = 'submission_pdf.ready';
	private const FAILED_EVENT    = 'submission_pdf.failed';
	private const OUTBOX_SCHEMA   = 'oj_ai.submission_pdf_event';
	private const AUDIT_SCHEMA    = 'oj_ai.submission_pdf_audit';

	/**
	 * Initialize WordPress submission pdf repository dependencies and state.
	 *
	 * @param Canonical_Json                                      $canonical Canonical JSON encoder used for stable digests.
	 * @param Generated_Private_File_Metadata_Port_Interface|null $file_metadata File metadata.
	 * @param object|null                                         $database Database.
	 */
	public function __construct( private Canonical_Json $canonical, private ?Generated_Private_File_Metadata_Port_Interface $file_metadata = null, private ?object $database = null ) {
	}

	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Enqueue_Submission_Pdf_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Enqueue_Submission_Pdf_Result {
		$this->storage_key( $command_storage_key );
		$this->hash( $request_digest );
		return $this->command_receipt( $command_storage_key, $request_digest, false );
	}

	/**
	 * Enqueue.
	 *
	 * @param array $record Record.
	 * @return Enqueue_Submission_Pdf_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function enqueue( array $record ): Enqueue_Submission_Pdf_Result {
		$this->assert_enqueue_record( $record );
		$receipt = $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], false );
		if ( $receipt instanceof Enqueue_Submission_Pdf_Result ) {
			return $receipt;
		}
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], true );
			if ( $receipt instanceof Enqueue_Submission_Pdf_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}
			$semantic = $this->semantic_receipt( $record, true );
			if ( $semantic instanceof Enqueue_Submission_Pdf_Result ) {
				$this->insert_outbox( $record, $semantic, self::REPLAY_EVENT, true );
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $semantic;
			}

			$this->insert_or_fail(
				self::TABLE_JOBS,
				array(
					'public_id'                => $record['job_public_id'],
					'lock_version'             => 0,
					'created_by_user_id'       => $record['actor_user_id'],
					'created_at_utc'           => $record['queued_at_utc'],
					'updated_by_user_id'       => $record['actor_user_id'],
					'updated_at_utc'           => $record['queued_at_utc'],
					'context_type'             => $record['context_type'],
					'context_id'               => $record['context_id'],
					'context_revision'         => $record['context_revision'],
					'report_snapshot_id'       => null,
					'artifact_kind'            => 'submission_pdf',
					'source_type'              => 'submission_revision',
					'source_public_id'         => $record['source_public_id'],
					'source_hash'              => $record['source_hash'],
					'request_contract_version' => Submission_Pdf_Request_Snapshot::restore(
						(string) $record['snapshot_json'],
						(string) $record['snapshot_hash'],
						$this->canonical
					)->version(),
					'request_snapshot_schema'  => Submission_Pdf_Request_Snapshot::SCHEMA,
					'request_snapshot_version' => Submission_Pdf_Request_Snapshot::DATABASE_VERSION,
					'request_snapshot_json'    => $record['snapshot_json'],
					'request_snapshot_hash'    => $record['snapshot_hash'],
					'request_hash'             => $record['snapshot_hash'],
					'state'                    => 'queued',
					'requested_by_user_id'     => $record['actor_user_id'],
					'idempotency_key'          => $record['job_idempotency_key'],
					'active_identity_key'      => 'submission-pdf-active:' . $record['semantic_hash'],
					'print_profile_version'    => $record['print_profile_version'],
					'available_at_utc'         => $record['queued_at_utc'],
					'queued_at_utc'            => $record['queued_at_utc'],
					'lease_token'              => null,
					'lease_expires_at_utc'     => null,
					'started_at_utc'           => null,
					'finished_at_utc'          => null,
					'result_file_public_id'    => null,
					'error_code'               => null,
				)
			);
			$result = $this->result_from_record( $record, false );
			$this->insert_audit( $record, $result );
			$this->insert_outbox( $record, $result, self::QUEUED_EVENT, false );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Submission_Pdf_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$receipt = $this->recover_receipt( $record );
			if ( $receipt instanceof Enqueue_Submission_Pdf_Result ) {
				return $receipt;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Submission_Pdf_Work_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Submission_Pdf_Work_Item {
		$this->utc( $now_utc );
		$this->utc( $lease_expires_at_utc );
		$this->lease( $lease_token );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$sql         = 'SELECT id, public_id, lock_version, requested_by_user_id, context_type, context_id, context_revision, source_public_id, source_hash, request_snapshot_json, request_snapshot_hash, request_hash, print_profile_version, queued_at_utc, state FROM ' . self::TABLE_JOBS . ' WHERE artifact_kind = %s AND ( '
				. '(state = %s AND available_at_utc <= %s) OR '
				. '(state = %s AND lease_expires_at_utc IS NOT NULL AND lease_expires_at_utc <= %s) '
				. ') ORDER BY available_at_utc ASC, id ASC LIMIT 1 FOR UPDATE';
			$row         = $this->row( $this->prepare( $sql, array( 'submission_pdf', 'queued', $now_utc, 'running', $now_utc ) ) );
			if ( null === $row ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return null;
			}
			$pk      = $this->positive_int( $row['id'] ?? null );
			$lock    = $this->non_negative_int( $row['lock_version'] ?? null );
			$state   = (string) ( $row['state'] ?? '' );
			$changed = $this->query(
				$this->prepare(
					'UPDATE ' . self::TABLE_JOBS . ' SET state = %s, lease_token = %s, lease_expires_at_utc = %s, '
					. 'started_at_utc = COALESCE(started_at_utc, %s), lock_version = lock_version + 1, '
					. 'updated_by_user_id = requested_by_user_id, updated_at_utc = %s '
					. 'WHERE id = %d AND lock_version = %d AND state = %s',
					array( 'running', $lease_token, $lease_expires_at_utc, $now_utc, $now_utc, $pk, $lock, $state )
				)
			);
			if ( 1 !== $changed ) {
				$this->rollback();
				$transaction = false;
				return null;
			}
			$item = new Submission_Pdf_Work_Item(
				$pk,
				(string) $row['public_id'],
				$lock + 1,
				$this->positive_int( $row['requested_by_user_id'] ?? null ),
				(string) $row['context_type'],
				(string) $row['context_id'],
				(string) $row['context_revision'],
				(string) $row['source_public_id'],
				(string) $row['source_hash'],
				(string) $row['request_snapshot_json'],
				(string) $row['request_snapshot_hash'],
				(string) $row['request_hash'],
				(string) $row['print_profile_version'],
				(string) $row['queued_at_utc'],
				$lease_token
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $item;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			if ( $error instanceof Submission_Pdf_Failure || $error instanceof InvalidArgumentException ) {
				throw $error;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Complete ready.
	 *
	 * @param Submission_Pdf_Work_Item $item Item.
	 * @param array                    $file File.
	 * @param array                    $artifact Artifact.
	 * @param string                   $finished_at_utc Finished at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete_ready( Submission_Pdf_Work_Item $item, array $file, array $artifact, string $finished_at_utc ): bool {
		$this->utc( $finished_at_utc );
		$this->assert_ready_records( $item, $file, $artifact );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			if ( ! $this->work_lock_matches( $item ) || null !== $this->artifact_for_job( $item->job_pk(), true ) ) {
				$this->rollback();
				$transaction = false;
				return false;
			}
			if ( null === $this->file_metadata ) {
				throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
			}
			$this->file_metadata->insert_ready( $file );
			$content_json = $this->canonical->encode(
				array(
					'context'               => array(
						'type'     => $item->context_type(),
						'id'       => $item->context_id(),
						'revision' => $item->context_revision(),
					),
					'export_job_id'         => $item->job_id(),
					'expires_at_utc'        => $file['expires_at_utc'],
					'file_public_id'        => $file['file_public_id'],
					'page_count'            => $artifact['page_count'],
					'print_profile_version' => $item->profile_version(),
					'renderer_version'      => $artifact['renderer_version'],
					'source_hash'           => $item->source_hash(),
					'source_id'             => $item->source_id(),
					'submission_id'         => $artifact['submission_public_id'],
				)
			);
			$this->insert_or_fail(
				self::TABLE_ARTIFACTS,
				array(
					'public_id'             => $artifact['artifact_public_id'],
					'content_hash'          => hash( 'sha256', $content_json ),
					'created_by_user_id'    => $item->actor_user_id(),
					'created_at_utc'        => $finished_at_utc,
					'export_job_id'         => $item->job_pk(),
					'file_public_id'        => $file['file_public_id'],
					'source_type'           => 'submission_revision',
					'source_public_id'      => $item->source_id(),
					'source_hash'           => $item->source_hash(),
					'print_profile_version' => $item->profile_version(),
					'renderer_version'      => $artifact['renderer_version'],
					'page_count'            => $artifact['page_count'],
					'state'                 => 'ready',
					'expires_at_utc'        => $file['expires_at_utc'],
				)
			);
			$changed = $this->query(
				$this->prepare(
					'UPDATE ' . self::TABLE_JOBS . ' SET state = %s, result_file_public_id = %s, error_code = NULL, '
					. 'finished_at_utc = %s, lease_token = NULL, lease_expires_at_utc = NULL, active_identity_key = NULL, '
					. 'lock_version = lock_version + 1, updated_at_utc = %s '
					. 'WHERE id = %d AND lock_version = %d AND state = %s AND lease_token = %s',
					array( 'ready', $file['file_public_id'], $finished_at_utc, $finished_at_utc, $item->job_pk(), $item->lock_version(), 'running', $item->lease_token() )
				)
			);
			if ( 1 !== $changed ) {
				$this->rollback();
				$transaction = false;
				return false;
			}
			$this->insert_worker_audit(
				(string) $artifact['audit_public_id'],
				self::READY_EVENT,
				$item,
				(string) $artifact['correlation_id'],
				$finished_at_utc,
				array(
					'artifact_id'      => $artifact['artifact_public_id'],
					'file_sha256'      => $file['sha256'],
					'page_count'       => $artifact['page_count'],
					'renderer_version' => $artifact['renderer_version'],
					'submission_id'    => $artifact['submission_public_id'],
				)
			);
			$this->insert_worker_outbox(
				(string) $artifact['outbox_public_id'],
				self::READY_EVENT,
				$item,
				$finished_at_utc,
				array(
					'artifact_id' => $artifact['artifact_public_id'],
					'file_id'     => $file['file_public_id'],
				)
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return true;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			if ( $error instanceof Submission_Pdf_Failure || $error instanceof InvalidArgumentException ) {
				throw $error;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Complete failure.
	 *
	 * @param Submission_Pdf_Work_Item $item Item.
	 * @param string                   $error_code Error code.
	 * @param string                   $audit_public_id Audit public id.
	 * @param string                   $outbox_public_id Outbox public id.
	 * @param string                   $correlation_id Request correlation identifier for diagnostics.
	 * @param string                   $finished_at_utc Finished at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete_failure( Submission_Pdf_Work_Item $item, string $error_code, string $audit_public_id, string $outbox_public_id, string $correlation_id, string $finished_at_utc ): bool {
		$this->utc( $finished_at_utc );
		$this->stable_code( $error_code );
		new Public_Id( $audit_public_id );
		new Public_Id( $outbox_public_id );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			if ( ! $this->work_lock_matches( $item ) ) {
				$this->rollback();
				$transaction = false;
				return false;
			}
			$changed = $this->query(
				$this->prepare(
					'UPDATE ' . self::TABLE_JOBS . ' SET state = %s, error_code = %s, finished_at_utc = %s, '
					. 'lease_token = NULL, lease_expires_at_utc = NULL, active_identity_key = NULL, '
					. 'lock_version = lock_version + 1, updated_at_utc = %s '
					. 'WHERE id = %d AND lock_version = %d AND state = %s AND lease_token = %s',
					array( 'failed', $error_code, $finished_at_utc, $finished_at_utc, $item->job_pk(), $item->lock_version(), 'running', $item->lease_token() )
				)
			);
			if ( 1 !== $changed ) {
				$this->rollback();
				$transaction = false;
				return false;
			}
			$this->insert_worker_audit(
				$audit_public_id,
				self::FAILED_EVENT,
				$item,
				$correlation_id,
				$finished_at_utc,
				array(
					'error_code'  => $error_code,
					'source_hash' => $item->source_hash(),
				)
			);
			$this->insert_worker_outbox( $outbox_public_id, self::FAILED_EVENT, $item, $finished_at_utc, array( 'error_code' => $error_code ) );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return true;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			if ( $error instanceof Submission_Pdf_Failure || $error instanceof InvalidArgumentException ) {
				throw $error;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @return Enqueue_Submission_Pdf_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $key, string $digest, bool $for_update ): ?Enqueue_Submission_Pdf_Result {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			'submission_pdf_job' !== ( $row['aggregate_type'] ?? null )
			|| ! in_array( $row['event_type'] ?? null, array( self::QUEUED_EVENT, self::REPLAY_EVENT ), true )
			|| self::OUTBOX_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| 1 !== $this->positive_int( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		$payload = $this->decode( (string) $row['payload_json'], (string) $row['payload_hash'] );
		if ( ! hash_equals( $digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::IDEMPOTENCY_CONFLICT );
		}
		$receipt = $payload['receipt'] ?? null;
		if ( ! is_array( $receipt ) || ! hash_equals( (string) $row['aggregate_public_id'], (string) ( $receipt['job_id'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return $this->result_from_receipt( $receipt, true );
	}

	/**
	 * Semantic receipt.
	 *
	 * @param array $record Record.
	 * @param bool  $for_update For update.
	 * @return Enqueue_Submission_Pdf_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function semantic_receipt( array $record, bool $for_update ): ?Enqueue_Submission_Pdf_Result {
		$sql = 'SELECT public_id, state, source_public_id, source_hash, request_snapshot_json, request_snapshot_hash, queued_at_utc FROM ' . self::TABLE_JOBS . ' WHERE active_identity_key = %s AND artifact_kind = %s AND state IN (%s,%s) '
			. 'ORDER BY id ASC LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row(
			$this->prepare(
				$sql,
				array(
					'submission-pdf-active:' . $record['semantic_hash'],
					'submission_pdf',
					'queued',
					'running',
				)
			)
		);
		if ( null === $row ) {
			return null;
		}
		try {
			$snapshot = Submission_Pdf_Request_Snapshot::restore(
				(string) $row['request_snapshot_json'],
				(string) $row['request_snapshot_hash'],
				$this->canonical
			);
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return new Enqueue_Submission_Pdf_Result(
			(string) $row['public_id'],
			(string) $row['state'],
			(string) $row['source_public_id'],
			(string) $row['source_hash'],
			(string) $snapshot->submission()['id'],
			(string) $row['queued_at_utc'],
			true
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param array                         $record Record.
	 * @param Enqueue_Submission_Pdf_Result $result Result.
	 * @param string                        $event Event.
	 * @param bool                          $receipt_only Receipt only.
	 * @return void
	 */
	private function insert_outbox( array $record, Enqueue_Submission_Pdf_Result $result, string $event, bool $receipt_only ): void {
		$payload = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'event'          => $event,
				'request_digest' => $record['request_digest'],
				'receipt'        => array(
					'job_id'        => $result->job_id(),
					'queued_at_utc' => $result->queued_at_utc(),
					'source_hash'   => $result->source_hash(),
					'source_id'     => $result->source_id(),
					'state'         => $result->state(),
					'submission_id' => $result->submission_id(),
				),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['queued_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['queued_at_utc'],
				'aggregate_type'       => 'submission_pdf_job',
				'aggregate_public_id'  => $result->job_id(),
				'event_type'           => $event,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => 1,
				'payload_json'         => $payload,
				'payload_hash'         => hash( 'sha256', $payload ),
				'available_at_utc'     => $record['queued_at_utc'],
				'published_at_utc'     => $receipt_only ? $record['queued_at_utc'] : null,
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
	 * @param array                         $record Record.
	 * @param Enqueue_Submission_Pdf_Result $result Result.
	 * @return void
	 */
	private function insert_audit( array $record, Enqueue_Submission_Pdf_Result $result ): void {
		$previous = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->hash( (string) $previous );
		}
		$metadata_json = $this->canonical->encode(
			array(
				'attachment_set_hash' => $record['attachment_set_hash'],
				'command_id'          => $record['command_id'],
				'publication_hash'    => $record['publication_hash'],
				'request_digest'      => $record['request_digest'],
				'request_hash'        => $record['snapshot_hash'],
				'source_hash'         => $record['source_hash'],
				'source_id'           => $record['source_public_id'],
				'submission_id'       => $record['submission_public_id'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::QUEUED_EVENT,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $metadata_hash,
					'before_digest'       => null,
					'context_id'          => $record['context_id'],
					'context_revision'    => $record['context_revision'],
					'context_type'        => $record['context_type'],
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $result->job_id(),
					'object_type'         => 'submission_pdf_job',
					'occurred_at_utc'     => $record['queued_at_utc'],
					'previous_event_hash' => $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['queued_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => self::QUEUED_EVENT,
				'object_type'         => 'submission_pdf_job',
				'object_public_id'    => $result->job_id(),
				'context_type'        => $record['context_type'],
				'context_id'          => $record['context_id'],
				'context_revision'    => $record['context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => null,
				'after_digest'        => $metadata_hash,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => 1,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert worker audit.
	 *
	 * @param string                   $public_id Public id.
	 * @param string                   $action Action.
	 * @param Submission_Pdf_Work_Item $item Item.
	 * @param string                   $correlation Correlation.
	 * @param string                   $utc Utc.
	 * @param array                    $metadata Metadata.
	 * @return void
	 */
	private function insert_worker_audit( string $public_id, string $action, Submission_Pdf_Work_Item $item, string $correlation, string $utc, array $metadata ): void {
		$previous = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->hash( (string) $previous );
		}
		$metadata_json = $this->canonical->encode( $metadata );
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $action,
					'actor_user_id'       => $item->actor_user_id(),
					'after_digest'        => $metadata_hash,
					'before_digest'       => null,
					'context_id'          => $item->context_id(),
					'context_revision'    => $item->context_revision(),
					'context_type'        => $item->context_type(),
					'correlation_id'      => $correlation,
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $item->job_id(),
					'object_type'         => 'submission_pdf_job',
					'occurred_at_utc'     => $utc,
					'previous_event_hash' => $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $public_id,
				'occurred_at_utc'     => $utc,
				'actor_user_id'       => $item->actor_user_id(),
				'action_key'          => $action,
				'object_type'         => 'submission_pdf_job',
				'object_public_id'    => $item->job_id(),
				'context_type'        => $item->context_type(),
				'context_id'          => $item->context_id(),
				'context_revision'    => $item->context_revision(),
				'correlation_id'      => $correlation,
				'before_digest'       => null,
				'after_digest'        => $metadata_hash,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => 1,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert worker outbox.
	 *
	 * @param string                   $public_id Public id.
	 * @param string                   $event Event.
	 * @param Submission_Pdf_Work_Item $item Item.
	 * @param string                   $utc Utc.
	 * @param array                    $result Result.
	 * @return void
	 */
	private function insert_worker_outbox( string $public_id, string $event, Submission_Pdf_Work_Item $item, string $utc, array $result ): void {
		$payload = $this->canonical->encode(
			array(
				'context'     => array(
					'type'     => $item->context_type(),
					'id'       => $item->context_id(),
					'revision' => $item->context_revision(),
				),
				'event'       => $event,
				'job_id'      => $item->job_id(),
				'result'      => $result,
				'source_hash' => $item->source_hash(),
				'source_id'   => $item->source_id(),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $public_id,
				'lock_version'         => 0,
				'created_by_user_id'   => $item->actor_user_id(),
				'created_at_utc'       => $utc,
				'updated_by_user_id'   => $item->actor_user_id(),
				'updated_at_utc'       => $utc,
				'aggregate_type'       => 'submission_pdf_job',
				'aggregate_public_id'  => $item->job_id(),
				'event_type'           => $event,
				'idempotency_key'      => 'submission-pdf-event:' . hash( 'sha256', $item->job_id() . '|' . $event ),
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => 1,
				'payload_json'         => $payload,
				'payload_hash'         => hash( 'sha256', $payload ),
				'available_at_utc'     => $utc,
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Work lock matches.
	 *
	 * @param Submission_Pdf_Work_Item $item Item.
	 * @return bool
	 */
	private function work_lock_matches( Submission_Pdf_Work_Item $item ): bool {
		$sql = 'SELECT id FROM ' . self::TABLE_JOBS . ' WHERE id = %d AND lock_version = %d '
			. 'AND state = %s AND lease_token = %s LIMIT 1 FOR UPDATE';
		return null !== $this->row( $this->prepare( $sql, array( $item->job_pk(), $item->lock_version(), 'running', $item->lease_token() ) ) );
	}

	/**
	 * Artifact for job.
	 *
	 * @param int  $job_pk Job pk.
	 * @param bool $for_update For update.
	 * @return array|null
	 */
	private function artifact_for_job( int $job_pk, bool $for_update ): ?array {
		$sql = 'SELECT public_id FROM ' . self::TABLE_ARTIFACTS . ' WHERE export_job_id = %d LIMIT 1' . ( $for_update ? ' FOR UPDATE' : '' );
		return $this->row( $this->prepare( $sql, array( $job_pk ) ) );
	}

	/**
	 * Reject invalid ready records.
	 *
	 * @param Submission_Pdf_Work_Item $item Item.
	 * @param array                    $file File.
	 * @param array                    $artifact Artifact.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_ready_records( Submission_Pdf_Work_Item $item, array $file, array $artifact ): void {
		foreach ( array( 'file_public_id', 'artifact_public_id', 'audit_public_id', 'outbox_public_id', 'submission_public_id' ) as $key ) {
			$value = $file[ $key ] ?? $artifact[ $key ] ?? null;
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Submission PDF completion ID is missing.' );
			}
			new Public_Id( $value );
		}
		if (
			! is_int( $file['byte_size'] ?? null ) || $file['byte_size'] < 1
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $file['sha256'] ?? '' ) )
			|| ! is_int( $artifact['page_count'] ?? null ) || $artifact['page_count'] < 1
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]{0,31}$/D', (string) ( $artifact['renderer_version'] ?? '' ) )
			|| (string) ( $file['artifact_public_id'] ?? '' ) !== (string) ( $artifact['artifact_public_id'] ?? '' )
			|| (int) ( $file['actor_user_id'] ?? 0 ) !== $item->actor_user_id()
		) {
			throw new InvalidArgumentException( 'Submission PDF completion record is invalid.' );
		}
	}

	/**
	 * Reject invalid enqueue record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_enqueue_record( array $record ): void {
		$expected = array(
			'actor_user_id',
			'attachment_set_hash',
			'audit_public_id',
			'command_id',
			'command_storage_key',
			'context_id',
			'context_revision',
			'context_type',
			'correlation_id',
			'job_idempotency_key',
			'job_public_id',
			'outbox_public_id',
			'print_profile_version',
			'publication_hash',
			'queued_at_utc',
			'request_digest',
			'semantic_hash',
			'snapshot_hash',
			'snapshot_json',
			'source_hash',
			'source_public_id',
			'submission_lock_version',
			'submission_public_id',
			'submission_state',
		);
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected || ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Submission PDF enqueue record is invalid.' );
		}
		foreach ( array( 'audit_public_id', 'job_public_id', 'outbox_public_id', 'source_public_id', 'submission_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		foreach ( array( 'attachment_set_hash', 'publication_hash', 'request_digest', 'semantic_hash', 'snapshot_hash', 'source_hash' ) as $key ) {
			$this->hash( (string) $record[ $key ] );
		}
		$this->storage_key( (string) $record['command_storage_key'] );
		$this->storage_key( (string) $record['job_idempotency_key'] );
		$this->utc( (string) $record['queued_at_utc'] );
		$this->context_token( (string) $record['context_id'] );
		$this->context_token( (string) $record['context_revision'] );
		if (
			! in_array( $record['context_type'], array( 'class', 'group' ), true )
			|| ! in_array( $record['submission_state'], array( 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true )
			|| ! is_int( $record['submission_lock_version'] ) || $record['submission_lock_version'] < 0
			|| Build_Submission_Print_Projection::ARTIFACT_PROFILE_VERSION !== $record['print_profile_version']
		) {
			throw new InvalidArgumentException( 'Submission PDF enqueue contract is invalid.' );
		}
		try {
			$snapshot = Submission_Pdf_Request_Snapshot::restore( (string) $record['snapshot_json'], (string) $record['snapshot_hash'], $this->canonical );
		} catch ( InvalidArgumentException ) {
			throw new InvalidArgumentException( 'Submission PDF enqueue snapshot is invalid.' );
		}
		$context     = $snapshot->context();
		$submission  = $snapshot->submission();
		$publication = $snapshot->publication();
		if (
			! hash_equals( (string) $record['source_public_id'], $snapshot->source_id() )
			|| ! hash_equals( (string) $record['source_hash'], $snapshot->source_hash() )
			|| ! hash_equals( (string) $record['attachment_set_hash'], $snapshot->attachment_set_hash() )
			|| ! hash_equals( (string) $record['context_type'], (string) $context['type'] )
			|| ! hash_equals( (string) $record['context_id'], (string) $context['id'] )
			|| ! hash_equals( (string) $record['context_revision'], (string) $context['revision'] )
			|| ! hash_equals( (string) $record['submission_public_id'], (string) $submission['id'] )
			|| ! hash_equals( (string) $record['submission_state'], (string) $submission['state'] )
			|| $record['submission_lock_version'] !== $submission['lock_version']
			|| ! hash_equals( (string) $record['publication_hash'], (string) $publication['hash'] )
		) {
			throw new InvalidArgumentException( 'Submission PDF enqueue snapshot evidence drifted.' );
		}
	}

	/**
	 * Result from record.
	 *
	 * @param array $record Record.
	 * @param bool  $replayed Replayed.
	 * @return Enqueue_Submission_Pdf_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Enqueue_Submission_Pdf_Result {
		return new Enqueue_Submission_Pdf_Result(
			(string) $record['job_public_id'],
			'queued',
			(string) $record['source_public_id'],
			(string) $record['source_hash'],
			(string) $record['submission_public_id'],
			(string) $record['queued_at_utc'],
			$replayed
		);
	}

	/**
	 * Result from receipt.
	 *
	 * @param array $receipt Receipt.
	 * @param bool  $replayed Replayed.
	 * @return Enqueue_Submission_Pdf_Result
	 */
	private function result_from_receipt( array $receipt, bool $replayed ): Enqueue_Submission_Pdf_Result {
		return new Enqueue_Submission_Pdf_Result(
			(string) ( $receipt['job_id'] ?? '' ),
			(string) ( $receipt['state'] ?? '' ),
			(string) ( $receipt['source_id'] ?? '' ),
			(string) ( $receipt['source_hash'] ?? '' ),
			(string) ( $receipt['submission_id'] ?? '' ),
			(string) ( $receipt['queued_at_utc'] ?? '' ),
			$replayed
		);
	}

	/**
	 * Recover receipt.
	 *
	 * @param array $record Record.
	 * @return Enqueue_Submission_Pdf_Result|null
	 */
	private function recover_receipt( array $record ): ?Enqueue_Submission_Pdf_Result {
		try {
			return $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], false )
				?? $this->semantic_receipt( $record, false );
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Decode WordPress submission pdf repository.
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
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
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
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}

	/**
	 * Scalar.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function scalar( string $sql ): mixed {
		$db = $this->db();
		$this->reset( $db );
		$value = $db->get_var( $sql );
		$this->assert_ok( $db );
		return $value;
	}

	/**
	 * Insert or fail.
	 *
	 * @param string $table Table.
	 * @param array  $data Data.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_or_fail( string $table, array $data ): void {
		$db = $this->db();
		$this->reset( $db );
		$written = $db->insert( $table, $data );
		$this->assert_ok( $db );
		if ( 1 !== $written ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_or_fail( string $sql ): void {
		if ( false === $this->query( $sql ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function query( string $sql ): mixed {
		$db = $this->db();
		$this->reset( $db );
		$result = $db->query( $sql );
		$this->assert_ok( $db );
		return $result;
	}

	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->query( 'ROLLBACK' ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
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
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
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
			throw new InvalidArgumentException( 'Submission PDF hash is invalid.' );
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
			throw new InvalidArgumentException( 'Submission PDF UTC value is invalid.' );
		}
	}
	/**
	 * Storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function storage_key( string $value ): void {
		if ( '' === $value || strlen( $value ) > 191 || 1 !== preg_match( '/^[a-z0-9][a-z0-9:._-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission PDF idempotency key is invalid.' );
		}
	}
	/**
	 * Lease.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lease( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission PDF lease is invalid.' );
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
			throw new InvalidArgumentException( 'Submission PDF error code is invalid.' );
		}
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
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
	}
	/**
	 * Context token.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_token( string $value ): void {
		if ( '' === $value || strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission PDF Context token is invalid.' );
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
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
	}
}
