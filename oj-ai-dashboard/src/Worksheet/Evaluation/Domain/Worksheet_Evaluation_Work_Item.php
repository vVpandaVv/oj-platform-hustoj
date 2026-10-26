<?php
/**
 * Worksheet evaluation work item.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One conditionally leased worksheet evaluation run.
 */
final class Worksheet_Evaluation_Work_Item {
	/**
	 * Initialize worksheet evaluation work item dependencies and state.
	 *
	 * @param int                                   $evaluation_pk Evaluation pk.
	 * @param int                                   $run_pk Run pk.
	 * @param string                                $evaluation_id Evaluation id.
	 * @param string                                $run_id Run id.
	 * @param int                                   $actor_user_id Authenticated actor from the access context.
	 * @param string                                $revision_id Revision id.
	 * @param string                                $source_hash Source hash.
	 * @param string                                $source_state Source state.
	 * @param string                                $contract_version Contract version.
	 * @param string                                $input_digest Input digest.
	 * @param string                                $run_idempotency_key Run idempotency key.
	 * @param array<array-key,mixed>                $input Input.
	 * @param Worksheet_Evaluation_Routing_Snapshot $planned_routing Planned routing.
	 * @param string                                $lease_token Lease token.
	 * @param int                                   $lock_version Lock version.
	 * @param int                                   $attempt_count Attempt count.
	 * @param int                                   $attempt_no Attempt no.
	 * @param string                                $state State.
	 * @param string                                $queued_at_utc Queued at utc.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $evaluation_pk,
		private int $run_pk,
		private string $evaluation_id,
		private string $run_id,
		private int $actor_user_id,
		private string $revision_id,
		private string $source_hash,
		private string $source_state,
		private string $contract_version,
		private string $input_digest,
		private string $run_idempotency_key,
		private array $input,
		private Worksheet_Evaluation_Routing_Snapshot $planned_routing,
		private string $lease_token,
		private int $lock_version,
		private int $attempt_count,
		private int $attempt_no,
		private string $state,
		private string $queued_at_utc
	) {
		new Public_Id( $evaluation_id );
		new Public_Id( $run_id );
		new Public_Id( $revision_id );
		if (
			$evaluation_pk < 1
			|| $run_pk < 1
			|| $actor_user_id < 1
			|| ! in_array( $source_state, array( 'draft', 'approved' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_hash )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,63}$/D', $contract_version )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input_digest )
			|| strlen( $run_idempotency_key ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $run_idempotency_key )
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
			throw new InvalidArgumentException( 'Worksheet evaluation work item is invalid.' );
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
			throw new InvalidArgumentException( 'Worksheet evaluation attempt transition is invalid.' );
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
	 * @return Worksheet_Evaluation_Routing_Snapshot
	 */
	public function planned_routing(): Worksheet_Evaluation_Routing_Snapshot {
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
}
