<?php
/**
 * Atomic append-only C33 worksheet evaluation review repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Review_Result;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Query_Projector;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Review_Repository_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic append-only C33 worksheet evaluation review repository.
 */
final class WordPress_Worksheet_Evaluation_Review_Repository implements Worksheet_Evaluation_Review_Repository_Port_Interface {
	private const TABLE_EVALUATIONS = 'oj_ai_worksheet_evaluations';
	private const TABLE_RESULTS     = 'oj_ai_worksheet_evaluation_results';
	private const TABLE_REVIEWS     = 'oj_ai_worksheet_evaluation_reviews';
	private const TABLE_RUNS        = 'oj_ai_evaluation_runs';
	private const TABLE_REVISIONS   = 'oj_ai_worksheet_revisions';
	private const TABLE_WORKSHEETS  = 'oj_ai_worksheets';
	private const TABLE_AUDIT       = 'oj_ai_audit_events';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';

	private const EVENT_PROGRESS    = 'worksheet_evaluation.review_progressed';
	private const EVENT_COMPLETED   = 'worksheet_evaluation.reviewed';
	private const AUDIT_SCHEMA      = 'oj_ai.worksheet_evaluation_review_audit';
	private const OUTBOX_SCHEMA     = 'oj_ai.worksheet_evaluation_review_event';
	private const PROFILE_VERSION   = 1;
	private const MAX_REVISION_HOPS = 100;

	/**
	 * Initialize WordPress worksheet evaluation review repository dependencies and state.
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
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Worksheet_Evaluation_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt(
		string $command_storage_key,
		string $request_digest
	): ?Worksheet_Evaluation_Review_Result {
		$this->assert_storage_key( $command_storage_key );
		$this->assert_hash( $request_digest );
		try {
			return $this->command_receipt( $command_storage_key, $request_digest, false );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Find owned snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_owned_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review requires an actor.' );
		}
		new Public_Id( $evaluation_id );
		try {
			return $this->snapshot( $actor_user_id, $evaluation_id, false );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Append review.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Worksheet_Evaluation_Review_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_review( array $record ): Worksheet_Evaluation_Review_Result {
		$this->assert_record( $record );
		$receipt = $this->command_receipt(
			(string) $record['command_storage_key'],
			(string) $record['request_digest'],
			false
		);
		if ( $receipt instanceof Worksheet_Evaluation_Review_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt(
				(string) $record['command_storage_key'],
				(string) $record['request_digest'],
				true
			);
			if ( $receipt instanceof Worksheet_Evaluation_Review_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$snapshot = $this->snapshot(
				(int) $record['actor_user_id'],
				(string) $record['evaluation_public_id'],
				true
			);
			if ( null === $snapshot ) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::NOT_FOUND );
			}
			$this->assert_transition( $snapshot, $record );
			$this->assert_resolutions( $snapshot, $record['item_decisions'] );
			$this->insert_review( $snapshot, $record );
			$this->update_evaluation( $snapshot, $record );
			$result = $this->result_from_record( $record, false );
			$event  = 'completed' === $record['decision'] ? self::EVENT_COMPLETED : self::EVENT_PROGRESS;
			$this->insert_audit( $snapshot, $record, $result, $event );
			$this->insert_outbox( $record, $result, $event );
			$this->assert_postcondition( $record );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$receipt = $this->recover_receipt( $record );
			if ( $receipt instanceof Worksheet_Evaluation_Review_Result ) {
				return $receipt;
			}
			throw $this->storage_failure();
		}
	}

	/**
	 * Snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function snapshot( int $actor_user_id, string $evaluation_id, bool $for_update ): ?array {
		$sql = 'SELECT e.id AS evaluation_pk, e.public_id AS evaluation_id, e.lock_version AS evaluation_lock_version, e.state AS evaluation_state, e.worksheet_revision_public_id AS worksheet_revision_id, e.worksheet_revision_hash AS source_hash, revision.id AS source_revision_pk, revision.worksheet_id AS worksheet_pk, revision.document_hash AS source_document_hash, w.current_revision_public_id AS current_revision_id, result.id AS result_pk, result.public_id AS result_id, result.content_hash AS result_content_hash, result.normalized_result_schema, result.normalized_result_version, result.normalized_result_json, result.normalized_result_hash, result.provider_output_digest, run.public_id AS result_run_id, run.state AS result_run_state, run.result_public_id AS run_result_id, review.id AS latest_review_pk, review.public_id AS latest_review_id, review.review_no AS latest_review_no, review.decision AS latest_review_decision, review.content_hash AS latest_review_content_hash, review.item_decisions_schema, review.item_decisions_version, review.item_decisions_json, review.item_decisions_hash, previous.public_id AS previous_review_public_id, (SELECT COUNT(*) FROM ' . self::TABLE_RESULTS . ' counted_result WHERE counted_result.worksheet_evaluation_id = e.id) AS result_count, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_REVIEWS . ' counted_review WHERE counted_review.worksheet_evaluation_id = e.id) AS review_count '
			. 'FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.public_id = e.worksheet_revision_public_id '
			. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = revision.worksheet_id '
			. 'LEFT JOIN ' . self::TABLE_RESULTS . ' result ON result.worksheet_evaluation_id = e.id '
			. 'LEFT JOIN ' . self::TABLE_RUNS . ' run ON run.id = result.evaluation_run_id '
			. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' review ON review.worksheet_evaluation_id = e.id '
			. 'AND review.review_no = (SELECT MAX(latest.review_no) FROM ' . self::TABLE_REVIEWS . ' latest '
			. 'WHERE latest.worksheet_evaluation_id = e.id) '
			. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' previous ON previous.id = review.previous_review_id '
			. 'WHERE e.public_id = %s AND w.owner_user_id = %d LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $evaluation_id, $actor_user_id ) ) );
		if ( null === $row ) {
			return null;
		}

		$source_hash  = $this->hash_value( $row['source_hash'] ?? null );
		$result_count = $this->non_negative_integer( $row['result_count'] ?? null );
		if ( 1 !== $result_count ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$result_id       = $this->public_id( $row['result_id'] ?? null );
		$result_run_id   = $this->public_id( $row['result_run_id'] ?? null );
		$normalized_hash = $this->hash_value( $row['normalized_result_hash'] ?? null );
		$provider_digest = $this->hash_value( $row['provider_output_digest'] ?? null );
		$normalized      = $this->decode_canonical( (string) ( $row['normalized_result_json'] ?? '' ), $normalized_hash );
		$this->exact_keys(
			$normalized,
			array(
				'schema_id',
				'schema_version',
				'source_hash',
				'provider_status',
				'overall_status',
				'overall_confidence',
				'abstained',
				'safety_flagged',
				'summary',
				'abstain_reason',
				'dimensions',
				'findings',
				'limitations',
			)
		);
		if (
			Worksheet_Evaluation_Output_Normalizer::RESULT_SCHEMA !== ( $row['normalized_result_schema'] ?? null )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['normalized_result_version'] ?? null )
			|| Worksheet_Evaluation_Output_Normalizer::RESULT_SCHEMA !== ( $normalized['schema_id'] ?? null )
			|| Worksheet_Evaluation_Output_Normalizer::RESULT_VERSION !== ( $normalized['schema_version'] ?? null )
			|| ! hash_equals( $source_hash, (string) ( $normalized['source_hash'] ?? '' ) )
			|| 'succeeded' !== ( $row['result_run_state'] ?? null )
			|| ! hash_equals( $result_id, (string) ( $row['run_result_id'] ?? '' ) )
			|| ! hash_equals( $source_hash, $this->hash_value( $row['source_document_hash'] ?? null ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$result_content_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_id'          => $evaluation_id,
					'normalized_hash'        => $normalized_hash,
					'provider_output_digest' => $provider_digest,
					'run_id'                 => $result_run_id,
				)
			)
		);
		if ( ! hash_equals( $result_content_hash, $this->hash_value( $row['result_content_hash'] ?? null ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}

		$finding_keys        = $this->finding_keys( $normalized['findings'] ?? null );
		$review_count        = $this->non_negative_integer( $row['review_count'] ?? null );
		$latest_review_id    = null;
		$latest_review_pk    = null;
		$latest_review_no    = 0;
		$latest_review_items = array();
		if ( $review_count > 0 ) {
			$latest_review_id = $this->public_id( $row['latest_review_id'] ?? null );
			$latest_review_pk = $this->positive_integer( $row['latest_review_pk'] ?? null );
			$latest_review_no = $this->positive_integer( $row['latest_review_no'] ?? null );
			if ( $latest_review_no !== $review_count ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
			}
			$latest_review_items = $this->review_items( $row, $finding_keys );
			$previous_public_id  = $this->nullable_public_id( $row['previous_review_public_id'] ?? null );
			if ( 1 === $latest_review_no && null !== $previous_public_id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
			}
			$review_content_hash = $this->review_content_hash(
				$evaluation_id,
				$result_id,
				$latest_review_no,
				(string) ( $row['latest_review_decision'] ?? '' ),
				$this->hash_value( $row['item_decisions_hash'] ?? null ),
				$previous_public_id
			);
			if ( ! hash_equals( $review_content_hash, $this->hash_value( $row['latest_review_content_hash'] ?? null ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
			}
		} elseif ( null !== ( $row['latest_review_id'] ?? null ) || null !== ( $row['latest_review_no'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}

		return array(
			'evaluation_pk'           => $this->positive_integer( $row['evaluation_pk'] ?? null ),
			'evaluation_id'           => $this->public_id( $row['evaluation_id'] ?? null ),
			'evaluation_lock_version' => $this->non_negative_integer( $row['evaluation_lock_version'] ?? null ),
			'evaluation_state'        => $this->evaluation_state( $row['evaluation_state'] ?? null ),
			'worksheet_revision_id'   => $this->public_id( $row['worksheet_revision_id'] ?? null ),
			'source_revision_pk'      => $this->positive_integer( $row['source_revision_pk'] ?? null ),
			'worksheet_pk'            => $this->positive_integer( $row['worksheet_pk'] ?? null ),
			'current_revision_id'     => $this->public_id( $row['current_revision_id'] ?? null ),
			'source_hash'             => $source_hash,
			'result_pk'               => $this->positive_integer( $row['result_pk'] ?? null ),
			'result_id'               => $result_id,
			'finding_keys'            => $finding_keys,
			'latest_review_pk'        => $latest_review_pk,
			'latest_review_id'        => $latest_review_id,
			'latest_review_no'        => $latest_review_no,
			'latest_review_items'     => $latest_review_items,
		);
	}

	/**
	 * Reject invalid transition.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_transition( array $snapshot, array $record ): void {
		if (
			! hash_equals( $snapshot['evaluation_id'], (string) $record['evaluation_public_id'] )
			|| ! hash_equals( $snapshot['worksheet_revision_id'], (string) $record['worksheet_revision_public_id'] )
			|| ! hash_equals( $snapshot['source_hash'], (string) $record['worksheet_revision_hash'] )
			|| ! hash_equals( $snapshot['evaluation_state'], (string) $record['expected_evaluation_state'] )
			|| (int) $snapshot['evaluation_lock_version'] !== (int) $record['expected_evaluation_lock_version']
			|| ! hash_equals( $snapshot['result_id'], (string) $record['expected_result_public_id'] )
			|| (int) $snapshot['latest_review_no'] !== (int) $record['expected_review_no']
			|| $snapshot['latest_review_id'] !== $record['expected_previous_review_public_id']
			|| (int) $record['review_no'] !== (int) $snapshot['latest_review_no'] + 1
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::REVIEW_CONFLICT, $this->safe_state( $snapshot ) );
		}
		$allowed = array_fill_keys( $snapshot['finding_keys'], true );
		$seen    = array();
		foreach ( $record['item_decisions'] as $item ) {
			$key = (string) ( $item['finding_key'] ?? '' );
			if ( ! isset( $allowed[ $key ] ) || isset( $seen[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::REVIEW_CONFLICT, $this->safe_state( $snapshot ) );
			}
			$seen[ $key ] = true;
		}
		if ( 'completed' === $record['decision'] ) {
			if ( count( $seen ) !== count( $allowed ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::REVIEW_INCOMPLETE );
			}
			foreach ( $record['item_decisions'] as $item ) {
				if ( 'deferred' === ( $item['decision'] ?? null ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::REVIEW_INCOMPLETE );
				}
			}
		}
	}

	/**
	 * Reject invalid resolutions.
	 *
	 * @param array<string,mixed>            $snapshot Snapshot.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_resolutions( array $snapshot, array $items ): void {
		foreach ( $items as $item ) {
			if ( 'resolved' !== ( $item['decision'] ?? null ) ) {
				continue;
			}
			$resolution_id = (string) $item['resolution_revision_id'];
			if ( ! hash_equals( (string) $snapshot['current_revision_id'], $resolution_id ) ) {
				throw Worksheet_Evaluation_Failure::because(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					Worksheet_Evaluation_Failure::REVIEW_CONFLICT,
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					array( 'current_revision_id' => (string) $snapshot['current_revision_id'] )
				);
			}
			$row = $this->row(
				$this->prepare(
					'SELECT id, source_revision_id, document_hash FROM ' . self::TABLE_REVISIONS
						. ' WHERE public_id = %s AND worksheet_id = %d LIMIT 1 FOR UPDATE',
					array( $resolution_id, $snapshot['worksheet_pk'] )
				)
			);
			if (
				null === $row
				|| ! hash_equals( (string) $item['resolution_source_hash'], $this->hash_value( $row['document_hash'] ?? null ) )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::REVIEW_CONFLICT );
			}
			$cursor = $this->positive_integer( $row['id'] ?? null );
			$parent = $this->nullable_positive_integer( $row['source_revision_id'] ?? null );
			if ( $cursor === (int) $snapshot['source_revision_pk'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::REVIEW_CONFLICT );
			}
			$hops = 0;
			while ( $cursor !== (int) $snapshot['source_revision_pk'] ) {
				if ( null === $parent || ++$hops > self::MAX_REVISION_HOPS ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::REVIEW_CONFLICT );
				}
				$cursor = $parent;
				if ( $cursor === (int) $snapshot['source_revision_pk'] ) {
					break;
				}
				$ancestor = $this->row(
					$this->prepare(
						'SELECT source_revision_id FROM ' . self::TABLE_REVISIONS
							. ' WHERE id = %d AND worksheet_id = %d LIMIT 1 FOR UPDATE',
						array( $cursor, $snapshot['worksheet_pk'] )
					)
				);
				if ( null === $ancestor ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::REVIEW_CONFLICT );
				}
				$parent = $this->nullable_positive_integer( $ancestor['source_revision_id'] ?? null );
			}
		}
	}

	/**
	 * Insert review.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_review( array $snapshot, array $record ): void {
		$this->insert_or_fail(
			self::TABLE_REVIEWS,
			array(
				'public_id'                      => $record['review_public_id'],
				'content_hash'                   => $record['content_hash'],
				'created_by_user_id'             => $record['actor_user_id'],
				'created_at_utc'                 => $record['occurred_at_utc'],
				'worksheet_evaluation_id'        => $snapshot['evaluation_pk'],
				'worksheet_evaluation_result_id' => $snapshot['result_pk'],
				'review_no'                      => $record['review_no'],
				'decision'                       => $record['decision'],
				'note'                           => null,
				'item_decisions_schema'          => Worksheet_Evaluation_Query_Projector::REVIEW_ITEMS_SCHEMA,
				'item_decisions_version'         => Worksheet_Evaluation_Query_Projector::REVIEW_ITEMS_VERSION,
				'item_decisions_json'            => $record['item_decisions_json'],
				'item_decisions_hash'            => $record['item_decisions_hash'],
				'previous_review_id'             => $snapshot['latest_review_pk'],
			)
		);
	}

	/**
	 * Update evaluation.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_evaluation( array $snapshot, array $record ): void {
		$state   = 'completed' === $record['decision'] ? 'reviewed' : 'review_required';
		$updated = $this->query(
			$this->prepare(
				'UPDATE ' . self::TABLE_EVALUATIONS . ' SET state = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND state = %s',
				array(
					$state,
					$record['actor_user_id'],
					$record['occurred_at_utc'],
					$snapshot['evaluation_pk'],
					$snapshot['evaluation_lock_version'],
					$snapshot['evaluation_state'],
				)
			)
		);
		if ( 1 !== $updated ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::REVIEW_CONFLICT, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed>                $snapshot Snapshot.
	 * @param array<string,mixed>                $record Record.
	 * @param Worksheet_Evaluation_Review_Result $result Result.
	 * @param string                             $event Event.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit(
		array $snapshot,
		array $record,
		Worksheet_Evaluation_Review_Result $result,
		string $event
	): void {
		$previous_hash = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous_hash ) ) {
			throw $this->storage_failure();
		}
		$before_digest = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_state' => $snapshot['evaluation_state'],
					'result_id'        => $snapshot['result_id'],
					'review_no'        => $snapshot['latest_review_no'],
				)
			)
		);
		$after_digest  = hash( 'sha256', $this->canonical->encode( $this->receipt_payload( $result ) ) );
		$metadata      = array(
			'command_id'          => $record['command_id'],
			'decision'            => $result->decision(),
			'item_decisions_hash' => $record['item_decisions_hash'],
			'previous_review_id'  => $snapshot['latest_review_id'],
			'request_digest'      => $record['request_digest'],
			'result_id'           => $result->result_id(),
			'review_id'           => $result->review_id(),
			'review_no'           => $result->review_no(),
		);
		$metadata_json = $this->canonical->encode( $metadata );
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $after_digest,
					'before_digest'       => $before_digest,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['evaluation_public_id'],
					'object_type'         => 'worksheet_evaluation',
					'occurred_at_utc'     => $record['occurred_at_utc'],
					'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
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
				'object_type'         => 'worksheet_evaluation',
				'object_public_id'    => $record['evaluation_public_id'],
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
				'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param array<string,mixed>                $record Record.
	 * @param Worksheet_Evaluation_Review_Result $result Result.
	 * @param string                             $event Event.
	 * @return void
	 */
	private function insert_outbox(
		array $record,
		Worksheet_Evaluation_Review_Result $result,
		string $event
	): void {
		$payload_json = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'event'          => $event,
				'operation'      => 'review',
				'request_digest' => $record['request_digest'],
				'receipt'        => $this->receipt_payload( $result ),
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
				'aggregate_type'       => 'worksheet_evaluation',
				'aggregate_public_id'  => $record['evaluation_public_id'],
				'event_type'           => $event,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
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
	 * Command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @param bool   $for_update For update.
	 * @return Worksheet_Evaluation_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt(
		string $command_storage_key,
		string $request_digest,
		bool $for_update
	): ?Worksheet_Evaluation_Review_Result {
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $command_storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			! in_array( $row['event_type'] ?? null, array( self::EVENT_PROGRESS, self::EVENT_COMPLETED ), true )
			|| self::OUTBOX_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			throw $this->storage_failure();
		}
		$payload = $this->decode_canonical(
			(string) ( $row['payload_json'] ?? '' ),
			$this->hash_value( $row['payload_hash'] ?? null )
		);
		if (
			! hash_equals( $request_digest, (string) ( $payload['request_digest'] ?? '' ) )
			|| 'review' !== ( $payload['operation'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$receipt = $this->object( $payload['receipt'] ?? null );
		if ( ! hash_equals( (string) ( $row['aggregate_public_id'] ?? '' ), (string) ( $receipt['evaluation_id'] ?? '' ) ) ) {
			throw $this->storage_failure();
		}
		return $this->result_from_receipt( $receipt, true );
	}

	/**
	 * Recover receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Worksheet_Evaluation_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function recover_receipt( array $record ): ?Worksheet_Evaluation_Review_Result {
		try {
			return $this->command_receipt(
				(string) $record['command_storage_key'],
				(string) $record['request_digest'],
				false
			);
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Result from record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Worksheet_Evaluation_Review_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Worksheet_Evaluation_Review_Result {
		return new Worksheet_Evaluation_Review_Result(
			(string) $record['evaluation_public_id'],
			'completed' === $record['decision'] ? 'reviewed' : 'review_required',
			(string) $record['worksheet_revision_public_id'],
			(string) $record['worksheet_revision_hash'],
			(string) $record['expected_result_public_id'],
			(string) $record['review_public_id'],
			(int) $record['review_no'],
			(string) $record['decision'],
			$record['item_decisions'],
			(string) $record['occurred_at_utc'],
			$replayed
		);
	}

	/**
	 * Result from receipt.
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 * @param bool                $replayed Replayed.
	 * @return Worksheet_Evaluation_Review_Result
	 */
	private function result_from_receipt( array $receipt, bool $replayed ): Worksheet_Evaluation_Review_Result {
		return new Worksheet_Evaluation_Review_Result(
			(string) ( $receipt['evaluation_id'] ?? '' ),
			(string) ( $receipt['evaluation_state'] ?? '' ),
			(string) ( $receipt['worksheet_revision_id'] ?? '' ),
			(string) ( $receipt['source_hash'] ?? '' ),
			(string) ( $receipt['result_id'] ?? '' ),
			(string) ( $receipt['review_id'] ?? '' ),
			$this->positive_integer( $receipt['review_no'] ?? null ),
			(string) ( $receipt['decision'] ?? '' ),
			$this->review_decision_list( $receipt['item_decisions'] ?? null ),
			(string) ( $receipt['occurred_at_utc'] ?? '' ),
			$replayed
		);
	}

	/**
	 * Receipt payload.
	 *
	 * @param Worksheet_Evaluation_Review_Result $result Result.
	 * @return array<string,mixed>
	 */
	private function receipt_payload( Worksheet_Evaluation_Review_Result $result ): array {
		return array(
			'evaluation_id'         => $result->evaluation_id(),
			'evaluation_state'      => $result->evaluation_state(),
			'worksheet_revision_id' => $result->worksheet_revision_id(),
			'source_hash'           => $result->source_hash(),
			'result_id'             => $result->result_id(),
			'review_id'             => $result->review_id(),
			'review_no'             => $result->review_no(),
			'decision'              => $result->decision(),
			'item_decisions'        => $result->item_decisions(),
			'occurred_at_utc'       => $result->occurred_at_utc(),
		);
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition( array $record ): void {
		$row   = $this->row(
			$this->prepare(
				'SELECT e.state AS evaluation_state, review.review_no, review.decision, review.content_hash, review.item_decisions_hash, result.public_id AS result_id, (SELECT COUNT(*) FROM ' . self::TABLE_REVIEWS . ' counted WHERE counted.worksheet_evaluation_id = e.id) AS review_count '
					. 'FROM ' . self::TABLE_EVALUATIONS . ' e INNER JOIN ' . self::TABLE_REVIEWS . ' review '
					. 'ON review.worksheet_evaluation_id = e.id INNER JOIN ' . self::TABLE_RESULTS . ' result '
					. 'ON result.id = review.worksheet_evaluation_result_id '
					. 'WHERE e.public_id = %s AND review.public_id = %s LIMIT 1',
				array( $record['evaluation_public_id'], $record['review_public_id'] )
			)
		);
		$state = 'completed' === $record['decision'] ? 'reviewed' : 'review_required';
		if (
			null === $row
			|| $state !== ( $row['evaluation_state'] ?? null )
			|| (int) $record['review_no'] !== $this->positive_integer( $row['review_no'] ?? null )
			|| (int) $record['review_no'] !== $this->positive_integer( $row['review_count'] ?? null )
			|| $record['decision'] !== ( $row['decision'] ?? null )
			|| ! hash_equals( (string) $record['content_hash'], (string) ( $row['content_hash'] ?? '' ) )
			|| ! hash_equals( (string) $record['item_decisions_hash'], (string) ( $row['item_decisions_hash'] ?? '' ) )
			|| ! hash_equals( (string) $record['expected_result_public_id'], (string) ( $row['result_id'] ?? '' ) )
		) {
			throw $this->storage_failure();
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
		$expected = array(
			'actor_user_id',
			'command_id',
			'command_storage_key',
			'request_digest',
			'correlation_id',
			'occurred_at_utc',
			'evaluation_public_id',
			'worksheet_revision_public_id',
			'worksheet_revision_hash',
			'expected_evaluation_state',
			'expected_evaluation_lock_version',
			'expected_result_public_id',
			'expected_review_no',
			'expected_previous_review_public_id',
			'review_public_id',
			'review_no',
			'decision',
			'item_decisions',
			'item_decisions_json',
			'item_decisions_hash',
			'content_hash',
			'audit_public_id',
			'outbox_public_id',
		);
		$this->exact_keys( $record, $expected );
		if (
			! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1
			|| ! is_int( $record['expected_evaluation_lock_version'] ) || $record['expected_evaluation_lock_version'] < 0
			|| ! is_int( $record['expected_review_no'] ) || $record['expected_review_no'] < 0
			|| ! is_int( $record['review_no'] ) || $record['review_no'] !== $record['expected_review_no'] + 1
			|| ! in_array( $record['expected_evaluation_state'], array( 'review_required', 'reviewed' ), true )
			|| ! in_array( $record['decision'], array( 'in_progress', 'completed' ), true )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation review record identity is invalid.' );
		}
		foreach ( array( 'evaluation_public_id', 'worksheet_revision_public_id', 'expected_result_public_id', 'review_public_id', 'audit_public_id', 'outbox_public_id' ) as $key ) {
			$this->public_id_argument( $record[ $key ] ?? null );
		}
		$this->nullable_public_id_argument( $record['expected_previous_review_public_id'] ?? null );
		$this->assert_hash( (string) $record['worksheet_revision_hash'] );
		$this->assert_hash( (string) $record['request_digest'] );
		$this->assert_hash( (string) $record['item_decisions_hash'] );
		$this->assert_hash( (string) $record['content_hash'] );
		$this->assert_storage_key( (string) $record['command_storage_key'] );
		$this->safe_token( $record['command_id'] ?? null, 'command_id', 8, 128 );
		$this->safe_token( $record['correlation_id'] ?? null, 'correlation_id', 1, 100 );
		$this->datetime_argument( $record['occurred_at_utc'] ?? null );
		$items     = $this->review_decision_list( $record['item_decisions'] ?? null );
		$item_json = (string) ( $record['item_decisions_json'] ?? '' );
		$payload   = $this->decode_canonical_argument( $item_json, (string) $record['item_decisions_hash'] );
		$this->exact_keys( $payload, array( 'schema_id', 'schema_version', 'items' ) );
		if (
			Worksheet_Evaluation_Query_Projector::REVIEW_ITEMS_SCHEMA !== ( $payload['schema_id'] ?? null )
			|| '1.0.0' !== ( $payload['schema_version'] ?? null )
			|| $this->canonical->encode( $items ) !== $this->canonical->encode( $payload['items'] ?? null )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation review payload is invalid.' );
		}
		$content_hash = $this->review_content_hash(
			(string) $record['evaluation_public_id'],
			(string) $record['expected_result_public_id'],
			(int) $record['review_no'],
			(string) $record['decision'],
			(string) $record['item_decisions_hash'],
			$record['expected_previous_review_public_id']
		);
		if ( ! hash_equals( (string) $record['content_hash'], $content_hash ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review content hash is invalid.' );
		}
	}

	/**
	 * Finding keys.
	 *
	 * @param mixed $value Value.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function finding_keys( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 100 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$result = array();
		$seen   = array();
		foreach ( $value as $finding ) {
			if ( ! is_array( $finding ) || array_is_list( $finding ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
			}
			$key = $this->hash_value( $finding['finding_key'] ?? null );
			if ( isset( $seen[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
			}
			$seen[ $key ] = true;
			$result[]     = $key;
		}
		sort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Review items.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param array<int,string>   $finding_keys Finding keys.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function review_items( array $row, array $finding_keys ): array {
		if (
			Worksheet_Evaluation_Query_Projector::REVIEW_ITEMS_SCHEMA !== ( $row['item_decisions_schema'] ?? null )
			|| Worksheet_Evaluation_Query_Projector::REVIEW_ITEMS_VERSION !== $this->positive_integer( $row['item_decisions_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$payload = $this->decode_canonical(
			(string) ( $row['item_decisions_json'] ?? '' ),
			$this->hash_value( $row['item_decisions_hash'] ?? null )
		);
		$this->exact_keys( $payload, array( 'schema_id', 'schema_version', 'items' ) );
		$items = $this->review_decision_list( $payload['items'] ?? null );
		if (
			Worksheet_Evaluation_Query_Projector::REVIEW_ITEMS_SCHEMA !== ( $payload['schema_id'] ?? null )
			|| '1.0.0' !== ( $payload['schema_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$allowed = array_fill_keys( $finding_keys, true );
		foreach ( $items as $item ) {
			if ( ! isset( $allowed[ $item['finding_key'] ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
			}
		}
		return $items;
	}

	/**
	 * Review decision list.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function review_decision_list( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 100 ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review items are invalid.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Worksheet evaluation review item is invalid.' );
			}
			$this->exact_keys( $item, array( 'finding_key', 'decision', 'reason_code', 'note', 'resolution_revision_id', 'resolution_source_hash' ) );
			$key      = $this->hash_argument( $item['finding_key'] ?? null );
			$decision = $item['decision'] ?? null;
			if ( isset( $seen[ $key ] ) || ! in_array( $decision, array( 'resolved', 'deferred', 'dismissed' ), true ) ) {
				throw new InvalidArgumentException( 'Worksheet evaluation review item identity is invalid.' );
			}
			$seen[ $key ]    = true;
			$reason          = $item['reason_code'] ?? null;
			$note            = $item['note'] ?? null;
			$resolution_id   = $item['resolution_revision_id'] ?? null;
			$resolution_hash = $item['resolution_source_hash'] ?? null;
			if ( null !== $reason ) {
				$this->reason_argument( $reason );
			}
			if ( null !== $note ) {
				$this->note_argument( $note );
			}
			if ( 'resolved' === $decision ) {
				$this->public_id_argument( $resolution_id );
				$this->hash_argument( $resolution_hash );
			} elseif ( null !== $resolution_id || null !== $resolution_hash || null === $reason || null === $note ) {
				throw new InvalidArgumentException( 'Worksheet evaluation review item evidence is invalid.' );
			}
			$result[] = array(
				'finding_key'            => $key,
				'decision'               => $decision,
				'reason_code'            => $reason,
				'note'                   => $note,
				'resolution_revision_id' => $resolution_id,
				'resolution_source_hash' => $resolution_hash,
			);
		}
		usort( $result, static fn ( array $left, array $right ): int => strcmp( $left['finding_key'], $right['finding_key'] ) );
		return $result;
	}

	/**
	 * Review content hash.
	 *
	 * @param string      $evaluation_id Evaluation id.
	 * @param string      $result_id Result id.
	 * @param int         $review_no Review no.
	 * @param string      $decision Decision.
	 * @param string      $item_hash Item hash.
	 * @param string|null $previous_review_id Previous review id.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function review_content_hash(
		string $evaluation_id,
		string $result_id,
		int $review_no,
		string $decision,
		string $item_hash,
		?string $previous_review_id
	): string {
		if ( ! in_array( $decision, array( 'in_progress', 'completed' ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review decision is invalid.' );
		}
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'decision'            => $decision,
					'evaluation_id'       => $evaluation_id,
					'item_decisions_hash' => $item_hash,
					'previous_review_id'  => $previous_review_id,
					'result_id'           => $result_id,
					'review_no'           => $review_no,
				)
			)
		);
	}

	/**
	 * Safe state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return array<string,string>
	 */
	private function safe_state( array $snapshot ): array {
		return array(
			'current_state'     => (string) ( $snapshot['evaluation_state'] ?? '' ),
			'current_result_id' => (string) ( $snapshot['result_id'] ?? '' ),
			'current_review_no' => (string) ( $snapshot['latest_review_no'] ?? '' ),
		);
	}

	/**
	 * Object.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function object( $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw $this->storage_failure();
		}
		return $value;
	}

	/**
	 * Decode canonical.
	 *
	 * @param string $json Json.
	 * @param string $expected_hash Expected hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_canonical( string $json, string $expected_hash ): array {
		try {
			return $this->decode_canonical_argument( $json, $expected_hash ); } catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
			}
	}

	/**
	 * Decode canonical argument.
	 *
	 * @param string $json Json.
	 * @param string $expected_hash Expected hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_canonical_argument( string $json, string $expected_hash ): array {
		if ( ! hash_equals( $expected_hash, hash( 'sha256', $json ) ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation canonical hash is invalid.' );
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			throw new InvalidArgumentException( 'Worksheet evaluation canonical JSON is invalid.' );
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
				throw new InvalidArgumentException( 'Worksheet evaluation canonical JSON drifted.' );
			}
			return $value;
	}

	/**
	 * Evaluation state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evaluation_state( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'requested', 'queued', 'running', 'review_required', 'reviewed', 'failed', 'cancelled' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Reject invalid storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_storage_key( string $value ): void {
		if ( 1 !== preg_match( '/^command:[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review storage key is invalid.' );
		}
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
			throw new InvalidArgumentException( 'Worksheet evaluation review hash is invalid.' );
		}
	}

	/**
	 * Hash argument.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_argument( $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review hash is invalid.' );
		}
		$this->assert_hash( $value );
		return $value;
	}

	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Public id argument.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id_argument( $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review public ID is invalid.' );
		}
		new Public_Id( $value );
		return $value;
	}

	/**
	 * Nullable public id argument.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function nullable_public_id_argument( $value ): ?string {
		return null === $value ? null : $this->public_id_argument( $value );
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( $value ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}
		new Public_Id( $value );
		return $value;
	}

	/**
	 * Nullable public id.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function nullable_public_id( $value ): ?string {
		return null === $value ? null : $this->public_id( $value );
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
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
	}

	/**
	 * Nullable positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private function nullable_positive_integer( $value ): ?int {
		return null === $value ? null : $this->positive_integer( $value );
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
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
	}

	/**
	 * Reason argument.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function reason_argument( $value ): void {
		if ( ! is_string( $value ) || strlen( $value ) > 64 || 1 !== preg_match( '/^[a-z][a-z0-9_.-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review reason is invalid.' );
		}
	}

	/**
	 * Note argument.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function note_argument( $value ): void {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 4000 ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review note is invalid.' );
		}
	}

	/**
	 * Safe token.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( $value, string $field, int $minimum, int $maximum ): void {
		if (
			! is_string( $value ) || strlen( $value ) < $minimum || strlen( $value ) > $maximum
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation review ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Datetime argument.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function datetime_argument( $value ): void {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review UTC timestamp is invalid.' );
		}
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
			throw new InvalidArgumentException( 'Worksheet evaluation review contains unknown or missing fields.' );
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
		$prepared = $this->database()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw $this->storage_failure();
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
		if ( ! is_array( $row ) || array_is_list( $row ) || '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			throw $this->storage_failure();
		}
		return $row;
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
		if ( '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			throw $this->storage_failure();
		}
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
		$result = $this->database()->query( $sql );
		if ( false === $result || '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			throw $this->storage_failure();
		}
		return (int) $result;
	}

	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_or_fail( string $sql ): void {
		$result = $this->database()->query( $sql );
		if ( false === $result || '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			throw $this->storage_failure();
		}
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
		$result = $this->database()->insert( $table, $data );
		if ( false === $result || 1 !== (int) $result || '' !== (string) ( $this->database()->last_error ?? '' ) ) {
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
			! is_object( $database )
			|| ! method_exists( $database, 'prepare' )
			|| ! method_exists( $database, 'get_row' )
			|| ! method_exists( $database, 'get_var' )
			|| ! method_exists( $database, 'query' )
			|| ! method_exists( $database, 'insert' )
		) {
			throw $this->storage_failure();
		}
		return $database;
	}

	/**
	 * Storage failure.
	 *
	 * @return Worksheet_Evaluation_Failure
	 */
	private function storage_failure(): Worksheet_Evaluation_Failure {
		return Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
	}
}
