<?php
/**
 * C31 retry student submission evaluation command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C31 retry student submission evaluation command.
 */
final class Retry_Submission_Evaluation_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize retry submission evaluation command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $evaluation_id Evaluation id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_state Expected state.
	 * @param string         $expected_run_id Expected run id.
	 * @param int            $expected_run_no Expected run no.
	 * @param string|null    $expected_latest_result_id Expected latest result id.
	 * @param string         $reason Reason.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param string         $authorized_revision Authorized revision.
	 * @param string         $authorized_state Authorized state.
	 * @param string         $actor_relation Actor relation.
	 * @param string         $context_type Context type.
	 * @param string         $context_id Context id.
	 * @param string         $context_revision Context revision.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $evaluation_id,
		private string $command_id,
		private string $expected_state,
		private string $expected_run_id,
		private int $expected_run_no,
		private ?string $expected_latest_result_id,
		private string $reason,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $authorized_revision,
		private string $authorized_state,
		private string $actor_relation,
		private string $context_type,
		private string $context_id,
		private string $context_revision,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 || $expected_run_no < 1 ) {
			throw new InvalidArgumentException( 'RetrySubmissionEvaluation command identity is invalid.' );
		}
		new Public_Id( $evaluation_id );
		new Public_Id( $expected_run_id );
		if ( null !== $expected_latest_result_id ) {
			new Public_Id( $expected_latest_result_id );
		}
		$this->safe_token( $command_id, 'command_id', 8, 128 );
		$this->safe_token( $correlation_id, 'correlation_id', 1, 100 );
		$this->context_token( $context_id, 'context_id' );
		$this->context_token( $context_revision, 'context_revision' );
		$this->assert_reason( $reason );
		if (
			! in_array( $expected_state, array( 'review_required', 'reviewed', 'failed', 'cancelled' ), true )
			|| ! in_array( $authorized_state, array( 'requested', 'queued', 'running', 'review_required', 'reviewed', 'failed', 'cancelled' ), true )
			|| 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $authorized_revision )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| 'context_staff' !== $actor_relation
			|| ! in_array( $context_type, array( 'class', 'group' ), true )
		) {
			throw new InvalidArgumentException( 'RetrySubmissionEvaluation command evidence is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id'    => $command_id,
					'evaluation_id' => $evaluation_id,
					'expected'      => array(
						'expected_latest_result_id' => $expected_latest_result_id,
						'expected_run_id'           => $expected_run_id,
						'expected_run_no'           => $expected_run_no,
						'expected_state'            => $expected_state,
					),
					'payload'       => array( 'reason' => $reason ),
				)
			)
		);
	}

	/**
	 * Actor user id.
	 *
	 * @return int
	 */
	public function actor_user_id(): int {
		return $this->actor_user_id;
	}
	/**
	 * Evaluation id.
	 *
	 * @return string
	 */
	public function evaluation_id(): string {
		return $this->evaluation_id;
	}
	/**
	 * Command id.
	 *
	 * @return string
	 */
	public function command_id(): string {
		return $this->command_id;
	}
	/**
	 * Expected state.
	 *
	 * @return string
	 */
	public function expected_state(): string {
		return $this->expected_state;
	}
	/**
	 * Expected run id.
	 *
	 * @return string
	 */
	public function expected_run_id(): string {
		return $this->expected_run_id;
	}
	/**
	 * Expected run no.
	 *
	 * @return int
	 */
	public function expected_run_no(): int {
		return $this->expected_run_no;
	}
	/**
	 * Expected latest result id.
	 *
	 * @return string|null
	 */
	public function expected_latest_result_id(): ?string {
		return $this->expected_latest_result_id;
	}
	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
	/**
	 * Correlation id.
	 *
	 * @return string
	 */
	public function correlation_id(): string {
		return $this->correlation_id;
	}
	/**
	 * Authorized revision.
	 *
	 * @return string
	 */
	public function authorized_revision(): string {
		return $this->authorized_revision;
	}
	/**
	 * Authorized state.
	 *
	 * @return string
	 */
	public function authorized_state(): string {
		return $this->authorized_state;
	}
	/**
	 * Actor relation.
	 *
	 * @return string
	 */
	public function actor_relation(): string {
		return $this->actor_relation;
	}
	/**
	 * Context type.
	 *
	 * @return string
	 */
	public function context_type(): string {
		return $this->context_type;
	}
	/**
	 * Context id.
	 *
	 * @return string
	 */
	public function context_id(): string {
		return $this->context_id;
	}
	/**
	 * Context revision.
	 *
	 * @return string
	 */
	public function context_revision(): string {
		return $this->context_revision;
	}
	/**
	 * Request digest.
	 *
	 * @return string
	 */
	public function request_digest(): string {
		return $this->request_digest;
	}

	/**
	 * Idempotency storage key.
	 *
	 * @return string
	 */
	public function idempotency_storage_key(): string {
		return 'command:' . hash(
			'sha256',
			$this->actor_user_id . '|submission.evaluation.retry|' . $this->evaluation_id . '|' . $this->idempotency_fingerprint
		);
	}

	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( string $value, string $field, int $minimum, int $maximum ): void {
		if ( strlen( $value ) < $minimum || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'RetrySubmissionEvaluation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Context token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_token( string $value, string $field ): void {
		if ( '' === $value || strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'RetrySubmissionEvaluation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Reject invalid reason.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_reason( string $value ): void {
		if ( $value !== trim( $value ) || strlen( $value ) < 3 || strlen( $value ) > 1000 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
			throw new InvalidArgumentException( 'RetrySubmissionEvaluation reason is invalid.' );
		}
	}
}
