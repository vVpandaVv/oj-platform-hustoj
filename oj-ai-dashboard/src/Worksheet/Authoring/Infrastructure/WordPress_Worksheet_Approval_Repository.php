<?php
/**
 * WordPress worksheet approval repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Application\Approve_Worksheet_Revision_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Approval_Repository_Port_Interface;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress worksheet approval repository.
 */
final class WordPress_Worksheet_Approval_Repository implements Worksheet_Approval_Repository_Port_Interface {
	private const TABLE_WORKSHEETS    = 'oj_ai_worksheets';
	private const TABLE_REVISIONS     = 'oj_ai_worksheet_revisions';
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';
	private const TABLE_POLICIES      = 'oj_ai_evaluation_policy_revisions';
	private const TABLE_RUBRICS       = 'oj_ai_rubric_revisions';
	private const TABLE_REVIEW_RULES  = 'oj_ai_review_rule_revisions';
	private const TABLE_EVALUATIONS   = 'oj_ai_worksheet_evaluations';
	private const TABLE_AUDIT         = 'oj_ai_audit_events';
	private const TABLE_OUTBOX        = 'oj_ai_outbox_events';

	private const EVENT_TYPE      = 'worksheet.revision_approved';
	private const PAYLOAD_SCHEMA  = 'oj_ai.worksheet_revision_approved_event';
	private const AUDIT_SCHEMA    = 'oj_ai.audit_metadata';
	private const PROFILE_VERSION = 1;
	private const SUBJECT_TYPE    = 'worksheet_quality';

	/**
	 * Initialize WordPress worksheet approval repository dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null    $database Database.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private ?object $database = null
	) {
	}

	/**
	 * Find command receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Approve_Worksheet_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt(
		int $actor_user_id,
		string $idempotency_storage_key,
		string $request_digest
	): ?Approve_Worksheet_Revision_Result {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Worksheet approval receipt actor is invalid.' );
		}
		$this->assert_hash( $idempotency_storage_key );
		$this->assert_hash( $request_digest );
		return $this->find_receipt( $actor_user_id, $idempotency_storage_key, $request_digest, false );
	}

	/**
	 * Approve revision.
	 *
	 * @param array $record Record.
	 * @return Approve_Worksheet_Revision_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function approve_revision( array $record ): Approve_Worksheet_Revision_Result {
		$this->assert_record( $record );
		$receipt = $this->find_receipt(
			$record['actor_user_id'],
			$record['idempotency_storage_key'],
			$record['request_digest'],
			false
		);
		if ( $receipt instanceof Approve_Worksheet_Revision_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->find_receipt(
				$record['actor_user_id'],
				$record['idempotency_storage_key'],
				$record['request_digest'],
				true
			);
			if ( $receipt instanceof Approve_Worksheet_Revision_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $receipt;
			}

			$snapshot = $this->lock_source( $record );
			$this->lock_assets( $record );
			$this->lock_configuration( $record['configuration_guard'] );
			$this->lock_evaluation( $record );

			$update = 'UPDATE ' . self::TABLE_REVISIONS
				. ' SET state = %s, approved_by_user_id = %d, approved_at_utc = %s '
				. 'WHERE id = %d AND state = %s AND document_hash = %s AND validation_hash = %s '
				. 'AND approved_by_user_id IS NULL AND approved_at_utc IS NULL';
			$this->query_exactly_one_or_fail(
				$this->prepare(
					$update,
					array(
						'approved',
						$record['actor_user_id'],
						$record['approved_at_utc'],
						$snapshot['revision_pk'],
						'draft',
						$record['expected_document_hash'],
						$record['source_validation_hash'],
					)
				)
			);

			$root_update = 'UPDATE ' . self::TABLE_WORKSHEETS
				. ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
				. 'WHERE id = %d AND lock_version = %d AND current_revision_public_id = %s AND state = %s';
			$this->query_exactly_one_or_fail(
				$this->prepare(
					$root_update,
					array(
						$record['actor_user_id'],
						$record['approved_at_utc'],
						$snapshot['worksheet_pk'],
						$snapshot['root_lock_version'],
						$record['revision_public_id'],
						$snapshot['root_state'],
					)
				)
			);

			$result = $this->result( $record, $snapshot['revision_no'], false );
			$this->insert_audit( $record, $snapshot );
			$this->insert_outbox( $record, $result );
			$this->assert_postcondition( $record, $snapshot );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Worksheet_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$receipt = $this->find_receipt(
				$record['actor_user_id'],
				$record['idempotency_storage_key'],
				$record['request_digest'],
				false
			);
			if ( $receipt instanceof Approve_Worksheet_Revision_Result ) {
				return $receipt;
			}
			throw Worksheet_Failure::storage_unavailable();
		}
	}

	/**
	 * Lock source.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lock_source( array $record ): array {
		$sql = 'SELECT w.id AS worksheet_pk, w.state AS root_state, w.lock_version AS root_lock_version, w.current_revision_public_id, r.id AS revision_pk, r.public_id AS revision_id, r.revision_no, r.state AS revision_state, r.document_hash, r.validation_hash, r.approved_by_user_id, r.approved_at_utc FROM ' . self::TABLE_WORKSHEETS . ' w INNER JOIN ' . self::TABLE_REVISIONS . ' r '
			. 'ON r.worksheet_id = w.id WHERE w.public_id = %s AND w.owner_user_id = %d '
			. 'AND r.public_id = %s LIMIT 1 FOR UPDATE';
		$row = $this->row(
			$this->prepare(
				$sql,
				array( $record['worksheet_public_id'], $record['actor_user_id'], $record['revision_public_id'] )
			)
		);
		if ( null === $row ) {
			throw Worksheet_Failure::not_found();
		}

		$revision_id         = (string) ( $row['revision_id'] ?? '' );
		$current_revision_id = (string) ( $row['current_revision_public_id'] ?? '' );
		$document_hash       = (string) ( $row['document_hash'] ?? '' );
		$validation_hash     = (string) ( $row['validation_hash'] ?? '' );
		$revision_state      = (string) ( $row['revision_state'] ?? '' );
		$root_state          = (string) ( $row['root_state'] ?? '' );
		if (
			! hash_equals( $record['revision_public_id'], $revision_id )
			|| ! hash_equals( $record['revision_public_id'], $current_revision_id )
			|| ! hash_equals( $record['expected_state'], $revision_state )
			|| ! hash_equals( $record['expected_document_hash'], $document_hash )
			|| ! hash_equals( $record['source_validation_hash'], $validation_hash )
			|| ! in_array( $root_state, array( 'draft', 'active' ), true )
			|| null !== ( $row['approved_by_user_id'] ?? null )
			|| null !== ( $row['approved_at_utc'] ?? null )
		) {
			throw Worksheet_Failure::revision_conflict(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$record['revision_public_id'],
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$current_revision_id,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$this->safe_hash( $document_hash )
			);
		}
		return array(
			'worksheet_pk'      => $this->positive_integer( $row['worksheet_pk'] ?? null ),
			'revision_pk'       => $this->positive_integer( $row['revision_pk'] ?? null ),
			'revision_no'       => $this->positive_integer( $row['revision_no'] ?? null ),
			'root_lock_version' => $this->non_negative_integer( $row['root_lock_version'] ?? null ),
			'root_state'        => $root_state,
		);
	}

	/**
	 * Lock assets.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lock_assets( array $record ): void {
		foreach ( $record['asset_public_ids'] as $asset_id ) {
			$row = $this->row(
				$this->prepare(
					'SELECT public_id, owner_type, owner_public_id, purpose, state, scan_state, byte_size, sha256, purged_at_utc FROM ' . self::TABLE_PRIVATE_FILES . ' WHERE public_id = %s LIMIT 1 FOR UPDATE',
					array( $asset_id )
				)
			);
			if (
				null === $row
				|| ! hash_equals( $asset_id, (string) ( $row['public_id'] ?? '' ) )
				|| 'worksheet' !== ( $row['owner_type'] ?? null )
				|| ! hash_equals( $record['worksheet_public_id'], (string) ( $row['owner_public_id'] ?? '' ) )
				|| 'worksheet_asset' !== ( $row['purpose'] ?? null )
				|| 'ready' !== ( $row['state'] ?? null )
				|| 'clean' !== ( $row['scan_state'] ?? null )
				|| null !== ( $row['purged_at_utc'] ?? null )
				|| $this->positive_integer( $row['byte_size'] ?? null ) > Worksheet_Document_Profile::MAX_FILE_BYTES
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $row['sha256'] ?? '' ) )
			) {
				throw Worksheet_Failure::asset_not_ready();
			}
		}
	}

	/**
	 * Lock configuration.
	 *
	 * @param array<string,string> $guard Guard.
	 * @return void
	 */
	private function lock_configuration( array $guard ): void {
		$this->lock_one_active_configuration(
			self::TABLE_POLICIES,
			$guard['policy_revision_id'],
			$guard['policy_content_hash']
		);
		$this->lock_one_active_configuration(
			self::TABLE_RUBRICS,
			$guard['rubric_revision_id'],
			$guard['rubric_content_hash']
		);
		$this->lock_one_active_configuration(
			self::TABLE_REVIEW_RULES,
			$guard['review_rule_revision_id'],
			$guard['review_rule_content_hash']
		);
	}

	/**
	 * Lock one active configuration.
	 *
	 * @param string $table Table.
	 * @param string $public_id Public id.
	 * @param string $content_hash Content hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lock_one_active_configuration( string $table, string $public_id, string $content_hash ): void {
		$rows = $this->rows(
			$this->prepare(
				'SELECT public_id, content_hash FROM ' . $table
					. ' WHERE subject_type = %s AND state = %s ORDER BY revision_no DESC LIMIT 2 FOR UPDATE',
				array( self::SUBJECT_TYPE, 'active' )
			)
		);
		if (
			1 !== count( $rows )
			|| ! hash_equals( $public_id, (string) ( $rows[0]['public_id'] ?? '' ) )
			|| ! hash_equals( $content_hash, (string) ( $rows[0]['content_hash'] ?? '' ) )
		) {
			throw Worksheet_Failure::approval_evidence_unavailable();
		}
	}

	/**
	 * Lock evaluation.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lock_evaluation( array $record ): void {
		$guard = $record['evaluation_guard'];
		$sql   = 'SELECT e.public_id AS evaluation_id, e.lock_version, e.state, e.updated_at_utc, e.worksheet_revision_hash AS source_hash, e.policy_revision_public_id, e.policy_hash, e.rubric_revision_public_id, e.rubric_hash, e.review_rule_revision_public_id, e.review_rule_hash FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' r ON r.public_id = e.worksheet_revision_public_id '
			. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = r.worksheet_id '
			. 'WHERE e.worksheet_revision_public_id = %s AND w.public_id = %s AND w.owner_user_id = %d '
			. 'ORDER BY e.updated_at_utc DESC, e.public_id DESC LIMIT 1 FOR UPDATE';
		$row   = $this->row(
			$this->prepare(
				$sql,
				array( $record['revision_public_id'], $record['worksheet_public_id'], $record['actor_user_id'] )
			)
		);
		if ( null === $guard['evaluation_id'] ) {
			if ( null !== $row || null !== $record['evaluation_projection_hash'] ) {
				throw Worksheet_Failure::approval_evidence_unavailable();
			}
			return;
		}
		if ( null === $row ) {
			throw Worksheet_Failure::approval_evidence_unavailable();
		}
		$comparisons = array(
			'evaluation_id'            => 'evaluation_id',
			'state'                    => 'state',
			'updated_at_utc'           => 'updated_at_utc',
			'source_hash'              => 'source_hash',
			'policy_revision_id'       => 'policy_revision_public_id',
			'policy_content_hash'      => 'policy_hash',
			'rubric_revision_id'       => 'rubric_revision_public_id',
			'rubric_content_hash'      => 'rubric_hash',
			'review_rule_revision_id'  => 'review_rule_revision_public_id',
			'review_rule_content_hash' => 'review_rule_hash',
		);
		foreach ( $comparisons as $guard_key => $row_key ) {
			if ( ! is_string( $guard[ $guard_key ] ) || ! hash_equals( $guard[ $guard_key ], (string) ( $row[ $row_key ] ?? '' ) ) ) {
				throw Worksheet_Failure::approval_evidence_unavailable();
			}
		}
		if (
			$guard['lock_version'] !== $this->non_negative_integer( $row['lock_version'] ?? null )
			|| ! is_string( $guard['projection_hash'] )
			|| ! hash_equals( $guard['projection_hash'], (string) $record['evaluation_projection_hash'] )
		) {
			throw Worksheet_Failure::approval_evidence_unavailable();
		}
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit( array $record, array $snapshot ): void {
		$previous = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$metadata_json = $this->canonical->encode(
			array(
				'approval_decision_hash'     => $record['approval_decision_hash'],
				'approval_evidence_hash'     => $record['approval_evidence_hash'],
				'command_id'                 => $record['command_id'],
				'document_hash'              => $record['expected_document_hash'],
				'evaluation_projection_hash' => $record['evaluation_projection_hash'],
				'policy_hash'                => $record['policy_hash'],
				'policy_revision_id'         => $record['policy_revision_public_id'],
				'revision_id'                => $record['revision_public_id'],
				'validation_projection_hash' => $record['validation_projection_hash'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$before_digest = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'document_hash' => $record['expected_document_hash'],
					'state'         => 'draft',
				)
			)
		);
		$after_digest  = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'approval_decision_hash' => $record['approval_decision_hash'],
					'document_hash'          => $record['expected_document_hash'],
					'state'                  => 'approved',
				)
			)
		);
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::EVENT_TYPE,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $after_digest,
					'before_digest'       => $before_digest,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['revision_public_id'],
					'object_type'         => 'worksheet_revision',
					'occurred_at_utc'     => $record['approved_at_utc'],
					'previous_event_hash' => null === $previous ? null : (string) $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['approved_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => self::EVENT_TYPE,
				'object_type'         => 'worksheet_revision',
				'object_public_id'    => $record['revision_public_id'],
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $before_digest,
				'after_digest'        => $after_digest,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::PROFILE_VERSION,
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
	 * @param array<string,mixed>               $record Record.
	 * @param Approve_Worksheet_Revision_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Approve_Worksheet_Revision_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'event'          => self::EVENT_TYPE,
				'owner_user_id'  => $record['actor_user_id'],
				'request_digest' => $record['request_digest'],
				'receipt'        => array(
					'approval_decision_hash'     => $result->approval_decision_hash(),
					'approval_evidence_hash'     => $result->approval_evidence_hash(),
					'approved_at_utc'            => $result->approved_at_utc(),
					'approved_by_user_id'        => $result->approved_by_user_id(),
					'document_hash'              => $result->document_hash(),
					'evaluation_projection_hash' => $result->evaluation_projection_hash(),
					'policy_hash'                => $result->policy_hash(),
					'policy_revision_id'         => $result->policy_revision_id(),
					'revision_id'                => $result->revision_id(),
					'revision_no'                => $result->revision_no(),
					'validation_projection_hash' => $result->validation_projection_hash(),
					'worksheet_id'               => $result->worksheet_id(),
				),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['approved_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['approved_at_utc'],
				'aggregate_type'       => 'worksheet',
				'aggregate_public_id'  => $record['worksheet_public_id'],
				'event_type'           => self::EVENT_TYPE,
				'idempotency_key'      => $record['idempotency_storage_key'],
				'payload_schema'       => self::PAYLOAD_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['approved_at_utc'],
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition( array $record, array $snapshot ): void {
		$row = $this->row(
			$this->prepare(
				'SELECT r.state, r.document_hash, r.validation_hash, r.approved_by_user_id, r.approved_at_utc, w.current_revision_public_id, w.lock_version FROM ' . self::TABLE_REVISIONS . ' r '
					. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = r.worksheet_id '
					. 'WHERE r.id = %d AND w.id = %d LIMIT 1 FOR UPDATE',
				array( $snapshot['revision_pk'], $snapshot['worksheet_pk'] )
			)
		);
		if (
			null === $row || 'approved' !== ( $row['state'] ?? null )
			|| ! hash_equals( $record['expected_document_hash'], (string) ( $row['document_hash'] ?? '' ) )
			|| ! hash_equals( $record['source_validation_hash'], (string) ( $row['validation_hash'] ?? '' ) )
			|| $record['actor_user_id'] !== $this->positive_integer( $row['approved_by_user_id'] ?? null )
			|| ! hash_equals( $record['approved_at_utc'], (string) ( $row['approved_at_utc'] ?? '' ) )
			|| ! hash_equals( $record['revision_public_id'], (string) ( $row['current_revision_public_id'] ?? '' ) )
			|| $snapshot['root_lock_version'] + 1 !== $this->non_negative_integer( $row['lock_version'] ?? null )
		) {
			throw new RuntimeException( 'ApproveWorksheetRevision postcondition failed.' );
		}
	}

	/**
	 * Find receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @return Approve_Worksheet_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function find_receipt( int $actor_user_id, string $key, string $digest, bool $for_update ): ?Approve_Worksheet_Revision_Result {
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			self::EVENT_TYPE !== ( $row['event_type'] ?? null )
			|| self::PAYLOAD_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$payload = $this->decode( (string) ( $row['payload_json'] ?? '' ), (string) ( $row['payload_hash'] ?? '' ) );
		if ( ! hash_equals( $digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			throw Worksheet_Failure::idempotency_conflict();
		}
		$receipt = $payload['receipt'] ?? null;
		if (
			! is_array( $receipt ) || array_is_list( $receipt )
			|| $actor_user_id !== (int) ( $payload['owner_user_id'] ?? 0 )
			|| ! hash_equals( (string) ( $row['aggregate_public_id'] ?? '' ), (string) ( $receipt['worksheet_id'] ?? '' ) )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		try {
			return new Approve_Worksheet_Revision_Result(
				(string) ( $receipt['worksheet_id'] ?? '' ),
				(string) ( $receipt['revision_id'] ?? '' ),
				$this->positive_integer( $receipt['revision_no'] ?? null ),
				(string) ( $receipt['document_hash'] ?? '' ),
				(string) ( $receipt['validation_projection_hash'] ?? '' ),
				(string) ( $receipt['policy_revision_id'] ?? '' ),
				(string) ( $receipt['policy_hash'] ?? '' ),
				null === ( $receipt['evaluation_projection_hash'] ?? null ) ? null : (string) $receipt['evaluation_projection_hash'],
				(string) ( $receipt['approval_evidence_hash'] ?? '' ),
				(string) ( $receipt['approval_decision_hash'] ?? '' ),
				$this->positive_integer( $receipt['approved_by_user_id'] ?? null ),
				(string) ( $receipt['approved_at_utc'] ?? '' ),
				true
			);
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param int                 $revision_no Revision no.
	 * @param bool                $replayed Replayed.
	 * @return Approve_Worksheet_Revision_Result
	 */
	private function result( array $record, int $revision_no, bool $replayed ): Approve_Worksheet_Revision_Result {
		return new Approve_Worksheet_Revision_Result(
			$record['worksheet_public_id'],
			$record['revision_public_id'],
			$revision_no,
			$record['expected_document_hash'],
			$record['validation_projection_hash'],
			$record['policy_revision_public_id'],
			$record['policy_hash'],
			$record['evaluation_projection_hash'],
			$record['approval_evidence_hash'],
			$record['approval_decision_hash'],
			$record['actor_user_id'],
			$record['approved_at_utc'],
			$replayed
		);
	}

	/**
	 * Reject invalid record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_record( array $record ): void {
		$expected = array(
			'actor_user_id',
			'command_id',
			'correlation_id',
			'approved_at_utc',
			'idempotency_storage_key',
			'request_digest',
			'worksheet_public_id',
			'revision_public_id',
			'expected_state',
			'expected_document_hash',
			'source_validation_hash',
			'validation_projection_hash',
			'policy_revision_public_id',
			'policy_hash',
			'evaluation_projection_hash',
			'approval_evidence_hash',
			'approval_decision_hash',
			'configuration_guard',
			'evaluation_guard',
			'asset_public_ids',
			'audit_public_id',
			'outbox_public_id',
		);
		$keys     = array_keys( $record );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $keys !== $expected || ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 || 'draft' !== $record['expected_state'] ) {
			throw new InvalidArgumentException( 'Worksheet approval record is invalid.' );
		}
		foreach ( array( 'worksheet_public_id', 'revision_public_id', 'policy_revision_public_id', 'audit_public_id', 'outbox_public_id' ) as $field ) {
			new Public_Id( (string) $record[ $field ] );
		}
		foreach ( array( 'idempotency_storage_key', 'request_digest', 'expected_document_hash', 'source_validation_hash', 'validation_projection_hash', 'policy_hash', 'approval_evidence_hash', 'approval_decision_hash' ) as $field ) {
			$this->assert_hash( (string) $record[ $field ] );
		}
		if ( null !== $record['evaluation_projection_hash'] ) {
			$this->assert_hash( (string) $record['evaluation_projection_hash'] );
		}
		$this->assert_utc( (string) $record['approved_at_utc'] );
		if ( ! is_array( $record['configuration_guard'] ) || array_is_list( $record['configuration_guard'] ) ) {
			throw new InvalidArgumentException( 'Worksheet approval configuration guard is invalid.' );
		}
		if ( ! is_array( $record['evaluation_guard'] ) || array_is_list( $record['evaluation_guard'] ) ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation guard is invalid.' );
		}
		$this->assert_exact_keys(
			$record['configuration_guard'],
			array(
				'policy_revision_id',
				'policy_content_hash',
				'rubric_revision_id',
				'rubric_content_hash',
				'review_rule_revision_id',
				'review_rule_content_hash',
			)
		);
		$this->assert_exact_keys(
			$record['evaluation_guard'],
			array(
				'evaluation_id',
				'lock_version',
				'state',
				'updated_at_utc',
				'source_hash',
				'policy_revision_id',
				'policy_content_hash',
				'rubric_revision_id',
				'rubric_content_hash',
				'review_rule_revision_id',
				'review_rule_content_hash',
				'projection_hash',
			)
		);
		foreach ( $record['configuration_guard'] as $key => $value ) {
			if ( str_ends_with( (string) $key, '_id' ) ) {
				new Public_Id( (string) $value );
			} else {
				$this->assert_hash( (string) $value );
			}
		}
		$evaluation_id = $record['evaluation_guard']['evaluation_id'];
		if ( null === $evaluation_id ) {
			foreach ( $record['evaluation_guard'] as $value ) {
				if ( null !== $value ) {
					throw new InvalidArgumentException( 'Empty worksheet approval evaluation guard is inconsistent.' );
				}
			}
		} else {
			new Public_Id( (string) $evaluation_id );
			$this->non_negative_integer( $record['evaluation_guard']['lock_version'] );
			foreach ( array( 'source_hash', 'policy_content_hash', 'rubric_content_hash', 'review_rule_content_hash', 'projection_hash' ) as $field ) {
				$this->assert_hash( (string) $record['evaluation_guard'][ $field ] );
			}
			foreach ( array( 'policy_revision_id', 'rubric_revision_id', 'review_rule_revision_id' ) as $field ) {
				new Public_Id( (string) $record['evaluation_guard'][ $field ] );
			}
			$this->assert_utc( (string) $record['evaluation_guard']['updated_at_utc'] );
		}
		if ( ! array_is_list( $record['asset_public_ids'] ) || count( $record['asset_public_ids'] ) > Worksheet_Document_Profile::MAX_BODY_ASSETS || count( $record['asset_public_ids'] ) !== count( array_unique( $record['asset_public_ids'] ) ) ) {
			throw new InvalidArgumentException( 'Worksheet approval asset guard is invalid.' );
		}
		foreach ( $record['asset_public_ids'] as $asset_id ) {
			new Public_Id( (string) $asset_id );
		}
	}

	/**
	 * Reject invalid exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_exact_keys( array $value, array $expected ): void {
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $keys !== $expected ) {
			throw new InvalidArgumentException( 'Worksheet approval guard fields are invalid.' );
		}
	}

	/**
	 * Decode WordPress worksheet approval repository.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( string $json, string $hash ): array {
		$this->assert_hash( $hash );
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR ); } catch ( Throwable ) {
			throw Worksheet_Failure::stored_data_invalid();
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
			return $value;
	}

	/**
	 * Safe hash.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function safe_hash( string $value ): string {
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ) ? $value : str_repeat( '0', 64 );
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
			throw new InvalidArgumentException( 'Worksheet approval SHA-256 value is invalid.' );
		}
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
			throw new InvalidArgumentException( 'Worksheet approval UTC value is invalid.' );
		}
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		}
		throw Worksheet_Failure::stored_data_invalid();
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		}
		throw Worksheet_Failure::stored_data_invalid();
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
			throw Worksheet_Failure::storage_unavailable();
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
		$row = $this->database()->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) || array_is_list( $row ) || '' !== trim( (string) ( $this->database()->last_error ?? '' ) ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}
		return $row;
	}

	/**
	 * Rows.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rows( string $sql ): array {
		$rows = $this->database()->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || '' !== trim( (string) ( $this->database()->last_error ?? '' ) ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				throw Worksheet_Failure::storage_unavailable();
			}
		}
		return $rows;
	}

	/**
	 * Scalar.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function scalar( string $sql ) {
		$value = $this->database()->get_var( $sql );
		if ( '' !== trim( (string) ( $this->database()->last_error ?? '' ) ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}
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
		if ( false === $this->database()->insert( $table, $data ) ) {
			throw new RuntimeException( 'Worksheet approval insert failed.' );
		}
	}

	/**
	 * Query exactly one or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_exactly_one_or_fail( string $sql ): void {
		if ( 1 !== $this->database()->query( $sql ) ) {
			throw Worksheet_Failure::approval_evidence_unavailable();
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
		if ( false === $this->database()->query( $sql ) ) {
			throw new RuntimeException( 'Worksheet approval transaction query failed.' );
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
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if (
			! is_object( $database ) || ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_row' )
			|| ! method_exists( $database, 'get_results' ) || ! method_exists( $database, 'get_var' )
			|| ! method_exists( $database, 'query' ) || ! method_exists( $database, 'insert' )
		) {
			throw Worksheet_Failure::storage_unavailable();
		}
		return $database;
	}
}
