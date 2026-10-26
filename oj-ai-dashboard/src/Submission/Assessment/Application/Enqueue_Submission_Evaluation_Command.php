<?php
/**
 * C29 enqueue student submission evaluation command.
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
 * C29 enqueue student submission evaluation command.
 */
final class Enqueue_Submission_Evaluation_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize enqueue submission evaluation command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $submission_id Submission public identifier.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_official_revision_id Expected official revision id.
	 * @param string         $expected_official_revision_hash Expected official revision hash.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param string         $authorized_context_type Authorized context type.
	 * @param string         $authorized_context_id Authorized context id.
	 * @param string         $authorized_context_revision Authorized context revision.
	 * @param string         $authorized_revision Authorized revision.
	 * @param string         $authorized_state Authorized state.
	 * @param string         $actor_relation Actor relation.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param bool           $manual Prepare a teacher score sheet without AI.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $submission_id,
		private string $command_id,
		private string $expected_official_revision_id,
		private string $expected_official_revision_hash,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $authorized_context_type,
		private string $authorized_context_id,
		private string $authorized_context_revision,
		private string $authorized_revision,
		private string $authorized_state,
		private string $actor_relation,
		Canonical_Json $canonical,
		private bool $manual = false
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'EnqueueSubmissionEvaluation requires an authenticated actor.' );
		}
		new Public_Id( $submission_id );
		new Public_Id( $expected_official_revision_id );
		$this->safe_token( $command_id, 'command_id', 8, 128 );
		$this->safe_token( $correlation_id, 'correlation_id', 1, 100 );
		$this->safe_token( $authorized_context_type, 'context_type', 1, 32 );
		$this->safe_token( $authorized_context_id, 'context_id', 1, 191, true );
		$this->safe_token( $authorized_context_revision, 'context_revision', 1, 191, true );
		$this->safe_token( $authorized_revision, 'authorized_revision', 1, 191 );
		if (
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_official_revision_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| ! in_array( $authorized_context_type, array( 'class', 'group' ), true )
			|| ! in_array( $authorized_state, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true )
			|| 'context_staff' !== $actor_relation
		) {
			throw new InvalidArgumentException( 'EnqueueSubmissionEvaluation command evidence is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id'    => $command_id,
					'expected'      => array(
						'expected_official_revision_hash' => $expected_official_revision_hash,
						'expected_official_revision_id'   => $expected_official_revision_id,
					),
					'payload'       => $manual ? array( 'mode' => 'manual' ) : array(),
					'submission_id' => $submission_id,
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

	/** Whether this command prepares a teacher-only score sheet. */
	public function manual(): bool {
		return $this->manual;
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
	 * Command id.
	 *
	 * @return string
	 */
	public function command_id(): string {
		return $this->command_id;
	}
	/**
	 * Expected official revision id.
	 *
	 * @return string
	 */
	public function expected_official_revision_id(): string {
		return $this->expected_official_revision_id;
	}
	/**
	 * Expected official revision hash.
	 *
	 * @return string
	 */
	public function expected_official_revision_hash(): string {
		return $this->expected_official_revision_hash;
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
	 * Authorized context type.
	 *
	 * @return string
	 */
	public function authorized_context_type(): string {
		return $this->authorized_context_type;
	}
	/**
	 * Authorized context id.
	 *
	 * @return string
	 */
	public function authorized_context_id(): string {
		return $this->authorized_context_id;
	}
	/**
	 * Authorized context revision.
	 *
	 * @return string
	 */
	public function authorized_context_revision(): string {
		return $this->authorized_context_revision;
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
			$this->actor_user_id . '|submission.evaluation.enqueue|' . $this->submission_id . '|'
				. $this->idempotency_fingerprint
		);
	}

	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @param bool   $allow_slash Allow slash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( string $value, string $field, int $minimum, int $maximum, bool $allow_slash = false ): void {
		$pattern = $allow_slash ? '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D' : '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D';
		if ( strlen( $value ) < $minimum || strlen( $value ) > $maximum || 1 !== preg_match( $pattern, $value ) ) {
			throw new InvalidArgumentException( 'EnqueueSubmissionEvaluation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}
