<?php
/**
 * WordPress export work repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Export\Infrastructure;

use InvalidArgumentException;
use RuntimeException;
use OJAI\Dashboard\Reporting\Export\Domain\Export_Work_Item;
use OJAI\Dashboard\Reporting\Export\Port\Export_Work_Repository_Port_Interface;
use OJAI\Dashboard\Storage\Infrastructure\WordPress_Export_Artifact_Private_File_Metadata;
use OJAI\Dashboard\Storage\Port\Generated_Private_File_Metadata_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared lease and atomic artifact completion for the four G5 export formats.
 */
final class WordPress_Export_Work_Repository implements Export_Work_Repository_Port_Interface {
	private const TABLE_JOBS   = 'oj_ai_export_jobs';
	private const TABLE_PDF    = 'oj_ai_pdf_artifacts';
	private const TABLE_AUDIT  = 'oj_ai_audit_events';
	private const TABLE_OUTBOX = 'oj_ai_outbox_events';
	private const KINDS        = array( 'report_csv', 'report_pdf', 'report_json_package', 'submission_bundle' );
	/**
	 * File metadata.
	 *
	 * @var Generated_Private_File_Metadata_Port_Interface
	 */
	private Generated_Private_File_Metadata_Port_Interface $file_metadata;

	/**
	 * Initialize WordPress export work repository dependencies and state.
	 *
	 * @param Canonical_Json                                      $canonical Canonical JSON encoder used for stable digests.
	 * @param Generated_Private_File_Metadata_Port_Interface|null $file_metadata File metadata.
	 * @param object|null                                         $database Database.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		?Generated_Private_File_Metadata_Port_Interface $file_metadata = null,
		private ?object $database = null
	) {
		$this->file_metadata = $file_metadata ?? new WordPress_Export_Artifact_Private_File_Metadata( $database );
	}

	/**
	 * Claim due.
	 *
	 * @param array  $artifact_kinds Artifact kinds.
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Export_Work_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim_due( array $artifact_kinds, string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Export_Work_Item {
		$artifact_kinds = $this->artifact_kinds( $artifact_kinds );
		$this->utc( $now_utc );
		$this->utc( $lease_expires_at_utc );
		$this->lease( $lease_token );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction  = true;
			$placeholders = implode( ',', array_fill( 0, count( $artifact_kinds ), '%s' ) );
			$sql          = 'SELECT id, public_id, lock_version, requested_by_user_id, artifact_kind, source_type, source_public_id, source_hash, context_type, context_id, context_revision, request_contract_version, request_snapshot_schema, request_snapshot_version, request_snapshot_json, request_snapshot_hash, request_hash, print_profile_version, queued_at_utc, state FROM '
				. self::TABLE_JOBS . ' WHERE artifact_kind IN (' . $placeholders . ') AND ( '
				. '(state = %s AND available_at_utc <= %s) OR (state = %s AND lease_expires_at_utc IS NOT NULL AND lease_expires_at_utc <= %s) '
				. ') ORDER BY available_at_utc ASC, id ASC LIMIT 1 FOR UPDATE';
			$values       = array_merge( $artifact_kinds, array( 'queued', $now_utc, 'running', $now_utc ) );
			$row          = $this->row( $this->prepare( $sql, $values ) );
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
					'UPDATE ' . self::TABLE_JOBS . ' SET state = %s, lease_token = %s, lease_expires_at_utc = %s, started_at_utc = COALESCE(started_at_utc, %s), '
					. 'lock_version = lock_version + 1, updated_by_user_id = requested_by_user_id, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND state = %s',
					array( 'running', $lease_token, $lease_expires_at_utc, $now_utc, $now_utc, $pk, $lock, $state )
				)
			);
			if ( 1 !== $changed ) {
				$this->rollback();
				$transaction = false;
				return null;
			}
			$item = new Export_Work_Item(
				$pk,
				(string) $row['public_id'],
				$lock + 1,
				$this->positive_int( $row['requested_by_user_id'] ?? null ),
				(string) $row['artifact_kind'],
				(string) $row['source_type'],
				(string) $row['source_public_id'],
				(string) $row['source_hash'],
				(string) $row['context_type'],
				(string) $row['context_id'],
				(string) $row['context_revision'],
				(string) $row['request_contract_version'],
				(string) $row['request_snapshot_schema'],
				$this->positive_int( $row['request_snapshot_version'] ?? null ),
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
			throw $error instanceof InvalidArgumentException ? $error : new RuntimeException( 'Export work storage is unavailable.', 0, $error );
		}
	}

	/**
	 * Complete ready.
	 *
	 * @param Export_Work_Item $item Item.
	 * @param array            $file File.
	 * @param array            $artifact Artifact.
	 * @param string           $finished_at_utc Finished at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete_ready( Export_Work_Item $item, array $file, array $artifact, string $finished_at_utc ): bool {
		$this->utc( $finished_at_utc );
		$this->assert_ready_records( $item, $file, $artifact );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			if ( ! $this->work_lock_matches( $item ) || ( 'report_pdf' === $item->artifact_kind() && null !== $this->artifact_for_job( $item->job_pk() ) ) ) {
				$this->rollback();
				$transaction = false;
				return false;
			}
			$this->file_metadata->insert_ready( $file );
			if ( 'report_pdf' === $item->artifact_kind() ) {
				$this->insert_pdf_artifact( $item, $file, $artifact, $finished_at_utc );
			}
			$changed = $this->query(
				$this->prepare(
					'UPDATE ' . self::TABLE_JOBS . ' SET state = %s, result_file_public_id = %s, error_code = NULL, finished_at_utc = %s, '
					. 'lease_token = NULL, lease_expires_at_utc = NULL, active_identity_key = NULL, lock_version = lock_version + 1, updated_at_utc = %s '
					. 'WHERE id = %d AND lock_version = %d AND state = %s AND lease_token = %s AND result_file_public_id IS NULL',
					array( 'ready', $file['file_public_id'], $finished_at_utc, $finished_at_utc, $item->job_pk(), $item->lock_version(), 'running', $item->lease_token() )
				)
			);
			if ( 1 !== $changed ) {
				$this->rollback();
				$transaction = false;
				return false;
			}
			$event    = $item->artifact_kind() . '.ready';
			$metadata = array(
				'artifact_id'      => $artifact['artifact_public_id'],
				'artifact_kind'    => $item->artifact_kind(),
				'byte_size'        => $file['byte_size'],
				'file_id'          => $file['file_public_id'],
				'file_sha256'      => $file['sha256'],
				'page_count'       => $artifact['page_count'],
				'renderer_version' => $artifact['renderer_version'],
				'details'          => $artifact['details'],
			);
			$this->insert_audit( (string) $artifact['audit_public_id'], $event, $item, (string) $artifact['correlation_id'], $finished_at_utc, $metadata );
			$this->insert_outbox(
				(string) $artifact['outbox_public_id'],
				$event,
				$item,
				$finished_at_utc,
				array(
					'artifact_id' => $artifact['artifact_public_id'],
					'file_id'     => $file['file_public_id'],
					'sha256'      => $file['sha256'],
				)
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return true;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $error instanceof InvalidArgumentException ? $error : new RuntimeException( 'Export completion storage is unavailable.', 0, $error );
		}
	}

	/**
	 * Complete failure.
	 *
	 * @param Export_Work_Item $item Item.
	 * @param string           $error_code Error code.
	 * @param string           $audit_public_id Audit public id.
	 * @param string           $outbox_public_id Outbox public id.
	 * @param string           $correlation_id Request correlation identifier for diagnostics.
	 * @param string           $finished_at_utc Finished at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete_failure( Export_Work_Item $item, string $error_code, string $audit_public_id, string $outbox_public_id, string $correlation_id, string $finished_at_utc ): bool {
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
					'UPDATE ' . self::TABLE_JOBS . ' SET state = %s, error_code = %s, finished_at_utc = %s, lease_token = NULL, lease_expires_at_utc = NULL, '
					. 'active_identity_key = NULL, lock_version = lock_version + 1, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND state = %s AND lease_token = %s',
					array( 'failed', $error_code, $finished_at_utc, $finished_at_utc, $item->job_pk(), $item->lock_version(), 'running', $item->lease_token() )
				)
			);
			if ( 1 !== $changed ) {
				$this->rollback();
				$transaction = false;
				return false;
			}
			$event = $item->artifact_kind() . '.failed';
			$this->insert_audit(
				$audit_public_id,
				$event,
				$item,
				$correlation_id,
				$finished_at_utc,
				array(
					'artifact_kind' => $item->artifact_kind(),
					'error_code'    => $error_code,
					'source_hash'   => $item->source_hash(),
				)
			);
			$this->insert_outbox( $outbox_public_id, $event, $item, $finished_at_utc, array( 'error_code' => $error_code ) );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return true;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $error instanceof InvalidArgumentException ? $error : new RuntimeException( 'Export failure storage is unavailable.', 0, $error );
		}
	}

	/**
	 * Insert pdf artifact.
	 *
	 * @param Export_Work_Item $item Item.
	 * @param array            $file File.
	 * @param array            $artifact Artifact.
	 * @param string           $utc Utc.
	 * @return void
	 */
	private function insert_pdf_artifact( Export_Work_Item $item, array $file, array $artifact, string $utc ): void {
		$content = $this->canonical->encode(
			array(
				'export_job_id'         => $item->job_id(),
				'file_public_id'        => $file['file_public_id'],
				'source_id'             => $item->source_id(),
				'source_hash'           => $item->source_hash(),
				'print_profile_version' => $item->profile_version(),
				'renderer_version'      => $artifact['renderer_version'],
				'page_count'            => $artifact['page_count'],
				'expires_at_utc'        => $file['expires_at_utc'],
			)
		);
		$this->insert_or_fail(
			self::TABLE_PDF,
			array(
				'public_id'             => $artifact['artifact_public_id'],
				'content_hash'          => hash( 'sha256', $content ),
				'created_by_user_id'    => $item->actor_user_id(),
				'created_at_utc'        => $utc,
				'export_job_id'         => $item->job_pk(),
				'file_public_id'        => $file['file_public_id'],
				'source_type'           => $item->source_type(),
				'source_public_id'      => $item->source_id(),
				'source_hash'           => $item->source_hash(),
				'print_profile_version' => $item->profile_version(),
				'renderer_version'      => $artifact['renderer_version'],
				'page_count'            => $artifact['page_count'],
				'state'                 => 'ready',
				'expires_at_utc'        => $file['expires_at_utc'],
			)
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param string           $public_id Public id.
	 * @param string           $action Action.
	 * @param Export_Work_Item $item Item.
	 * @param string           $correlation Correlation.
	 * @param string           $utc Utc.
	 * @param array            $metadata Metadata.
	 * @return void
	 */
	private function insert_audit( string $public_id, string $action, Export_Work_Item $item, string $correlation, string $utc, array $metadata ): void {
		$previous = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->hash( (string) $previous );
		}
		$json          = $this->canonical->encode( $metadata );
		$metadata_hash = hash( 'sha256', $json );
		$object_type   = $this->aggregate_type( $item );
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
					'object_type'         => $object_type,
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
				'object_type'         => $object_type,
				'object_public_id'    => $item->job_id(),
				'context_type'        => $item->context_type(),
				'context_id'          => $item->context_id(),
				'context_revision'    => $item->context_revision(),
				'correlation_id'      => $correlation,
				'before_digest'       => null,
				'after_digest'        => $metadata_hash,
				'metadata_schema'     => $this->audit_schema( $item ),
				'metadata_version'    => 1,
				'metadata_json'       => $json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param string           $public_id Public id.
	 * @param string           $event Event.
	 * @param Export_Work_Item $item Item.
	 * @param string           $utc Utc.
	 * @param array            $result Result.
	 * @return void
	 */
	private function insert_outbox( string $public_id, string $event, Export_Work_Item $item, string $utc, array $result ): void {
		$payload = $this->canonical->encode(
			array(
				'artifact_kind' => $item->artifact_kind(),
				'context'       => $item->context(),
				'event'         => $event,
				'job_id'        => $item->job_id(),
				'result'        => $result,
				'source_hash'   => $item->source_hash(),
				'source_id'     => $item->source_id(),
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
				'aggregate_type'       => $this->aggregate_type( $item ),
				'aggregate_public_id'  => $item->job_id(),
				'event_type'           => $event,
				'idempotency_key'      => 'export-event:' . hash( 'sha256', $item->job_id() . '|' . $event ),
				'payload_schema'       => $this->event_schema( $item ),
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
	 * Reject invalid ready records.
	 *
	 * @param Export_Work_Item $item Item.
	 * @param array            $file File.
	 * @param array            $artifact Artifact.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_ready_records( Export_Work_Item $item, array $file, array $artifact ): void {
		$file_keys     = array( 'actor_user_id', 'artifact_kind', 'artifact_public_id', 'byte_size', 'created_at_utc', 'expires_at_utc', 'file_public_id', 'job_public_id', 'mime_type', 'original_name', 'sha256', 'storage_key' );
		$artifact_keys = array( 'artifact_public_id', 'audit_public_id', 'correlation_id', 'details', 'outbox_public_id', 'page_count', 'renderer_version' );
		$this->exact( $file, $file_keys );
		$this->exact( $artifact, $artifact_keys );
		foreach ( array( $file['artifact_public_id'], $file['file_public_id'], $file['job_public_id'], $artifact['artifact_public_id'], $artifact['audit_public_id'], $artifact['outbox_public_id'] ) as $id ) {
			if ( ! is_string( $id ) ) {
				throw new InvalidArgumentException( 'Export completion ID is invalid.' );
			} new Public_Id( $id );
		}
		$direct = 'report_pdf' !== $item->artifact_kind();
		if (
			$item->artifact_kind() !== $file['artifact_kind'] || $item->job_id() !== $file['job_public_id']
			|| $item->actor_user_id() !== $file['actor_user_id'] || $file['artifact_public_id'] !== $artifact['artifact_public_id']
			|| ( $direct && $file['file_public_id'] !== $artifact['artifact_public_id'] )
			|| ( ! $direct && ( ! is_int( $artifact['page_count'] ) || $artifact['page_count'] < 1 || $artifact['page_count'] > 500 ) )
			|| ( $direct && null !== $artifact['page_count'] ) || ! is_array( $artifact['details'] ) || ( array() !== $artifact['details'] && array_is_list( $artifact['details'] ) )
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]{0,31}$/D', (string) $artifact['renderer_version'] )
		) {
			throw new InvalidArgumentException( 'Export completion record is invalid.' );
		}
		$this->utc( (string) $file['created_at_utc'] );
		$this->utc( (string) $file['expires_at_utc'] );
		$this->hash( (string) $file['sha256'] );
	}

	/**
	 * Work lock matches.
	 *
	 * @param Export_Work_Item $item Item.
	 * @return bool
	 */
	private function work_lock_matches( Export_Work_Item $item ): bool {
		return null !== $this->row(
			$this->prepare(
				'SELECT id FROM ' . self::TABLE_JOBS . ' WHERE id = %d AND lock_version = %d AND state = %s AND lease_token = %s AND result_file_public_id IS NULL LIMIT 1 FOR UPDATE',
				array( $item->job_pk(), $item->lock_version(), 'running', $item->lease_token() )
			)
		);
	}
	/**
	 * Artifact for job.
	 *
	 * @param int $job_pk Job pk.
	 * @return array|null
	 */
	private function artifact_for_job( int $job_pk ): ?array {
		return $this->row( $this->prepare( 'SELECT public_id FROM ' . self::TABLE_PDF . ' WHERE export_job_id = %d LIMIT 1 FOR UPDATE', array( $job_pk ) ) );
	}
	/**
	 * Aggregate type.
	 *
	 * @param Export_Work_Item $item Item.
	 * @return string
	 */
	private function aggregate_type( Export_Work_Item $item ): string {
		return 'submission_bundle' === $item->artifact_kind() ? 'submission_bundle_job' : 'report_export_job';
	}
	/**
	 * Event schema.
	 *
	 * @param Export_Work_Item $item Item.
	 * @return string
	 */
	private function event_schema( Export_Work_Item $item ): string {
		return 'submission_bundle' === $item->artifact_kind() ? 'oj_ai.submission_bundle_event' : 'oj_ai.report_export_event';
	}
	/**
	 * Audit schema.
	 *
	 * @param Export_Work_Item $item Item.
	 * @return string
	 */
	private function audit_schema( Export_Work_Item $item ): string {
		return 'submission_bundle' === $item->artifact_kind() ? 'oj_ai.submission_bundle_audit' : 'oj_ai.report_export_audit';
	}

	/**
	 * Artifact kinds.
	 *
	 * @param array<array-key,mixed> $values Values.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function artifact_kinds( array $values ): array {
		if ( array() === $values || ! array_is_list( $values ) ) {
			throw new InvalidArgumentException( 'Export work kind list is invalid.' );
		}
		$result = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || ! in_array( $value, self::KINDS, true ) || isset( $result[ $value ] ) ) {
				throw new InvalidArgumentException( 'Export work kind is invalid.' );
			}
			$result[ $value ] = $value;
		}
		return array_values( $result );
	}
	/**
	 * Exact.
	 *
	 * @param array $value Value.
	 * @param array $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Export completion shape is invalid.' );
		}
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param array  $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->db()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw new RuntimeException( 'Export work storage is unavailable.' );
		} return $prepared;
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
		} if ( ! is_array( $row ) ) {
			throw new RuntimeException( 'Export work storage is unavailable.' );
		} return $row;
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
			throw new RuntimeException( 'Export work storage is unavailable.' );
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
			throw new RuntimeException( 'Export work storage is unavailable.' );
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
			throw new RuntimeException( 'Export work storage is unavailable.' );
		} foreach ( array( 'prepare', 'get_row', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				throw new RuntimeException( 'Export work storage is unavailable.' );
			}
		} return $db;
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
			throw new RuntimeException( 'Export work storage is unavailable.' );
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
			throw new InvalidArgumentException( 'Export work hash is invalid.' );
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
			throw new InvalidArgumentException( 'Export work UTC is invalid.' );
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
			throw new InvalidArgumentException( 'Export work lease is invalid.' );
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
			throw new InvalidArgumentException( 'Export work error code is invalid.' );
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
		} throw new RuntimeException( 'Export work storage is unavailable.' );
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
		} if ( is_string( $value ) && 1 === preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		} throw new RuntimeException( 'Export work storage is unavailable.' );
	}
}
