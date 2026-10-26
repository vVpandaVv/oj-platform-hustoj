<?php
/**
 * WordPress student submission self projection.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attempt_Allowance;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Save_Token;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads Q32 from literal add-on tables without selecting private file or evaluation data.
 */
final class WordPress_Student_Submission_Query {
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';
	private const TABLE_REVISIONS   = 'oj_ai_submission_revisions';
	private const TABLE_RECEIPTS    = 'oj_ai_submission_receipts';
	private const TABLE_ATTACHMENTS = 'oj_ai_submission_attachments';
	private const MAX_ATTACHMENTS   = 100;
	private const POLICY_SCHEMA     = 'oj_ai.assignment_resubmission_policy';
	private const VERSION           = 1;

	/**
	 * WordPress student submission query.
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
	 * Integrity.
	 *
	 * @var Submission_Revision_Integrity
	 */
	private Submission_Revision_Integrity $integrity;
	/**
	 * Save tokens.
	 *
	 * @var Submission_Save_Token
	 */
	private Submission_Save_Token $save_tokens;

	/**
	 * Initialize WordPress student submission query dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private ?object $database = null, ?Canonical_Json $canonical = null ) {
		$this->canonical   = $canonical ?? new Canonical_Json();
		$this->integrity   = new Submission_Revision_Integrity( $this->canonical );
		$this->save_tokens = new Submission_Save_Token( $this->canonical );
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array{submission_id:string,assignment_id:string,context_type:string,context_id:string,context_revision:string,current_revision:string,state:string,assignment_state:string,target_state:string}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorization_snapshot( string $submission_id, int $actor_user_id ): ?array {
		new Public_Id( $submission_id );
		$this->actor( $actor_user_id );
		$sql = 'SELECT s.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, '
			. 'a.public_id AS assignment_id, a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, t.state AS target_state '
			. 'FROM ' . self::TABLE_SUBMISSIONS . ' s INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id '
			. 'WHERE s.public_id = %s AND s.learner_user_id = %d AND t.learner_user_id = %d LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( $submission_id, $actor_user_id, $actor_user_id ) ) );
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
	 * Find for actor.
	 *
	 * @param string                                                                                                                               $submission_id Submission public identifier.
	 * @param int                                                                                                                                  $actor_user_id Authenticated actor from the access context.
	 * @param array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>} $context Access or execution context for this operation.
	 * @param string                                                                                                                               $now_utc Now utc.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_for_actor( string $submission_id, int $actor_user_id, array $context, string $now_utc ): ?array {
		new Public_Id( $submission_id );
		$this->actor( $actor_user_id );
		$this->assert_context( $context );
		$this->assert_utc( $now_utc );
		$sql = 'SELECT s.id AS submission_pk, s.public_id AS submission_id, s.lock_version AS submission_lock_version, '
			. 's.state AS submission_state, s.current_revision_public_id, s.official_revision_public_id, s.official_attempt_no, '
			. 's.started_at_utc, s.submitted_at_utc, s.closed_at_utc, a.public_id AS assignment_id, a.state AS assignment_state, '
			. 'a.context_type, a.context_id, a.context_revision, a.worksheet_publication_public_id, a.publication_hash, '
			. 'a.opens_at_utc, a.due_at_utc, a.closes_at_utc, a.schedule_version, a.schedule_hash, a.auto_submit_enabled, '
			. 'a.resubmission_policy_schema, a.resubmission_policy_version, a.resubmission_policy_json, '
			. 'a.resubmission_policy_hash, '
			. 't.state AS target_state FROM ' . self::TABLE_SUBMISSIONS . ' s '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id '
			. 'WHERE s.public_id = %s AND s.learner_user_id = %d AND t.learner_user_id = %d AND t.state = %s LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( $submission_id, $actor_user_id, $actor_user_id, 'active' ) ) );
		if (
			null === $row
			|| $context['type'] !== ( $row['context_type'] ?? null )
			|| $context['id'] !== ( $row['context_id'] ?? null )
		) {
			return null;
		}

		$stored_id = $this->public_id( $row['submission_id'] ?? null );
		if ( ! hash_equals( $submission_id, $stored_id ) || 'active' !== $this->target_state( $row['target_state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$assignment_state = $this->assignment_state( $row['assignment_state'] ?? null );
		if ( 'withdrawn' === $assignment_state ) {
			return null;
		}
		$assignment_id       = $this->public_id( $row['assignment_id'] ?? null );
		$submission_pk       = $this->positive_integer( $row['submission_pk'] ?? null );
		$state               = $this->submission_state( $row['submission_state'] ?? null );
		$lock_version        = $this->non_negative_integer( $row['submission_lock_version'] ?? null );
		$publication_id      = $this->public_id( $row['worksheet_publication_public_id'] ?? null );
		$publication_hash    = $this->hash_value( $row['publication_hash'] ?? null );
		$schedule            = $this->schedule( $row );
		$policy              = $this->resubmission_policy( $row );
		$availability        = $this->availability( $assignment_state, $schedule, $now_utc );
		$current_id          = $this->optional_public_id( $row['current_revision_public_id'] ?? null );
		$official_id         = $this->optional_public_id( $row['official_revision_public_id'] ?? null );
		$official_attempt_no = $this->non_negative_integer( $row['official_attempt_no'] ?? null );
		if ( ( null === $official_id ) !== ( 0 === $official_attempt_no ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		if ( in_array( $state, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true ) && null === $official_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}

		$current_record  = $this->revision( $submission_pk, $stored_id, $current_id, $publication_id, $publication_hash );
		$official_record = null;
		if ( null !== $official_id ) {
			$official_record = null !== $current_record && $official_id === $current_record['projection']['id']
				? $current_record
				: $this->revision( $submission_pk, $stored_id, $official_id, $publication_id, $publication_hash );
		}
		if ( null !== $current_id && null === $current_record ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		if ( null !== $official_id && null === $official_record ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}

		$receipt = null === $official_record ? null : $this->latest_receipt(
			$submission_pk,
			$stored_id,
			$official_record['revision_pk'],
			$official_record['projection']['id'],
			$official_record['projection']['content_hash']
		);
		if ( null !== $official_record && null === $receipt ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$official_pk     = null === $official_record ? null : $official_record['revision_pk'];
		$control_receipt = $this->latest_control_receipt( $submission_pk, $stored_id );
		if ( in_array( $state, array( 'reopened', 'locked' ), true ) ) {
			$expected_action = 'reopened' === $state ? 'reopen' : 'lock';
			if ( null === $control_receipt || $expected_action !== $control_receipt['action'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
		} else {
			$control_receipt = null;
		}
		$resubmission = array(
			'max_attempts'       => $policy['max_attempts'],
			'remaining_attempts' => Submission_Attempt_Allowance::remaining( $policy['max_attempts'], $official_attempt_no, $state ),
			'reopen_until_utc'   => $policy['reopen_until_utc'],
		);
		$actions      = array();
		if ( in_array( $state, array( 'draft', 'conflict', 'reopened' ), true ) && in_array( $availability, array( 'open', 'late' ), true ) ) {
			$actions[] = 'submission.autosave';
		}
		if ( 'open' === $assignment_state && in_array( $state, array( 'draft', 'reopened' ), true ) && null !== $current_id ) {
			$actions[] = 'submission.submit';
		}
		if ( null !== $official_record ) {
			$actions[] = 'submission.export_pdf';
		}

		return array(
			'submission' => array(
				'id'                  => $stored_id,
				'assignment_id'       => $assignment_id,
				'state'               => $state,
				'lock_version'        => $lock_version,
				'availability'        => $availability,
				'current_revision'    => null === $current_record ? null : $current_record['projection'],
				'official_revision'   => null === $official_record ? null : $official_record['projection'],
				'official_attempt_no' => $official_attempt_no,
				'receipt'             => $receipt,
				'attachments'         => $this->attachments(
					$submission_pk,
					null === $current_record ? null : $current_record['projection'],
					null === $official_record ? null : $official_record['projection']
				),
				'started_at_utc'      => $this->optional_utc( $row['started_at_utc'] ?? null ),
				'submitted_at_utc'    => $this->optional_utc( $row['submitted_at_utc'] ?? null ),
				'closed_at_utc'       => $this->optional_utc( $row['closed_at_utc'] ?? null ),
				'control_receipt'     => $control_receipt,
				'resubmission'        => $resubmission,
				'source'              => array(
					'worksheet_publication_id' => $publication_id,
					'publication_hash'         => $publication_hash,
				),
				'schedule'            => array(
					'version'       => $schedule['version'],
					'hash'          => $schedule['hash'],
					'due_at_utc'    => $schedule['due_at_utc'],
					'closes_at_utc' => $schedule['closes_at_utc'],
				),
				'save_token'          => $this->save_tokens->create(
					$stored_id,
					$assignment_id,
					$lock_version,
					$current_id,
					$publication_hash,
					$schedule['hash']
				),
				'actions'             => $actions,
			),
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
	private function revision(
		int $submission_pk,
		string $submission_id,
		?string $revision_id,
		string $publication_id,
		string $publication_hash
	): ?array {
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
		$stored_id = $this->public_id( $row['public_id'] ?? null );
		if ( ! hash_equals( $revision_id, $stored_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$revision_no             = $this->positive_integer( $row['revision_no'] ?? null );
		$base_revision_no        = $this->optional_positive_integer( $row['base_revision_no'] ?? null );
		$state                   = (string) ( $row['state'] ?? '' );
		$response_schema_version = (string) ( $row['response_schema_version'] ?? '' );
		if (
			Submission_Revision_Integrity::RESPONSE_SCHEMA !== ( $row['response_schema'] ?? null )
			|| Submission_Revision_Integrity::RESPONSE_VERSION !== $this->positive_integer( $row['response_version'] ?? null )
		) {
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
				'id'                      => $stored_id,
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
	 * @param string $revision_hash Revision hash.
	 * @return array<string,mixed>|null
	 */
	private function latest_receipt(
		int $submission_pk,
		string $submission_id,
		int $revision_pk,
		string $revision_id,
		string $revision_hash
	): ?array {
		$sql = 'SELECT public_id, content_hash, receipt_no, action, idempotency_key, context_revision, actor_type, actor_user_id, reason, '
			. 'attempt_no, submission_revision_hash, publication_hash, attachment_set_hash, assignment_schedule_version, '
			. 'assignment_schedule_hash, resubmission_policy_hash, timeliness, received_at_utc FROM ' . self::TABLE_RECEIPTS
			. ' WHERE submission_id = %d AND submission_revision_id = %d ORDER BY receipt_no DESC LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( $submission_pk, $revision_pk ) ) );
		if ( null === $row ) {
			return null;
		}
		$receipt = $this->validated_receipt( $row, $submission_id, $revision_id, $revision_hash );

		return array(
			'id'              => $receipt['id'],
			'receipt_no'      => $receipt['receipt_no'],
			'action'          => $receipt['action'],
			'attempt_no'      => $receipt['attempt_no'],
			'timeliness'      => $receipt['timeliness'],
			'received_at_utc' => $receipt['received_at_utc'],
		);
	}

	/**
	 * Latest control receipt.
	 *
	 * @param int    $submission_pk Submission pk.
	 * @param string $submission_id Submission public identifier.
	 * @return array{action:string,reason:string,received_at_utc:string}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function latest_control_receipt( int $submission_pk, string $submission_id ): ?array {
		$sql = 'SELECT r.public_id, r.content_hash, r.receipt_no, r.action, r.idempotency_key, r.context_revision, '
			. 'r.actor_type, r.actor_user_id, r.reason, r.attempt_no, r.submission_revision_hash, r.publication_hash, '
			. 'r.attachment_set_hash, r.assignment_schedule_version, r.assignment_schedule_hash, r.resubmission_policy_hash, '
			. 'r.timeliness, r.received_at_utc, v.public_id AS revision_public_id, v.content_hash AS revision_content_hash '
			. 'FROM ' . self::TABLE_RECEIPTS . ' r INNER JOIN ' . self::TABLE_REVISIONS . ' v ON v.id = r.submission_revision_id '
			. 'WHERE r.submission_id = %d AND r.action IN (%s,%s) ORDER BY r.receipt_no DESC LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( $submission_pk, 'reopen', 'lock' ) ) );
		if ( null === $row ) {
			return null;
		}
		$receipt = $this->validated_receipt(
			$row,
			$submission_id,
			$this->public_id( $row['revision_public_id'] ?? null ),
			$this->hash_value( $row['revision_content_hash'] ?? null )
		);
		if (
			! in_array( $receipt['action'], array( 'reopen', 'lock' ), true )
			|| 'teacher' !== $receipt['actor_type']
			|| null === $receipt['reason']
			|| '' === trim( $receipt['reason'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}

		return array(
			'action'          => $receipt['action'],
			'reason'          => $receipt['reason'],
			'received_at_utc' => $receipt['received_at_utc'],
		);
	}

	/**
	 * Validated receipt.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $submission_id Submission public identifier.
	 * @param string              $revision_id Revision id.
	 * @param string              $revision_hash Revision hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function validated_receipt(
		array $row,
		string $submission_id,
		string $revision_id,
		string $revision_hash
	): array {
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
		$hash_fields = array(
			'attachment_set_hash'      => $this->hash_value( $row['attachment_set_hash'] ?? null ),
			'assignment_schedule_hash' => $this->hash_value( $row['assignment_schedule_hash'] ?? null ),
			'publication_hash'         => $this->hash_value( $row['publication_hash'] ?? null ),
			'resubmission_policy_hash' => $this->hash_value( $row['resubmission_policy_hash'] ?? null ),
			'submission_revision_hash' => $this->hash_value( $row['submission_revision_hash'] ?? null ),
		);
		if ( ! hash_equals( $revision_hash, $hash_fields['submission_revision_hash'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$content = array(
			'action'                      => $action,
			'actor_type'                  => $actor_type,
			'actor_user_id'               => $actor_user_id,
			'assignment_schedule_hash'    => $hash_fields['assignment_schedule_hash'],
			'assignment_schedule_version' => $this->positive_integer( $row['assignment_schedule_version'] ?? null ),
			'attachment_set_hash'         => $hash_fields['attachment_set_hash'],
			'context_revision'            => $this->token( $row['context_revision'] ?? null, 191 ),
			'idempotency_key'             => $this->token( $row['idempotency_key'] ?? null, 191 ),
			'attempt_no'                  => $attempt_no,
			'publication_hash'            => $hash_fields['publication_hash'],
			'reason'                      => $reason,
			'receipt_no'                  => $receipt_no,
			'received_at_utc'             => $received_at,
			'resubmission_policy_hash'    => $hash_fields['resubmission_policy_hash'],
			'submission_id'               => $submission_id,
			'submission_revision_hash'    => $hash_fields['submission_revision_hash'],
			'submission_revision_id'      => $revision_id,
			'timeliness'                  => $timeliness,
		);
		if ( ! hash_equals( $this->hash_value( $row['content_hash'] ?? null ), hash( 'sha256', $this->canonical->encode( $content ) ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}

		return array(
			'id'              => $receipt_id,
			'receipt_no'      => $receipt_no,
			'action'          => $action,
			'actor_type'      => $actor_type,
			'reason'          => $reason,
			'attempt_no'      => $attempt_no,
			'timeliness'      => $timeliness,
			'received_at_utc' => $received_at,
		);
	}

	/**
	 * Attachments.
	 *
	 * @param int        $submission_pk Submission pk.
	 * @param array|null $current_revision Current revision.
	 * @param array|null $official_revision Official revision.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachments( int $submission_pk, ?array $current_revision, ?array $official_revision ): array {
		$sql  = 'SELECT public_id, state, response_key, submission_revision_id, original_name, mime_type, byte_size, bound_at_utc '
			. 'FROM ' . self::TABLE_ATTACHMENTS . ' WHERE submission_id = %d AND state IN (%s,%s,%s,%s,%s) '
			. 'ORDER BY response_key ASC, public_id ASC LIMIT %d';
		$rows = $this->get_results(
			$this->prepare( $sql, array( $submission_pk, 'staged', 'scanning', 'ready', 'rejected', 'bound', self::MAX_ATTACHMENTS + 1 ) )
		);
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
	 * Resubmission policy.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array{max_attempts:int,reopen_until_utc:?string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function resubmission_policy( array $row ): array {
		if (
			self::POLICY_SCHEMA !== ( $row['resubmission_policy_schema'] ?? null )
			|| self::VERSION !== $this->positive_integer( $row['resubmission_policy_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$hash   = $this->hash_value( $row['resubmission_policy_hash'] ?? null );
		$policy = $this->decode_json( (string) ( $row['resubmission_policy_json'] ?? '' ), $hash );
		$this->exact_keys( $policy, array( 'copy_previous_answers', 'max_attempts', 'reopen_until_utc' ) );
		$maximum      = $this->positive_integer( $policy['max_attempts'] ?? null );
		$reopen_until = $this->optional_utc( $policy['reopen_until_utc'] ?? null );
		if (
			$maximum > 10
			|| ! is_bool( $policy['copy_previous_answers'] ?? null )
			|| ( null !== $reopen_until && $reopen_until > $this->required_utc( $row['closes_at_utc'] ?? null ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}

		return array(
			'max_attempts'     => $maximum,
			'reopen_until_utc' => $reopen_until,
		);
	}

	/**
	 * Schedule.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array{version:int,hash:string,opens_at_utc:?string,due_at_utc:string,closes_at_utc:string}
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
		$hash     = $this->hash_value( $row['schedule_hash'] ?? null );
		$expected = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'auto_submit_enabled' => 1 === $this->zero_or_one( $row['auto_submit_enabled'] ?? null ),
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
			'version'       => $version,
			'hash'          => $hash,
			'opens_at_utc'  => $opens_at,
			'due_at_utc'    => $due_at,
			'closes_at_utc' => $closes_at,
		);
	}

	/**
	 * Availability.
	 *
	 * @param string                                                             $assignment_state Assignment state.
	 * @param array{opens_at_utc:?string,due_at_utc:string,closes_at_utc:string} $schedule Schedule.
	 * @param string                                                             $now_utc Now utc.
	 * @return string
	 */
	private function availability( string $assignment_state, array $schedule, string $now_utc ): string {
		if ( 'closed' === $assignment_state || $now_utc >= $schedule['closes_at_utc'] ) {
			return 'closed';
		}
		if ( 'scheduled' === $assignment_state || ( null !== $schedule['opens_at_utc'] && $now_utc < $schedule['opens_at_utc'] ) ) {
			return 'upcoming';
		}

		return $now_utc > $schedule['due_at_utc'] ? 'late' : 'open';
	}

	/**
	 * Reject invalid context.
	 *
	 * @param array<string,mixed> $context Access or execution context for this operation.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_context( array $context ): void {
		$keys = array_keys( $context );
		sort( $keys, SORT_STRING );
		if (
			array( 'attributes', 'id', 'label', 'lifecycle_state', 'revision', 'type' ) !== $keys
			|| ! in_array( $context['type'] ?? null, array( 'class', 'group' ), true )
			|| 'active' !== ( $context['lifecycle_state'] ?? null )
			|| ! is_array( $context['attributes'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ROSTER_INVALID );
		}
		$this->token( $context['id'] ?? null, 191 );
		$this->token( $context['revision'] ?? null, 191 );
		$this->text( $context['label'] ?? null, 255 );
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
	 * Actor.
	 *
	 * @param int $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function actor( int $value ): void {
		if ( $value < 1 ) {
			throw new InvalidArgumentException( 'Student submission actor is invalid.' );
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
