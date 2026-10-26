<?php
/**
 * Append an immutable teacher_draft revision that reverses an approved student-note revision.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Approval_Withdrawal_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append an immutable teacher_draft revision that reverses an approved student-note revision.
 */
final class Withdraw_Student_Note_Approval {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize withdraw student note approval dependencies and state.
	 *
	 * @param Student_Note_Approval_Withdrawal_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Student_Note_Output_Normalizer                             $outputs Outputs.
	 * @param Public_Id_Generator                                        $ids Ids.
	 * @param Canonical_Json                                             $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                              $clock Clock.
	 */
	public function __construct(
		private Student_Note_Approval_Withdrawal_Repository_Port_Interface $repository,
		private Student_Note_Output_Normalizer $outputs,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute withdraw student note approval.
	 *
	 * @param Withdraw_Student_Note_Approval_Command $command Command.
	 * @return Student_Note_Revision_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Withdraw_Student_Note_Approval_Command $command ): Student_Note_Revision_Result {
		$receipt = $this->repository->find_withdrawal_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Student_Note_Revision_Result ) {
			return $receipt;
		}
		$snapshot = $this->repository->find_owned_withdrawal_snapshot( $command->actor_user_id(), $command->note_id() );
		if ( null === $snapshot ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}
		$evaluation_id = $snapshot['evaluation_id'] ?? null;
		if ( ! is_string( $evaluation_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$this->assert_expected( $command, $snapshot );
		$profile       = $this->approved_profile( $snapshot );
		$revision_no   = $command->expected_revision_no() + 1;
		$revision_id   = $this->ids->create()->value();
		$occurred_at   = $this->now();
		$source_run_id = $snapshot['latest_source_run_id'];
		$content_hash  = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_id'        => $evaluation_id,
					'include_in_reports'   => false,
					'note_hash'            => $profile['hash'],
					'note_root_id'         => $command->note_id(),
					'previous_revision_id' => $command->expected_revision_id(),
					'review_id'            => $command->expected_official_review_id(),
					'revision_no'          => $revision_no,
					'source_run_id'        => $source_run_id,
					'state'                => 'teacher_draft',
				)
			)
		);

		return $this->repository->append_withdrawn_revision(
			array(
				'actor_user_id'                    => $command->actor_user_id(),
				'audit_public_id'                  => $this->ids->create()->value(),
				'command_id'                       => $command->command_id(),
				'command_storage_key'              => $command->idempotency_storage_key(),
				'content_hash'                     => $content_hash,
				'context_id'                       => $snapshot['context_id'],
				'context_revision'                 => $snapshot['context_revision'],
				'context_type'                     => $snapshot['context_type'],
				'correlation_id'                   => $command->correlation_id(),
				'evaluation_public_id'             => $evaluation_id,
				'expected_evaluation_lock_version' => $snapshot['evaluation_lock_version'],
				'expected_evaluation_state'        => $snapshot['evaluation_state'],
				'expected_include_in_reports'      => false,
				'expected_note_state'              => 'approved',
				'expected_official_review_hash'    => $command->expected_official_review_hash(),
				'expected_official_review_id'      => $command->expected_official_review_id(),
				'expected_revision_hash'           => $command->expected_revision_hash(),
				'expected_revision_id'             => $command->expected_revision_id(),
				'expected_revision_no'             => $command->expected_revision_no(),
				'expected_source_run_id'           => $source_run_id,
				'include_in_reports'               => false,
				'note_hash'                        => $profile['hash'],
				'note_json'                        => $profile['json'],
				'note_root_id'                     => $command->note_id(),
				'note_schema'                      => Student_Note_Input_Builder::RESULT_SCHEMA,
				'note_version'                     => 1,
				'occurred_at_utc'                  => $occurred_at,
				'outbox_public_id'                 => $this->ids->create()->value(),
				'previous_revision_id'             => $command->expected_revision_id(),
				'reason_digest'                    => null,
				'request_digest'                   => $command->request_digest(),
				'revision_no'                      => $revision_no,
				'revision_public_id'               => $revision_id,
				'source_evaluation_run_public_id'  => $source_run_id,
				'source_mode'                      => 'withdraw_approval',
				'state'                            => 'teacher_draft',
			)
		);
	}

	/**
	 * Approved profile.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return array{json:string,hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function approved_profile( array $snapshot ): array {
		$json = $snapshot['latest_note_json'] ?? null;
		$hash = $snapshot['latest_note_hash'] ?? null;
		if ( ! is_string( $json ) || ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		try {
			$profile = json_decode( $json, true, 128, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
			if ( ! is_array( $profile ) || array_is_list( $profile ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
			try {
				$validated = $this->outputs->validate_stored_profile( $profile ); } catch ( InvalidArgumentException ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
						throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
				}
				if ( ! hash_equals( $json, $this->canonical->encode( $validated ) ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
				}
				return array(
					'json' => $json,
					'hash' => $hash,
				);
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param Withdraw_Student_Note_Approval_Command $command Command.
	 * @param array<string,mixed>                    $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( Withdraw_Student_Note_Approval_Command $command, array $snapshot ): void {
		$required = array( 'note_root_id', 'evaluation_id', 'evaluation_lock_version', 'evaluation_state', 'context_type', 'context_id', 'context_revision', 'official_review_id', 'official_review_hash', 'latest_revision_id', 'latest_revision_hash', 'latest_revision_no', 'latest_state', 'latest_include_in_reports', 'latest_source_run_id', 'latest_note_json', 'latest_note_hash' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $snapshot ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
		}
		if (
			'context_staff' !== $command->actor_relation() || 'reviewed' !== $snapshot['evaluation_state']
			|| 'approved' !== $snapshot['latest_state'] || $snapshot['latest_include_in_reports']
			|| ! hash_equals( $command->note_id(), (string) $snapshot['note_root_id'] )
			|| ! hash_equals( $command->context_type(), (string) $snapshot['context_type'] )
			|| ! hash_equals( $command->context_id(), (string) $snapshot['context_id'] )
			|| ! hash_equals( $command->context_revision(), (string) $snapshot['context_revision'] )
			|| ! hash_equals( $command->expected_official_review_id(), (string) $snapshot['official_review_id'] )
			|| ! hash_equals( $command->expected_official_review_hash(), (string) $snapshot['official_review_hash'] )
			|| ! hash_equals( $command->expected_revision_id(), (string) $snapshot['latest_revision_id'] )
			|| ! hash_equals( $command->expected_revision_hash(), (string) $snapshot['latest_revision_hash'] )
			|| $command->expected_revision_no() !== $snapshot['latest_revision_no']
			|| ! hash_equals( $command->authorized_revision(), (string) $snapshot['latest_revision_hash'] )
			|| ! hash_equals( $command->authorized_state(), (string) $snapshot['latest_state'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Safe state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return array<string,int|string>
	 */
	private function safe_state( array $snapshot ): array {
		return array(
			'current_include_in_reports'   => (int) (bool) ( $snapshot['latest_include_in_reports'] ?? false ),
			'current_official_review_hash' => (string) ( $snapshot['official_review_hash'] ?? '' ),
			'current_official_review_id'   => (string) ( $snapshot['official_review_id'] ?? '' ),
			'current_revision_hash'        => (string) ( $snapshot['latest_revision_hash'] ?? '' ),
			'current_revision_id'          => (string) ( $snapshot['latest_revision_id'] ?? '' ),
			'current_revision_no'          => (int) ( $snapshot['latest_revision_no'] ?? 0 ),
			'current_state'                => (string) ( $snapshot['latest_state'] ?? '' ),
		);
	}
	/**
	 * Now.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		$value = ( $this->clock )();
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'WithdrawStudentNoteApproval clock returned an invalid UTC value.' );
		} return $value;
	}
}
