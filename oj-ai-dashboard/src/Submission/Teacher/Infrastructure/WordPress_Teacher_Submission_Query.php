<?php
/**
 * WordPress teacher submission projections.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Teacher\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Submission\Teacher\Port\Submission_Print_Source_Query_Port_Interface;
use OJAI\Dashboard\Submission\Teacher\Port\Teacher_Submission_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements Q13-Q15 without selecting private file storage or evaluation bodies.
 */
final class WordPress_Teacher_Submission_Query implements Teacher_Submission_Query_Port_Interface, Submission_Print_Source_Query_Port_Interface {
	private const TABLE_ASSIGNMENTS    = 'oj_ai_assignments';
	private const TABLE_TARGETS        = 'oj_ai_assignment_targets';
	private const TABLE_SUBMISSIONS    = 'oj_ai_submissions';
	private const TABLE_REVISIONS      = 'oj_ai_submission_revisions';
	private const TABLE_RECEIPTS       = 'oj_ai_submission_receipts';
	private const TABLE_ATTACHMENTS    = 'oj_ai_submission_attachments';
	private const MAX_ATTACHMENTS      = 100;
	private const MAX_HISTORY_RECEIPTS = 500;

	/**
	 * WordPress teacher submission query.
	 *
	 * @var array<int,string>
	 */
	private const FORBIDDEN_RESPONSE_KEYS = array(
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
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;
	/**
	 * Cursors.
	 *
	 * @var Signed_Cursor_Codec
	 */
	private Signed_Cursor_Codec $cursors;
	/**
	 * Integrity.
	 *
	 * @var Submission_Revision_Integrity
	 */
	private Submission_Revision_Integrity $integrity;

	/**
	 * Initialize WordPress teacher submission query dependencies and state.
	 *
	 * @param object|null              $database Database.
	 * @param Canonical_Json|null      $canonical Canonical JSON encoder used for stable digests.
	 * @param Signed_Cursor_Codec|null $cursors Cursors.
	 */
	public function __construct(
		private ?object $database = null,
		?Canonical_Json $canonical = null,
		?Signed_Cursor_Codec $cursors = null
	) {
		$this->canonical = $canonical ?? new Canonical_Json();
		$this->cursors   = $cursors ?? new Signed_Cursor_Codec( $this->canonical );
		$this->integrity = new Submission_Revision_Integrity( $this->canonical );
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @return array{submission_id:string,assignment_id:string,context_type:string,context_id:string,context_revision:string,current_revision:string,state:string,assignment_state:string,target_state:string}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorization_snapshot( string $submission_id ): ?array {
		new Public_Id( $submission_id );
		$sql = 'SELECT s.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, '
			. 'a.public_id AS assignment_id, a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, t.state AS target_state '
			. 'FROM ' . self::TABLE_SUBMISSIONS . ' s INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id '
			. 'WHERE s.public_id = %s LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( $submission_id ) ) );
		if ( null === $row ) {
			return null;
		}
		$stored_id = $this->public_id( $row['submission_id'] ?? null );
		if ( ! hash_equals( $submission_id, $stored_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}

		return array(
			'submission_id'    => $stored_id,
			'assignment_id'    => $this->public_id( $row['assignment_id'] ?? null ),
			'context_type'     => $this->context_type( $row['context_type'] ?? null ),
			'context_id'       => $this->token( $row['context_id'] ?? null, 191 ),
			'context_revision' => $this->token( $row['context_revision'] ?? null, 191 ),
			'current_revision' => (string) $this->non_negative_integer( $row['submission_lock_version'] ?? null ),
			'state'            => $this->submission_state( $row['submission_state'] ?? null ),
			'assignment_state' => $this->assignment_state( $row['assignment_state'] ?? null ),
			'target_state'     => $this->target_state( $row['target_state'] ?? null ),
		);
	}

	/**
	 * Find official revision.
	 *
	 * @param string $revision_id Revision id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_official_revision( string $revision_id ): ?array {
		new Public_Id( $revision_id );
		$sql = 'SELECT r.public_id AS revision_id, s.public_id AS submission_id, s.official_revision_public_id, '
			. 'a.context_type, a.context_id, a.context_revision FROM ' . self::TABLE_REVISIONS . ' r '
			. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = r.submission_id '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'WHERE r.public_id = %s LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( $revision_id ) ) );
		if ( null === $row ) {
			return null;
		}
		$stored_revision_id   = $this->public_id( $row['revision_id'] ?? null );
		$submission_id        = $this->public_id( $row['submission_id'] ?? null );
		$official_revision_id = $this->optional_public_id( $row['official_revision_public_id'] ?? null );
		if ( ! hash_equals( $revision_id, $stored_revision_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		if ( null === $official_revision_id || ! hash_equals( $revision_id, $official_revision_id ) ) {
			return null;
		}
		$context_type     = $this->context_type( $row['context_type'] ?? null );
		$context_id       = $this->token( $row['context_id'] ?? null, 191 );
		$context_revision = $this->token( $row['context_revision'] ?? null, 191 );
		$detail           = $this->find_submission( $submission_id, $context_type, $context_id, $context_revision );
		$submission       = is_array( $detail ) ? ( $detail['submission'] ?? null ) : null;
		$official         = is_array( $submission ) ? ( $submission['official_revision'] ?? null ) : null;
		$receipt          = is_array( $submission ) ? ( $submission['receipt'] ?? null ) : null;
		$source           = is_array( $submission ) ? ( $submission['source'] ?? null ) : null;
		$context          = is_array( $submission ) ? ( $submission['context'] ?? null ) : null;
		if (
			! is_array( $submission ) || array_is_list( $submission )
			|| ! is_array( $official ) || array_is_list( $official )
			|| ! is_array( $receipt ) || array_is_list( $receipt )
			|| ! is_array( $source ) || array_is_list( $source )
			|| ! is_array( $context ) || array_is_list( $context )
			|| ! hash_equals( $submission_id, (string) ( $submission['id'] ?? '' ) )
			|| ! hash_equals( $revision_id, (string) ( $submission['official_revision_id'] ?? '' ) )
			|| ! hash_equals( $revision_id, (string) ( $official['id'] ?? '' ) )
			|| ! hash_equals( $revision_id, (string) ( $receipt['submission_revision_id'] ?? '' ) )
			|| ! hash_equals( (string) ( $official['content_hash'] ?? '' ), (string) ( $receipt['submission_revision_hash'] ?? '' ) )
			|| ! hash_equals( (string) ( $official['attachment_set_hash'] ?? '' ), (string) ( $receipt['attachment_set_hash'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['publication_hash'] ?? '' ), (string) ( $receipt['publication_hash'] ?? '' ) )
			|| (int) ( $submission['official_attempt_no'] ?? 0 ) !== (int) ( $receipt['attempt_no'] ?? -1 )
			|| $context_type !== ( $context['type'] ?? null )
			|| $context_id !== ( $context['id'] ?? null )
			|| $context_revision !== ( $context['revision'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		return $submission;
	}

	/**
	 * List assignment submissions.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{assignment:array<string,mixed>,items:array<int,array<string,mixed>>,next_cursor:string}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_assignment_submissions(
		string $assignment_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $cursor,
		int $limit
	): ?array {
		$assignment_row = $this->assignment_row( $assignment_id, $context_type, $context_id, $context_revision );
		if ( null === $assignment_row ) {
			return null;
		}
		$assignment    = $this->assignment_projection( $assignment_row );
		$assignment_pk = $this->positive_integer( $assignment_row['assignment_pk'] ?? null );
		$cursor_value  = $this->cursors->decode( $cursor, 'teacher_submission_list' );
		$values        = array( $assignment_pk );
		$after         = '';
		if ( array() !== $cursor_value ) {
			$this->exact_keys( $cursor_value, array( 'a', 't' ) );
			if ( $assignment_id !== ( $cursor_value['a'] ?? null ) ) {
				throw new InvalidArgumentException( 'Teacher submission list cursor assignment does not match.' );
			}
			$target_id = $this->public_id( $cursor_value['t'] ?? null );
			$after     = ' AND t.public_id > %s';
			$values[]  = $target_id;
		}
		$values[]    = $limit + 1;
		$sql         = 'SELECT t.public_id AS target_id, t.content_hash AS target_content_hash, t.learner_user_id, t.membership_revision, '
			. 't.display_snapshot_schema, t.display_snapshot_version, t.display_snapshot_json, t.display_snapshot_hash, t.snapshot_hash, t.state AS target_state, '
			. 's.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, '
			. 's.current_revision_public_id, s.official_revision_public_id, s.official_attempt_no, '
			. 's.official_review_public_id, s.official_review_hash, s.current_release_public_id, s.current_release_hash, '
			. 's.started_at_utc, s.submitted_at_utc, s.closed_at_utc FROM ' . self::TABLE_TARGETS . ' t '
			. 'LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.assignment_id = t.assignment_id AND s.learner_user_id = t.learner_user_id '
			. 'WHERE t.assignment_id = %d' . $after . ' ORDER BY t.public_id ASC LIMIT %d';
		$rows        = $this->get_results( $this->prepare( $sql, $values ) );
		$has_more    = count( $rows ) > $limit;
		$rows        = array_slice( $rows, 0, $limit );
		$items       = array_map( fn ( array $row ): array => $this->list_item( $row ), $rows );
		$next_cursor = '';
		if ( $has_more && array() !== $items ) {
			$last        = $items[ count( $items ) - 1 ];
			$next_cursor = $this->cursors->encode(
				'teacher_submission_list',
				array(
					'a' => $assignment_id,
					't' => $last['target']['id'],
				)
			);
		}

		return array(
			'assignment'  => $assignment,
			'items'       => $items,
			'next_cursor' => $next_cursor,
		);
	}

	/**
	 * Find submission.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_submission(
		string $submission_id,
		string $context_type,
		string $context_id,
		string $context_revision
	): ?array {
		$row = $this->submission_row( $submission_id, $context_type, $context_id, $context_revision );
		if ( null === $row ) {
			return null;
		}
		$root            = $this->submission_root( $row );
		$current_record  = $this->revision(
			$root['submission_pk'],
			$root['id'],
			$root['current_revision_id'],
			$root['source']['worksheet_publication_id'],
			$root['source']['publication_hash']
		);
		$official_record = null;
		if ( null !== $root['official_revision_id'] ) {
			$official_record = null !== $current_record && $root['official_revision_id'] === $current_record['projection']['id']
				? $current_record
				: $this->revision(
					$root['submission_pk'],
					$root['id'],
					$root['official_revision_id'],
					$root['source']['worksheet_publication_id'],
					$root['source']['publication_hash']
				);
		}
		if ( null !== $root['current_revision_id'] && null === $current_record ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		if ( null !== $root['official_revision_id'] && null === $official_record ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$receipt = null;
		if ( null !== $official_record ) {
			$receipt = $this->latest_receipt(
				$root['submission_pk'],
				$root['id'],
				$official_record['revision_pk'],
				$official_record['projection']['id']
			);
			if ( null === $receipt ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
		}
		$submission                      = $this->submission_projection( $root );
		$submission['current_revision']  = null === $current_record ? null : $current_record['projection'];
		$submission['official_revision'] = null === $official_record ? null : $official_record['projection'];
		$submission['receipt']           = $receipt;
		$submission['attachments']       = $this->attachments(
			$root['submission_pk'],
			null === $current_record ? null : $current_record['projection'],
			null === $official_record ? null : $official_record['projection']
		);

		return array( 'submission' => $submission );
	}

	/**
	 * List submission history.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{submission:array<string,mixed>,revisions:array<int,array<string,mixed>>,next_cursor:string}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_submission_history(
		string $submission_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $cursor,
		int $limit
	): ?array {
		$row = $this->submission_row( $submission_id, $context_type, $context_id, $context_revision );
		if ( null === $row ) {
			return null;
		}
		$root         = $this->submission_root( $row );
		$cursor_value = $this->cursors->decode( $cursor, 'teacher_submission_history' );
		$values       = array( $root['submission_pk'] );
		$after        = '';
		if ( array() !== $cursor_value ) {
			$this->exact_keys( $cursor_value, array( 'n', 's' ) );
			if ( $submission_id !== ( $cursor_value['s'] ?? null ) ) {
				throw new InvalidArgumentException( 'Teacher submission history cursor submission does not match.' );
			}
			$revision_no = $this->positive_integer( $cursor_value['n'] ?? null );
			$after       = ' AND revision_no < %d';
			$values[]    = $revision_no;
		}
		$values[] = $limit + 1;
		$sql      = 'SELECT id AS revision_pk, public_id, content_hash, created_at_utc, revision_no, base_revision_no, state, '
			. 'response_schema_version, response_schema, response_version, response_json, response_hash, attachment_set_hash '
			. 'FROM ' . self::TABLE_REVISIONS . ' WHERE submission_id = %d' . $after . ' ORDER BY revision_no DESC LIMIT %d';
		$rows     = $this->get_results( $this->prepare( $sql, $values ) );
		$has_more = count( $rows ) > $limit;
		$rows     = array_slice( $rows, 0, $limit );
		$records  = array();
		foreach ( $rows as $revision_row ) {
			$records[] = $this->revision_row(
				$revision_row,
				$root['id'],
				$root['source']['worksheet_publication_id'],
				$root['source']['publication_hash']
			);
		}
		$receipts  = $this->history_receipts( $root['submission_pk'], $root['id'], $records );
		$revisions = array();
		foreach ( $records as $record ) {
			$projection                = $record['projection'];
			$projection['is_current']  = $root['current_revision_id'] === $projection['id'];
			$projection['is_official'] = $root['official_revision_id'] === $projection['id'];
			$projection['receipts']    = $receipts[ $record['revision_pk'] ] ?? array();
			$revisions[]               = $projection;
		}
		$next_cursor = '';
		if ( $has_more && array() !== $revisions ) {
			$last        = $revisions[ count( $revisions ) - 1 ];
			$next_cursor = $this->cursors->encode(
				'teacher_submission_history',
				array(
					'n' => $last['revision_no'],
					's' => $submission_id,
				)
			);
		}

		return array(
			'submission'  => $this->submission_projection( $root ),
			'revisions'   => $revisions,
			'next_cursor' => $next_cursor,
		);
	}

	/**
	 * Assignment row.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array<string,mixed>|null
	 */
	private function assignment_row( string $assignment_id, string $context_type, string $context_id, string $context_revision ): ?array {
		$sql = 'SELECT a.id AS assignment_pk, a.public_id AS assignment_id, a.title_snapshot, a.state AS assignment_state, a.lock_version AS assignment_lock_version, '
			. 'a.context_type, a.context_id, a.context_revision, a.worksheet_publication_public_id, a.publication_hash, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_TARGETS . ' counted_targets WHERE counted_targets.assignment_id = a.id) AS target_count, '
			. 'a.opens_at_utc, a.due_at_utc, a.closes_at_utc, a.schedule_version, a.schedule_hash, a.auto_submit_enabled '
			. 'FROM ' . self::TABLE_ASSIGNMENTS . ' a WHERE a.public_id = %s AND a.context_type = %s AND a.context_id = %s AND a.context_revision = %s LIMIT 1';
		return $this->get_row( $this->prepare( $sql, array( $assignment_id, $context_type, $context_id, $context_revision ) ) );
	}

	/**
	 * Submission row.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array<string,mixed>|null
	 */
	private function submission_row( string $submission_id, string $context_type, string $context_id, string $context_revision ): ?array {
		$sql = 'SELECT s.id AS submission_pk, s.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, '
			. 's.current_revision_public_id, s.official_revision_public_id, s.official_attempt_no, '
			. 's.official_review_public_id, s.official_review_hash, s.current_release_public_id, s.current_release_hash, '
			. 's.started_at_utc, s.submitted_at_utc, s.closed_at_utc, '
			. 'a.public_id AS assignment_id, a.title_snapshot, a.state AS assignment_state, a.context_type, a.context_id, a.context_revision, '
			. 'a.worksheet_publication_public_id, a.publication_hash, a.opens_at_utc, a.due_at_utc, a.closes_at_utc, '
			. 'a.schedule_version, a.schedule_hash, a.auto_submit_enabled, '
			. 't.public_id AS target_id, t.content_hash AS target_content_hash, t.learner_user_id, t.membership_revision, '
			. 't.display_snapshot_schema, t.display_snapshot_version, t.display_snapshot_json, t.display_snapshot_hash, t.snapshot_hash, t.state AS target_state '
			. 'FROM ' . self::TABLE_SUBMISSIONS . ' s INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id '
			. 'WHERE s.public_id = %s AND a.context_type = %s AND a.context_id = %s AND a.context_revision = %s LIMIT 1';
		return $this->get_row( $this->prepare( $sql, array( $submission_id, $context_type, $context_id, $context_revision ) ) );
	}

	/**
	 * Assignment projection.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function assignment_projection( array $row ): array {
		$schedule = $this->schedule( $row );
		return array(
			'id'           => $this->public_id( $row['assignment_id'] ?? null ),
			'title'        => $this->text( $row['title_snapshot'] ?? null, 255 ),
			'state'        => $this->assignment_state( $row['assignment_state'] ?? null ),
			'lock_version' => $this->non_negative_integer( $row['assignment_lock_version'] ?? null ),
			'context'      => array(
				'type'     => $this->context_type( $row['context_type'] ?? null ),
				'id'       => $this->token( $row['context_id'] ?? null, 191 ),
				'revision' => $this->token( $row['context_revision'] ?? null, 191 ),
			),
			'target_count' => $this->non_negative_integer( $row['target_count'] ?? null ),
			'source'       => array(
				'worksheet_publication_id' => $this->public_id( $row['worksheet_publication_public_id'] ?? null ),
				'publication_hash'         => $this->hash_value( $row['publication_hash'] ?? null ),
			),
			'schedule'     => $schedule,
		);
	}

	/**
	 * List item.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function list_item( array $row ): array {
		$target = $this->target_projection( $row );
		if ( null === ( $row['submission_id'] ?? null ) ) {
			return array(
				'target'            => $target,
				'status'            => 'not_started',
				'submission'        => null,
				'evaluation_status' => 'not_evaluated',
				'release_status'    => 'not_released',
			);
		}
		$submission = $this->submission_values( $row );
		return array(
			'target'            => $target,
			'status'            => $submission['state'],
			'submission'        => array(
				'id'                   => $submission['id'],
				'state'                => $submission['state'],
				'lock_version'         => $submission['lock_version'],
				'current_revision_id'  => $submission['current_revision_id'],
				'official_revision_id' => $submission['official_revision_id'],
				'official_attempt_no'  => $submission['official_attempt_no'],
				'started_at_utc'       => $submission['started_at_utc'],
				'submitted_at_utc'     => $submission['submitted_at_utc'],
				'closed_at_utc'        => $submission['closed_at_utc'],
			),
			'evaluation_status' => $submission['evaluation']['status'],
			'release_status'    => $submission['release']['status'],
		);
	}

	/**
	 * Submission root.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function submission_root( array $row ): array {
		$submission                     = $this->submission_values( $row );
		$assignment_id                  = $this->public_id( $row['assignment_id'] ?? null );
		$submission['submission_pk']    = $this->positive_integer( $row['submission_pk'] ?? null );
		$submission['assignment_id']    = $assignment_id;
		$submission['assignment_title'] = $this->text( $row['title_snapshot'] ?? null, 255 );
		$submission['assignment_state'] = $this->assignment_state( $row['assignment_state'] ?? null );
		$submission['context']          = array(
			'type'     => $this->context_type( $row['context_type'] ?? null ),
			'id'       => $this->token( $row['context_id'] ?? null, 191 ),
			'revision' => $this->token( $row['context_revision'] ?? null, 191 ),
		);
		$submission['target']           = $this->target_projection( $row );
		$submission['source']           = array(
			'worksheet_publication_id' => $this->public_id( $row['worksheet_publication_public_id'] ?? null ),
			'publication_hash'         => $this->hash_value( $row['publication_hash'] ?? null ),
		);
		$submission['schedule']         = $this->schedule( $row );
		return $submission;
	}

	/**
	 * Submission projection.
	 *
	 * @param array<string,mixed> $root Root.
	 * @return array<string,mixed>
	 */
	private function submission_projection( array $root ): array {
		return array(
			'id'                   => $root['id'],
			'assignment_id'        => $root['assignment_id'],
			'assignment_title'     => $root['assignment_title'],
			'assignment_state'     => $root['assignment_state'],
			'context'              => $root['context'],
			'target'               => $root['target'],
			'state'                => $root['state'],
			'lock_version'         => $root['lock_version'],
			'current_revision_id'  => $root['current_revision_id'],
			'official_revision_id' => $root['official_revision_id'],
			'official_attempt_no'  => $root['official_attempt_no'],
			'evaluation'           => $root['evaluation'],
			'release'              => $root['release'],
			'started_at_utc'       => $root['started_at_utc'],
			'submitted_at_utc'     => $root['submitted_at_utc'],
			'closed_at_utc'        => $root['closed_at_utc'],
			'source'               => $root['source'],
			'schedule'             => $root['schedule'],
		);
	}

	/**
	 * Submission values.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function submission_values( array $row ): array {
		$id          = $this->public_id( $row['submission_id'] ?? null );
		$state       = $this->submission_state( $row['submission_state'] ?? null );
		$current_id  = $this->optional_public_id( $row['current_revision_public_id'] ?? null );
		$official_id = $this->optional_public_id( $row['official_revision_public_id'] ?? null );
		$attempt_no  = $this->non_negative_integer( $row['official_attempt_no'] ?? null );
		if ( ( null === $official_id ) !== ( 0 === $attempt_no ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::TEACHER_SUBMISSION_ATTEMPT_MISMATCH );
		}
		if ( in_array( $state, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true ) && null === $official_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::TEACHER_SUBMISSION_MISSING_OFFICIAL_REVISION );
		}
		$review  = $this->pointer( $row['official_review_public_id'] ?? null, $row['official_review_hash'] ?? null, 'reviewed', 'not_evaluated', Assignment_Failure::TEACHER_SUBMISSION_REVIEW_POINTER_INCOMPLETE );
		$release = $this->pointer( $row['current_release_public_id'] ?? null, $row['current_release_hash'] ?? null, 'released', 'not_released', Assignment_Failure::TEACHER_SUBMISSION_RELEASE_POINTER_INCOMPLETE );
		if ( null !== $release['id'] && null === $review['id'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::TEACHER_SUBMISSION_RELEASE_WITHOUT_REVIEW );
		}

		return array(
			'id'                   => $id,
			'state'                => $state,
			'lock_version'         => $this->non_negative_integer( $row['submission_lock_version'] ?? null ),
			'current_revision_id'  => $current_id,
			'official_revision_id' => $official_id,
			'official_attempt_no'  => $attempt_no,
			'evaluation'           => array(
				'status'      => $review['status'],
				'review_id'   => $review['id'],
				'review_hash' => $review['hash'],
			),
			'release'              => array(
				'status'       => $release['status'],
				'release_id'   => $release['id'],
				'release_hash' => $release['hash'],
			),
			'started_at_utc'       => $this->optional_utc( $row['started_at_utc'] ?? null ),
			'submitted_at_utc'     => $this->optional_utc( $row['submitted_at_utc'] ?? null ),
			'closed_at_utc'        => $this->optional_utc( $row['closed_at_utc'] ?? null ),
		);
	}

	/**
	 * Pointer.
	 *
	 * @param mixed  $id_value Id value.
	 * @param mixed  $hash_value Hash value.
	 * @param string $present_status Present status.
	 * @param string $empty_status Empty status.
	 * @param string $incomplete_reason Incomplete reason.
	 * @return array{id:?string,hash:?string,status:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function pointer( mixed $id_value, mixed $hash_value, string $present_status, string $empty_status, string $incomplete_reason ): array {
		if ( null === $id_value && null === $hash_value ) {
			return array(
				'id'     => null,
				'hash'   => null,
				'status' => $empty_status,
			);
		}
		if ( null === $id_value || null === $hash_value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( $incomplete_reason );
		}
		return array(
			'id'     => $this->public_id( $id_value ),
			'hash'   => $this->hash_value( $hash_value ),
			'status' => $present_status,
		);
	}

	/**
	 * Target projection.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_projection( array $row ): array {
		$id = $this->public_id( $row['target_id'] ?? null );
		foreach ( array( 'target_content_hash', 'display_snapshot_hash', 'snapshot_hash' ) as $field ) {
			$this->hash_value( $row[ $field ] ?? null );
		}
		if ( 'oj_ai.assignment_target_display' !== ( $row['display_snapshot_schema'] ?? null ) || 1 !== $this->positive_integer( $row['display_snapshot_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::TEACHER_TARGET_DISPLAY_SCHEMA_INVALID );
		}
		$display = $this->decode_json( (string) ( $row['display_snapshot_json'] ?? '' ), $this->hash_value( $row['display_snapshot_hash'] ?? null ) );
		$this->exact_keys( $display, array( 'display_name' ) );
		return array(
			'id'                  => $id,
			'learner_user_id'     => $this->positive_integer( $row['learner_user_id'] ?? null ),
			'display_name'        => $this->text( $display['display_name'] ?? null, 255 ),
			'membership_revision' => $this->token( $row['membership_revision'] ?? null, 191 ),
			'state'               => $this->target_state( $row['target_state'] ?? null ),
		);
	}

	/**
	 * Revision.
	 *
	 * @param int         $submission_pk Submission pk.
	 * @param string      $submission_id Submission public identifier.
	 * @param string|null $revision_id Revision id.
	 * @param string      $publication_id Publication id.
	 * @param string      $publication_hash Publication hash.
	 * @return array{revision_pk:int,projection:array<string,mixed>}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function revision( int $submission_pk, string $submission_id, ?string $revision_id, string $publication_id, string $publication_hash ): ?array {
		if ( null === $revision_id ) {
			return null;
		}
		$sql = 'SELECT id AS revision_pk, public_id, content_hash, created_at_utc, revision_no, base_revision_no, state, '
			. 'response_schema_version, response_schema, response_version, response_json, response_hash, attachment_set_hash '
			. 'FROM ' . self::TABLE_REVISIONS . ' WHERE submission_id = %d AND public_id = %s LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( $submission_pk, $revision_id ) ) );
		if ( null === $row ) {
			return null;
		}
		$record = $this->revision_row( $row, $submission_id, $publication_id, $publication_hash );
		if ( ! hash_equals( $revision_id, $record['projection']['id'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		return $record;
	}

	/**
	 * Revision row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $submission_id Submission public identifier.
	 * @param string              $publication_id Publication id.
	 * @param string              $publication_hash Publication hash.
	 * @return array{revision_pk:int,projection:array<string,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function revision_row( array $row, string $submission_id, string $publication_id, string $publication_hash ): array {
		$revision_id      = $this->public_id( $row['public_id'] ?? null );
		$revision_no      = $this->positive_integer( $row['revision_no'] ?? null );
		$base_revision_no = $this->optional_positive_integer( $row['base_revision_no'] ?? null );
		$state            = (string) ( $row['state'] ?? '' );
		if ( ! in_array( $state, array( 'saved', 'reopened_fork' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$response_schema_version = (string) ( $row['response_schema_version'] ?? '' );
		if ( Submission_Revision_Integrity::RESPONSE_SCHEMA !== ( $row['response_schema'] ?? null ) || Submission_Revision_Integrity::RESPONSE_VERSION !== $this->positive_integer( $row['response_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$response_hash = $this->hash_value( $row['response_hash'] ?? null );
		$response      = $this->decode_json( (string) ( $row['response_json'] ?? '' ), $response_hash );
		$this->exact_keys( $response, array( 'answers', 'publication_hash', 'publication_id', 'response_schema_version' ) );
		if (
			$publication_id !== ( $response['publication_id'] ?? null )
			|| $publication_hash !== ( $response['publication_hash'] ?? null )
			|| Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION !== ( $response['response_schema_version'] ?? null )
			|| ! is_array( $response['answers'] ?? null )
			|| ( array() !== $response['answers'] && array_is_list( $response['answers'] ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		foreach ( array_keys( $response['answers'] ) as $response_id ) {
			$this->token( $response_id, 100 );
		}
		$this->assert_no_private_response_keys( $response );
		$attachment_set_hash = $this->hash_value( $row['attachment_set_hash'] ?? null );
		try {
			$expected_hash = $this->integrity->content_hash(
				$submission_id,
				$revision_no,
				$base_revision_no,
				$state,
				$response_schema_version,
				$response_hash,
				$attachment_set_hash
			);
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$content_hash = $this->hash_value( $row['content_hash'] ?? null );
		if ( ! hash_equals( $content_hash, $expected_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}

		return array(
			'revision_pk' => $this->positive_integer( $row['revision_pk'] ?? null ),
			'projection'  => array(
				'id'                      => $revision_id,
				'revision_no'             => $revision_no,
				'base_revision_no'        => $base_revision_no,
				'state'                   => $state,
				'response_schema_version' => $response_schema_version,
				'response'                => $response,
				'content_hash'            => $content_hash,
				'attachment_set_hash'     => $attachment_set_hash,
				'created_at_utc'          => $this->required_utc( $row['created_at_utc'] ?? null ),
			),
		);
	}

	/**
	 * Latest receipt.
	 *
	 * @param int    $submission_pk Submission pk.
	 * @param string $submission_id Submission public identifier.
	 * @param int    $revision_pk Revision pk.
	 * @param string $revision_id Revision id.
	 * @return array<string,mixed>|null
	 */
	private function latest_receipt( int $submission_pk, string $submission_id, int $revision_pk, string $revision_id ): ?array {
		$sql = $this->receipt_select() . ' FROM ' . self::TABLE_RECEIPTS
			. ' WHERE submission_id = %d AND submission_revision_id = %d ORDER BY receipt_no DESC LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( $submission_pk, $revision_pk ) ) );
		return null === $row ? null : $this->receipt_projection( $row, $submission_id, $revision_id );
	}

	/**
	 * History receipts.
	 *
	 * @param int                                                              $submission_pk Submission pk.
	 * @param string                                                           $submission_id Submission public identifier.
	 * @param array<int,array{revision_pk:int,projection:array<string,mixed>}> $records Records.
	 * @return array<int,array<int,array<string,mixed>>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function history_receipts( int $submission_pk, string $submission_id, array $records ): array {
		if ( array() === $records ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $records ), '%d' ) );
		$values       = array( $submission_pk );
		$revision_ids = array();
		foreach ( $records as $record ) {
			$values[]                               = $record['revision_pk'];
			$revision_ids[ $record['revision_pk'] ] = $record['projection']['id'];
		}
		$values[] = self::MAX_HISTORY_RECEIPTS + 1;
		$sql      = $this->receipt_select() . ' FROM ' . self::TABLE_RECEIPTS
			. ' WHERE submission_id = %d AND submission_revision_id IN (' . $placeholders . ') ORDER BY receipt_no DESC LIMIT %d';
		$rows     = $this->get_results( $this->prepare( $sql, $values ) );
		if ( count( $rows ) > self::MAX_HISTORY_RECEIPTS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$result = array();
		foreach ( $rows as $row ) {
			$revision_pk = $this->positive_integer( $row['submission_revision_id'] ?? null );
			if ( ! isset( $revision_ids[ $revision_pk ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
			$result[ $revision_pk ][] = $this->receipt_projection( $row, $submission_id, $revision_ids[ $revision_pk ] );
		}
		return $result;
	}

	/**
	 * Receipt select.
	 *
	 * @return string
	 */
	private function receipt_select(): string {
		return 'SELECT public_id, content_hash, submission_revision_id, receipt_no, action, idempotency_key, context_revision, actor_type, actor_user_id, reason, '
			. 'attempt_no, submission_revision_hash, publication_hash, attachment_set_hash, assignment_schedule_version, '
			. 'assignment_schedule_hash, resubmission_policy_hash, timeliness, received_at_utc';
	}

	/**
	 * Receipt projection.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $submission_id Submission public identifier.
	 * @param string              $revision_id Revision id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt_projection( array $row, string $submission_id, string $revision_id ): array {
		$receipt_id    = $this->public_id( $row['public_id'] ?? null );
		$receipt_no    = $this->positive_integer( $row['receipt_no'] ?? null );
		$action        = (string) ( $row['action'] ?? '' );
		$actor_type    = (string) ( $row['actor_type'] ?? '' );
		$actor_user_id = null === ( $row['actor_user_id'] ?? null ) ? null : $this->positive_integer( $row['actor_user_id'] );
		$reason        = $this->optional_text( $row['reason'] ?? null, 500 );
		$attempt_no    = $this->positive_integer( $row['attempt_no'] ?? null );
		$timeliness    = (string) ( $row['timeliness'] ?? '' );
		$received_at   = $this->required_utc( $row['received_at_utc'] ?? null );
		if (
			! in_array( $action, array( 'submit', 'auto_submit', 'proxy_submit', 'reopen', 'lock' ), true )
			|| ! in_array( $actor_type, array( 'learner', 'teacher', 'system' ), true )
			|| ( 'system' === $actor_type ) !== ( null === $actor_user_id )
			|| ! in_array( $timeliness, array( 'on_time', 'late' ), true )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$hashes           = array(
			'attachment_set_hash'      => $this->hash_value( $row['attachment_set_hash'] ?? null ),
			'assignment_schedule_hash' => $this->hash_value( $row['assignment_schedule_hash'] ?? null ),
			'publication_hash'         => $this->hash_value( $row['publication_hash'] ?? null ),
			'resubmission_policy_hash' => $this->hash_value( $row['resubmission_policy_hash'] ?? null ),
			'submission_revision_hash' => $this->hash_value( $row['submission_revision_hash'] ?? null ),
		);
		$schedule_version = $this->positive_integer( $row['assignment_schedule_version'] ?? null );
		$context_revision = $this->token( $row['context_revision'] ?? null, 191 );
		$idempotency_key  = $this->token( $row['idempotency_key'] ?? null, 191 );
		$content          = array(
			'action'                      => $action,
			'actor_type'                  => $actor_type,
			'actor_user_id'               => $actor_user_id,
			'assignment_schedule_hash'    => $hashes['assignment_schedule_hash'],
			'assignment_schedule_version' => $schedule_version,
			'attachment_set_hash'         => $hashes['attachment_set_hash'],
			'context_revision'            => $context_revision,
			'idempotency_key'             => $idempotency_key,
			'attempt_no'                  => $attempt_no,
			'publication_hash'            => $hashes['publication_hash'],
			'reason'                      => $reason,
			'receipt_no'                  => $receipt_no,
			'received_at_utc'             => $received_at,
			'resubmission_policy_hash'    => $hashes['resubmission_policy_hash'],
			'submission_id'               => $submission_id,
			'submission_revision_hash'    => $hashes['submission_revision_hash'],
			'submission_revision_id'      => $revision_id,
			'timeliness'                  => $timeliness,
		);
		$content_hash     = $this->hash_value( $row['content_hash'] ?? null );
		if ( ! hash_equals( $content_hash, hash( 'sha256', $this->canonical->encode( $content ) ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}

		return array(
			'id'                       => $receipt_id,
			'content_hash'             => $content_hash,
			'receipt_no'               => $receipt_no,
			'action'                   => $action,
			'context_revision'         => $context_revision,
			'actor'                    => array(
				'type'    => $actor_type,
				'user_id' => $actor_user_id,
			),
			'reason'                   => $reason,
			'attempt_no'               => $attempt_no,
			'submission_revision_id'   => $revision_id,
			'submission_revision_hash' => $hashes['submission_revision_hash'],
			'publication_hash'         => $hashes['publication_hash'],
			'attachment_set_hash'      => $hashes['attachment_set_hash'],
			'assignment_schedule'      => array(
				'version' => $schedule_version,
				'hash'    => $hashes['assignment_schedule_hash'],
			),
			'resubmission_policy_hash' => $hashes['resubmission_policy_hash'],
			'timeliness'               => $timeliness,
			'received_at_utc'          => $received_at,
		);
	}

	/**
	 * Attachments.
	 *
	 * @param int                      $submission_pk Submission pk.
	 * @param array<string,mixed>|null $current_revision Current revision.
	 * @param array<string,mixed>|null $official_revision Official revision.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachments( int $submission_pk, ?array $current_revision, ?array $official_revision ): array {
		$sql  = 'SELECT public_id, state, response_key, submission_revision_id, original_name, mime_type, byte_size, sha256, bound_at_utc '
			. 'FROM ' . self::TABLE_ATTACHMENTS . ' WHERE submission_id = %d AND state IN (%s,%s,%s,%s,%s) '
			. 'ORDER BY response_key ASC, public_id ASC LIMIT %d';
		$rows = $this->get_results( $this->prepare( $sql, array( $submission_pk, 'staged', 'scanning', 'ready', 'rejected', 'bound', self::MAX_ATTACHMENTS + 1 ) ) );
		if ( count( $rows ) > self::MAX_ATTACHMENTS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$result        = array();
		$current_seen  = array();
		$official_seen = array();
		foreach ( $rows as $row ) {
			$revision_pk = null === ( $row['submission_revision_id'] ?? null ) ? null : $this->positive_integer( $row['submission_revision_id'] );
			$id          = $this->public_id( $row['public_id'] ?? null );
			$scopes      = array();
			if ( null === $revision_pk ) {
				$scopes[] = 'staged';
			} else {
				if ( null !== $current_revision && $this->contains_id( $current_revision['response']['answers'], $id ) ) {
					$scopes[]       = 'current';
					$current_seen[] = $id;
				}
				if ( null !== $official_revision && $this->contains_id( $official_revision['response']['answers'], $id ) ) {
					$scopes[]        = 'official';
					$official_seen[] = $id;
				}
				if ( array() === $scopes ) {
					continue;
				}
			}
			$state = (string) ( $row['state'] ?? '' );
			if ( ! in_array( $state, array( 'staged', 'scanning', 'ready', 'rejected', 'bound' ), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
			$name = $this->text( $row['original_name'] ?? null, 255 );
			if ( basename( str_replace( '\\', '/', $name ) ) !== $name || 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
			$mime = $this->text( $row['mime_type'] ?? null, 191 );
			if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/D', $mime ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
			$result[] = array(
				'id'             => $id,
				'response_id'    => $this->token( $row['response_key'] ?? null, 100 ),
				'state'          => $state,
				'name'           => $name,
				'mime_type'      => $mime,
				'byte_size'      => $this->non_negative_integer( $row['byte_size'] ?? null ),
				'sha256'         => $this->hash_value( $row['sha256'] ?? null ),
				'bound_at_utc'   => $this->optional_utc( $row['bound_at_utc'] ?? null ),
				'revision_scope' => $scopes,
			);
		}
		$this->assert_revision_attachment_set( $current_revision, $current_seen );
		$this->assert_revision_attachment_set( $official_revision, $official_seen );
		return $result;
	}

	/**
	 * Contains id.
	 *
	 * @param mixed  $value Value.
	 * @param string $id Id.
	 * @return bool
	 */
	private function contains_id( $value, string $id ): bool {
		if ( is_string( $value ) ) {
			return hash_equals( $id, $value );
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( $this->contains_id( $item, $id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reject invalid revision attachment set.
	 *
	 * @param array<string,mixed>|null $revision Revision.
	 * @param array<int,string>        $ids Ids.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_revision_attachment_set( ?array $revision, array $ids ): void {
		if ( null === $revision ) {
			return;
		}
		sort( $ids, SORT_STRING );
		$actual = hash( 'sha256', $this->canonical->encode( array_values( array_unique( $ids ) ) ) );
		if ( ! hash_equals( (string) $revision['attachment_set_hash'], $actual ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Schedule.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function schedule( array $row ): array {
		$version   = $this->positive_integer( $row['schedule_version'] ?? null );
		$opens_at  = $this->optional_utc( $row['opens_at_utc'] ?? null );
		$due_at    = $this->required_utc( $row['due_at_utc'] ?? null );
		$closes_at = $this->required_utc( $row['closes_at_utc'] ?? null );
		if ( ( null !== $opens_at && $opens_at > $due_at ) || $due_at > $closes_at ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$auto_submit = 1 === $this->zero_or_one( $row['auto_submit_enabled'] ?? null );
		$hash        = $this->hash_value( $row['schedule_hash'] ?? null );
		$expected    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'auto_submit_enabled' => $auto_submit,
					'closes_at_utc'       => $closes_at,
					'due_at_utc'          => $due_at,
					'opens_at_utc'        => $opens_at,
					'schedule_version'    => $version,
				)
			)
		);
		if ( ! hash_equals( $hash, $expected ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		return array(
			'version'             => $version,
			'hash'                => $hash,
			'opens_at_utc'        => $opens_at,
			'due_at_utc'          => $due_at,
			'closes_at_utc'       => $closes_at,
			'auto_submit_enabled' => $auto_submit,
		);
	}

	/**
	 * Reject invalid no private response keys.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_no_private_response_keys( $value ): void {
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$snake      = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key );
				$normalized = strtolower( str_replace( '-', '_', is_string( $snake ) ? $snake : $key ) );
				if ( in_array( $normalized, self::FORBIDDEN_RESPONSE_KEYS, true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
				}
			}
			$this->assert_no_private_response_keys( $item );
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
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
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
		} return $value;
	}
	/**
	 * Submission state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function submission_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'draft', 'conflict', 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
	}
	/**
	 * Target state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'active', 'revoked' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
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
		} return $value;
	}
	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
	}
	/**
	 * Optional public id.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_public_id( mixed $value ): ?string {
		return null === $value ? null : $this->public_id( $value );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
	}
	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $maximum ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
	}
	/**
	 * Optional text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function optional_text( mixed $value, int $maximum ): ?string {
		if ( null === $value ) {
			return null;
		} if ( ! is_string( $value ) || strlen( $value ) > $maximum ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
	}
	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
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
	 * Optional positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private function optional_positive_integer( mixed $value ): ?int {
		return null === $value ? null : $this->positive_integer( $value );
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
		if ( ! is_object( $db ) || ! method_exists( $db, 'prepare' ) || ! method_exists( $db, 'get_row' ) || ! method_exists( $db, 'get_results' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		return $db;
	}
}
