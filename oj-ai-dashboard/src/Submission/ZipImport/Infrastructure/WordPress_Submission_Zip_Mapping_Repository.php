<?php
/**
 * Literal-table C68 submission ZIP mapping repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\ZipImport\Application\Map_Submission_Zip_Items_Result;
use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Mapping_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commits explicit target and response selections without creating submissions.
 */
final class WordPress_Submission_Zip_Mapping_Repository implements Submission_Zip_Mapping_Repository_Port_Interface {
	private const TABLE_UPLOADS     = 'oj_ai_zip_uploads';
	private const TABLE_ITEMS       = 'oj_ai_zip_upload_items';
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_AUDIT       = 'oj_ai_audit_events';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';
	private const MAPPING_SCHEMA    = 'oj_ai.submission_zip_mapping';
	private const EVENT_TYPE        = 'submission_zip.mapped';
	private const PAYLOAD_SCHEMA    = 'oj_ai.submission_zip_mapped_event';
	private const AUDIT_SCHEMA      = 'oj_ai.submission_zip_mapping_audit';
	private const VERSION           = 1;

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;

	/**
	 * Initialize WordPress submission zip mapping repository dependencies and state.
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
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Map_Submission_Zip_Items_Result|null
	 */
	public function find_receipt( string $idempotency_storage_key, string $request_digest ): ?Map_Submission_Zip_Items_Result {
		$this->storage_key( $idempotency_storage_key );
		$this->hash_argument( $request_digest );
		return $this->receipt( $idempotency_storage_key, $request_digest, false );
	}

	/**
	 * Map WordPress submission zip mapping repository.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Map_Submission_Zip_Items_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function map( array $record ): Map_Submission_Zip_Items_Result {
		$this->assert_record( $record );
		$receipt = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
		if ( $receipt instanceof Map_Submission_Zip_Items_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], true );
			if ( $receipt instanceof Map_Submission_Zip_Items_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$root = $this->lock_root( $record['upload_id'] );
			if ( null === $root || ! $this->same_context( $root, $record ) ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::NOT_FOUND );
			}
			if ( ! in_array( $root['assignment_state'] ?? null, array( 'draft', 'scheduled', 'open', 'closed' ), true ) ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
			}
			if ( ! in_array( $root['upload_state'] ?? null, array( 'ready', 'mapped', 'completed' ), true ) ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
			}
			if ( self::MAPPING_SCHEMA !== ( $root['mapping_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $root['mapping_version'] ?? null ) ) {
				$this->stored_invalid();
			}
			$mapping = $this->mapping( $root );
			if ( $record['expected_mapping_version'] !== $mapping['mapping_revision'] ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
			}

			$items   = $this->lock_items( $this->positive_integer( $root['upload_pk'] ?? null ) );
			$targets = $this->lock_targets( $this->positive_integer( $root['assignment_pk'] ?? null ) );
			$change  = $this->mapping_change( $root, $record, $mapping, $items, $targets );
			$this->update_root( $root, $record, $change['mapping'] );
			$result = $this->result( $root, $record, $change, false );
			$this->insert_audit( $root, $record, $mapping, $result );
			$this->insert_outbox( $record, $result );

			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Zip_Import_Failure $failure ) {
			$this->rollback();
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$receipt = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
			if ( $receipt instanceof Map_Submission_Zip_Items_Result ) {
				return $receipt;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Lock root.
	 *
	 * @param string $upload_id Upload id.
	 * @return array<string,mixed>|null
	 */
	private function lock_root( string $upload_id ): ?array {
		$sql = 'SELECT u.id AS upload_pk, u.public_id AS upload_id, u.lock_version AS upload_lock_version, u.state AS upload_state, u.mapping_schema, u.mapping_version, u.mapping_json, u.mapping_hash, u.item_total, u.success_total, u.failure_total, a.id AS assignment_pk, a.public_id AS assignment_id, a.state AS assignment_state, a.context_type, a.context_id, a.context_revision FROM '
			. self::TABLE_UPLOADS . ' u INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = u.assignment_id '
			. 'WHERE u.public_id = %s LIMIT 1 FOR UPDATE';
		return $this->get_row( $this->prepare( $sql, array( $upload_id ) ) );
	}

	/**
	 * Lock items.
	 *
	 * @param int $upload_pk Upload pk.
	 * @return array<int,array<string,mixed>>
	 */
	private function lock_items( int $upload_pk ): array {
		return $this->get_results(
			$this->prepare(
				'SELECT id, lock_version, item_no, learner_user_id, state, error_code FROM ' . self::TABLE_ITEMS
				. ' WHERE zip_upload_id = %d ORDER BY item_no ASC FOR UPDATE',
				array( $upload_pk )
			)
		);
	}

	/**
	 * Lock targets.
	 *
	 * @param int $assignment_pk Assignment pk.
	 * @return array<string,array{learner_user_id:int,state:string}>
	 */
	private function lock_targets( int $assignment_pk ): array {
		$rows   = $this->get_results(
			$this->prepare(
				'SELECT public_id AS target_id, learner_user_id, state FROM ' . self::TABLE_TARGETS
				. ' WHERE assignment_id = %d ORDER BY public_id ASC FOR UPDATE',
				array( $assignment_pk )
			)
		);
		$result = array();
		foreach ( $rows as $row ) {
			$target_id = $this->public_id( $row['target_id'] ?? null );
			if ( isset( $result[ $target_id ] ) || ! in_array( $row['state'] ?? null, array( 'active', 'removed' ), true ) ) {
				$this->stored_invalid();
			}
			$result[ $target_id ] = array(
				'learner_user_id' => $this->positive_integer( $row['learner_user_id'] ?? null ),
				'state'           => (string) $row['state'],
			);
		}
		return $result;
	}

	/**
	 * Mapping change.
	 *
	 * @param array<string,mixed>                                   $root Root.
	 * @param array<string,mixed>                                   $record Record.
	 * @param array<string,mixed>                                   $mapping Mapping.
	 * @param array<int,array<string,mixed>>                        $items Items.
	 * @param array<string,array{learner_user_id:int,state:string}> $targets Targets.
	 * @return array{mapping:array<string,mixed>,items:array<int,array<string,mixed>>,mapped_total:int,excluded_total:int,imported_total:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function mapping_change( array $root, array $record, array $mapping, array $items, array $targets ): array {
		$item_total    = $this->non_negative_integer( $root['item_total'] ?? null );
		$success_total = $this->non_negative_integer( $root['success_total'] ?? null );
		$failure_total = $this->non_negative_integer( $root['failure_total'] ?? null );
		if ( count( $items ) !== $item_total || count( $mapping['items'] ) !== $item_total || $success_total + $failure_total !== $item_total ) {
			$this->stored_invalid();
		}

		$decisions = array();
		foreach ( $record['items'] as $decision ) {
			$decisions[ $decision['item_no'] ] = $decision;
		}
		$mapped_items   = array();
		$result_items   = array();
		$mapped_total   = 0;
		$excluded_total = 0;
		$imported_total = 0;

		foreach ( $items as $index => $row ) {
			$item_no = $this->positive_integer( $row['item_no'] ?? null );
			if ( $item_no !== $index + 1 || $mapping['items'][ $index ]['item_no'] !== $item_no ) {
				$this->stored_invalid();
			}
			$state = (string) ( $row['state'] ?? '' );
			if ( ! in_array( $state, array( 'ready', 'mapped', 'failed', 'rejected', 'excluded', 'imported' ), true ) ) {
				$this->stored_invalid();
			}
			$error        = $row['error_code'] ?? null;
			$mapping_item = $mapping['items'][ $index ];

			if ( in_array( $state, array( 'ready', 'mapped', 'failed' ), true ) ) {
				$decision = $decisions[ $item_no ] ?? null;
				if ( ! is_array( $decision ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Zip_Import_Failure::because( Zip_Import_Failure::MAPPING_INVALID );
				}
				$target = $targets[ $decision['target_id'] ] ?? null;
				if (
					! is_array( $target ) || 'active' !== $target['state']
					|| $target['learner_user_id'] !== $decision['learner_user_id']
					|| ! in_array( $decision['response_id'], $mapping_item['compatible_response_ids'], true )
				) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Zip_Import_Failure::because( Zip_Import_Failure::MAPPING_INVALID );
				}
				$selection                 = array(
					'learner_user_id' => $decision['learner_user_id'],
					'response_id'     => $decision['response_id'],
					'target_id'       => $decision['target_id'],
				);
				$mapping_item['selection'] = $selection;
				$this->update_item( $row, $record, 'mapped', $decision['learner_user_id'] );
				$result_items[] = array(
					'error_code' => null,
					'item_no'    => $item_no,
					'selection'  => $selection,
					'state'      => 'mapped',
				);
				++$mapped_total;
				unset( $decisions[ $item_no ] );
			} elseif ( in_array( $state, array( 'rejected', 'excluded' ), true ) ) {
				if ( isset( $decisions[ $item_no ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Zip_Import_Failure::because( Zip_Import_Failure::MAPPING_INVALID );
				}
				$this->reason( $error );
				$mapping_item['selection'] = null;
				$this->update_item( $row, $record, 'excluded', null );
				$result_items[] = array(
					'error_code' => $error,
					'item_no'    => $item_no,
					'selection'  => null,
					'state'      => 'excluded',
				);
				++$excluded_total;
			} else {
				if ( isset( $decisions[ $item_no ] ) || null !== $error || null === $mapping_item['selection'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Zip_Import_Failure::because( Zip_Import_Failure::MAPPING_INVALID );
				}
				$result_items[] = array(
					'error_code' => null,
					'item_no'    => $item_no,
					'selection'  => $mapping_item['selection'],
					'state'      => 'imported',
				);
				++$imported_total;
			}
			$mapped_items[] = $mapping_item;
		}

		if ( array() !== $decisions || $mapped_total + $imported_total < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::MAPPING_INVALID );
		}
		return array(
			'excluded_total' => $excluded_total,
			'imported_total' => $imported_total,
			'items'          => $result_items,
			'mapped_total'   => $mapped_total,
			'mapping'        => array(
				'items'            => $mapped_items,
				'mapping_revision' => $record['expected_mapping_version'] + 1,
				'scan_error_code'  => null,
				'source'           => $mapping['source'],
			),
		);
	}

	/**
	 * Update item.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param array<string,mixed> $record Record.
	 * @param string              $state State.
	 * @param int|null            $learner_user_id Learner user id.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_item( array $row, array $record, string $state, ?int $learner_user_id ): void {
		$id            = $this->positive_integer( $row['id'] ?? null );
		$lock          = $this->non_negative_integer( $row['lock_version'] ?? null );
		$current_state = (string) ( $row['state'] ?? '' );
		if ( null === $learner_user_id ) {
			$sql    = 'UPDATE ' . self::TABLE_ITEMS . ' SET learner_user_id = NULL, state = %s, lock_version = lock_version + 1, '
				. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND state = %s';
			$values = array( $state, $record['actor_user_id'], $record['occurred_at_utc'], $id, $lock, $current_state );
		} else {
			$sql    = 'UPDATE ' . self::TABLE_ITEMS . ' SET learner_user_id = %d, state = %s, error_code = NULL, lock_version = lock_version + 1, '
				. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND state = %s';
			$values = array( $learner_user_id, $state, $record['actor_user_id'], $record['occurred_at_utc'], $id, $lock, $current_state );
		}
		if ( 1 !== $this->change_count( $this->prepare( $sql, $values ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
		}
	}

	/**
	 * Update root.
	 *
	 * @param array<string,mixed> $root Root.
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $mapping Mapping.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_root( array $root, array $record, array $mapping ): void {
		$mapping_json = $this->canonical->encode( $mapping );
		$mapping_hash = hash( 'sha256', $mapping_json );
		$sql          = 'UPDATE ' . self::TABLE_UPLOADS . ' SET state = %s, mapping_json = %s, mapping_hash = %s, '
			. 'lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
			. 'WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s AND mapping_schema = %s '
			. 'AND mapping_version = %d AND mapping_hash = %s';
		$changed      = $this->change_count(
			$this->prepare(
				$sql,
				array(
					'mapped',
					$mapping_json,
					$mapping_hash,
					$record['actor_user_id'],
					$record['occurred_at_utc'],
					$this->positive_integer( $root['upload_pk'] ?? null ),
					$record['upload_id'],
					$this->non_negative_integer( $root['upload_lock_version'] ?? null ),
					$root['upload_state'],
					self::MAPPING_SCHEMA,
					self::VERSION,
					$this->hash_value( $root['mapping_hash'] ?? null ),
				)
			)
		);
		if ( 1 !== $changed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
		}
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed> $root Root.
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $change Change.
	 * @param bool                $replayed Replayed.
	 * @return Map_Submission_Zip_Items_Result
	 */
	private function result( array $root, array $record, array $change, bool $replayed ): Map_Submission_Zip_Items_Result {
		return new Map_Submission_Zip_Items_Result(
			array(
				'assignment_id'   => $this->public_id( $root['assignment_id'] ?? null ),
				'excluded_total'  => $change['excluded_total'],
				'id'              => $record['upload_id'],
				'imported_total'  => $change['imported_total'],
				'item_total'      => count( $change['items'] ),
				'mapped_total'    => $change['mapped_total'],
				'mapping_version' => $change['mapping']['mapping_revision'],
				'state'           => 'mapped',
			),
			$change['items'],
			$record['actor_user_id'],
			$record['occurred_at_utc'],
			$replayed
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed>             $root Root.
	 * @param array<string,mixed>             $record Record.
	 * @param array<string,mixed>             $before_mapping Before mapping.
	 * @param Map_Submission_Zip_Items_Result $result Result.
	 * @return void
	 */
	private function insert_audit( array $root, array $record, array $before_mapping, Map_Submission_Zip_Items_Result $result ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->hash_value( $previous );
		}
		$before        = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'mapping_version' => $before_mapping['mapping_revision'],
					'state'           => $root['upload_state'],
				)
			)
		);
		$after         = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'items'  => $result->items(),
					'upload' => $result->upload(),
				)
			)
		);
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'               => $record['command_id'],
				'expected_mapping_version' => $record['expected_mapping_version'],
				'excluded_total'           => $result->upload()['excluded_total'],
				'mapped_total'             => $result->upload()['mapped_total'],
				'request_digest'           => $record['request_digest'],
				'result_mapping_version'   => $result->upload()['mapping_version'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::EVENT_TYPE,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $after,
					'before_digest'       => $before,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['upload_id'],
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
				'object_public_id'    => $record['upload_id'],
				'context_type'        => $root['context_type'],
				'context_id'          => $root['context_id'],
				'context_revision'    => $record['authorized_context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $before,
				'after_digest'        => $after,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous ? null : (string) $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param array<string,mixed>             $record Record.
	 * @param Map_Submission_Zip_Items_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Map_Submission_Zip_Items_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'request_digest' => $record['request_digest'],
				'result'         => array(
					'items'             => $result->items(),
					'mapped_at_utc'     => $result->mapped_at_utc(),
					'mapped_by_user_id' => $result->mapped_by_user_id(),
					'upload'            => $result->upload(),
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
				'aggregate_public_id'  => $record['upload_id'],
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
	 * @return Map_Submission_Zip_Items_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $storage_key, string $request_digest, bool $for_update ): ?Map_Submission_Zip_Items_Result {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			'submission_zip' !== ( $row['aggregate_type'] ?? null ) || self::EVENT_TYPE !== ( $row['event_type'] ?? null )
			|| self::PAYLOAD_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::IDEMPOTENCY_CONFLICT );
		}
		$payload = $this->decode_json( (string) ( $row['payload_json'] ?? '' ), $this->hash_value( $row['payload_hash'] ?? null ) );
		$this->exact_keys( $payload, array( 'request_digest', 'result' ) );
		if ( ! is_string( $payload['request_digest'] ?? null ) || ! hash_equals( $request_digest, $payload['request_digest'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $payload['result'] ?? null;
		if ( ! is_array( $result ) || array_is_list( $result ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $result, array( 'items', 'mapped_at_utc', 'mapped_by_user_id', 'upload' ) );
		if ( ! is_array( $result['upload'] ?? null ) || ! is_array( $result['items'] ?? null ) || ( $row['aggregate_public_id'] ?? null ) !== ( $result['upload']['id'] ?? null ) ) {
			$this->stored_invalid();
		}
		try {
			return new Map_Submission_Zip_Items_Result(
				$result['upload'],
				$result['items'],
				$result['mapped_by_user_id'],
				$result['mapped_at_utc'],
				true
			);
		} catch ( Throwable ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Mapping.
	 *
	 * @param array<string,mixed> $root Root.
	 * @return array<string,mixed>
	 */
	private function mapping( array $root ): array {
		$mapping = $this->decode_json( (string) ( $root['mapping_json'] ?? '' ), $this->hash_value( $root['mapping_hash'] ?? null ) );
		$this->exact_keys( $mapping, array( 'items', 'mapping_revision', 'scan_error_code', 'source' ) );
		if ( null !== $mapping['scan_error_code'] || ! is_array( $mapping['items'] ?? null ) || ! array_is_list( $mapping['items'] ) ) {
			$this->stored_invalid();
		}
		$revision = $this->non_negative_integer( $mapping['mapping_revision'] ?? null );
		$items    = array();
		foreach ( $mapping['items'] as $index => $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				$this->stored_invalid();
			}
			$actual = array_keys( $item );
			sort( $actual, SORT_STRING );
			$old     = array( 'compatible_response_ids', 'detected_mime_type', 'item_no', 'suggestion' );
			$current = array( 'compatible_response_ids', 'detected_mime_type', 'item_no', 'selection', 'suggestion' );
			if ( $actual !== $old && $actual !== $current ) {
				$this->stored_invalid();
			}
			$item_no = $this->positive_integer( $item['item_no'] ?? null );
			if ( $item_no !== $index + 1 || ! is_array( $item['compatible_response_ids'] ?? null ) || ! array_is_list( $item['compatible_response_ids'] ) ) {
				$this->stored_invalid();
			}
			$responses = array();
			foreach ( $item['compatible_response_ids'] as $response_id ) {
				$response_id = $this->public_id( $response_id );
				if ( isset( $responses[ $response_id ] ) ) {
					$this->stored_invalid();
				}
				$responses[ $response_id ] = true;
			}
			$mime = $item['detected_mime_type'] ?? null;
			if ( null !== $mime ) {
				$this->mime( $mime );
			}
			$suggestion = $this->suggestion( $item['suggestion'] ?? null );
			$selection  = array_key_exists( 'selection', $item ) ? $this->selection( $item['selection'] ) : null;
			$items[]    = array(
				'compatible_response_ids' => array_keys( $responses ),
				'detected_mime_type'      => $mime,
				'item_no'                 => $item_no,
				'selection'               => $selection,
				'suggestion'              => $suggestion,
			);
		}
		$source = $this->source( $mapping['source'] ?? null );
		return array(
			'items'            => $items,
			'mapping_revision' => $revision,
			'scan_error_code'  => null,
			'source'           => $source,
		);
	}

	/**
	 * Source.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 */
	private function source( mixed $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $value, array( 'byte_size', 'compressed_entry_bytes', 'entry_count', 'sha256', 'uncompressed_bytes' ) );
		$this->positive_integer( $value['byte_size'] ?? null );
		$this->non_negative_integer( $value['compressed_entry_bytes'] ?? null );
		$this->positive_integer( $value['entry_count'] ?? null );
		$this->hash_value( $value['sha256'] ?? null );
		$this->non_negative_integer( $value['uncompressed_bytes'] ?? null );
		return $value;
	}

	/**
	 * Suggestion.
	 *
	 * @param mixed $value Value.
	 * @return array{learner:?array<string,mixed>,response:?array<string,mixed>}
	 */
	private function suggestion( mixed $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $value, array( 'learner', 'response' ) );
		$learner = $value['learner'];
		if ( null !== $learner ) {
			if ( ! is_array( $learner ) || array_is_list( $learner ) ) {
				$this->stored_invalid();
			}
			$this->exact_keys( $learner, array( 'learner_user_id', 'method', 'target_id' ) );
			$learner = array(
				'learner_user_id' => $this->positive_integer( $learner['learner_user_id'] ?? null ),
				'method'          => $this->suggestion_method( $learner['method'] ?? null ),
				'target_id'       => $this->public_id( $learner['target_id'] ?? null ),
			);
		}
		$response = $value['response'];
		if ( null !== $response ) {
			if ( ! is_array( $response ) || array_is_list( $response ) ) {
				$this->stored_invalid();
			}
			$this->exact_keys( $response, array( 'method', 'response_id' ) );
			$response = array(
				'method'      => $this->suggestion_method( $response['method'] ?? null ),
				'response_id' => $this->public_id( $response['response_id'] ?? null ),
			);
		}
		return array(
			'learner'  => $learner,
			'response' => $response,
		);
	}

	/**
	 * Selection.
	 *
	 * @param mixed $value Value.
	 * @return array{learner_user_id:int,response_id:string,target_id:string}|null
	 */
	private function selection( mixed $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $value, array( 'learner_user_id', 'response_id', 'target_id' ) );
		return array(
			'learner_user_id' => $this->positive_integer( $value['learner_user_id'] ?? null ),
			'response_id'     => $this->public_id( $value['response_id'] ?? null ),
			'target_id'       => $this->public_id( $value['target_id'] ?? null ),
		);
	}

	/**
	 * Same context.
	 *
	 * @param array<string,mixed> $root Root.
	 * @param array<string,mixed> $record Record.
	 * @return bool
	 */
	private function same_context( array $root, array $record ): bool {
		return $record['authorized_context_type'] === ( $root['context_type'] ?? null )
			&& $record['authorized_context_id'] === ( $root['context_id'] ?? null )
			&& $record['authorized_context_revision'] === ( $root['context_revision'] ?? null );
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
				'audit_public_id',
				'authorized_context_id',
				'authorized_context_revision',
				'authorized_context_type',
				'command_id',
				'correlation_id',
				'expected_mapping_version',
				'idempotency_storage_key',
				'items',
				'occurred_at_utc',
				'outbox_public_id',
				'request_digest',
				'upload_id',
			)
		);
		if ( ! is_int( $record['actor_user_id'] ?? null ) || $record['actor_user_id'] < 1 || ! is_int( $record['expected_mapping_version'] ?? null ) || $record['expected_mapping_version'] < 1 ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping record identity is invalid.' );
		}
		foreach ( array( 'upload_id', 'audit_public_id', 'outbox_public_id' ) as $field ) {
			$this->public_id( $record[ $field ] ?? null );
		}
		foreach ( array(
			'authorized_context_type'     => 16,
			'authorized_context_id'       => 191,
			'authorized_context_revision' => 191,
		) as $field => $maximum ) {
			$this->context_value( $record[ $field ] ?? null, $maximum );
		}
		$this->token( $record['command_id'] ?? null, 128 );
		$this->token( $record['correlation_id'] ?? null, 128 );
		$this->storage_key( $record['idempotency_storage_key'] ?? null );
		$this->hash_argument( $record['request_digest'] ?? null );
		$this->required_utc( $record['occurred_at_utc'] ?? null );
		$items = $record['items'] ?? null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) || array() === $items || count( $items ) > 500 ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping record items are invalid.' );
		}
		$last = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Submission ZIP mapping record item is invalid.' );
			}
			$this->exact_keys( $item, array( 'item_no', 'learner_user_id', 'response_id', 'target_id' ) );
			if ( ! is_int( $item['item_no'] ?? null ) || $item['item_no'] <= $last || ! is_int( $item['learner_user_id'] ?? null ) || $item['learner_user_id'] < 1 ) {
				throw new InvalidArgumentException( 'Submission ZIP mapping record item identity is invalid.' );
			}
			$last = $item['item_no'];
			$this->public_id( $item['target_id'] ?? null );
			$this->public_id( $item['response_id'] ?? null );
		}
	}

	/**
	 * Decode json.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 */
	private function decode_json( string $json, string $hash ): array {
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
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping object keys are invalid.' );
		}
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function public_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			$this->stored_invalid();
		}
		try {
			return ( new Public_Id( $value ) )->value();
		} catch ( Throwable ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 1 ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Hash argument.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_argument( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping hash is invalid.' );
		}
		return $value;
	}

	/**
	 * Storage key.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function storage_key( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^submission-zip-map:[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping idempotency key is invalid.' );
		}
		return $value;
	}

	/**
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping token is invalid.' );
		}
		return $value;
	}

	/**
	 * Context value.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_value( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping Context value is invalid.' );
		}
		return $value;
	}

	/**
	 * Required utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function required_utc( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping UTC value is invalid.' );
		}
		return $value;
	}

	/**
	 * Mime.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function mime( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function reason( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:zip_[a-z0-9_]{2,95}|submission_official_exists)$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Suggestion method.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function suggestion_method( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'exact_path_token', 'single_compatible' ), true ) ) {
			$this->stored_invalid();
		}
		return $value;
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
	 * @param string $sql Sql.
	 * @param array  $values Values.
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
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_results( string $sql ): array {
		$db = $this->database();
		$this->reset_error( $db );
		$rows = $db->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_database_ok( $db );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $rows;
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
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_or_fail( string $sql ): void {
		$db = $this->database();
		$this->reset_error( $db );
		$result = $db->query( $sql );
		$this->assert_database_ok( $db );
		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Change count.
	 *
	 * @param string $sql Sql.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function change_count( string $sql ): int {
		$db = $this->database();
		$this->reset_error( $db );
		$result = $db->query( $sql );
		$this->assert_database_ok( $db );
		if ( ! is_int( $result ) || $result < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
		return $result;
	}

	/**
	 * Insert or fail.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_or_fail( string $table, array $record ): void {
		$db = $this->database();
		$this->reset_error( $db );
		$inserted = $db->insert( $table, $record );
		$this->assert_database_ok( $db );
		if ( false === $inserted || 1 !== (int) $inserted ) {
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
			$this->query_or_fail( 'ROLLBACK' ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
			/* The original failure remains authoritative. */
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
		foreach ( array( 'prepare', 'get_row', 'get_results', 'get_var', 'query', 'insert' ) as $method ) {
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
