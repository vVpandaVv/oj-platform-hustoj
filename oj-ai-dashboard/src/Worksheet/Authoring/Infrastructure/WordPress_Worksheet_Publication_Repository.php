<?php
/**
 * WordPress worksheet publication repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Application\Publish_Worksheet_Revision_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Publication_Repository_Port_Interface;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress worksheet publication repository.
 */
final class WordPress_Worksheet_Publication_Repository implements Worksheet_Publication_Repository_Port_Interface {
	private const TABLE_WORKSHEETS    = 'oj_ai_worksheets';
	private const TABLE_REVISIONS     = 'oj_ai_worksheet_revisions';
	private const TABLE_PUBLICATIONS  = 'oj_ai_worksheet_publications';
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';
	private const TABLE_POLICIES      = 'oj_ai_evaluation_policy_revisions';
	private const TABLE_RUBRICS       = 'oj_ai_rubric_revisions';
	private const TABLE_REVIEW_RULES  = 'oj_ai_review_rule_revisions';
	private const TABLE_AUDIT         = 'oj_ai_audit_events';
	private const TABLE_OUTBOX        = 'oj_ai_outbox_events';

	private const APPROVAL_EVENT        = 'worksheet.revision_approved';
	private const EVENT_TYPE            = 'worksheet.revision_published';
	private const PAYLOAD_SCHEMA        = 'oj_ai.worksheet_revision_published_event';
	private const AUDIT_SCHEMA          = 'oj_ai.audit_metadata';
	private const PROFILE_VERSION       = 1;
	private const MANIFEST_VERSION      = '1.0.0';
	private const CONFIGURATION_SUBJECT = 'student_submission';

	private const PROFILE_SCHEMAS = array(
		'student_document' => 'oj_ai.student_document',
		'response_schema'  => 'oj_ai.student_response_schema',
		'grading_schema'   => 'oj_ai.grading_schema',
		'print_projection' => 'oj_ai.worksheet_print',
	);

	/**
	 * Initialize WordPress worksheet publication repository dependencies and state.
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
	 * @return Publish_Worksheet_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt(
		int $actor_user_id,
		string $idempotency_storage_key,
		string $request_digest
	): ?Publish_Worksheet_Revision_Result {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Worksheet publication receipt actor is invalid.' );
		}
		$this->assert_hash( $idempotency_storage_key );
		$this->assert_hash( $request_digest );

		return $this->find_receipt( $actor_user_id, $idempotency_storage_key, $request_digest, false );
	}

	/**
	 * Find approval evidence.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_approval_evidence(
		int $actor_user_id,
		string $worksheet_id,
		string $revision_id
	): ?array {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Worksheet publication approval actor is invalid.' );
		}
		new Public_Id( $worksheet_id );
		new Public_Id( $revision_id );

		$rows = $this->rows(
			$this->prepare(
				'SELECT a.public_id, a.occurred_at_utc, a.actor_user_id, a.action_key, a.object_type, a.object_public_id, a.correlation_id, a.before_digest, a.after_digest, a.metadata_schema, a.metadata_version, a.metadata_json, a.metadata_hash, a.previous_event_hash, a.event_hash FROM ' . self::TABLE_AUDIT . ' a '
					. 'INNER JOIN ' . self::TABLE_REVISIONS . ' r ON r.public_id = a.object_public_id '
					. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = r.worksheet_id '
					. 'WHERE a.action_key = %s AND a.object_type = %s AND a.object_public_id = %s '
					. 'AND w.public_id = %s AND w.owner_user_id = %d ORDER BY a.id DESC LIMIT 2',
				array( self::APPROVAL_EVENT, 'worksheet_revision', $revision_id, $worksheet_id, $actor_user_id )
			)
		);
		if ( array() === $rows ) {
			return null;
		}
		if ( 1 !== count( $rows ) ) {
			throw Worksheet_Failure::publication_approval_unavailable();
		}

		return $this->validated_approval_evidence_from_row( $rows[0], $revision_id );
	}

	/**
	 * Publish revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Publish_Worksheet_Revision_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function publish_revision( array $record ): Publish_Worksheet_Revision_Result {
		$this->assert_record( $record );
		$receipt = $this->find_receipt(
			$record['actor_user_id'],
			$record['idempotency_storage_key'],
			$record['request_digest'],
			false
		);
		if ( $receipt instanceof Publish_Worksheet_Revision_Result ) {
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
			if ( $receipt instanceof Publish_Worksheet_Revision_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$snapshot = $this->lock_source( $record );
			$this->lock_approval_evidence( $record );
			$this->lock_assets( $record );
			$this->lock_configuration( $record['configuration_guard'] );
			$publication_no = $this->next_publication_no( $snapshot['worksheet_pk'] );

			$this->insert_publication( $record, $snapshot, $publication_no );
			$this->update_root( $record, $snapshot );
			$result = $this->result( $record, $publication_no, false );
			$this->insert_audit( $record, $snapshot, $publication_no );
			$this->insert_outbox( $record, $result );
			$this->assert_postcondition( $record, $snapshot, $publication_no );
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
			if ( $receipt instanceof Publish_Worksheet_Revision_Result ) {
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
		$row = $this->row(
			$this->prepare(
				'SELECT w.id AS worksheet_pk, w.state AS root_state, w.lock_version AS root_lock_version, w.current_revision_public_id, w.latest_publication_public_id, w.archived_at_utc, r.id AS revision_pk, r.public_id AS revision_id, r.state AS revision_state, r.document_hash, r.validation_hash, r.approved_by_user_id, r.approved_at_utc FROM ' . self::TABLE_WORKSHEETS . ' w INNER JOIN ' . self::TABLE_REVISIONS . ' r '
					. 'ON r.worksheet_id = w.id WHERE w.public_id = %s AND w.owner_user_id = %d '
					. 'AND r.public_id = %s LIMIT 1 FOR UPDATE',
				array( $record['worksheet_public_id'], $record['actor_user_id'], $record['revision_public_id'] )
			)
		);
		if ( null === $row ) {
			throw Worksheet_Failure::not_found();
		}

		$current_revision_id   = (string) ( $row['current_revision_public_id'] ?? '' );
		$current_document_hash = (string) ( $row['document_hash'] ?? '' );
		if (
			! hash_equals( $record['revision_public_id'], (string) ( $row['revision_id'] ?? '' ) )
			|| ! hash_equals( $record['revision_public_id'], $current_revision_id )
			|| ! hash_equals( $record['expected_state'], (string) ( $row['revision_state'] ?? '' ) )
			|| ! hash_equals( $record['expected_document_hash'], $current_document_hash )
			|| ! hash_equals( $record['source_validation_hash'], (string) ( $row['validation_hash'] ?? '' ) )
			|| ! in_array( $row['root_state'] ?? null, array( 'draft', 'active' ), true )
			|| null !== ( $row['archived_at_utc'] ?? null )
			|| null === ( $row['approved_by_user_id'] ?? null )
			|| null === ( $row['approved_at_utc'] ?? null )
		) {
			throw Worksheet_Failure::publication_conflict(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$this->safe_hash( $current_document_hash ),
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$record['approval_decision_hash']
			);
		}

		$existing = $this->row(
			$this->prepare(
				'SELECT public_id FROM ' . self::TABLE_PUBLICATIONS . ' WHERE worksheet_revision_id = %d LIMIT 1 FOR UPDATE',
				array( $this->positive_integer( $row['revision_pk'] ?? null ) )
			)
		);
		if ( null !== $existing ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Failure::publication_conflict( $current_document_hash, $record['approval_decision_hash'] );
		}

		return array(
			'latest_publication_public_id' => null === ( $row['latest_publication_public_id'] ?? null ) ? null : (string) $row['latest_publication_public_id'],
			'revision_pk'                  => $this->positive_integer( $row['revision_pk'] ?? null ),
			'root_lock_version'            => $this->non_negative_integer( $row['root_lock_version'] ?? null ),
			'root_state'                   => (string) $row['root_state'],
			'worksheet_pk'                 => $this->positive_integer( $row['worksheet_pk'] ?? null ),
		);
	}

	/**
	 * Lock approval evidence.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lock_approval_evidence( array $record ): void {
		$row = $this->row(
			$this->prepare(
				'SELECT public_id, occurred_at_utc, actor_user_id, action_key, object_type, object_public_id, correlation_id, before_digest, after_digest, metadata_schema, metadata_version, metadata_json, metadata_hash, previous_event_hash, event_hash FROM ' . self::TABLE_AUDIT
					. ' WHERE public_id = %s LIMIT 1 FOR UPDATE',
				array( $record['approval_guard']['audit_public_id'] )
			)
		);
		if ( null === $row ) {
			throw Worksheet_Failure::publication_approval_unavailable();
		}
		$evidence = $this->validated_approval_evidence_from_row( $row, $record['revision_public_id'] );
		$guard    = $evidence['guard'];
		foreach ( array( 'audit_public_id', 'event_hash', 'metadata_hash' ) as $field ) {
			if ( ! hash_equals( $record['approval_guard'][ $field ], (string) $guard[ $field ] ) ) {
				throw Worksheet_Failure::publication_approval_unavailable();
			}
		}
		foreach ( array( 'approval_decision_hash', 'approval_evidence_hash', 'document_hash', 'validation_projection_hash' ) as $field ) {
			$expected = 'document_hash' === $field ? $record['expected_document_hash'] : $record[ $field ];
			if ( ! hash_equals( $expected, (string) $evidence[ $field ] ) ) {
				throw Worksheet_Failure::publication_approval_unavailable();
			}
		}
	}

	/**
	 * Validated approval evidence from row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $revision_id Revision id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function validated_approval_evidence_from_row( array $row, string $revision_id ): array {
		try {
			return $this->approval_evidence_from_row( $row, $revision_id );
		} catch ( InvalidArgumentException | Worksheet_Failure ) {
			throw Worksheet_Failure::publication_approval_unavailable();
		}
	}

	/**
	 * Approval evidence from row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $revision_id Revision id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function approval_evidence_from_row( array $row, string $revision_id ): array {
		if (
			self::APPROVAL_EVENT !== ( $row['action_key'] ?? null )
			|| 'worksheet_revision' !== ( $row['object_type'] ?? null )
			|| ! hash_equals( $revision_id, (string) ( $row['object_public_id'] ?? '' ) )
			|| self::AUDIT_SCHEMA !== ( $row['metadata_schema'] ?? null )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['metadata_version'] ?? null )
		) {
			throw Worksheet_Failure::publication_approval_unavailable();
		}
		$audit_id = (string) ( $row['public_id'] ?? '' );
		new Public_Id( $audit_id );
		$metadata_hash = (string) ( $row['metadata_hash'] ?? '' );
		$event_hash    = (string) ( $row['event_hash'] ?? '' );
		$metadata      = $this->decode( (string) ( $row['metadata_json'] ?? '' ), $metadata_hash );
		$this->assert_exact_keys(
			$metadata,
			array(
				'approval_decision_hash',
				'approval_evidence_hash',
				'command_id',
				'document_hash',
				'evaluation_projection_hash',
				'policy_hash',
				'policy_revision_id',
				'revision_id',
				'validation_projection_hash',
			)
		);
		if ( ! hash_equals( $revision_id, (string) $metadata['revision_id'] ) ) {
			throw Worksheet_Failure::publication_approval_unavailable();
		}
		foreach ( array( 'approval_decision_hash', 'approval_evidence_hash', 'document_hash', 'policy_hash', 'validation_projection_hash' ) as $field ) {
			$this->assert_hash( (string) $metadata[ $field ] );
		}
		if ( null !== $metadata['evaluation_projection_hash'] ) {
			$this->assert_hash( (string) $metadata['evaluation_projection_hash'] );
		}
		new Public_Id( (string) $metadata['policy_revision_id'] );

		$before_digest = (string) ( $row['before_digest'] ?? '' );
		$after_digest  = (string) ( $row['after_digest'] ?? '' );
		$this->assert_hash( $before_digest );
		$this->assert_hash( $after_digest );
		if (
			! hash_equals(
				$before_digest,
				hash(
					'sha256',
					$this->canonical->encode(
						array(
							'document_hash' => $metadata['document_hash'],
							'state'         => 'draft',
						)
					)
				)
			)
			|| ! hash_equals(
				$after_digest,
				hash(
					'sha256',
					$this->canonical->encode(
						array(
							'approval_decision_hash' => $metadata['approval_decision_hash'],
							'document_hash'          => $metadata['document_hash'],
							'state'                  => 'approved',
						)
					)
				)
			)
		) {
			throw Worksheet_Failure::publication_approval_unavailable();
		}
		$previous = $row['previous_event_hash'] ?? null;
		if ( null !== $previous ) {
			$this->assert_hash( (string) $previous );
		}
		$occurred_at = (string) ( $row['occurred_at_utc'] ?? '' );
		$this->assert_utc( $occurred_at );
		$calculated_event_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::APPROVAL_EVENT,
					'actor_user_id'       => $this->positive_integer( $row['actor_user_id'] ?? null ),
					'after_digest'        => $after_digest,
					'before_digest'       => $before_digest,
					'correlation_id'      => (string) ( $row['correlation_id'] ?? '' ),
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $revision_id,
					'object_type'         => 'worksheet_revision',
					'occurred_at_utc'     => $occurred_at,
					'previous_event_hash' => null === $previous ? null : (string) $previous,
				)
			)
		);
		if ( ! hash_equals( $calculated_event_hash, $event_hash ) ) {
			throw Worksheet_Failure::publication_approval_unavailable();
		}

		return array(
			'approval_decision_hash'     => (string) $metadata['approval_decision_hash'],
			'approval_evidence_hash'     => (string) $metadata['approval_evidence_hash'],
			'document_hash'              => (string) $metadata['document_hash'],
			'guard'                      => array(
				'audit_public_id' => $audit_id,
				'event_hash'      => $event_hash,
				'metadata_hash'   => $metadata_hash,
			),
			'validation_projection_hash' => (string) $metadata['validation_projection_hash'],
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
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lock_configuration( array $guard ): void {
		$this->lock_one_active_configuration( self::TABLE_POLICIES, $guard['policy_revision_id'], $guard['policy_content_hash'] );
		if ( isset( $guard['project_id'] ) ) {
			$rows = $this->rows(
				$this->prepare(
					'SELECT r.content_hash FROM oj_ai_assessment_project_worksheets m JOIN oj_ai_assessment_projects p ON p.id = m.project_id JOIN oj_ai_assessment_project_revisions r ON r.id = m.project_revision_id AND r.project_id = p.id JOIN oj_ai_worksheets w ON w.id = m.worksheet_id WHERE p.public_id = %s AND w.public_id = %s AND r.public_id = %s AND r.state = %s FOR UPDATE',
					array( $guard['project_id'], $guard['project_worksheet_id'], $guard['rubric_revision_id'], 'confirmed' )
				)
			);
			if ( 1 !== count( $rows ) || ! hash_equals( $guard['project_content_hash'], (string) $rows[0]['content_hash'] ) ) {
				throw Worksheet_Failure::publication_configuration_unavailable();
			}
		} else {
			$this->lock_one_active_configuration( self::TABLE_RUBRICS, $guard['rubric_revision_id'], $guard['rubric_content_hash'] );
		}
		$this->lock_one_active_configuration( self::TABLE_REVIEW_RULES, $guard['review_rule_revision_id'], $guard['review_rule_content_hash'] );
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
				array( self::CONFIGURATION_SUBJECT, 'active' )
			)
		);
		if (
			1 !== count( $rows )
			|| ! hash_equals( $public_id, (string) ( $rows[0]['public_id'] ?? '' ) )
			|| ! hash_equals( $content_hash, (string) ( $rows[0]['content_hash'] ?? '' ) )
		) {
			throw Worksheet_Failure::publication_configuration_unavailable();
		}
	}

	/**
	 * Next publication no.
	 *
	 * @param int $worksheet_pk Worksheet pk.
	 * @return int
	 */
	private function next_publication_no( int $worksheet_pk ): int {
		$value = $this->scalar(
			$this->prepare(
				'SELECT publication_no FROM ' . self::TABLE_PUBLICATIONS
					. ' WHERE worksheet_id = %d ORDER BY publication_no DESC LIMIT 1 FOR UPDATE',
				array( $worksheet_pk )
			)
		);
		if ( null === $value ) {
			return 1;
		}
		return $this->positive_integer( $value ) + 1;
	}

	/**
	 * Insert publication.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param int                 $publication_no Publication no.
	 * @return void
	 */
	private function insert_publication( array $record, array $snapshot, int $publication_no ): void {
		$data = array(
			'public_id'                   => $record['publication_public_id'],
			'content_hash'                => $record['publication_hash'],
			'created_by_user_id'          => $record['actor_user_id'],
			'created_at_utc'              => $record['published_at_utc'],
			'worksheet_id'                => $snapshot['worksheet_pk'],
			'worksheet_revision_id'       => $snapshot['revision_pk'],
			'publication_no'              => $publication_no,
			'state'                       => 'active',
			'projection_manifest_version' => $record['projection_manifest_version'],
			'publication_hash'            => $record['publication_hash'],
			'published_at_utc'            => $record['published_at_utc'],
			'retired_by_user_id'          => null,
			'retired_at_utc'              => null,
		);
		foreach ( self::PROFILE_SCHEMAS as $name => $_schema ) {
			$profile                    = $record['profiles'][ $name ];
			$data[ $name . '_schema' ]  = $profile['schema'];
			$data[ $name . '_version' ] = $profile['version'];
			$data[ $name . '_json' ]    = $profile['json'];
			$data[ $name . '_hash' ]    = $profile['hash'];
		}
		$this->insert_or_fail( self::TABLE_PUBLICATIONS, $data );
	}

	/**
	 * Update root.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return void
	 */
	private function update_root( array $record, array $snapshot ): void {
		$this->query_exactly_one_or_fail(
			$this->prepare(
				'UPDATE ' . self::TABLE_WORKSHEETS
					. ' SET state = %s, latest_publication_public_id = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s '
					. 'WHERE id = %d AND lock_version = %d AND current_revision_public_id = %s AND state = %s',
				array(
					'active',
					$record['publication_public_id'],
					$record['actor_user_id'],
					$record['published_at_utc'],
					$snapshot['worksheet_pk'],
					$snapshot['root_lock_version'],
					$record['revision_public_id'],
					$snapshot['root_state'],
				)
			),
			$record['expected_document_hash'],
			$record['approval_decision_hash']
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param int                 $publication_no Publication no.
	 * @return void
	 */
	private function insert_audit( array $record, array $snapshot, int $publication_no ): void {
		$previous = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->assert_hash( (string) $previous );
		}
		$metadata_json = $this->canonical->encode(
			array(
				'approval_decision_hash'      => $record['approval_decision_hash'],
				'approval_evidence_hash'      => $record['approval_evidence_hash'],
				'approval_event_hash'         => $record['approval_guard']['event_hash'],
				'command_id'                  => $record['command_id'],
				'document_hash'               => $record['expected_document_hash'],
				'profile_hashes'              => $this->profile_hashes( $record ),
				'projection_manifest'         => $record['projection_manifest'],
				'projection_manifest_version' => $record['projection_manifest_version'],
				'publication_hash'            => $record['publication_hash'],
				'publication_id'              => $record['publication_public_id'],
				'publication_no'              => $publication_no,
				'revision_id'                 => $record['revision_public_id'],
				'validation_projection_hash'  => $record['validation_projection_hash'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$before_digest = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'latest_publication_id' => $snapshot['latest_publication_public_id'],
					'revision_id'           => $record['revision_public_id'],
					'state'                 => $snapshot['root_state'],
				)
			)
		);
		$after_digest  = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'latest_publication_id' => $record['publication_public_id'],
					'publication_hash'      => $record['publication_hash'],
					'revision_id'           => $record['revision_public_id'],
					'state'                 => 'active',
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
					'object_public_id'    => $record['publication_public_id'],
					'object_type'         => 'worksheet_publication',
					'occurred_at_utc'     => $record['published_at_utc'],
					'previous_event_hash' => null === $previous ? null : (string) $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['published_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => self::EVENT_TYPE,
				'object_type'         => 'worksheet_publication',
				'object_public_id'    => $record['publication_public_id'],
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
	 * @param Publish_Worksheet_Revision_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Publish_Worksheet_Revision_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'event'          => self::EVENT_TYPE,
				'owner_user_id'  => $record['actor_user_id'],
				'request_digest' => $record['request_digest'],
				'receipt'        => array(
					'approval_decision_hash'      => $result->approval_decision_hash(),
					'document_hash'               => $result->document_hash(),
					'profile_hashes'              => $result->profile_hashes(),
					'projection_manifest_version' => $result->projection_manifest_version(),
					'publication_hash'            => $result->publication_hash(),
					'publication_id'              => $result->publication_id(),
					'publication_no'              => $result->publication_no(),
					'published_at_utc'            => $result->published_at_utc(),
					'published_by_user_id'        => $result->published_by_user_id(),
					'revision_id'                 => $result->revision_id(),
					'worksheet_id'                => $result->worksheet_id(),
				),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['published_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['published_at_utc'],
				'aggregate_type'       => 'worksheet',
				'aggregate_public_id'  => $record['worksheet_public_id'],
				'event_type'           => self::EVENT_TYPE,
				'idempotency_key'      => $record['idempotency_storage_key'],
				'payload_schema'       => self::PAYLOAD_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['published_at_utc'],
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
	 * @param int                 $publication_no Publication no.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition( array $record, array $snapshot, int $publication_no ): void {
		$row = $this->row(
			$this->prepare(
				'SELECT p.public_id, p.content_hash, p.publication_no, p.state AS publication_state, p.student_document_hash, p.response_schema_hash, p.grading_schema_hash, p.print_projection_hash, p.projection_manifest_version, p.publication_hash, p.published_at_utc, p.created_by_user_id, p.retired_by_user_id, p.retired_at_utc, r.state AS revision_state, w.state AS root_state, w.current_revision_public_id, w.latest_publication_public_id, w.lock_version FROM ' . self::TABLE_PUBLICATIONS . ' p '
					. 'INNER JOIN ' . self::TABLE_REVISIONS . ' r ON r.id = p.worksheet_revision_id '
					. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = p.worksheet_id '
					. 'WHERE p.public_id = %s AND p.worksheet_id = %d AND p.worksheet_revision_id = %d LIMIT 1 FOR UPDATE',
				array( $record['publication_public_id'], $snapshot['worksheet_pk'], $snapshot['revision_pk'] )
			)
		);
		if (
			null === $row
			|| ! hash_equals( $record['publication_public_id'], (string) ( $row['public_id'] ?? '' ) )
			|| ! hash_equals( $record['publication_hash'], (string) ( $row['content_hash'] ?? '' ) )
			|| $publication_no !== $this->positive_integer( $row['publication_no'] ?? null )
			|| 'active' !== ( $row['publication_state'] ?? null )
			|| 'approved' !== ( $row['revision_state'] ?? null )
			|| 'active' !== ( $row['root_state'] ?? null )
			|| ! hash_equals( $record['revision_public_id'], (string) ( $row['current_revision_public_id'] ?? '' ) )
			|| ! hash_equals( $record['publication_public_id'], (string) ( $row['latest_publication_public_id'] ?? '' ) )
			|| $snapshot['root_lock_version'] + 1 !== $this->non_negative_integer( $row['lock_version'] ?? null )
			|| ! hash_equals( $record['projection_manifest_version'], (string) ( $row['projection_manifest_version'] ?? '' ) )
			|| ! hash_equals( $record['publication_hash'], (string) ( $row['publication_hash'] ?? '' ) )
			|| ! hash_equals( $record['published_at_utc'], (string) ( $row['published_at_utc'] ?? '' ) )
			|| $record['actor_user_id'] !== $this->positive_integer( $row['created_by_user_id'] ?? null )
			|| null !== ( $row['retired_by_user_id'] ?? null )
			|| null !== ( $row['retired_at_utc'] ?? null )
		) {
			throw new RuntimeException( 'PublishWorksheetRevision postcondition failed.' );
		}
		foreach ( self::PROFILE_SCHEMAS as $name => $_schema ) {
			if ( ! hash_equals( $record['profiles'][ $name ]['hash'], (string) ( $row[ $name . '_hash' ] ?? '' ) ) ) {
				throw new RuntimeException( 'PublishWorksheetRevision profile postcondition failed.' );
			}
		}
	}

	/**
	 * Find receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @return Publish_Worksheet_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function find_receipt( int $actor_user_id, string $key, string $digest, bool $for_update ): ?Publish_Worksheet_Revision_Result {
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
			return new Publish_Worksheet_Revision_Result(
				(string) ( $receipt['worksheet_id'] ?? '' ),
				(string) ( $receipt['revision_id'] ?? '' ),
				(string) ( $receipt['publication_id'] ?? '' ),
				$this->positive_integer( $receipt['publication_no'] ?? null ),
				(string) ( $receipt['document_hash'] ?? '' ),
				(string) ( $receipt['approval_decision_hash'] ?? '' ),
				(string) ( $receipt['projection_manifest_version'] ?? '' ),
				(string) ( $receipt['publication_hash'] ?? '' ),
				is_array( $receipt['profile_hashes'] ?? null ) ? $receipt['profile_hashes'] : array(),
				$this->positive_integer( $receipt['published_by_user_id'] ?? null ),
				(string) ( $receipt['published_at_utc'] ?? '' ),
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
	 * @param int                 $publication_no Publication no.
	 * @param bool                $replayed Replayed.
	 * @return Publish_Worksheet_Revision_Result
	 */
	private function result( array $record, int $publication_no, bool $replayed ): Publish_Worksheet_Revision_Result {
		return new Publish_Worksheet_Revision_Result(
			$record['worksheet_public_id'],
			$record['revision_public_id'],
			$record['publication_public_id'],
			$publication_no,
			$record['expected_document_hash'],
			$record['approval_decision_hash'],
			$record['projection_manifest_version'],
			$record['publication_hash'],
			$this->profile_hashes( $record ),
			$record['actor_user_id'],
			$record['published_at_utc'],
			$replayed
		);
	}

	/**
	 * Profile hashes.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,string>
	 */
	private function profile_hashes( array $record ): array {
		$hashes = array();
		foreach ( self::PROFILE_SCHEMAS as $name => $_schema ) {
			$hashes[ $name ] = $record['profiles'][ $name ]['hash'];
		}
		return $hashes;
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
			'approval_decision_hash',
			'approval_evidence_hash',
			'approval_guard',
			'asset_public_ids',
			'audit_public_id',
			'command_id',
			'configuration_guard',
			'correlation_id',
			'expected_document_hash',
			'expected_state',
			'idempotency_storage_key',
			'outbox_public_id',
			'profiles',
			'projection_manifest',
			'projection_manifest_version',
			'publication_hash',
			'publication_public_id',
			'published_at_utc',
			'request_digest',
			'revision_public_id',
			'source_validation_hash',
			'validation_projection_hash',
			'worksheet_public_id',
		);
		$this->assert_exact_keys( $record, $expected );
		if ( ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 || 'approved' !== $record['expected_state'] ) {
			throw new InvalidArgumentException( 'Worksheet publication record is invalid.' );
		}
		foreach ( array( 'worksheet_public_id', 'revision_public_id', 'publication_public_id', 'audit_public_id', 'outbox_public_id' ) as $field ) {
			new Public_Id( (string) $record[ $field ] );
		}
		foreach ( array( 'approval_decision_hash', 'approval_evidence_hash', 'expected_document_hash', 'idempotency_storage_key', 'publication_hash', 'request_digest', 'source_validation_hash', 'validation_projection_hash' ) as $field ) {
			$this->assert_hash( (string) $record[ $field ] );
		}
		if ( self::MANIFEST_VERSION !== $record['projection_manifest_version'] ) {
			throw new InvalidArgumentException( 'Worksheet publication manifest version is invalid.' );
		}
		$this->assert_utc( (string) $record['published_at_utc'] );
		if (
			! is_string( $record['command_id'] ) || strlen( $record['command_id'] ) < 8 || strlen( $record['command_id'] ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $record['command_id'] )
			|| ! is_string( $record['correlation_id'] ) || strlen( $record['correlation_id'] ) > 100
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $record['correlation_id'] )
		) {
			throw new InvalidArgumentException( 'Worksheet publication command identity is invalid.' );
		}

		$this->assert_guard( $record['approval_guard'], array( 'audit_public_id', 'event_hash', 'metadata_hash' ) );
		new Public_Id( (string) $record['approval_guard']['audit_public_id'] );
		$this->assert_hash( (string) $record['approval_guard']['event_hash'] );
		$this->assert_hash( (string) $record['approval_guard']['metadata_hash'] );
		$project_guard = isset( $record['configuration_guard']['project_id'] ) ? array( 'project_id', 'project_worksheet_id', 'project_content_hash' ) : array();
		if ( $project_guard && ( $record['configuration_guard']['project_worksheet_id'] ?? null ) !== $record['worksheet_public_id'] ) {
			throw new InvalidArgumentException( 'Publication assessment scope differs from the worksheet.' );
		}
		$this->assert_guard(
			$record['configuration_guard'],
			array_merge(
				$project_guard,
				array(
					'policy_content_hash',
					'policy_revision_id',
					'review_rule_content_hash',
					'review_rule_revision_id',
					'rubric_content_hash',
					'rubric_revision_id',
				)
			)
		);
		foreach ( $record['configuration_guard'] as $field => $value ) {
			if ( str_ends_with( (string) $field, '_id' ) ) {
				new Public_Id( (string) $value ); } else {
				$this->assert_hash( (string) $value );
				}
		}

		if (
			! is_array( $record['asset_public_ids'] ) || ! array_is_list( $record['asset_public_ids'] )
			|| count( $record['asset_public_ids'] ) > Worksheet_Document_Profile::MAX_BODY_ASSETS
			|| count( $record['asset_public_ids'] ) !== count( array_unique( $record['asset_public_ids'] ) )
		) {
			throw new InvalidArgumentException( 'Worksheet publication asset guard is invalid.' );
		}
		foreach ( $record['asset_public_ids'] as $asset_id ) {
			new Public_Id( (string) $asset_id );
		}

		if ( ! is_array( $record['profiles'] ) || array_is_list( $record['profiles'] ) ) {
			throw new InvalidArgumentException( 'Worksheet publication profiles are invalid.' );
		}
		$this->assert_exact_keys( $record['profiles'], array_keys( self::PROFILE_SCHEMAS ) );
		$payload_hashes = array();
		foreach ( self::PROFILE_SCHEMAS as $name => $schema ) {
			$profile = $record['profiles'][ $name ];
			if ( ! is_array( $profile ) || array_is_list( $profile ) ) {
				throw new InvalidArgumentException( 'Worksheet publication profile is invalid.' );
			}
			$this->assert_exact_keys( $profile, array( 'hash', 'json', 'schema', 'version' ) );
			if ( $schema !== $profile['schema'] || self::PROFILE_VERSION !== $profile['version'] || ! is_string( $profile['json'] ) ) {
				throw new InvalidArgumentException( 'Worksheet publication profile metadata is invalid.' );
			}
			$this->assert_hash( (string) $profile['hash'] );
			if ( ! hash_equals( $profile['hash'], hash( 'sha256', $profile['json'] ) ) ) {
				throw new InvalidArgumentException( 'Worksheet publication profile hash is invalid.' );
			}
			$envelope = $this->decode( $profile['json'], $profile['hash'] );
			$this->assert_exact_keys( $envelope, array( 'payload', 'payload_hash', 'projector_version', 'schema_id', 'schema_version', 'source_hash' ) );
			if (
				$schema !== ( $envelope['schema_id'] ?? null )
				|| ! hash_equals( $record['expected_document_hash'], (string) ( $envelope['source_hash'] ?? '' ) )
				|| ! is_array( $envelope['payload'] ?? null ) || array_is_list( $envelope['payload'] )
			) {
				throw new InvalidArgumentException( 'Worksheet publication profile envelope is invalid.' );
			}
			$this->assert_hash( (string) ( $envelope['payload_hash'] ?? '' ) );
			if ( ! hash_equals( $envelope['payload_hash'], hash( 'sha256', $this->canonical->encode( $envelope['payload'] ) ) ) ) {
				throw new InvalidArgumentException( 'Worksheet publication profile payload hash is invalid.' );
			}
			$payload_hashes[ $name ] = (string) $envelope['payload_hash'];
		}

		$manifest = $record['projection_manifest'];
		if ( ! is_array( $manifest ) || array_is_list( $manifest ) ) {
			throw new InvalidArgumentException( 'Worksheet publication manifest is invalid.' );
		}
		$this->assert_exact_keys( $manifest, array( 'manifest_version', 'profiles', 'source_revision_hash' ) );
		if (
			self::MANIFEST_VERSION !== ( $manifest['manifest_version'] ?? null )
			|| ! hash_equals( $record['expected_document_hash'], (string) ( $manifest['source_revision_hash'] ?? '' ) )
			|| ! is_array( $manifest['profiles'] ?? null ) || array_is_list( $manifest['profiles'] )
		) {
			throw new InvalidArgumentException( 'Worksheet publication manifest metadata is invalid.' );
		}
		$this->assert_exact_keys( $manifest['profiles'], array_keys( self::PROFILE_SCHEMAS ) );
		foreach ( self::PROFILE_SCHEMAS as $name => $schema ) {
			$item = $manifest['profiles'][ $name ];
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Worksheet publication manifest profile is invalid.' );
			}
			$this->assert_exact_keys( $item, array( 'payload_hash', 'projector_version', 'schema_id', 'schema_version' ) );
			if ( $schema !== ( $item['schema_id'] ?? null ) || ! hash_equals( $payload_hashes[ $name ], (string) ( $item['payload_hash'] ?? '' ) ) ) {
				throw new InvalidArgumentException( 'Worksheet publication manifest profile drifted.' );
			}
		}
		if ( ! hash_equals( $record['publication_hash'], hash( 'sha256', $this->canonical->encode( $manifest ) ) ) ) {
			throw new InvalidArgumentException( 'Worksheet publication manifest hash is invalid.' );
		}
	}

	/**
	 * Reject invalid guard.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $keys Keys.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_guard( $value, array $keys ): void {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet publication guard is invalid.' );
		}
		$this->assert_exact_keys( $value, $keys );
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
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Worksheet publication fields are invalid.' );
		}
	}

	/**
	 * Decode WordPress worksheet publication repository.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
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
			throw new InvalidArgumentException( 'Worksheet publication SHA-256 value is invalid.' );
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
			throw new InvalidArgumentException( 'Worksheet publication UTC value is invalid.' );
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
			throw new RuntimeException( 'Worksheet publication insert failed.' );
		}
	}

	/**
	 * Query exactly one or fail.
	 *
	 * @param string $sql Sql.
	 * @param string $document_hash Document hash.
	 * @param string $approval_decision_hash Approval decision hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_exactly_one_or_fail( string $sql, string $document_hash, string $approval_decision_hash ): void {
		$result = $this->database()->query( $sql );
		if ( false === $result ) {
			throw new RuntimeException( 'Worksheet publication compare-and-swap failed.' );
		}
		if ( 1 !== $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Failure::publication_conflict( $document_hash, $approval_decision_hash );
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
			throw new RuntimeException( 'Worksheet publication transaction query failed.' );
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
