<?php
/**
 * Append an immutable withdrawn release and advance the current pointer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Result_Withdrawal_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append an immutable withdrawn release and advance the current pointer.
 */
final class Withdraw_Submission_Result {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize withdraw submission result dependencies and state.
	 *
	 * @param Submission_Result_Withdrawal_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                                    $ids Ids.
	 * @param Canonical_Json                                         $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                          $clock Clock.
	 */
	public function __construct(
		private Submission_Result_Withdrawal_Repository_Port_Interface $repository,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute withdraw submission result.
	 *
	 * @param Withdraw_Submission_Result_Command $command Command.
	 * @return Submission_Result_Withdrawal_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Withdraw_Submission_Result_Command $command ): Submission_Result_Withdrawal_Result {
		$receipt = $this->repository->find_withdrawal_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Submission_Result_Withdrawal_Result ) {
			return $receipt;
		}

		$snapshot = $this->repository->find_owned_withdrawal_snapshot( $command->actor_user_id(), $command->evaluation_id() );
		if ( null === $snapshot ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}
		$this->assert_expected( $command, $snapshot );
		$projection   = $this->projection( $snapshot );
		$occurred_at  = $this->now();
		$release_no   = (int) $snapshot['current_release_no'] + 1;
		$release_id   = $this->ids->create()->value();
		$content_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_id'              => $command->evaluation_id(),
					'feedback_id'                => $snapshot['current_release_feedback_id'],
					'previous_release_id'        => $command->expected_current_release_id(),
					'release_no'                 => $release_no,
					'release_reason_code'        => $command->reason_code(),
					'release_reason_note'        => $command->reason_note(),
					'released_at_utc'            => $occurred_at,
					'review_id'                  => $snapshot['current_release_review_id'],
					'state'                      => 'withdrawn',
					'student_projection_hash'    => $projection->hash(),
					'student_projection_schema'  => $projection->schema(),
					'student_projection_version' => $projection->version(),
					'visible_score'              => $projection->visible_score(),
				)
			)
		);

		return $this->repository->append_withdrawal(
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
				'expected_current_release_hash'    => $command->expected_current_release_hash(),
				'expected_current_release_id'      => $command->expected_current_release_id(),
				'expected_current_release_no'      => $snapshot['current_release_no'],
				'expected_current_release_state'   => $snapshot['current_release_state'],
				'expected_evaluation_lock_version' => $snapshot['evaluation_lock_version'],
				'expected_evaluation_state'        => $snapshot['evaluation_state'],
				'expected_official_review_hash'    => $snapshot['official_review_hash'],
				'expected_official_review_id'      => $snapshot['official_review_id'],
				'expected_submission_lock_version' => $snapshot['submission_lock_version'],
				'feedback_revision_id'             => $snapshot['current_release_feedback_id'],
				'outbox_public_id'                 => $this->ids->create()->value(),
				'previous_release_id'              => $command->expected_current_release_id(),
				'projection_hash'                  => $projection->hash(),
				'projection_json'                  => $projection->json(),
				'projection_schema'                => $projection->schema(),
				'projection_version'               => $projection->version(),
				'reason_code'                      => $command->reason_code(),
				'reason_note'                      => $command->reason_note(),
				'release_no'                       => $release_no,
				'release_public_id'                => $release_id,
				'released_at_utc'                  => $occurred_at,
				'request_digest'                   => $command->request_digest(),
				'review_id'                        => $snapshot['current_release_review_id'],
				'review_hash'                      => $snapshot['current_release_review_hash'],
				'state'                            => 'withdrawn',
				'visible_score'                    => $projection->visible_score(),
			)
		);
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param Withdraw_Submission_Result_Command $command Command.
	 * @param array<string,mixed>                $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( Withdraw_Submission_Result_Command $command, array $snapshot ): void {
		$required = array(
			'context_id',
			'context_revision',
			'context_type',
			'current_release_feedback_id',
			'current_release_hash',
			'current_release_id',
			'current_release_no',
			'current_release_projection_hash',
			'current_release_projection_json',
			'current_release_projection_schema',
			'current_release_projection_version',
			'current_release_review_hash',
			'current_release_review_id',
			'current_release_state',
			'current_release_visible_score',
			'evaluation_lock_version',
			'evaluation_state',
			'official_review_hash',
			'official_review_id',
			'submission_lock_version',
		);
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $snapshot ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
		}
		if ( ! in_array( $snapshot['current_release_state'], array( 'released', 'corrected' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STATE_CONFLICT, $this->safe_state( $snapshot ) );
		}
		$stale =
			'context_staff' !== $command->actor_relation()
			|| 'reviewed' !== $snapshot['evaluation_state']
			|| ! hash_equals( $command->authorized_revision(), (string) $snapshot['evaluation_lock_version'] )
			|| ! hash_equals( $command->authorized_state(), (string) $snapshot['evaluation_state'] )
			|| ! hash_equals( $command->context_type(), (string) $snapshot['context_type'] )
			|| ! hash_equals( $command->context_id(), (string) $snapshot['context_id'] )
			|| ! hash_equals( $command->context_revision(), (string) $snapshot['context_revision'] )
			|| ! hash_equals( $command->expected_current_release_id(), (string) $snapshot['current_release_id'] )
			|| ! hash_equals( $command->expected_current_release_hash(), (string) $snapshot['current_release_hash'] )
			|| ! hash_equals( (string) $snapshot['official_review_id'], (string) $snapshot['current_release_review_id'] )
			|| ! hash_equals( (string) $snapshot['official_review_hash'], (string) $snapshot['current_release_review_hash'] );
		if ( $stale ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::RELEASE_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Projection.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return Submission_Result_Projection
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function projection( array $snapshot ): Submission_Result_Projection {
		$json  = $snapshot['current_release_projection_json'];
		$hash  = $snapshot['current_release_projection_hash'];
		$score = $snapshot['current_release_visible_score'];
		if (
			! is_string( $json )
			|| ! is_string( $hash )
			|| Submission_Result_Projection::SCHEMA !== $snapshot['current_release_projection_schema']
			|| Submission_Result_Projection::VERSION !== $snapshot['current_release_projection_version']
			|| ( null !== $score && ! is_string( $score ) )
			|| ! hash_equals( $hash, hash( 'sha256', $json ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		try {
			$decoded = json_decode( $json, true, 128, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
			if ( ! is_array( $decoded ) || array_is_list( $decoded ) || ! hash_equals( $json, $this->canonical->encode( $decoded ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
			try {
				return new Submission_Result_Projection( $decoded, $json, $hash, $score ); } catch ( InvalidArgumentException ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
						throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
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
			'current_official_review_hash' => (string) ( $snapshot['official_review_hash'] ?? '' ),
			'current_official_review_id'   => (string) ( $snapshot['official_review_id'] ?? '' ),
			'current_release_hash'         => (string) ( $snapshot['current_release_hash'] ?? '' ),
			'current_release_id'           => (string) ( $snapshot['current_release_id'] ?? '' ),
			'current_release_state'        => (string) ( $snapshot['current_release_state'] ?? '' ),
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
			throw new InvalidArgumentException( 'WithdrawSubmissionResult clock returned an invalid UTC value.' );
		}
		return $value;
	}
}
