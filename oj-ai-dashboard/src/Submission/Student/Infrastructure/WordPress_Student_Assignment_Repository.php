<?php
/**
 * WordPress student assignment projection and start repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Application\Autosave_Own_Submission_Result;
use OJAI\Dashboard\Submission\Student\Application\Start_Own_Submission_Result;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Save_Token;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses literal add-on tables and never projects another learner's row.
 */
final class WordPress_Student_Assignment_Repository implements Student_Assignment_Repository_Port_Interface {
	private const TABLE_ASSIGNMENTS  = 'oj_ai_assignments';
	private const TABLE_TARGETS      = 'oj_ai_assignment_targets';
	private const TABLE_SUBMISSIONS  = 'oj_ai_submissions';
	private const TABLE_PUBLICATIONS = 'oj_ai_worksheet_publications';
	private const TABLE_AUDIT        = 'oj_ai_audit_events';
	private const TABLE_OUTBOX       = 'oj_ai_outbox_events';
	private const TABLE_RELEASES     = 'oj_ai_submission_release_revisions';
	private const TABLE_EVALUATIONS  = 'oj_ai_submission_evaluations';
	private const TABLE_REVIEWS      = 'oj_ai_submission_evaluation_reviews';
	private const TABLE_REVISIONS    = 'oj_ai_submission_revisions';

	private const EVENT_TYPE     = 'submission.started';
	private const PAYLOAD_SCHEMA = 'oj_ai.submission_started_event';
	private const AUDIT_SCHEMA   = 'oj_ai.submission_audit';
	private const VERSION        = 1;
	private const MAX_SCAN_ROWS  = 5000;

	/**
	 * WordPress student assignment repository.
	 *
	 * @var array<int,string>
	 */
	private const FORBIDDEN_PROJECTION_KEYS = array(
		'grading',
		'grading_enabled',
		'grading_schema',
		'grading_policy',
		'grade',
		'score',
		'scoring',
		'points',
		'point',
		'max_points',
		'max_score',
		'min_score',
		'total_score',
		'correct',
		'correct_answer',
		'answer',
		'answers',
		'answer_key',
		'example_answer',
		'expected_answer',
		'solution',
		'rubric',
		'rubric_id',
		'rubric_criterion_ids',
		'teacher_note',
		'teacher_memo',
		'private_metadata',
		'owner_user_id',
		'actor_user_id',
		'model_config',
		'system_prompt',
		'api_key',
		'authorization',
		'cookie',
		'feedback',
		'evaluation',
	);

	/**
	 * Submission queries.
	 *
	 * @var WordPress_Student_Submission_Query
	 */
	private WordPress_Student_Submission_Query $submission_queries;
	/**
	 * Submission autosaves.
	 *
	 * @var WordPress_Student_Submission_Autosave
	 */
	private WordPress_Student_Submission_Autosave $submission_autosaves;
	/**
	 * Save tokens.
	 *
	 * @var Submission_Save_Token
	 */
	private Submission_Save_Token $save_tokens;

	/**
	 * Initialize WordPress student assignment repository dependencies and state.
	 *
	 * @param object|null              $database Database.
	 * @param Canonical_Json|null      $canonical Canonical JSON encoder used for stable digests.
	 * @param Signed_Cursor_Codec|null $cursors Cursors.
	 */
	public function __construct(
		private ?object $database = null,
		private ?Canonical_Json $canonical = null,
		private ?Signed_Cursor_Codec $cursors = null
	) {
		$this->canonical            = $canonical ?? new Canonical_Json();
		$this->cursors              = $cursors ?? new Signed_Cursor_Codec( $this->canonical );
		$this->submission_queries   = new WordPress_Student_Submission_Query( $this->database, $this->canonical );
		$this->submission_autosaves = new WordPress_Student_Submission_Autosave( $this->database, $this->canonical );
		$this->save_tokens          = new Submission_Save_Token( $this->canonical );
	}

	/**
	 * List for actor.
	 *
	 * @param int                                                                                                                                                $actor_user_id Authenticated actor from the access context.
	 * @param array<string,array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>}> $contexts Contexts.
	 * @param string                                                                                                                                             $cursor Continuation cursor from the previous page.
	 * @param int                                                                                                                                                $limit Maximum number of entries requested.
	 * @param string                                                                                                                                             $now_utc Now utc.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_for_actor( int $actor_user_id, array $contexts, string $cursor, int $limit, string $now_utc ): array {
		$this->actor( $actor_user_id );
		$this->assert_context_map( $contexts );
		$this->assert_page( $cursor, $limit );
		$this->assert_utc( $now_utc );
		$cursor_value = $this->cursors->decode( $cursor, 'student_assignment_list' );
		if ( array() !== $cursor_value ) {
			$this->exact_keys( $cursor_value, array( 'a', 'd', 'i' ) );
			if ( $actor_user_id !== $this->positive_integer( $cursor_value['a'] ?? null ) ) {
				throw new InvalidArgumentException( 'Student assignment cursor actor does not match.' );
			}
			$this->assert_utc( (string) ( $cursor_value['d'] ?? '' ) );
			new Public_Id( (string) ( $cursor_value['i'] ?? '' ) );
		}

		if ( array() === $contexts ) {
			return array(
				'items'       => array(),
				'next_cursor' => '',
			);
		}
		$sql  = $this->base_select()
			. ' FROM ' . self::TABLE_ASSIGNMENTS . ' a '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = %d AND t.state = %s '
			. 'LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.assignment_id = a.id AND s.learner_user_id = %d '
			. 'WHERE a.state IN (%s,%s,%s) ORDER BY a.due_at_utc ASC, a.public_id ASC LIMIT %d';
		$rows = $this->get_results(
			$this->prepare( $sql, array( $actor_user_id, 'active', $actor_user_id, 'scheduled', 'open', 'closed', self::MAX_SCAN_ROWS + 1 ) )
		);
		if ( count( $rows ) > self::MAX_SCAN_ROWS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ROSTER_TOO_LARGE );
		}
		$eligible = array();
		foreach ( $rows as $row ) {
			$key = (string) ( $row['context_type'] ?? '' ) . ':' . (string) ( $row['context_id'] ?? '' );
			if ( ! isset( $contexts[ $key ] ) || 'active' !== $contexts[ $key ]['lifecycle_state'] ) {
				continue;
			}
			$item = $this->project_assignment( $row, $contexts[ $key ], $now_utc );
			if ( array() !== $cursor_value ) {
				$after = $item['schedule']['due_at_utc'] > $cursor_value['d']
					|| ( $item['schedule']['due_at_utc'] === $cursor_value['d'] && $item['id'] > $cursor_value['i'] );
				if ( ! $after ) {
					continue;
				}
			}
			$eligible[] = $item;
		}
		$has_more = count( $eligible ) > $limit;
		$items    = array_slice( $eligible, 0, $limit );
		$next     = '';
		if ( $has_more && array() !== $items ) {
			$last = $items[ count( $items ) - 1 ];
			$next = $this->cursors->encode(
				'student_assignment_list',
				array(
					'a' => $actor_user_id,
					'd' => $last['schedule']['due_at_utc'],
					'i' => $last['id'],
				)
			);
		}

		return array(
			'items'       => $items,
			'next_cursor' => $next,
		);
	}

	/**
	 * Find for actor.
	 *
	 * @param string                                                                                                                               $assignment_id Distribution public identifier.
	 * @param int                                                                                                                                  $actor_user_id Authenticated actor from the access context.
	 * @param array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>} $context Access or execution context for this operation.
	 * @param string                                                                                                                               $now_utc Now utc.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_for_actor( string $assignment_id, int $actor_user_id, array $context, string $now_utc ): ?array {
		new Public_Id( $assignment_id );
		$this->actor( $actor_user_id );
		$this->assert_context( $context );
		$this->assert_utc( $now_utc );
		$sql = $this->base_select()
			. ', p.content_hash AS stored_content_hash, p.publication_hash AS stored_publication_hash, '
			. 'p.student_document_schema, p.student_document_version, p.student_document_json, p.student_document_hash, '
			. 'p.response_schema_schema, p.response_schema_version, p.response_schema_json, p.response_schema_hash '
			. 'FROM ' . self::TABLE_ASSIGNMENTS . ' a '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = %d AND t.state = %s '
			. 'INNER JOIN ' . self::TABLE_PUBLICATIONS . ' p ON p.public_id = a.worksheet_publication_public_id '
			. 'LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.assignment_id = a.id AND s.learner_user_id = %d '
			. 'WHERE a.public_id = %s AND a.state IN (%s,%s,%s) LIMIT 1';
		$row = $this->get_row(
			$this->prepare( $sql, array( $actor_user_id, 'active', $actor_user_id, $assignment_id, 'scheduled', 'open', 'closed' ) )
		);
		if ( null === $row || $context['type'] !== ( $row['context_type'] ?? null ) || $context['id'] !== ( $row['context_id'] ?? null ) ) {
			return null;
		}
		$assignment                     = $this->project_assignment( $row, $context, $now_utc );
		$assignment['student_document'] = null;
		$assignment['response_schema']  = null;
		if ( $this->may_read_document( $assignment['state'], $assignment['availability'] ) ) {
			$publication_hash = (string) ( $row['publication_hash'] ?? '' );
			$this->assert_hash( $publication_hash );
			if (
				! hash_equals( $publication_hash, (string) ( $row['stored_content_hash'] ?? '' ) )
				|| ! hash_equals( $publication_hash, (string) ( $row['stored_publication_hash'] ?? '' ) )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
			$student_document = $this->profile( $row, 'student_document', 'oj_ai.student_document', '1.0.0' );
			$response_schema  = $this->profile( $row, 'response_schema', 'oj_ai.student_response_schema', '1.0.0' );
			if ( ! hash_equals( (string) $student_document['source_hash'], (string) $response_schema['source_hash'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
			$assignment['student_document'] = $student_document;
			$assignment['response_schema']  = $response_schema;
		}

		return array( 'assignment' => $assignment );
	}

	/**
	 * Find submission for actor.
	 *
	 * @param string                                                                                                                               $submission_id Submission public identifier.
	 * @param int                                                                                                                                  $actor_user_id Authenticated actor from the access context.
	 * @param array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>} $context Access or execution context for this operation.
	 * @param string                                                                                                                               $now_utc Now utc.
	 * @return array<string,mixed>|null
	 */
	public function find_submission_for_actor( string $submission_id, int $actor_user_id, array $context, string $now_utc ): ?array {
		return $this->submission_queries->find_for_actor( $submission_id, $actor_user_id, $context, $now_utc );
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array{assignment_id:string,context_type:string,context_id:string,context_revision:string,current_revision:string,state:string,target_state:string}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorization_snapshot( string $assignment_id, int $actor_user_id ): ?array {
		new Public_Id( $assignment_id );
		$this->actor( $actor_user_id );
		$sql = 'SELECT a.public_id, a.lock_version, a.context_type, a.context_id, a.context_revision, a.state, t.state AS target_state FROM ' . self::TABLE_ASSIGNMENTS . ' a INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id '
			. 'WHERE a.public_id = %s AND t.learner_user_id = %d LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( $assignment_id, $actor_user_id ) ) );
		if ( null === $row ) {
			return null;
		}
		$stored_assignment_id = (string) ( $row['public_id'] ?? '' );
		new Public_Id( $stored_assignment_id );
		if ( ! hash_equals( $assignment_id, $stored_assignment_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$state        = $this->assignment_state( $row['state'] ?? null );
		$target_state = (string) ( $row['target_state'] ?? '' );
		if ( ! in_array( $target_state, array( 'active', 'revoked' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}

		return array(
			'assignment_id'    => $stored_assignment_id,
			'context_type'     => $this->context_type( $row['context_type'] ?? null ),
			'context_id'       => $this->token( $row['context_id'] ?? null ),
			'context_revision' => $this->token( $row['context_revision'] ?? null ),
			'current_revision' => (string) $this->non_negative_integer( $row['lock_version'] ?? null ),
			'state'            => $state,
			'target_state'     => $target_state,
		);
	}

	/**
	 * Submission authorization snapshot.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array{submission_id:string,assignment_id:string,context_type:string,context_id:string,context_revision:string,current_revision:string,state:string,assignment_state:string,target_state:string}|null
	 */
	public function submission_authorization_snapshot( string $submission_id, int $actor_user_id ): ?array {
		return $this->submission_queries->authorization_snapshot( $submission_id, $actor_user_id );
	}

	/**
	 * Find start receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Start_Own_Submission_Result|null
	 */
	public function find_start_receipt( string $idempotency_storage_key, string $request_digest ): ?Start_Own_Submission_Result {
		$this->assert_storage_key( $idempotency_storage_key );
		$this->assert_hash( $request_digest );

		return $this->receipt( $idempotency_storage_key, $request_digest, false );
	}

	/**
	 * Start.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Start_Own_Submission_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function start( array $record ): Start_Own_Submission_Result {
		$this->assert_start_record( $record );
		$replay = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
		if ( $replay instanceof Start_Own_Submission_Result ) {
			return $replay;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$replay      = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], true );
			if ( $replay instanceof Start_Own_Submission_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $replay;
			}
			$current = $this->lock_actor_assignment( $record['assignment_public_id'], $record['actor_user_id'] );
			if ( null === $current || 'active' !== ( $current['target_state'] ?? null ) ) {
				throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
			}
			$this->assert_start_expected( $current, $record );
			$existing = $this->lock_submission( $this->positive_integer( $current['assignment_pk'] ?? null ), $record['actor_user_id'] );
			if ( null !== $existing ) {
				$result = $this->start_result_from_row( $existing, $record['assignment_public_id'], (string) $current['publication_hash'], (string) $current['schedule_hash'], false, false );
				$this->query_or_fail( 'COMMIT' );
				return $result;
			}

			$this->insert_or_fail(
				self::TABLE_SUBMISSIONS,
				array(
					'public_id'                   => $record['submission_public_id'],
					'lock_version'                => 0,
					'created_by_user_id'          => $record['actor_user_id'],
					'created_at_utc'              => $record['occurred_at_utc'],
					'updated_by_user_id'          => $record['actor_user_id'],
					'updated_at_utc'              => $record['occurred_at_utc'],
					'assignment_id'               => $this->positive_integer( $current['assignment_pk'] ?? null ),
					'learner_user_id'             => $record['actor_user_id'],
					'state'                       => 'draft',
					'current_revision_public_id'  => null,
					'official_revision_public_id' => null,
					'official_attempt_no'         => 0,
					'official_review_public_id'   => null,
					'official_review_hash'        => null,
					'current_release_public_id'   => null,
					'current_release_hash'        => null,
					'started_at_utc'              => $record['occurred_at_utc'],
					'submitted_at_utc'            => null,
					'closed_at_utc'               => null,
				)
			);
			$submission_pk = $this->insert_id();
			$result        = $this->start_result(
				$record['submission_public_id'],
				$record['assignment_public_id'],
				$record['occurred_at_utc'],
				(string) $current['publication_hash'],
				(string) $current['schedule_hash'],
				true,
				false
			);
			$this->insert_audit( $current, $record, $result );
			$this->insert_outbox( $record, $result );
			$this->assert_start_postcondition( $submission_pk, $record );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Assignment_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$replay = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
			if ( $replay instanceof Start_Own_Submission_Result ) {
				return $replay;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Find autosave receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Autosave_Own_Submission_Result|null
	 */
	public function find_autosave_receipt( string $idempotency_storage_key, string $request_digest ): ?Autosave_Own_Submission_Result {
		return $this->submission_autosaves->find_receipt( $idempotency_storage_key, $request_digest );
	}

	/**
	 * Autosave.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Autosave_Own_Submission_Result
	 */
	public function autosave( array $record ): Autosave_Own_Submission_Result {
		return $this->submission_autosaves->autosave( $record );
	}

	/**
	 * Base select.
	 *
	 * @return string
	 */
	private function base_select(): string {
		return 'SELECT a.id AS assignment_pk, a.public_id AS assignment_id, a.lock_version AS assignment_lock_version, a.context_type, a.context_id, a.context_revision, a.worksheet_publication_public_id, a.publication_hash, a.title_snapshot, a.state AS assignment_state, a.opens_at_utc, a.due_at_utc, a.closes_at_utc, a.schedule_version, a.schedule_hash, a.auto_submit_enabled, a.updated_at_utc, t.public_id AS target_id, t.state AS target_state, s.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, s.current_revision_public_id, s.official_revision_public_id, s.started_at_utc, s.submitted_at_utc, s.closed_at_utc, (SELECT released.public_id FROM ' . self::TABLE_RELEASES . ' released '
			. 'INNER JOIN ' . self::TABLE_EVALUATIONS . ' evaluation ON evaluation.id = released.submission_evaluation_id '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.public_id = evaluation.submission_revision_public_id '
			. 'INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.id = released.submission_review_id AND review.submission_evaluation_id = evaluation.id '
			. 'WHERE released.public_id = s.current_release_public_id AND released.content_hash = s.current_release_hash '
			. 'AND revision.submission_id = s.id AND revision.public_id = s.official_revision_public_id '
			. 'AND review.public_id = s.official_review_public_id AND review.content_hash = s.official_review_hash '
			. "AND evaluation.state = 'reviewed' AND released.state IN ('released','corrected') LIMIT 1) AS visible_release_id";
	}

	/**
	 * Project assignment.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param array<string,mixed> $context Access or execution context for this operation.
	 * @param string              $now_utc Now utc.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function project_assignment( array $row, array $context, string $now_utc ): array {
		$id = (string) ( $row['assignment_id'] ?? '' );
		new Public_Id( $id );
		$publication_id = (string) ( $row['worksheet_publication_public_id'] ?? '' );
		new Public_Id( $publication_id );
		$state            = $this->assignment_state( $row['assignment_state'] ?? null );
		$lock_version     = $this->non_negative_integer( $row['assignment_lock_version'] ?? null );
		$schedule_version = $this->positive_integer( $row['schedule_version'] ?? null );
		$schedule         = array(
			'opens_at_utc'  => $this->optional_utc( $row['opens_at_utc'] ?? null ),
			'due_at_utc'    => $this->required_utc( $row['due_at_utc'] ?? null ),
			'closes_at_utc' => $this->required_utc( $row['closes_at_utc'] ?? null ),
		);
		if ( ( null !== $schedule['opens_at_utc'] && $schedule['opens_at_utc'] > $schedule['due_at_utc'] ) || $schedule['due_at_utc'] > $schedule['closes_at_utc'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$schedule_hash    = (string) ( $row['schedule_hash'] ?? '' );
		$publication_hash = (string) ( $row['publication_hash'] ?? '' );
		$this->assert_hash( $schedule_hash );
		$this->assert_hash( $publication_hash );
		$calculated_schedule_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'auto_submit_enabled' => $this->auto_submit_from_row( $row ),
					'closes_at_utc'       => $schedule['closes_at_utc'],
					'due_at_utc'          => $schedule['due_at_utc'],
					'opens_at_utc'        => $schedule['opens_at_utc'],
					'schedule_version'    => $schedule_version,
				)
			)
		);
		if ( ! hash_equals( $schedule_hash, $calculated_schedule_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$availability = $this->availability( $state, $schedule, $now_utc );
		$submission   = $this->submission_projection( $row );
		$actions      = array();
		if ( $this->may_read_document( $state, $availability ) ) {
			$actions[] = 'assignment.export_pdf';
		}
		if ( in_array( $availability, array( 'open', 'late' ), true ) ) {
			if ( null === $submission ) {
				$actions[] = 'assignment.start';
			} elseif ( in_array( $submission['state'], array( 'draft', 'conflict', 'reopened' ), true ) ) {
				$actions[] = 'assignment.continue';
			}
		}
		$attributes = $context['attributes'];

		return array(
			'id'                       => $id,
			'title'                    => $this->title( $row['title_snapshot'] ?? null ),
			'state'                    => $state,
			'availability'             => $availability,
			'lock_version'             => $lock_version,
			'worksheet_publication_id' => $publication_id,
			'publication_hash'         => $publication_hash,
			'context'                  => array(
				'type'     => $context['type'],
				'id'       => $context['id'],
				'label'    => $context['label'],
				'revision' => $context['revision'],
				'subject'  => $this->attribute_label( $attributes, 'subject_label' ),
				'teacher'  => $this->attribute_label( $attributes, 'teacher_label' ),
			),
			'schedule_version'         => $schedule_version,
			'schedule_hash'            => $schedule_hash,
			'schedule'                 => $schedule,
			'submission'               => $submission,
			'result'                   => null === $submission || null === ( $row['visible_release_id'] ?? null )
				? null : array( 'release_id' => $this->optional_public_id( $row['visible_release_id'] ) ),
			'actions'                  => $actions,
		);
	}

	/**
	 * Submission projection.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function submission_projection( array $row ): ?array {
		if ( null === ( $row['submission_id'] ?? null ) ) {
			return null;
		}
		$id = (string) $row['submission_id'];
		new Public_Id( $id );
		$state = (string) ( $row['submission_state'] ?? '' );
		if ( ! in_array( $state, array( 'draft', 'conflict', 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}

		return array(
			'id'                   => $id,
			'state'                => $state,
			'lock_version'         => $this->non_negative_integer( $row['submission_lock_version'] ?? null ),
			'current_revision_id'  => $this->optional_public_id( $row['current_revision_public_id'] ?? null ),
			'official_revision_id' => $this->optional_public_id( $row['official_revision_public_id'] ?? null ),
			'started_at_utc'       => $this->optional_utc( $row['started_at_utc'] ?? null ),
			'submitted_at_utc'     => $this->optional_utc( $row['submitted_at_utc'] ?? null ),
			'closed_at_utc'        => $this->optional_utc( $row['closed_at_utc'] ?? null ),
		);
	}

	/**
	 * Availability.
	 *
	 * @param string                                                             $state State.
	 * @param array{opens_at_utc:?string,due_at_utc:string,closes_at_utc:string} $schedule Schedule.
	 * @param string                                                             $now_utc Now utc.
	 * @return string
	 */
	private function availability( string $state, array $schedule, string $now_utc ): string {
		if ( 'closed' === $state || $now_utc >= $schedule['closes_at_utc'] ) {
			return 'closed';
		}
		if ( 'scheduled' === $state || ( null !== $schedule['opens_at_utc'] && $now_utc < $schedule['opens_at_utc'] ) ) {
			return 'upcoming';
		}

		return $now_utc > $schedule['due_at_utc'] ? 'late' : 'open';
	}

	/**
	 * May read document.
	 *
	 * @param string $state State.
	 * @param string $availability Availability.
	 * @return bool
	 */
	private function may_read_document( string $state, string $availability ): bool {
		return 'closed' === $state || ( 'open' === $state && in_array( $availability, array( 'open', 'late', 'closed' ), true ) );
	}

	/**
	 * Profile.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $stem Stem.
	 * @param string              $schema Schema.
	 * @param string              $version Version.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function profile( array $row, string $stem, string $schema, string $version ): array {
		if ( $schema !== ( $row[ $stem . '_schema' ] ?? null ) || 1 !== $this->positive_integer( $row[ $stem . '_version' ] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$value = $this->decode_json( (string) ( $row[ $stem . '_json' ] ?? '' ), (string) ( $row[ $stem . '_hash' ] ?? '' ) );
		$this->exact_keys( $value, array( 'payload', 'payload_hash', 'projector_version', 'schema_id', 'schema_version', 'source_hash' ) );
		if (
			$schema !== ( $value['schema_id'] ?? null ) || $version !== ( $value['schema_version'] ?? null )
			|| ! is_array( $value['payload'] ?? null ) || ! is_string( $value['payload_hash'] ?? null )
			|| ! hash_equals( hash( 'sha256', $this->canonical->encode( $value['payload'] ) ), $value['payload_hash'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$this->assert_hash( (string) ( $value['source_hash'] ?? '' ) );
		$this->assert_no_private_keys( $value );

		return $value;
	}

	/**
	 * Reject invalid start expected.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_start_expected( array $current, array $record ): void {
		$state            = $this->assignment_state( $current['assignment_state'] ?? null );
		$lock_version     = $this->non_negative_integer( $current['assignment_lock_version'] ?? null );
		$schedule_version = $this->positive_integer( $current['schedule_version'] ?? null );
		$schedule         = array(
			'opens_at_utc'        => $this->optional_utc( $current['opens_at_utc'] ?? null ),
			'due_at_utc'          => $this->required_utc( $current['due_at_utc'] ?? null ),
			'closes_at_utc'       => $this->required_utc( $current['closes_at_utc'] ?? null ),
			'auto_submit_enabled' => 1 === $this->zero_or_one( $current['auto_submit_enabled'] ?? null ),
		);
		if ( ( null !== $schedule['opens_at_utc'] && $schedule['opens_at_utc'] > $schedule['due_at_utc'] ) || $schedule['due_at_utc'] > $schedule['closes_at_utc'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$schedule_hash    = (string) ( $current['schedule_hash'] ?? '' );
		$publication_hash = (string) ( $current['publication_hash'] ?? '' );
		$this->assert_hash( $schedule_hash );
		$this->assert_hash( $publication_hash );
		$actual_schedule_hash = hash( 'sha256', $this->canonical->encode( array_merge( $schedule, array( 'schedule_version' => $schedule_version ) ) ) );
		if ( ! hash_equals( $schedule_hash, $actual_schedule_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		if (
			$record['expected_state'] !== $state || $record['expected_lock_version'] !== $lock_version
			|| $record['expected_schedule_version'] !== $schedule_version
			|| ! hash_equals( $record['expected_schedule_hash'], $schedule_hash )
			|| ! hash_equals( $record['expected_publication_hash'], $publication_hash )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		if (
			'open' !== $state || ( null !== $schedule['opens_at_utc'] && $record['occurred_at_utc'] < $schedule['opens_at_utc'] )
			|| $record['occurred_at_utc'] >= $schedule['closes_at_utc']
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		if (
			'' === ( $record['authorized_context_revision'] )
			|| '' === ( $this->context_type( $current['context_type'] ?? null ) )
			|| '' === ( $this->token( $current['context_id'] ?? null ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Lock actor assignment.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array<string,mixed>|null
	 */
	private function lock_actor_assignment( string $assignment_id, int $actor_user_id ): ?array {
		$sql = 'SELECT a.id AS assignment_pk, a.public_id AS assignment_id, a.lock_version AS assignment_lock_version, a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, a.publication_hash, a.opens_at_utc, a.due_at_utc, a.closes_at_utc, a.schedule_version, a.schedule_hash, a.auto_submit_enabled, t.state AS target_state FROM ' . self::TABLE_ASSIGNMENTS . ' a INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id '
			. 'WHERE a.public_id = %s AND t.learner_user_id = %d LIMIT 1 FOR UPDATE';

		return $this->get_row( $this->prepare( $sql, array( $assignment_id, $actor_user_id ) ) );
	}

	/**
	 * Lock submission.
	 *
	 * @param int $assignment_pk Assignment pk.
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return array<string,mixed>|null
	 */
	private function lock_submission( int $assignment_pk, int $actor_user_id ): ?array {
		$sql = 'SELECT public_id, lock_version, state, current_revision_public_id, official_revision_public_id, started_at_utc FROM ' . self::TABLE_SUBMISSIONS . ' WHERE assignment_id = %d AND learner_user_id = %d LIMIT 1 FOR UPDATE';

		return $this->get_row( $this->prepare( $sql, array( $assignment_pk, $actor_user_id ) ) );
	}

	/**
	 * Start result from row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $assignment_id Distribution public identifier.
	 * @param string              $publication_hash Publication hash.
	 * @param string              $schedule_hash Schedule hash.
	 * @param bool                $created Created.
	 * @param bool                $replayed Replayed.
	 * @return Start_Own_Submission_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function start_result_from_row( array $row, string $assignment_id, string $publication_hash, string $schedule_hash, bool $created, bool $replayed ): Start_Own_Submission_Result {
		if ( 'draft' !== ( $row['state'] ?? null ) || null !== ( $row['current_revision_public_id'] ?? null ) || null !== ( $row['official_revision_public_id'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}

		return $this->start_result(
			(string) ( $row['public_id'] ?? '' ),
			$assignment_id,
			$this->required_utc( $row['started_at_utc'] ?? null ),
			$publication_hash,
			$schedule_hash,
			$created,
			$replayed,
			$this->non_negative_integer( $row['lock_version'] ?? null )
		);
	}

	/**
	 * Start result.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $started_at Started at.
	 * @param string $publication_hash Publication hash.
	 * @param string $schedule_hash Schedule hash.
	 * @param bool   $created Created.
	 * @param bool   $replayed Replayed.
	 * @param int    $lock_version Lock version.
	 * @return Start_Own_Submission_Result
	 */
	private function start_result( string $submission_id, string $assignment_id, string $started_at, string $publication_hash, string $schedule_hash, bool $created, bool $replayed, int $lock_version = 0 ): Start_Own_Submission_Result {
		$save_token = $this->save_tokens->create(
			$submission_id,
			$assignment_id,
			$lock_version,
			null,
			$publication_hash,
			$schedule_hash
		);

		return new Start_Own_Submission_Result(
			array(
				'id'                   => $submission_id,
				'assignment_id'        => $assignment_id,
				'state'                => 'draft',
				'lock_version'         => $lock_version,
				'current_revision_id'  => null,
				'official_revision_id' => null,
				'started_at_utc'       => $started_at,
				'save_token'           => $save_token,
				'actions'              => array( 'submission.autosave' ),
			),
			$created,
			$replayed
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed>         $current Current.
	 * @param array<string,mixed>         $record Record.
	 * @param Start_Own_Submission_Result $result Result.
	 * @return void
	 */
	private function insert_audit( array $current, array $record, Start_Own_Submission_Result $result ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->assert_hash( (string) $previous );
		}
		$submission    = $result->submission();
		$after         = hash( 'sha256', $this->canonical->encode( $submission ) );
		$metadata_json = $this->canonical->encode(
			array(
				'assignment_id'             => $record['assignment_public_id'],
				'command_id'                => $record['command_id'],
				'expected_publication_hash' => $record['expected_publication_hash'],
				'expected_schedule_hash'    => $record['expected_schedule_hash'],
				'expected_schedule_version' => $record['expected_schedule_version'],
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
					'before_digest'       => null,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $submission['id'],
					'object_type'         => 'submission',
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
				'object_type'         => 'submission',
				'object_public_id'    => $submission['id'],
				'context_type'        => (string) ( $current['context_type'] ?? '' ),
				'context_id'          => (string) ( $current['context_id'] ?? '' ),
				'context_revision'    => $record['authorized_context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => null,
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
	 * @param array<string,mixed>         $record Record.
	 * @param Start_Own_Submission_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Start_Own_Submission_Result $result ): void {
		$submission   = $result->submission();
		$payload_json = $this->canonical->encode(
			array(
				'request_digest' => $record['request_digest'],
				'result'         => $submission,
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
				'aggregate_type'       => 'submission',
				'aggregate_public_id'  => $submission['id'],
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
	 * @return Start_Own_Submission_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $storage_key, string $request_digest, bool $for_update ): ?Start_Own_Submission_Result {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			'submission' !== ( $row['aggregate_type'] ?? null ) || self::EVENT_TYPE !== ( $row['event_type'] ?? null )
			|| self::PAYLOAD_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		$payload = $this->decode_json( (string) ( $row['payload_json'] ?? '' ), (string) ( $row['payload_hash'] ?? '' ) );
		$this->exact_keys( $payload, array( 'request_digest', 'result' ) );
		if ( ! is_string( $payload['request_digest'] ?? null ) || ! hash_equals( $request_digest, $payload['request_digest'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $payload['result'] ?? null;
		if ( ! is_array( $result ) || array_is_list( $result ) || ( $row['aggregate_public_id'] ?? null ) !== ( $result['id'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		try {
			return new Start_Own_Submission_Result( $result, true, true ); } catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
	}

	/**
	 * Reject invalid start record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_start_record( array $record ): void {
		$this->exact_keys(
			$record,
			array(
				'actor_user_id',
				'assignment_public_id',
				'submission_public_id',
				'audit_public_id',
				'outbox_public_id',
				'command_id',
				'correlation_id',
				'idempotency_storage_key',
				'request_digest',
				'occurred_at_utc',
				'expected_state',
				'expected_lock_version',
				'expected_schedule_version',
				'expected_schedule_hash',
				'expected_publication_hash',
				'authorized_context_revision',
			)
		);
		$this->actor( $record['actor_user_id'] );
		foreach ( array( 'assignment_public_id', 'submission_public_id', 'audit_public_id', 'outbox_public_id' ) as $field ) {
			new Public_Id( (string) $record[ $field ] );
		}
		foreach ( array( 'request_digest', 'expected_schedule_hash', 'expected_publication_hash' ) as $field ) {
			$this->assert_hash( (string) $record[ $field ] );
		}
		$this->assert_storage_key( (string) $record['idempotency_storage_key'] );
		$this->assert_utc( (string) $record['occurred_at_utc'] );
		if (
			'open' !== $record['expected_state'] || ! is_int( $record['expected_lock_version'] ) || $record['expected_lock_version'] < 0
			|| ! is_int( $record['expected_schedule_version'] ) || $record['expected_schedule_version'] < 1
			|| ! is_string( $record['authorized_context_revision'] ) || '' === $record['authorized_context_revision']
		) {
			throw new InvalidArgumentException( 'StartOwnSubmission record is invalid.' );
		}
	}

	/**
	 * Reject invalid start postcondition.
	 *
	 * @param int                 $submission_pk Submission pk.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_start_postcondition( int $submission_pk, array $record ): void {
		$row = $this->get_row(
			$this->prepare(
				'SELECT public_id, lock_version, state, learner_user_id, current_revision_public_id, official_revision_public_id, started_at_utc FROM ' . self::TABLE_SUBMISSIONS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
				array( $submission_pk )
			)
		);
		if (
			null === $row || ! hash_equals( $record['submission_public_id'], (string) ( $row['public_id'] ?? '' ) )
			|| 0 !== $this->non_negative_integer( $row['lock_version'] ?? null ) || 'draft' !== ( $row['state'] ?? null )
			|| $record['actor_user_id'] !== $this->positive_integer( $row['learner_user_id'] ?? null )
			|| null !== ( $row['current_revision_public_id'] ?? null ) || null !== ( $row['official_revision_public_id'] ?? null )
			|| ! hash_equals( $record['occurred_at_utc'], (string) ( $row['started_at_utc'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Reject invalid context map.
	 *
	 * @param array<array-key,mixed> $contexts Contexts.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_context_map( array $contexts ): void {
		if ( ( array() !== $contexts && array_is_list( $contexts ) ) || count( $contexts ) > self::MAX_SCAN_ROWS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ROSTER_INVALID );
		}
		foreach ( $contexts as $key => $context ) {
			if ( ! is_array( $context ) || array_is_list( $context ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::ROSTER_INVALID );
			}
			$this->assert_context( $context );
			if ( $key !== $context['type'] . ':' . $context['id'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::ROSTER_INVALID );
			}
		}
	}

	/**
	 * Reject invalid context.
	 *
	 * @param array<string,mixed> $context Access or execution context for this operation.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_context( array $context ): void {
		$this->exact_keys( $context, array( 'attributes', 'id', 'label', 'lifecycle_state', 'revision', 'type' ) );
		if (
			! in_array( $context['type'] ?? null, array( 'class', 'group' ), true ) || 'active' !== ( $context['lifecycle_state'] ?? null )
			|| ! is_string( $context['label'] ?? null ) || '' === trim( $context['label'] ) || ! is_array( $context['attributes'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ROSTER_INVALID );
		}
		$this->token( $context['id'] ?? null );
		$this->token( $context['revision'] ?? null );
	}

	/**
	 * Reject invalid no private keys.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_no_private_keys( $value ): void {
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$snake_key  = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key );
				$normalized = strtolower( str_replace( '-', '_', is_string( $snake_key ) ? $snake_key : $key ) );
				if ( in_array( $normalized, self::FORBIDDEN_PROJECTION_KEYS, true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
				}
			}
			$this->assert_no_private_keys( $item );
		}
	}

	/**
	 * Decode json.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_json( string $json, string $hash ): array {
		$this->assert_hash( $hash );
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}

			return $value;
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Reject invalid page.
	 *
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_page( string $cursor, int $limit ): void {
		if ( strlen( $cursor ) > 512 || $limit < 1 || $limit > 100 ) {
			throw new InvalidArgumentException( 'Student assignment page is invalid.' );
		}
	}

	/**
	 * Actor.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function actor( mixed $value ): void {
		if ( ! is_int( $value ) || $value < 1 ) {
			throw new InvalidArgumentException( 'Student assignment actor is invalid.' );
		}
	}

	/**
	 * Assignment state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assignment_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'draft', 'scheduled', 'open', 'closed', 'withdrawn' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Context type.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_type( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'class', 'group' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Title.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function title( mixed $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 255 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Attribute label.
	 *
	 * @param array<string,mixed> $attributes Attributes.
	 * @param string              $key Key.
	 * @return string
	 */
	private function attribute_label( array $attributes, string $key ): string {
		$value = $attributes[ $key ] ?? '';
		return is_string( $value ) && strlen( $value ) <= 255 ? $value : '';
	}

	/**
	 * Auto submit from row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return bool
	 */
	private function auto_submit_from_row( array $row ): bool {
		return 1 === $this->zero_or_one( $row['auto_submit_enabled'] ?? null );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
	}
	/**
	 * Reject invalid storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_storage_key( string $value ): void {
		if ( 1 !== preg_match( '/^submission:[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission storage key is invalid.' );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
	}
	/**
	 * Required utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function required_utc( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} $this->assert_utc( $value );
		return $value;
	}
	/**
	 * Optional utc.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_utc( mixed $value ): ?string {
		return null === $value ? null : $this->required_utc( $value );
	}
	/**
	 * Optional public id.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function optional_public_id( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		} if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} new Public_Id( $value );
		return $value;
	}
	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		} if ( ! is_int( $value ) || $value < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
	}
	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		} if ( ! is_int( $value ) || $value < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
	}
	/**
	 * Zero or one.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function zero_or_one( mixed $value ): int {
		$value = $this->non_negative_integer( $value );
		if ( $value > 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
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
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		} return $prepared;
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
		$this->assert_read( $db );
		if ( null === $row ) {
			return null;
		} if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		} return $row;
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
		$this->assert_read( $db );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		} foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
			}
		} return array_values( $rows );
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
		$this->assert_read( $db );
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
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}
	/**
	 * Insert id.
	 *
	 * @return int
	 */
	private function insert_id(): int {
		$value = $this->database()->insert_id ?? null;
		return $this->positive_integer( $value );
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
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
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
	 * Reject invalid read.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_read( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
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
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_results', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
	}
}
