<?php
/**
 * WordPress submission ZIP staging repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\ZipImport\Application\Stage_Submission_Zip_Result;
use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns literal oj_ai_zip_uploads writes and their operation evidence.
 */
final class WordPress_Submission_Zip_Repository implements Submission_Zip_Repository_Port_Interface {
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_UPLOADS     = 'oj_ai_zip_uploads';
	private const TABLE_AUDIT       = 'oj_ai_audit_events';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';

	private const EVENT_TYPE     = 'submission_zip.staged';
	private const PAYLOAD_SCHEMA = 'oj_ai.submission_zip_staged_event';
	private const MAPPING_SCHEMA = 'oj_ai.submission_zip_mapping';
	private const AUDIT_SCHEMA   = 'oj_ai.submission_zip_audit';
	private const VERSION        = 1;

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;

	/**
	 * Initialize WordPress submission zip repository dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private ?object $database = null, ?Canonical_Json $canonical = null ) {
		$this->canonical = $canonical ?? new Canonical_Json();
	}

	/**
	 * Find receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Stage_Submission_Zip_Result|null
	 */
	public function find_receipt( string $storage_key, string $request_digest ): ?Stage_Submission_Zip_Result {
		$this->assert_storage_key( $storage_key );
		$this->assert_hash( $request_digest );
		return $this->receipt( $storage_key, $request_digest, false );
	}

	/**
	 * Stage.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Stage_Submission_Zip_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function stage( array $record ): Stage_Submission_Zip_Result {
		$this->assert_record( $record );
		$replay = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
		if ( $replay instanceof Stage_Submission_Zip_Result ) {
			return $replay;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$replay      = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], true );
			if ( $replay instanceof Stage_Submission_Zip_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $replay;
			}

			$assignment = $this->lock_assignment( $record['assignment_public_id'] );
			$this->assert_assignment( $assignment, $record );
			$mapping_json = $this->canonical->encode(
				array(
					'items'            => array(),
					'mapping_revision' => 0,
					'source'           => array(
						'byte_size'              => $record['byte_size'],
						'compressed_entry_bytes' => $record['compressed_entry_bytes'],
						'entry_count'            => $record['entry_count'],
						'sha256'                 => $record['sha256'],
						'uncompressed_bytes'     => $record['uncompressed_bytes'],
					),
				)
			);
			$this->insert_or_fail(
				self::TABLE_UPLOADS,
				array(
					'public_id'           => $record['upload_public_id'],
					'lock_version'        => 0,
					'created_by_user_id'  => $record['actor_user_id'],
					'created_at_utc'      => $record['occurred_at_utc'],
					'updated_by_user_id'  => $record['actor_user_id'],
					'updated_at_utc'      => $record['occurred_at_utc'],
					'assignment_id'       => $this->positive_integer( $assignment['id'] ?? null ),
					'uploaded_by_user_id' => $record['actor_user_id'],
					'file_public_id'      => $record['file_public_id'],
					'state'               => 'scanning',
					'idempotency_key'     => $record['idempotency_storage_key'],
					'mapping_schema'      => self::MAPPING_SCHEMA,
					'mapping_version'     => self::VERSION,
					'mapping_json'        => $mapping_json,
					'mapping_hash'        => hash( 'sha256', $mapping_json ),
					'item_total'          => 0,
					'success_total'       => 0,
					'failure_total'       => 0,
					'cleanup_after_utc'   => null,
				)
			);

			$result = $this->result( $record, false );
			$this->insert_audit( $record, $result );
			$this->insert_outbox( $record, $result );
			$this->assert_postcondition( $record, $assignment );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Zip_Import_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$replay = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
			if ( $replay instanceof Stage_Submission_Zip_Result ) {
				return $replay;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Lock assignment.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @return array<string,mixed>|null
	 */
	private function lock_assignment( string $assignment_id ): ?array {
		return $this->get_row(
			$this->prepare(
				'SELECT id, public_id, context_type, context_id, context_revision, state, schedule_version FROM '
				. self::TABLE_ASSIGNMENTS . ' WHERE public_id = %s LIMIT 1 FOR UPDATE',
				array( $assignment_id )
			)
		);
	}

	/**
	 * Reject invalid assignment.
	 *
	 * @param array<string,mixed>|null $assignment Assignment.
	 * @param array<string,mixed>      $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_assignment( ?array $assignment, array $record ): void {
		if (
			null === $assignment
			|| $record['assignment_public_id'] !== ( $assignment['public_id'] ?? null )
			|| $record['authorized_context_type'] !== ( $assignment['context_type'] ?? null )
			|| $record['authorized_context_id'] !== ( $assignment['context_id'] ?? null )
			|| $record['authorized_context_revision'] !== ( $assignment['context_revision'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::NOT_FOUND );
		}
		if (
			$record['expected_state'] !== ( $assignment['state'] ?? null )
			|| $record['expected_schedule_version'] !== $this->positive_integer( $assignment['schedule_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
		}
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Stage_Submission_Zip_Result
	 */
	private function result( array $record, bool $replayed ): Stage_Submission_Zip_Result {
		return new Stage_Submission_Zip_Result(
			$record['upload_public_id'],
			$record['assignment_public_id'],
			$record['file_public_id'],
			'scanning',
			$record['original_name'],
			$record['byte_size'],
			$record['sha256'],
			$record['entry_count'],
			$record['uncompressed_bytes'],
			$record['occurred_at_utc'],
			$record['expires_at_utc'],
			$replayed
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed>         $record Record.
	 * @param Stage_Submission_Zip_Result $result Result.
	 * @return void
	 */
	private function insert_audit( array $record, Stage_Submission_Zip_Result $result ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->assert_hash( (string) $previous );
		}
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'entry_count'    => $record['entry_count'],
				'request_digest' => $record['request_digest'],
				'source_bytes'   => $record['byte_size'],
				'source_sha256'  => $record['sha256'],
			)
		);
		$after         = hash( 'sha256', $this->canonical->encode( $result->upload() ) );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::EVENT_TYPE,
					'after_digest'        => $after,
					'before_digest'       => null,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => hash( 'sha256', $metadata_json ),
					'object_public_id'    => $record['upload_public_id'],
					'object_type'         => 'submission_zip',
					'occurred_at_utc'     => $record['occurred_at_utc'],
					'previous_event_hash' => null === $previous ? null : (string) $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['occurred_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => self::EVENT_TYPE,
				'object_type'         => 'submission_zip',
				'object_public_id'    => $record['upload_public_id'],
				'context_type'        => $record['authorized_context_type'],
				'context_id'          => $record['authorized_context_id'],
				'context_revision'    => $record['authorized_context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => null,
				'after_digest'        => $after,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => hash( 'sha256', $metadata_json ),
				'previous_event_hash' => null === $previous ? null : (string) $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param array<string,mixed>         $record Record.
	 * @param Stage_Submission_Zip_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Stage_Submission_Zip_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'request_digest' => $record['request_digest'],
				'result'         => array(
					'expires_at_utc' => $result->expires_at_utc(),
					'upload'         => $result->upload(),
				),
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
				'aggregate_type'       => 'submission_zip',
				'aggregate_public_id'  => $record['upload_public_id'],
				'event_type'           => self::EVENT_TYPE,
				'idempotency_key'      => $record['idempotency_storage_key'],
				'payload_schema'       => self::PAYLOAD_SCHEMA,
				'payload_version'      => self::VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['occurred_at_utc'],
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @param bool   $for_update For update.
	 * @return Stage_Submission_Zip_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $storage_key, string $request_digest, bool $for_update ): ?Stage_Submission_Zip_Result {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM '
			. self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			'submission_zip' !== ( $row['aggregate_type'] ?? null )
			|| self::EVENT_TYPE !== ( $row['event_type'] ?? null )
			|| self::PAYLOAD_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| self::VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::IDEMPOTENCY_CONFLICT );
		}
		$payload = $this->decode_json( (string) ( $row['payload_json'] ?? '' ), (string) ( $row['payload_hash'] ?? '' ) );
		$this->exact_keys( $payload, array( 'request_digest', 'result' ) );
		if ( ! is_string( $payload['request_digest'] ) || ! hash_equals( $request_digest, $payload['request_digest'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $payload['result'] ?? null;
		if ( ! is_array( $result ) || array_is_list( $result ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $result, array( 'expires_at_utc', 'upload' ) );
		$upload = $result['upload'] ?? null;
		if ( ! is_array( $upload ) || array_is_list( $upload ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $upload, array( 'assignment_id', 'byte_size', 'created_at_utc', 'entry_count', 'file_id', 'id', 'original_name', 'sha256', 'state', 'uncompressed_bytes' ) );
		if ( ( $row['aggregate_public_id'] ?? null ) !== ( $upload['id'] ?? null ) ) {
			$this->stored_invalid();
		}
		try {
			return new Stage_Submission_Zip_Result(
				(string) $upload['id'],
				(string) $upload['assignment_id'],
				(string) $upload['file_id'],
				(string) $upload['state'],
				(string) $upload['original_name'],
				$this->positive_integer( $upload['byte_size'] ?? null ),
				(string) $upload['sha256'],
				$this->positive_integer( $upload['entry_count'] ?? null ),
				$this->non_negative_integer( $upload['uncompressed_bytes'] ?? null ),
				(string) $upload['created_at_utc'],
				(string) $result['expires_at_utc'],
				true
			);
		} catch ( Throwable ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $assignment Assignment.
	 * @return void
	 */
	private function assert_postcondition( array $record, array $assignment ): void {
		$row = $this->get_row(
			$this->prepare(
				'SELECT public_id, assignment_id, file_public_id, state, idempotency_key, mapping_schema, mapping_version, item_total, success_total, failure_total, cleanup_after_utc FROM '
				. self::TABLE_UPLOADS . ' WHERE public_id = %s LIMIT 1 FOR UPDATE',
				array( $record['upload_public_id'] )
			)
		);
		if (
			null === $row
			|| $record['upload_public_id'] !== ( $row['public_id'] ?? null )
			|| $this->positive_integer( $assignment['id'] ?? null ) !== $this->positive_integer( $row['assignment_id'] ?? null )
			|| $record['file_public_id'] !== ( $row['file_public_id'] ?? null )
			|| 'scanning' !== ( $row['state'] ?? null )
			|| $record['idempotency_storage_key'] !== ( $row['idempotency_key'] ?? null )
			|| self::MAPPING_SCHEMA !== ( $row['mapping_schema'] ?? null )
			|| self::VERSION !== $this->positive_integer( $row['mapping_version'] ?? null )
			|| 0 !== $this->non_negative_integer( $row['item_total'] ?? null )
			|| 0 !== $this->non_negative_integer( $row['success_total'] ?? null )
			|| 0 !== $this->non_negative_integer( $row['failure_total'] ?? null )
			|| null !== ( $row['cleanup_after_utc'] ?? null )
		) {
			$this->stored_invalid();
		}
	}

	/**
	 * Reject invalid record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_record( array $record ): void {
		$this->exact_keys(
			$record,
			array(
				'actor_user_id',
				'assignment_public_id',
				'audit_public_id',
				'authorized_context_id',
				'authorized_context_revision',
				'authorized_context_type',
				'byte_size',
				'command_id',
				'compressed_entry_bytes',
				'correlation_id',
				'entry_count',
				'expected_schedule_version',
				'expected_state',
				'expires_at_utc',
				'file_public_id',
				'idempotency_storage_key',
				'occurred_at_utc',
				'original_name',
				'outbox_public_id',
				'request_digest',
				'sha256',
				'uncompressed_bytes',
				'upload_public_id',
			)
		);
		foreach ( array( 'assignment_public_id', 'audit_public_id', 'file_public_id', 'outbox_public_id', 'upload_public_id' ) as $field ) {
			new Public_Id( (string) $record[ $field ] );
		}
		foreach ( array( 'idempotency_storage_key', 'request_digest', 'sha256' ) as $field ) {
			$this->assert_hash( (string) $record[ $field ] );
		}
		if (
			! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1
			|| ! is_int( $record['byte_size'] ) || $record['byte_size'] < 22
			|| ! is_int( $record['compressed_entry_bytes'] ) || $record['compressed_entry_bytes'] < 0
			|| ! is_int( $record['entry_count'] ) || $record['entry_count'] < 1
			|| ! is_int( $record['uncompressed_bytes'] ) || $record['uncompressed_bytes'] < 0
			|| ! is_int( $record['expected_schedule_version'] ) || $record['expected_schedule_version'] < 1
			|| ! in_array( $record['expected_state'], array( 'draft', 'scheduled', 'open', 'closed' ), true )
			|| ! is_string( $record['original_name'] ) || '' === $record['original_name'] || strlen( $record['original_name'] ) > 255
		) {
			throw new InvalidArgumentException( 'Submission ZIP stage record values are invalid.' );
		}
		foreach ( array(
			'authorized_context_type'     => 16,
			'authorized_context_id'       => 191,
			'authorized_context_revision' => 191,
			'command_id'                  => 128,
			'correlation_id'              => 128,
		) as $field => $maximum ) {
			$value = $record[ $field ] ?? null;
			if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				throw new InvalidArgumentException( 'Submission ZIP stage record token is invalid.' );
			}
		}
		$this->assert_utc( (string) $record['occurred_at_utc'] );
		$this->assert_utc( (string) $record['expires_at_utc'] );
	}

	/**
	 * Decode json.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 */
	private function decode_json( string $json, string $hash ): array {
		$this->assert_hash( $hash );
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			$this->stored_invalid();
		}
		try {
			$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			$this->stored_invalid();
			}
			if ( ! is_array( $decoded ) || array_is_list( $decoded ) || $this->canonical->encode( $decoded ) !== $json ) {
				$this->stored_invalid();
			}
			return $decoded;
	}

	/**
	 * Reject unexpected keys before treating an input array as a validated object.
	 *
	 * @param array<array-key,mixed> $value Unvalidated input fields.
	 * @param array<int,string>      $expected Required field names.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		if ( array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP object must not be a list.' );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Submission ZIP object keys are invalid.' );
		}
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( mixed $value ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) {
			$this->stored_invalid();
		}
		$integer = (int) $value;
		if ( $integer < 1 ) {
			$this->stored_invalid();
		}
		return $integer;
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) ) ) {
			$this->stored_invalid();
		}
		$integer = (int) $value;
		if ( $integer < 0 ) {
			$this->stored_invalid();
		}
		return $integer;
	}

	/**
	 * Reject invalid hash.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_hash( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP hash is invalid.' );
		}
	}

	/**
	 * Reject invalid storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 */
	private function assert_storage_key( string $value ): void {
		$this->assert_hash( $value );
	}

	/**
	 * Reject invalid utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP UTC value is invalid.' );
		}
	}

	/**
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Zip_Import_Failure::because( Zip_Import_Failure::STORED_DATA_INVALID );
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
		$prepared = $this->database()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}

	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_row( string $sql ): ?array {
		$db = $this->database();
		$this->reset_error( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_database_ok( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function get_var( string $sql ): mixed {
		$db = $this->database();
		$this->reset_error( $db );
		$value = $db->get_var( $sql );
		$this->assert_database_ok( $db );
		return $value;
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
		$db = $this->database();
		$this->reset_error( $db );
		if ( false === $db->insert( $table, $data ) || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
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
		$db = $this->database();
		$this->reset_error( $db );
		if ( false === $db->query( $sql ) || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->database()->query( 'ROLLBACK' ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// Best-effort recovery must not replace the primary failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
			}
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
	}

	/**
	 * Reset error.
	 *
	 * @param object $db Db.
	 * @return void
	 */
	private function reset_error( object $db ): void {
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
	}

	/**
	 * Reject invalid database ok.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_database_ok( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
