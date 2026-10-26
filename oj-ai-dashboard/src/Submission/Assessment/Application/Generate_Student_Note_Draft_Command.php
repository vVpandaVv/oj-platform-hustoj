<?php
/**
 * Generate student note draft command.
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
 * Generate student note draft command.
 */
final class Generate_Student_Note_Draft_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;
	/**
	 * Initialize generate student note draft command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $evaluation_id Evaluation id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_official_review_id Expected official review id.
	 * @param string         $expected_official_review_hash Expected official review hash.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param string         $context_type Context type.
	 * @param string         $context_id Context id.
	 * @param string         $context_revision Context revision.
	 * @param string         $authorized_revision Authorized revision.
	 * @param string         $authorized_state Authorized state.
	 * @param string         $actor_relation Actor relation.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $evaluation_id,
		private string $command_id,
		private string $expected_official_review_id,
		private string $expected_official_review_hash,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $context_type,
		private string $context_id,
		private string $context_revision,
		private string $authorized_revision,
		private string $authorized_state,
		private string $actor_relation,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'GenerateStudentNoteDraft requires an actor.' );
		}
		new Public_Id( $evaluation_id );
		new Public_Id( $expected_official_review_id );
		if (
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_official_review_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| ! $this->token( $command_id, 128 ) || ! $this->token( $correlation_id, 100 )
			|| ! in_array( $context_type, array( 'class', 'group' ), true )
			|| ! $this->token( $context_id, 191, true ) || ! $this->token( $context_revision, 191, true )
			|| 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $authorized_revision )
			|| 'reviewed' !== $authorized_state || 'context_staff' !== $actor_relation
		) {
			throw new InvalidArgumentException( 'GenerateStudentNoteDraft command evidence is invalid.' );
		}
		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id'    => $command_id,
					'evaluation_id' => $evaluation_id,
					'expected'      => array(
						'expected_official_review_hash' => $expected_official_review_hash,
						'expected_official_review_id'   => $expected_official_review_id,
					),
					'payload'       => array(),
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
	 * Expected official review id.
	 *
	 * @return string
	 */
	public function expected_official_review_id(): string {
		return $this->expected_official_review_id;
	}
	/**
	 * Expected official review hash.
	 *
	 * @return string
	 */
	public function expected_official_review_hash(): string {
		return $this->expected_official_review_hash;
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
		return 'command:' . hash( 'sha256', $this->actor_user_id . '|submission.student_note.generate|' . $this->evaluation_id . '|' . $this->idempotency_fingerprint );
	}
	/**
	 * Token.
	 *
	 * @param string $value Value.
	 * @param int    $maximum Maximum.
	 * @param bool   $slash Slash.
	 * @return bool
	 */
	private function token( string $value, int $maximum, bool $slash = false ): bool {
		$pattern = $slash ? '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D' : '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D';
		return '' !== $value && strlen( $value ) <= $maximum && 1 === preg_match( $pattern, $value );
	}
}
