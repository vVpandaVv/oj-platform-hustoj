<?php
/**
 * G3-IMP-06-S05 submission concurrency and durable replay regression.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-create-g3-imp-01.php';

use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Application\Autosave_Own_Submission;
use OJAI\Dashboard\Submission\Student\Application\Autosave_Own_Submission_Command;
use OJAI\Dashboard\Submission\Student\Application\Autosave_Own_Submission_Result;
use OJAI\Dashboard\Submission\Student\Application\Start_Own_Submission_Result;
use OJAI\Dashboard\Submission\Student\Application\Submit_Own_Submission;
use OJAI\Dashboard\Submission\Student\Application\Submit_Own_Submission_Command;
use OJAI\Dashboard\Submission\Student\Application\Submit_Own_Submission_Result;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Submission_Commit_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Teacher\Application\Control_Teacher_Submission;
use OJAI\Dashboard\Submission\Teacher\Application\Control_Teacher_Submission_Command;
use OJAI\Dashboard\Submission\Teacher\Application\Control_Teacher_Submission_Result;
use OJAI\Dashboard\Submission\Teacher\Port\Teacher_Submission_Control_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * S05 id.
 *
 * @param int $number Number.
 * @return string
 */
function s05_id( int $number ): string {
	return str_pad( (string) $number, 26, '0', STR_PAD_LEFT );
}

/**
 * Shared deterministic store used to interleave C23, C26, C27, and C28.
 */
final class S05_Race_Store implements
	Student_Assignment_Repository_Port_Interface,
	Student_Submission_Commit_Repository_Port_Interface,
	Teacher_Submission_Control_Repository_Port_Interface,
	Learning_Context_Query_Port_Interface {
	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;
	/**
	 * Distribution public identifier.
	 *
	 * @var string
	 */
	private string $assignment_id;
	/**
	 * Submission public identifier.
	 *
	 * @var string
	 */
	private string $submission_id;
	/**
	 * Publication id.
	 *
	 * @var string
	 */
	private string $publication_id;
	/**
	 * Official revision id.
	 *
	 * @var string
	 */
	private string $official_revision_id;
	/**
	 * Current revision id.
	 *
	 * @var string
	 */
	private string $current_revision_id;
	/**
	 * State.
	 *
	 * @var string
	 */
	private string $state = 'submitted';
	/**
	 * Assignment state.
	 *
	 * @var string
	 */
	private string $assignment_state = 'open';
	/**
	 * Lock version.
	 *
	 * @var int
	 */
	private int $lock_version = 1;
	/**
	 * Official attempt no.
	 *
	 * @var int
	 */
	private int $official_attempt_no = 1;
	/**
	 * Schedule version.
	 *
	 * @var int
	 */
	private int $schedule_version = 1;
	/**
	 * Schedule hash.
	 *
	 * @var string
	 */
	private string $schedule_hash;
	/**
	 * Publication hash.
	 *
	 * @var string
	 */
	private string $publication_hash;
	/**
	 * Submitted at utc.
	 *
	 * @var string
	 */
	private string $submitted_at_utc = '2026-08-22 09:30:00';
	/**
	 * Closed at utc.
	 *
	 * @var string|null
	 */
	private ?string $closed_at_utc = null;
	/**
	 * Revision no.
	 *
	 * @var int
	 */
	private int $revision_no = 1;
	/**
	 * Receipt no.
	 *
	 * @var int
	 */
	private int $receipt_no = 1;
	/**
	 * Attachment set hash.
	 *
	 * @var string
	 */
	private string $attachment_set_hash;
	/**
	 * Policy hash.
	 *
	 * @var string
	 */
	private string $policy_hash;
	/**
	 * Revision hashes.
	 *
	 * @var array<string,string>
	 */
	private array $revision_hashes = array();
	/**
	 * Autosave replays.
	 *
	 * @var array<string,array{digest:string,submission:array<string,mixed>,revision:array<string,mixed>}>
	 */
	private array $autosave_replays = array();
	/**
	 * Submit replays.
	 *
	 * @var array<string,array{digest:string,submission:array<string,mixed>,receipt:array<string,mixed>}>
	 */
	private array $submit_replays = array();
	/**
	 * Control replays.
	 *
	 * @var array<string,array{digest:string,action:string,submission:array<string,mixed>,receipt:array<string,mixed>,revision:?array<string,mixed>}>
	 */
	private array $control_replays = array();
	/**
	 * Revision writes.
	 *
	 * @var int
	 */
	private int $revision_writes = 0;
	/**
	 * Receipt writes.
	 *
	 * @var int
	 */
	private int $receipt_writes = 0;
	/**
	 * Audit writes.
	 *
	 * @var int
	 */
	private int $audit_writes = 0;
	/**
	 * Outbox writes.
	 *
	 * @var int
	 */
	private int $outbox_writes = 0;

	/**
	 * Initialize s05 race store dependencies and state.
	 */
	public function __construct() {
		$this->canonical                                      = new Canonical_Json();
		$this->assignment_id                                  = s05_id( 1 );
		$this->submission_id                                  = s05_id( 2 );
		$this->publication_id                                 = s05_id( 3 );
		$this->official_revision_id                           = s05_id( 4 );
		$this->current_revision_id                            = $this->official_revision_id;
		$this->schedule_hash                                  = hash( 'sha256', 's05-schedule-1' );
		$this->publication_hash                               = hash( 'sha256', 's05-publication-1' );
		$this->attachment_set_hash                            = hash( 'sha256', $this->canonical->encode( array() ) );
		$this->policy_hash                                    = hash( 'sha256', 's05-policy-1' );
		$this->revision_hashes[ $this->official_revision_id ] = hash( 'sha256', 's05-revision-1' );
	}

	/**
	 * Canonical.
	 *
	 * @return Canonical_Json
	 */
	public function canonical(): Canonical_Json {
		return $this->canonical;
	}
	/**
	 * Assignment id.
	 *
	 * @return string
	 */
	public function assignment_id(): string {
		return $this->assignment_id;
	}
	/**
	 * Submission id.
	 *
	 * @return string
	 */
	public function submission_id(): string {
		return $this->submission_id;
	}

	/**
	 * Vector.
	 *
	 * @return array<string,mixed>
	 */
	public function vector(): array {
		return array(
			'state'                => $this->state,
			'lock_version'         => $this->lock_version,
			'current_revision_id'  => $this->current_revision_id,
			'official_revision_id' => $this->official_revision_id,
			'official_attempt_no'  => $this->official_attempt_no,
			'schedule_version'     => $this->schedule_version,
			'schedule_hash'        => $this->schedule_hash,
			'publication_hash'     => $this->publication_hash,
			'save_token'           => $this->save_token(),
		);
	}

	/**
	 * Writes.
	 *
	 * @return array{revision:int,receipt:int,audit:int,outbox:int}
	 */
	public function writes(): array {
		return array(
			'revision' => $this->revision_writes,
			'receipt'  => $this->receipt_writes,
			'audit'    => $this->audit_writes,
			'outbox'   => $this->outbox_writes,
		);
	}

	/**
	 * Drift schedule.
	 *
	 * @return void
	 */
	public function drift_schedule(): void {
		++$this->schedule_version;
		$this->schedule_hash = hash( 'sha256', 's05-schedule-' . $this->schedule_version );
	}

	/**
	 * Drift publication.
	 *
	 * @return void
	 */
	public function drift_publication(): void {
		$this->publication_hash = hash( 'sha256', 's05-publication-drift' );
	}

	/**
	 * For actor.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return array<string,array<string,mixed>>
	 */
	public function for_actor( int $actor_user_id ): array {
		if ( 7 !== $actor_user_id ) {
			return array();
		}
		return array(
			'class:class-21' => array(
				'type'            => 'class',
				'id'              => 'class-21',
				'label'           => 'Class 21',
				'lifecycle_state' => 'active',
				'revision'        => 'target-r1',
				'attributes'      => array(),
			),
		);
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array|null
	 */
	public function authorization_snapshot( string $assignment_id, int $actor_user_id ): ?array {
		unset( $assignment_id, $actor_user_id );
		return null;
	}

	/**
	 * Submission authorization snapshot.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array|null
	 */
	public function submission_authorization_snapshot( string $submission_id, int $actor_user_id ): ?array {
		if ( $this->submission_id !== $submission_id || 7 !== $actor_user_id ) {
			return null;
		}
		return array(
			'submission_id'    => $this->submission_id,
			'assignment_id'    => $this->assignment_id,
			'context_type'     => 'class',
			'context_id'       => 'class-21',
			'context_revision' => 'target-r1',
			'current_revision' => (string) $this->lock_version,
			'state'            => $this->state,
			'assignment_state' => $this->assignment_state,
			'target_state'     => 'active',
		);
	}

	/**
	 * List for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param array  $contexts Contexts.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @param string $now_utc Now utc.
	 * @return array
	 */
	public function list_for_actor( int $actor_user_id, array $contexts, string $cursor, int $limit, string $now_utc ): array {
		unset( $actor_user_id, $contexts, $cursor, $limit, $now_utc );
		return array(
			'items'       => array(),
			'next_cursor' => '',
		);
	}

	/**
	 * Find for actor.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param array  $context Access or execution context for this operation.
	 * @param string $now_utc Now utc.
	 * @return array|null
	 */
	public function find_for_actor( string $assignment_id, int $actor_user_id, array $context, string $now_utc ): ?array {
		unset( $assignment_id, $actor_user_id, $context, $now_utc );
		return null;
	}

	/**
	 * Find submission for actor.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param array  $context Access or execution context for this operation.
	 * @param string $now_utc Now utc.
	 * @return array|null
	 */
	public function find_submission_for_actor( string $submission_id, int $actor_user_id, array $context, string $now_utc ): ?array {
		unset( $submission_id, $actor_user_id, $context, $now_utc );
		return null;
	}

	/**
	 * Find start receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Start_Own_Submission_Result|null
	 */
	public function find_start_receipt( string $idempotency_storage_key, string $request_digest ): ?Start_Own_Submission_Result {
		unset( $idempotency_storage_key, $request_digest );
		return null;
	}

	/**
	 * Start.
	 *
	 * @param array $record Record.
	 * @return Start_Own_Submission_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function start( array $record ): Start_Own_Submission_Result {
		unset( $record );
		throw new RuntimeException( 'S05 does not start submissions.' );
	}

	/**
	 * Find autosave receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Autosave_Own_Submission_Result|null
	 */
	public function find_autosave_receipt( string $idempotency_storage_key, string $request_digest ): ?Autosave_Own_Submission_Result {
		$stored = $this->autosave_replays[ $idempotency_storage_key ] ?? null;
		if ( null === $stored ) {
			return null;
		}
		$this->assert_replay_digest( $stored['digest'], $request_digest );
		return new Autosave_Own_Submission_Result( $stored['submission'], $stored['revision'], true, true );
	}

	/**
	 * Autosave.
	 *
	 * @param array $record Record.
	 * @return Autosave_Own_Submission_Result
	 */
	public function autosave( array $record ): Autosave_Own_Submission_Result {
		$replay = $this->find_autosave_receipt( $record['idempotency_storage_key'], $record['request_digest'] );
		if ( $replay instanceof Autosave_Own_Submission_Result ) {
			return $replay;
		}
		$this->assert_current_vector( $record, true );
		$base_revision_no = $this->revision_no;
		++$this->revision_no;
		++$this->lock_version;
		$this->current_revision_id                           = $record['revision_public_id'];
		$response_hash                                       = hash( 'sha256', $this->canonical->encode( $record['answers'] ) );
		$content_hash                                        = hash( 'sha256', 's05-autosave-' . $this->current_revision_id );
		$this->revision_hashes[ $this->current_revision_id ] = $content_hash;
		$revision   = array(
			'id'                  => $this->current_revision_id,
			'revision_no'         => $this->revision_no,
			'base_revision_no'    => $base_revision_no,
			'state'               => 'saved',
			'response_hash'       => $response_hash,
			'attachment_set_hash' => $this->attachment_set_hash,
			'content_hash'        => $content_hash,
			'created_at_utc'      => $record['occurred_at_utc'],
		);
		$submission = array(
			'id'                   => $this->submission_id,
			'assignment_id'        => $this->assignment_id,
			'state'                => $this->state,
			'lock_version'         => $this->lock_version,
			'current_revision_id'  => $this->current_revision_id,
			'official_revision_id' => $this->official_revision_id,
			'save_token'           => $this->save_token(),
			'actions'              => array( 'submission.autosave' ),
		);
		$result     = new Autosave_Own_Submission_Result( $submission, $revision, true, false );
		$this->autosave_replays[ $record['idempotency_storage_key'] ] = array(
			'digest'     => $record['request_digest'],
			'submission' => $submission,
			'revision'   => $revision,
		);
		++$this->revision_writes;
		++$this->audit_writes;
		++$this->outbox_writes;
		return $result;
	}

	/**
	 * Find submit receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Submit_Own_Submission_Result|null
	 */
	public function find_submit_receipt( string $idempotency_storage_key, string $request_digest ): ?Submit_Own_Submission_Result {
		$stored = $this->submit_replays[ $idempotency_storage_key ] ?? null;
		if ( null === $stored ) {
			return null;
		}
		$this->assert_replay_digest( $stored['digest'], $request_digest );
		return new Submit_Own_Submission_Result( $stored['submission'], $stored['receipt'], true, true );
	}

	/**
	 * Submit.
	 *
	 * @param array $record Record.
	 * @return Submit_Own_Submission_Result
	 */
	public function submit( array $record ): Submit_Own_Submission_Result {
		$replay = $this->find_submit_receipt( $record['idempotency_storage_key'], $record['request_digest'] );
		if ( $replay instanceof Submit_Own_Submission_Result ) {
			return $replay;
		}
		$this->assert_current_vector( $record, true );
		$revision_id = $this->current_revision_id;
		++$this->lock_version;
		++$this->official_attempt_no;
		$this->state                = 'submitted';
		$this->official_revision_id = $revision_id;
		$this->submitted_at_utc     = $record['occurred_at_utc'];
		$this->closed_at_utc        = null;
		++$this->receipt_no;
		$submission = $this->official_submission_projection();
		$receipt    = $this->receipt_projection(
			$record['receipt_public_id'],
			'submit',
			'learner',
			$revision_id,
			$this->revision_hashes[ $revision_id ],
			$record['occurred_at_utc'],
			null,
			$record['authorized_context_revision']
		);
		$result     = new Submit_Own_Submission_Result( $submission, $receipt, true, false );
		$this->submit_replays[ $record['idempotency_storage_key'] ] = array(
			'digest'     => $record['request_digest'],
			'submission' => $submission,
			'receipt'    => $receipt,
		);
		++$this->receipt_writes;
		++$this->audit_writes;
		++$this->outbox_writes;
		return $result;
	}

	/**
	 * Find receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @param string $action Action.
	 * @return Control_Teacher_Submission_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_receipt( string $storage_key, string $request_digest, string $action ): ?Control_Teacher_Submission_Result {
		$stored = $this->control_replays[ $storage_key ] ?? null;
		if ( null === $stored ) {
			return null;
		}
		if ( $stored['action'] !== $action ) {
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		$this->assert_replay_digest( $stored['digest'], $request_digest );
		return new Control_Teacher_Submission_Result( $stored['submission'], $stored['receipt'], $stored['revision'], true, true );
	}

	/**
	 * Control.
	 *
	 * @param array $record Record.
	 * @return Control_Teacher_Submission_Result
	 */
	public function control( array $record ): Control_Teacher_Submission_Result {
		$replay = $this->find_receipt( $record['idempotency_storage_key'], $record['request_digest'], $record['action'] );
		if ( $replay instanceof Control_Teacher_Submission_Result ) {
			return $replay;
		}
		$this->assert_current_vector( $record, false );
		if (
			$record['expected_official_revision_id'] !== $this->official_revision_id
			|| $record['expected_official_attempt_no'] !== $this->official_attempt_no
		) {
			$this->conflict();
		}

		$revision            = null;
		$receipt_revision_id = $this->official_revision_id;
		if ( Control_Teacher_Submission_Command::REOPEN === $record['action'] ) {
			$base_revision_no = $this->revision_no;
			++$this->revision_no;
			++$this->lock_version;
			$this->state               = 'reopened';
			$this->closed_at_utc       = null;
			$this->current_revision_id = $record['revision_public_id'];
			$receipt_revision_id       = $this->current_revision_id;
			$content_hash              = hash( 'sha256', 's05-reopen-' . $this->current_revision_id );
			$this->revision_hashes[ $this->current_revision_id ] = $content_hash;
			$revision = array(
				'id'                  => $this->current_revision_id,
				'revision_no'         => $this->revision_no,
				'base_revision_no'    => $base_revision_no,
				'state'               => 'reopened_fork',
				'response_hash'       => hash( 'sha256', 's05-reopen-response-' . $this->revision_no ),
				'attachment_set_hash' => $this->attachment_set_hash,
				'content_hash'        => $content_hash,
				'created_at_utc'      => $record['occurred_at_utc'],
			);
			++$this->revision_writes;
		} else {
			++$this->lock_version;
			$this->state               = 'locked';
			$this->current_revision_id = $this->official_revision_id;
			$this->closed_at_utc       = $record['occurred_at_utc'];
		}

		++$this->receipt_no;
		$submission = $this->control_submission_projection();
		$receipt    = $this->receipt_projection(
			$record['receipt_public_id'],
			$record['action'],
			'teacher',
			$receipt_revision_id,
			$this->revision_hashes[ $receipt_revision_id ],
			$record['occurred_at_utc'],
			$record['reason'],
			$record['authorized_context_revision']
		);
		$result     = new Control_Teacher_Submission_Result( $submission, $receipt, $revision, true, false );
		$this->control_replays[ $record['idempotency_storage_key'] ] = array(
			'digest'     => $record['request_digest'],
			'action'     => $record['action'],
			'submission' => $submission,
			'receipt'    => $receipt,
			'revision'   => $revision,
		);
		++$this->receipt_writes;
		++$this->audit_writes;
		++$this->outbox_writes;
		return $result;
	}

	/**
	 * Reject invalid current vector.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $include_current Include current.
	 * @return void
	 */
	private function assert_current_vector( array $record, bool $include_current ): void {
		if (
			$record['expected_state'] !== $this->state
			|| $record['expected_lock_version'] !== $this->lock_version
			|| $record['expected_schedule_version'] !== $this->schedule_version
			|| ! hash_equals( $record['expected_schedule_hash'], $this->schedule_hash )
			|| ! hash_equals( $record['expected_publication_hash'], $this->publication_hash )
			|| ( $include_current && $record['expected_current_revision_id'] !== $this->current_revision_id )
			|| ( $include_current && ! hash_equals( $record['expected_save_token'], $this->save_token() ) )
		) {
			$this->conflict();
		}
	}

	/**
	 * Reject invalid replay digest.
	 *
	 * @param string $stored Stored.
	 * @param string $received Received.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_replay_digest( string $stored, string $received ): void {
		if ( ! hash_equals( $stored, $received ) ) {
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
	}

	/**
	 * Conflict.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function conflict(): never {
		throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
	}

	/**
	 * Save token.
	 *
	 * @return string
	 */
	private function save_token(): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					$this->submission_id,
					$this->state,
					$this->lock_version,
					$this->current_revision_id,
					$this->schedule_version,
					$this->schedule_hash,
					$this->publication_hash,
				)
			)
		);
	}

	/**
	 * Official submission projection.
	 *
	 * @return array<string,mixed>
	 */
	private function official_submission_projection(): array {
		return array(
			'id'                   => $this->submission_id,
			'assignment_id'        => $this->assignment_id,
			'state'                => 'submitted',
			'lock_version'         => $this->lock_version,
			'current_revision_id'  => $this->current_revision_id,
			'official_revision_id' => $this->official_revision_id,
			'official_attempt_no'  => $this->official_attempt_no,
			'submitted_at_utc'     => $this->submitted_at_utc,
			'actions'              => array(),
		);
	}

	/**
	 * Control submission projection.
	 *
	 * @return array<string,mixed>
	 */
	private function control_submission_projection(): array {
		return array(
			'id'                   => $this->submission_id,
			'assignment_id'        => $this->assignment_id,
			'state'                => $this->state,
			'lock_version'         => $this->lock_version,
			'current_revision_id'  => $this->current_revision_id,
			'official_revision_id' => $this->official_revision_id,
			'official_attempt_no'  => $this->official_attempt_no,
			'submitted_at_utc'     => $this->submitted_at_utc,
			'closed_at_utc'        => $this->closed_at_utc,
			'actions'              => array(),
		);
	}

	/**
	 * Receipt projection.
	 *
	 * @param string      $id Id.
	 * @param string      $action Action.
	 * @param string      $actor_type Actor type.
	 * @param string      $revision_id Revision id.
	 * @param string      $revision_hash Revision hash.
	 * @param string      $received_at Received at.
	 * @param string|null $reason Reason.
	 * @param string      $context_revision Context revision.
	 * @return array<string,mixed>
	 */
	private function receipt_projection(
		string $id,
		string $action,
		string $actor_type,
		string $revision_id,
		string $revision_hash,
		string $received_at,
		?string $reason,
		string $context_revision
	): array {
		$receipt = array(
			'id'                          => $id,
			'content_hash'                => hash( 'sha256', 's05-receipt-' . $id ),
			'receipt_no'                  => $this->receipt_no,
			'action'                      => $action,
			'actor_type'                  => $actor_type,
			'submission_id'               => $this->submission_id,
			'submission_revision_id'      => $revision_id,
			'attempt_no'                  => $this->official_attempt_no,
			'submission_revision_hash'    => $revision_hash,
			'publication_id'              => $this->publication_id,
			'publication_hash'            => $this->publication_hash,
			'attachment_set_hash'         => $this->attachment_set_hash,
			'assignment_schedule_version' => $this->schedule_version,
			'assignment_schedule_hash'    => $this->schedule_hash,
			'resubmission_policy_hash'    => $this->policy_hash,
			'timeliness'                  => 'on_time',
			'received_at_utc'             => $received_at,
			'context_revision'            => $context_revision,
		);
		if ( null !== $reason ) {
			$receipt['reason'] = $reason;
		}
		return $receipt;
	}
}

/**
 * S05 services.
 *
 * @return array{store:S05_Race_Store,autosave:Autosave_Own_Submission,submit:Submit_Own_Submission,control:Control_Teacher_Submission}
 */
function s05_services(): array {
	$store      = new S05_Race_Store();
	$characters = array( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K', 'M', 'N', 'P', 'Q', 'R', 'S', 'T', 'V', 'W', 'X', 'Y', 'Z' );
	$index      = 0;
	$ids        = new Public_Id_Generator(
		static function ( int $length ) use ( &$characters, &$index ): string {
			return str_repeat( $characters[ $index++ ], $length );
		}
	);
	return array(
		'store'    => $store,
		'autosave' => new Autosave_Own_Submission( $store, $store, $ids, static fn (): string => '2026-08-22 10:31:00' ),
		'submit'   => new Submit_Own_Submission( $store, $store, $store, $ids, static fn (): string => '2026-08-22 10:32:00' ),
		'control'  => new Control_Teacher_Submission( $store, $ids, static fn (): string => '2026-08-22 10:30:00' ),
	);
}

/**
 * S05 control command.
 *
 * @param S05_Race_Store      $store Store.
 * @param array<string,mixed> $vector Vector.
 * @param string              $action Action.
 * @param string              $fingerprint Fingerprint.
 * @param string              $command_id Command identifier used for idempotency.
 * @return Control_Teacher_Submission_Command
 */
function s05_control_command( S05_Race_Store $store, array $vector, string $action, string $fingerprint, string $command_id ): Control_Teacher_Submission_Command {
	return new Control_Teacher_Submission_Command(
		19,
		$store->submission_id(),
		$action,
		$command_id,
		$vector['state'],
		$vector['lock_version'],
		$vector['official_revision_id'],
		$vector['official_attempt_no'],
		$vector['schedule_version'],
		$vector['schedule_hash'],
		$vector['publication_hash'],
		'Teacher concurrency decision.',
		'class',
		'class-21',
		'target-r1',
		hash( 'sha256', $fingerprint ),
		's05-teacher-correlation',
		$store->canonical()
	);
}

/**
 * S05 submit command.
 *
 * @param S05_Race_Store      $store Store.
 * @param array<string,mixed> $vector Vector.
 * @param string              $fingerprint Fingerprint.
 * @param string              $command_id Command identifier used for idempotency.
 * @return Submit_Own_Submission_Command
 */
function s05_submit_command( S05_Race_Store $store, array $vector, string $fingerprint, string $command_id ): Submit_Own_Submission_Command {
	return new Submit_Own_Submission_Command(
		7,
		$store->submission_id(),
		$command_id,
		$vector['state'],
		$vector['lock_version'],
		$vector['current_revision_id'],
		$vector['schedule_version'],
		$vector['schedule_hash'],
		$vector['publication_hash'],
		$vector['save_token'],
		hash( 'sha256', $fingerprint ),
		's05-submit-correlation',
		$store->canonical()
	);
}

/**
 * S05 autosave command.
 *
 * @param S05_Race_Store      $store Store.
 * @param array<string,mixed> $vector Vector.
 * @param string              $fingerprint Fingerprint.
 * @param string              $command_id Command identifier used for idempotency.
 * @return Autosave_Own_Submission_Command
 */
function s05_autosave_command( S05_Race_Store $store, array $vector, string $fingerprint, string $command_id ): Autosave_Own_Submission_Command {
	return new Autosave_Own_Submission_Command(
		7,
		$store->submission_id(),
		$command_id,
		$vector['state'],
		$vector['lock_version'],
		$vector['current_revision_id'],
		$vector['schedule_version'],
		$vector['schedule_hash'],
		$vector['publication_hash'],
		$vector['save_token'],
		array( 'response_1' => 'Updated answer' ),
		array(),
		hash( 'sha256', $fingerprint ),
		's05-autosave-correlation',
		$store->canonical()
	);
}

// Student resubmission wins. A stale teacher lock cannot overwrite the new official attempt.
$race    = s05_services();
$initial = $race['store']->vector();
$reopen  = s05_control_command( $race['store'], $initial, Control_Teacher_Submission_Command::REOPEN, 'race-one-reopen', 's05-race-one-reopen' );
$race['control']->execute( $reopen );
$editable            = $race['store']->vector();
$submit              = s05_submit_command( $race['store'], $editable, 'race-one-submit', 's05-race-one-submit' );
$stale_lock          = s05_control_command( $race['store'], $editable, Control_Teacher_Submission_Command::LOCK, 'race-one-lock', 's05-race-one-lock' );
$submitted           = $race['submit']->execute( $submit );
$writes_after_submit = $race['store']->writes();
g3_same( 2, $submitted->submission()['official_attempt_no'], 'The student race winner must create exactly official attempt 2.' );
g3_failure( static fn () => $race['control']->execute( $stale_lock ), Assignment_Failure::SUBMISSION_REVISION_CONFLICT, 'A stale teacher lock must not overwrite a winning resubmission.' );
g3_same( $writes_after_submit, $race['store']->writes(), 'A stale teacher lock must add no revision, receipt, audit, or outbox row.' );
g3_same( true, $race['submit']->execute( $submit )->replayed(), 'C26 must replay after the root has become official.' );
g3_same( true, $race['control']->execute( $reopen )->replayed(), 'C27 must replay its durable historical result after a later resubmission.' );
g3_failure(
	static fn () => $race['submit']->execute( s05_submit_command( $race['store'], $editable, 'race-one-submit', 's05-race-one-submit-changed' ) ),
	Assignment_Failure::IDEMPOTENCY_CONFLICT,
	'A changed C26 request must not reuse the winner Idempotency-Key.'
);

// Autosave wins. Both stale submit and stale teacher lock must lose on the same root CAS.
$race    = s05_services();
$initial = $race['store']->vector();
$race['control']->execute( s05_control_command( $race['store'], $initial, Control_Teacher_Submission_Command::REOPEN, 'race-two-reopen', 's05-race-two-reopen' ) );
$editable          = $race['store']->vector();
$autosave          = s05_autosave_command( $race['store'], $editable, 'race-two-autosave', 's05-race-two-autosave' );
$stale_submit      = s05_submit_command( $race['store'], $editable, 'race-two-submit', 's05-race-two-submit' );
$stale_lock        = s05_control_command( $race['store'], $editable, Control_Teacher_Submission_Command::LOCK, 'race-two-lock', 's05-race-two-lock' );
$saved             = $race['autosave']->execute( $autosave );
$writes_after_save = $race['store']->writes();
g3_same( 3, $saved->submission()['lock_version'], 'The autosave race winner must advance the root once.' );
g3_failure( static fn () => $race['submit']->execute( $stale_submit ), Assignment_Failure::SUBMISSION_REVISION_CONFLICT, 'A stale submit must lose after autosave advances the root.' );
g3_failure( static fn () => $race['control']->execute( $stale_lock ), Assignment_Failure::SUBMISSION_REVISION_CONFLICT, 'A stale teacher lock must lose after autosave advances the root.' );
g3_same( $writes_after_save, $race['store']->writes(), 'Both stale losers must leave evidence counts unchanged.' );
$fresh_submit = s05_submit_command( $race['store'], $race['store']->vector(), 'race-two-fresh-submit', 's05-race-two-fresh-submit' );
$race['submit']->execute( $fresh_submit );
g3_same( true, $race['autosave']->execute( $autosave )->replayed(), 'C23 must replay after a later official submission changes the root state.' );

// Teacher lock wins. Stale student writes are rejected before they can reach persistence.
$race    = s05_services();
$initial = $race['store']->vector();
$race['control']->execute( s05_control_command( $race['store'], $initial, Control_Teacher_Submission_Command::REOPEN, 'race-three-reopen', 's05-race-three-reopen' ) );
$editable          = $race['store']->vector();
$stale_autosave    = s05_autosave_command( $race['store'], $editable, 'race-three-autosave', 's05-race-three-autosave' );
$stale_submit      = s05_submit_command( $race['store'], $editable, 'race-three-submit', 's05-race-three-submit' );
$lock              = s05_control_command( $race['store'], $editable, Control_Teacher_Submission_Command::LOCK, 'race-three-lock', 's05-race-three-lock' );
$locked            = $race['control']->execute( $lock );
$writes_after_lock = $race['store']->writes();
g3_same( 'locked', $locked->submission()['state'], 'The teacher lock race winner must close the editable fork.' );
g3_failure( static fn () => $race['autosave']->execute( $stale_autosave ), Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL, 'Autosave must stop after teacher lock wins.' );
g3_failure( static fn () => $race['submit']->execute( $stale_submit ), Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL, 'Submit must stop after teacher lock wins.' );
g3_same( $writes_after_lock, $race['store']->writes(), 'Student losers after lock must add no persistence evidence.' );
g3_same( true, $race['control']->execute( $lock )->replayed(), 'C28 must replay its exact durable lock result.' );

// Schedule and publication drift invalidate every stale mutation vector.
$race    = s05_services();
$initial = $race['store']->vector();
$race['control']->execute( s05_control_command( $race['store'], $initial, Control_Teacher_Submission_Command::REOPEN, 'race-four-reopen', 's05-race-four-reopen' ) );
$editable        = $race['store']->vector();
$schedule_submit = s05_submit_command( $race['store'], $editable, 'race-four-submit', 's05-race-four-submit' );
$schedule_lock   = s05_control_command( $race['store'], $editable, Control_Teacher_Submission_Command::LOCK, 'race-four-lock', 's05-race-four-lock' );
$race['store']->drift_schedule();
$writes_before_drift_attempt = $race['store']->writes();
g3_failure( static fn () => $race['submit']->execute( $schedule_submit ), Assignment_Failure::SUBMISSION_REVISION_CONFLICT, 'Schedule drift must invalidate a stale C26 vector.' );
g3_failure( static fn () => $race['control']->execute( $schedule_lock ), Assignment_Failure::SUBMISSION_REVISION_CONFLICT, 'Schedule drift must invalidate a stale C28 vector.' );
g3_same( $writes_before_drift_attempt, $race['store']->writes(), 'Schedule drift rejects must not append evidence.' );

$race    = s05_services();
$initial = $race['store']->vector();
$race['control']->execute( s05_control_command( $race['store'], $initial, Control_Teacher_Submission_Command::REOPEN, 'race-five-reopen', 's05-race-five-reopen' ) );
$editable             = $race['store']->vector();
$publication_autosave = s05_autosave_command( $race['store'], $editable, 'race-five-autosave', 's05-race-five-autosave' );
$publication_lock     = s05_control_command( $race['store'], $editable, Control_Teacher_Submission_Command::LOCK, 'race-five-lock', 's05-race-five-lock' );
$race['store']->drift_publication();
$writes_before_drift_attempt = $race['store']->writes();
g3_failure( static fn () => $race['autosave']->execute( $publication_autosave ), Assignment_Failure::SUBMISSION_REVISION_CONFLICT, 'Publication drift must invalidate a stale C23 vector.' );
g3_failure( static fn () => $race['control']->execute( $publication_lock ), Assignment_Failure::SUBMISSION_REVISION_CONFLICT, 'Publication drift must invalidate a stale C28 vector.' );
g3_same( $writes_before_drift_attempt, $race['store']->writes(), 'Publication drift rejects must not append evidence.' );

$addon_sources          = array(
	'autosave_app'  => (string) file_get_contents( $addon_root . '/src/Submission/Student/Application/Autosave_Own_Submission.php' ),
	'autosave_repo' => (string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/WordPress_Student_Submission_Autosave.php' ),
	'submit_repo'   => (string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/WordPress_Student_Submission_Commit_Repository.php' ),
	'control_repo'  => (string) file_get_contents( $addon_root . '/src/Submission/Teacher/Infrastructure/WordPress_Teacher_Submission_Control_Repository.php' ),
);
$receipt_position       = strpos( $addon_sources['autosave_app'], 'find_autosave_receipt' );
$mutable_state_position = strpos( $addon_sources['autosave_app'], "'open' !== \$snapshot['assignment_state']" );
g3_assert( false !== $receipt_position && false !== $mutable_state_position && $receipt_position < $mutable_state_position, 'C23 durable replay must be checked before mutable assignment and submission state gates.' );
foreach ( array( 'autosave_repo', 'submit_repo', 'control_repo' ) as $source_key ) {
	$source = $addon_sources[ $source_key ];
	foreach ( array( 'START TRANSACTION', 'FOR UPDATE', 'expected_lock_version', 'expected_schedule_version', 'expected_schedule_hash', 'expected_publication_hash', 'ROLLBACK' ) as $marker ) {
		g3_assert( str_contains( $source, $marker ), $source_key . ' concurrency marker is missing: ' . $marker );
	}
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), $source_key . ' must use literal add-on tables.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), $source_key . ' must not restore legacy prefixed tables.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), $source_key . ' must not depend on platform internals.' );
}

echo "G3-IMP-06-S05 passed: autosave, submit, reopen, and lock races use one CAS winner, durable replay, drift rejection, and zero loser evidence.\n";
