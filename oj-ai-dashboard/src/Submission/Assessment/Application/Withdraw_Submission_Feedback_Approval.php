<?php
/**
 * Append an immutable teacher_draft revision that reverses an approved feedback revision.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Approval_Withdrawal_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append an immutable teacher_draft revision that reverses an approved feedback revision.
 */
final class Withdraw_Submission_Feedback_Approval {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize withdraw submission feedback approval dependencies and state.
	 *
	 * @param Submission_Feedback_Approval_Withdrawal_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                                               $ids Ids.
	 * @param Canonical_Json                                                    $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                                     $clock Clock.
	 */
	public function __construct(
		private Submission_Feedback_Approval_Withdrawal_Repository_Port_Interface $repository,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute withdraw submission feedback approval.
	 *
	 * @param Withdraw_Submission_Feedback_Approval_Command $command Command.
	 * @return Submission_Feedback_Review_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Withdraw_Submission_Feedback_Approval_Command $command ): Submission_Feedback_Review_Result {
		$receipt = $this->repository->find_withdrawal_receipt(
			$command->idempotency_storage_key(),
			$command->request_digest()
		);
		if ( $receipt instanceof Submission_Feedback_Review_Result ) {
			return $receipt;
		}

		$snapshot = $this->repository->find_owned_withdrawal_snapshot( $command->actor_user_id(), $command->evaluation_id() );
		if ( null === $snapshot ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}
		$this->assert_expected( $command, $snapshot );
		$profile       = $this->approved_profile( $snapshot );
		$revision_no   = $command->expected_feedback_revision_no() + 1;
		$revision_id   = $this->ids->create()->value();
		$occurred_at   = $this->now();
		$source_run_id = $snapshot['latest_feedback_source_run_id'];
		$content_hash  = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'audience'             => 'student',
					'evaluation_id'        => $command->evaluation_id(),
					'feedback_hash'        => $profile['hash'],
					'previous_revision_id' => $command->expected_feedback_revision_id(),
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
				'evaluation_public_id'             => $command->evaluation_id(),
				'expected_evaluation_lock_version' => $snapshot['evaluation_lock_version'],
				'expected_evaluation_state'        => $snapshot['evaluation_state'],
				'expected_feedback_revision_hash'  => $command->expected_feedback_revision_hash(),
				'expected_feedback_revision_id'    => $command->expected_feedback_revision_id(),
				'expected_feedback_revision_no'    => $command->expected_feedback_revision_no(),
				'expected_feedback_review_id'      => $snapshot['latest_feedback_review_id'],
				'expected_feedback_source_run_id'  => $snapshot['latest_feedback_source_run_id'],
				'expected_feedback_state'          => $snapshot['latest_feedback_state'],
				'expected_official_review_hash'    => $command->expected_official_review_hash(),
				'expected_official_review_id'      => $command->expected_official_review_id(),
				'feedback_json'                    => $profile['json'],
				'feedback_hash'                    => $profile['hash'],
				'feedback_schema'                  => $profile['schema'],
				'feedback_version'                 => $profile['version'],
				'outbox_public_id'                 => $this->ids->create()->value(),
				'occurred_at_utc'                  => $occurred_at,
				'previous_feedback_revision_id'    => $command->expected_feedback_revision_id(),
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
	 * @return array{schema:string,version:int,json:string,hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function approved_profile( array $snapshot ): array {
		$json    = $snapshot['latest_feedback_json'] ?? null;
		$hash    = $snapshot['latest_feedback_profile_hash'] ?? null;
		$schema  = $snapshot['latest_feedback_schema'] ?? null;
		$version = $snapshot['latest_feedback_version'] ?? null;
		if (
			! is_string( $json ) || ! is_string( $hash ) || ! is_string( $schema ) || ! is_int( $version )
			|| Submission_Feedback_Input_Builder::RESULT_SCHEMA !== $schema
			|| Review_Submission_Feedback::PROFILE_VERSION !== $version
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			|| ! hash_equals( $hash, hash( 'sha256', $json ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		try {
			$profile = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		if ( ! is_array( $profile ) || array_is_list( $profile ) || ! hash_equals( $json, $this->canonical->encode( $profile ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return array(
			'schema'  => $schema,
			'version' => $version,
			'json'    => $json,
			'hash'    => $hash,
		);
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param Withdraw_Submission_Feedback_Approval_Command $command Command.
	 * @param array<string,mixed>                           $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( Withdraw_Submission_Feedback_Approval_Command $command, array $snapshot ): void {
		$required = array(
			'evaluation_lock_version',
			'evaluation_state',
			'context_type',
			'context_id',
			'context_revision',
			'official_review_id',
			'official_review_hash',
			'latest_feedback_revision_id',
			'latest_feedback_revision_hash',
			'latest_feedback_revision_no',
			'latest_feedback_state',
			'latest_feedback_review_id',
			'latest_feedback_source_run_id',
			'latest_feedback_schema',
			'latest_feedback_version',
			'latest_feedback_json',
			'latest_feedback_profile_hash',
		);
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $snapshot ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
		}
		if (
			'context_staff' !== $command->actor_relation()
			|| 'reviewed' !== $snapshot['evaluation_state']
			|| 'approved' !== $snapshot['latest_feedback_state']
			|| ! hash_equals( $command->authorized_revision(), (string) $snapshot['evaluation_lock_version'] )
			|| ! hash_equals( $command->authorized_state(), (string) $snapshot['evaluation_state'] )
			|| ! hash_equals( $command->context_type(), (string) $snapshot['context_type'] )
			|| ! hash_equals( $command->context_id(), (string) $snapshot['context_id'] )
			|| ! hash_equals( $command->context_revision(), (string) $snapshot['context_revision'] )
			|| ! hash_equals( $command->expected_official_review_id(), (string) $snapshot['official_review_id'] )
			|| ! hash_equals( $command->expected_official_review_hash(), (string) $snapshot['official_review_hash'] )
			|| ! hash_equals( $command->expected_feedback_revision_id(), (string) $snapshot['latest_feedback_revision_id'] )
			|| ! hash_equals( $command->expected_feedback_revision_hash(), (string) $snapshot['latest_feedback_revision_hash'] )
			|| $command->expected_feedback_revision_no() !== $snapshot['latest_feedback_revision_no']
			|| ! hash_equals( $command->expected_official_review_id(), (string) $snapshot['latest_feedback_review_id'] )
		) {
			throw Submission_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$this->safe_state( $snapshot )
			);
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
			'current_feedback_revision_hash' => (string) ( $snapshot['latest_feedback_revision_hash'] ?? '' ),
			'current_feedback_revision_id'   => (string) ( $snapshot['latest_feedback_revision_id'] ?? '' ),
			'current_feedback_revision_no'   => (int) ( $snapshot['latest_feedback_revision_no'] ?? 0 ),
			'current_feedback_state'         => (string) ( $snapshot['latest_feedback_state'] ?? '' ),
			'current_official_review_hash'   => (string) ( $snapshot['official_review_hash'] ?? '' ),
			'current_official_review_id'     => (string) ( $snapshot['official_review_id'] ?? '' ),
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
			throw new InvalidArgumentException( 'WithdrawSubmissionFeedbackApproval clock returned an invalid UTC value.' );
		}
		return $value;
	}
}
