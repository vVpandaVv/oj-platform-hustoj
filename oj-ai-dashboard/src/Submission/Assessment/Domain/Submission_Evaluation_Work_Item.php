<?php
/**
 * One conditionally leased student submission evaluation run.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One conditionally leased student submission evaluation run.
 */
final class Submission_Evaluation_Work_Item {
	/**
	 * Initialize submission evaluation work item dependencies and state.
	 *
	 * @param int                                    $evaluation_pk Evaluation pk.
	 * @param int                                    $run_pk Run pk.
	 * @param string                                 $evaluation_id Evaluation id.
	 * @param string                                 $run_id Run id.
	 * @param int                                    $actor_user_id Authenticated actor from the access context.
	 * @param string                                 $submission_id Submission public identifier.
	 * @param int                                    $source_lock_version Source lock version.
	 * @param string                                 $revision_id Revision id.
	 * @param string                                 $source_hash Source hash.
	 * @param string                                 $source_state Source state.
	 * @param string                                 $publication_id Publication id.
	 * @param string                                 $publication_hash Publication hash.
	 * @param string                                 $context_type Context type.
	 * @param string                                 $context_id Context id.
	 * @param string                                 $context_revision Context revision.
	 * @param string                                 $contract_version Contract version.
	 * @param string                                 $input_digest Input digest.
	 * @param string                                 $run_idempotency_key Run idempotency key.
	 * @param array<array-key,mixed>                 $input Input.
	 * @param Submission_Evaluation_Routing_Snapshot $planned_routing Planned routing.
	 * @param string                                 $lease_token Lease token.
	 * @param int                                    $lock_version Lock version.
	 * @param int                                    $attempt_count Attempt count.
	 * @param int                                    $attempt_no Attempt no.
	 * @param string                                 $state State.
	 * @param string                                 $queued_at_utc Queued at utc.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $evaluation_pk,
		private int $run_pk,
		private string $evaluation_id,
		private string $run_id,
		private int $actor_user_id,
		private string $submission_id,
		private int $source_lock_version,
		private string $revision_id,
		private string $source_hash,
		private string $source_state,
		private string $publication_id,
		private string $publication_hash,
		private string $context_type,
		private string $context_id,
		private string $context_revision,
		private string $contract_version,
		private string $input_digest,
		private string $run_idempotency_key,
		private array $input,
		private Submission_Evaluation_Routing_Snapshot $planned_routing,
		private string $lease_token,
		private int $lock_version,
		private int $attempt_count,
		private int $attempt_no,
		private string $state,
		private string $queued_at_utc
	) {
		new Public_Id( $evaluation_id );
		new Public_Id( $run_id );
		new Public_Id( $submission_id );
		new Public_Id( $revision_id );
		new Public_Id( $publication_id );
		if (
			$evaluation_pk < 1
			|| $run_pk < 1
			|| $actor_user_id < 1
			|| $source_lock_version < 0
			|| ! in_array( $source_state, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $publication_hash )
			|| ! in_array( $context_type, array( 'class', 'group' ), true )
			|| ! $this->safe_token( $context_id, 191, true )
			|| ! $this->safe_token( $context_revision, 191, true )
			|| ! $this->safe_token( $contract_version, 32 )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input_digest )
			|| ! $this->safe_token( $run_idempotency_key, 128 )
			|| array_is_list( $input )
			|| 1 !== preg_match( '/^[a-f0-9-]{36}$/D', $lease_token )
			|| $lock_version < 1
			|| $attempt_count < 0
			|| $attempt_no < 0
			|| ! in_array( $state, array( 'leased', 'running' ), true )
			|| ( 'leased' === $state && 0 !== $attempt_no )
			|| ( 'running' === $state && $attempt_no < 1 )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at_utc )
		) {
			throw new InvalidArgumentException( 'Submission evaluation work item is invalid.' );
		}
	}

	/**
	 * Started.
	 *
	 * @param int $attempt_no Attempt no.
	 * @param int $lock_version Lock version.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function started( int $attempt_no, int $lock_version ): self {
		if ( $attempt_no !== $this->attempt_count + 1 || $lock_version !== $this->lock_version + 1 ) {
			throw new InvalidArgumentException( 'Submission evaluation attempt transition is invalid.' );
		}
		$copy                = clone $this;
		$copy->attempt_no    = $attempt_no;
		$copy->attempt_count = $attempt_no;
		$copy->lock_version  = $lock_version;
		$copy->state         = 'running';
		return $copy;
	}

	/**
	 * Evaluation pk.
	 *
	 * @return int
	 */
	public function evaluation_pk(): int {
		return $this->evaluation_pk;
	}
	/**
	 * Run pk.
	 *
	 * @return int
	 */
	public function run_pk(): int {
		return $this->run_pk;
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
	 * Run id.
	 *
	 * @return string
	 */
	public function run_id(): string {
		return $this->run_id;
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
	 * Submission id.
	 *
	 * @return string
	 */
	public function submission_id(): string {
		return $this->submission_id;
	}
	/**
	 * Source lock version.
	 *
	 * @return int
	 */
	public function source_lock_version(): int {
		return $this->source_lock_version;
	}
	/**
	 * Revision id.
	 *
	 * @return string
	 */
	public function revision_id(): string {
		return $this->revision_id;
	}
	/**
	 * Source hash.
	 *
	 * @return string
	 */
	public function source_hash(): string {
		return $this->source_hash;
	}
	/**
	 * Source state.
	 *
	 * @return string
	 */
	public function source_state(): string {
		return $this->source_state;
	}
	/**
	 * Publication id.
	 *
	 * @return string
	 */
	public function publication_id(): string {
		return $this->publication_id;
	}
	/**
	 * Publication hash.
	 *
	 * @return string
	 */
	public function publication_hash(): string {
		return $this->publication_hash;
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
	 * Task key.
	 *
	 * @return string
	 */
	public function task_key(): string {
		return $this->planned_routing->task_key();
	}
	/**
	 * Contract version.
	 *
	 * @return string
	 */
	public function contract_version(): string {
		return $this->contract_version;
	}
	/**
	 * Input digest.
	 *
	 * @return string
	 */
	public function input_digest(): string {
		return $this->input_digest;
	}
	/**
	 * Run idempotency key.
	 *
	 * @return string
	 */
	public function run_idempotency_key(): string {
		return $this->run_idempotency_key;
	}
	/**
	 * Input.
	 *
	 * @return array<string,mixed>
	 */
	public function input(): array {
		return $this->input;
	}
	/**
	 * Planned routing.
	 *
	 * @return Submission_Evaluation_Routing_Snapshot
	 */
	public function planned_routing(): Submission_Evaluation_Routing_Snapshot {
		return $this->planned_routing;
	}
	/**
	 * Lease token.
	 *
	 * @return string
	 */
	public function lease_token(): string {
		return $this->lease_token;
	}
	/**
	 * Lock version.
	 *
	 * @return int
	 */
	public function lock_version(): int {
		return $this->lock_version;
	}
	/**
	 * Attempt count.
	 *
	 * @return int
	 */
	public function attempt_count(): int {
		return $this->attempt_count;
	}
	/**
	 * Attempt no.
	 *
	 * @return int
	 */
	public function attempt_no(): int {
		return $this->attempt_no;
	}
	/**
	 * State.
	 *
	 * @return string
	 */
	public function state(): string {
		return $this->state;
	}
	/**
	 * Queued at utc.
	 *
	 * @return string
	 */
	public function queued_at_utc(): string {
		return $this->queued_at_utc;
	}

	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param int    $maximum Maximum.
	 * @param bool   $allow_slash Allow slash.
	 * @return bool
	 */
	private function safe_token( string $value, int $maximum, bool $allow_slash = false ): bool {
		$pattern = $allow_slash ? '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D' : '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D';
		return '' !== $value && strlen( $value ) <= $maximum && 1 === preg_match( $pattern, $value );
	}
}
