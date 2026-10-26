<?php
/**
 * Student note work item.
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
 * Student note work item.
 */
final class Student_Note_Work_Item {
	/**
	 * Initialize student note work item dependencies and state.
	 *
	 * @param int                                    $evaluation_pk Evaluation pk.
	 * @param int                                    $run_pk Run pk.
	 * @param string                                 $evaluation_id Evaluation id.
	 * @param string                                 $run_id Run id.
	 * @param int                                    $actor_user_id Authenticated actor from the access context.
	 * @param string                                 $submission_id Submission public identifier.
	 * @param string                                 $context_type Context type.
	 * @param string                                 $context_id Context id.
	 * @param string                                 $context_revision Context revision.
	 * @param int                                    $evaluation_lock_version Evaluation lock version.
	 * @param string                                 $evaluation_state Evaluation state.
	 * @param string                                 $contract_version Contract version.
	 * @param string                                 $input_digest Input digest.
	 * @param string                                 $run_idempotency_key Run idempotency key.
	 * @param Submission_Evaluation_Routing_Snapshot $planned_routing Planned routing.
	 * @param string                                 $lease_token Lease token.
	 * @param int                                    $lock_version Lock version.
	 * @param int                                    $attempt_count Attempt count.
	 * @param int                                    $attempt_no Attempt no.
	 * @param string                                 $state State.
	 * @param string                                 $queued_at_utc Queued at utc.
	 * @param array<array-key,mixed>|null            $input Input.
	 * @param string|null                            $official_review_id Official review id.
	 * @param string|null                            $official_review_hash Official review hash.
	 * @param string|null                            $result_id Result id.
	 * @param string|null                            $submission_revision_hash Submission revision hash.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $evaluation_pk,
		private int $run_pk,
		private string $evaluation_id,
		private string $run_id,
		private int $actor_user_id,
		private string $submission_id,
		private string $context_type,
		private string $context_id,
		private string $context_revision,
		private int $evaluation_lock_version,
		private string $evaluation_state,
		private string $contract_version,
		private string $input_digest,
		private string $run_idempotency_key,
		private Submission_Evaluation_Routing_Snapshot $planned_routing,
		private string $lease_token,
		private int $lock_version,
		private int $attempt_count,
		private int $attempt_no,
		private string $state,
		private string $queued_at_utc,
		private ?array $input = null,
		private ?string $official_review_id = null,
		private ?string $official_review_hash = null,
		private ?string $result_id = null,
		private ?string $submission_revision_hash = null
	) {
		foreach ( array( $evaluation_id, $run_id, $submission_id ) as $id ) {
			new Public_Id( $id );
		}
		if (
			$evaluation_pk < 1 || $run_pk < 1 || $actor_user_id < 1 || $evaluation_lock_version < 0
			|| ! in_array( $context_type, array( 'class', 'group' ), true )
			|| ! $this->token( $context_id, 191, true ) || ! $this->token( $context_revision, 191, true )
			|| ! in_array( $evaluation_state, array( 'review_required', 'reviewed' ), true )
			|| ! $this->token( $contract_version, 32 )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input_digest )
			|| ! $this->token( $run_idempotency_key, 191 )
			|| 1 !== preg_match( '/^[a-f0-9-]{36}$/D', $lease_token )
			|| $lock_version < 1 || $attempt_count < 0 || $attempt_no < 0
			|| ! in_array( $state, array( 'leased', 'running' ), true )
			|| ( 'leased' === $state && 0 !== $attempt_no ) || ( 'running' === $state && $attempt_no < 1 )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at_utc )
			|| ( null === $input ) !== ( null === $official_review_id )
		) {
			throw new InvalidArgumentException( 'Student note work item is invalid.' );
		}
		if ( null !== $input ) {
			$this->assert_prepared();
		}
	}

	/**
	 * Prepared.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param Student_Note_Input  $input Input.
	 * @return self
	 */
	public function prepared( array $source, Student_Note_Input $input ): self {
		$copy                           = clone $this;
		$copy->input                    = $input->gateway_input();
		$copy->official_review_id       = $input->official_review_id();
		$copy->official_review_hash     = $input->official_review_hash();
		$copy->result_id                = $input->result_id();
		$copy->submission_revision_hash = $input->submission_revision_hash();
		$copy->evaluation_lock_version  = $source['evaluation_lock_version'];
		$copy->evaluation_state         = $source['evaluation_state'];
		$copy->submission_id            = $source['submission_id'];
		$copy->context_type             = $source['context_type'];
		$copy->context_id               = $source['context_id'];
		$copy->context_revision         = $source['context_revision'];
		$copy->assert_prepared();
		return $copy;
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
			throw new InvalidArgumentException( 'Student note attempt transition is invalid.' );
		}
		$copy                = clone $this;
		$copy->attempt_no    = $attempt_no;
		$copy->attempt_count = $attempt_no;
		$copy->lock_version  = $lock_version;
		$copy->state         = 'running';
		return $copy;
	}

	/**
	 * Reject invalid prepared.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_prepared(): void {
		if (
			null === $this->input || array_is_list( $this->input )
			|| null === $this->official_review_id || null === $this->result_id
			|| null === $this->official_review_hash || null === $this->submission_revision_hash
		) {
			throw new InvalidArgumentException( 'Student note work item is not prepared.' );
		}
		new Public_Id( $this->official_review_id );
		new Public_Id( $this->result_id );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $this->official_review_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $this->submission_revision_hash ) ) {
			throw new InvalidArgumentException( 'Student note source is invalid.' );
		}
	}

	/**
	 * Is prepared.
	 *
	 * @return bool
	 */
	public function is_prepared(): bool {
		return null !== $this->input;
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
	 * Evaluation lock version.
	 *
	 * @return int
	 */
	public function evaluation_lock_version(): int {
		return $this->evaluation_lock_version;
	}
	/**
	 * Evaluation state.
	 *
	 * @return string
	 */
	public function evaluation_state(): string {
		return $this->evaluation_state;
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
	 * Task key.
	 *
	 * @return string
	 */
	public function task_key(): string {
		return $this->planned_routing->task_key();
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
	 * Input.
	 *
	 * @return array<string,mixed>
	 */
	public function input(): array {
		$this->assert_prepared();
		return $this->input ?? array();
	}
	/**
	 * Official review id.
	 *
	 * @return string
	 */
	public function official_review_id(): string {
		$this->assert_prepared();
		return (string) $this->official_review_id;
	}
	/**
	 * Official review hash.
	 *
	 * @return string
	 */
	public function official_review_hash(): string {
		$this->assert_prepared();
		return (string) $this->official_review_hash;
	}
	/**
	 * Result id.
	 *
	 * @return string
	 */
	public function result_id(): string {
		$this->assert_prepared();
		return (string) $this->result_id;
	}
	/**
	 * Submission revision hash.
	 *
	 * @return string
	 */
	public function submission_revision_hash(): string {
		$this->assert_prepared();
		return (string) $this->submission_revision_hash;
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
