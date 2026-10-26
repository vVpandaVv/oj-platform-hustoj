<?php
/**
 * C34 teacher review command for one student submission evaluation result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C34 teacher review command for one student submission evaluation result.
 */
final class Review_Submission_Evaluation_Command {
	public const REASON_CODES = array(
		'evidence_checked',
		'rubric_interpretation',
		'manual_assessment',
		'data_correction',
		'other',
	);

	/**
	 * Item decisions.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $item_decisions;
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize review submission evaluation command dependencies and state.
	 *
	 * @param int                            $actor_user_id Authenticated actor from the access context.
	 * @param string                         $evaluation_id Evaluation id.
	 * @param string                         $command_id Command identifier used for idempotency.
	 * @param string                         $expected_result_id Expected result id.
	 * @param string                         $expected_result_hash Expected result hash.
	 * @param string|null                    $expected_official_review_id Expected official review id.
	 * @param string|null                    $expected_official_review_hash Expected official review hash.
	 * @param int                            $expected_review_no Expected review no.
	 * @param string                         $decision Decision.
	 * @param array<int,array<string,mixed>> $item_decisions Item decisions.
	 * @param string|null                    $reason Reason.
	 * @param string                         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string                         $correlation_id Request correlation identifier for diagnostics.
	 * @param string                         $authorized_revision Authorized revision.
	 * @param string                         $authorized_state Authorized state.
	 * @param string                         $actor_relation Actor relation.
	 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $evaluation_id,
		private string $command_id,
		private string $expected_result_id,
		private string $expected_result_hash,
		private ?string $expected_official_review_id,
		private ?string $expected_official_review_hash,
		private int $expected_review_no,
		private string $decision,
		array $item_decisions,
		private ?string $reason,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $authorized_revision,
		private string $authorized_state,
		private string $actor_relation,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 || $expected_review_no < 0 || count( $item_decisions ) > Submission_Score_Input_Builder::MAXIMUM_BINDINGS ) {
			throw new InvalidArgumentException( 'ReviewSubmissionEvaluation command identity is invalid.' );
		}
		new Public_Id( $evaluation_id );
		new Public_Id( $expected_result_id );
		$this->hash( $expected_result_hash );
		$this->pointer( $expected_official_review_id, $expected_official_review_hash );
		$this->safe_token( $command_id, 'command_id', 8, 128 );
		$this->safe_token( $correlation_id, 'correlation_id', 1, 100 );
		if (
			! in_array( $decision, array( 'accepted', 'adjusted', 'rejected', 'withdrawn' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $authorized_revision )
			|| ! in_array( $authorized_state, array( 'review_required', 'reviewed' ), true )
			|| 'context_staff' !== $actor_relation
		) {
			throw new InvalidArgumentException( 'ReviewSubmissionEvaluation command evidence is invalid.' );
		}

		$this->reason         = $this->normalize_reason( $reason );
		$this->item_decisions = $this->normalize_decisions( $item_decisions );
		if (
			( 'accepted' === $decision && ( array() !== $this->item_decisions || null !== $this->reason ) )
			|| ( 'adjusted' === $decision && array() === $this->item_decisions )
			|| ( in_array( $decision, array( 'rejected', 'withdrawn' ), true ) && ( array() !== $this->item_decisions || null === $this->reason ) )
		) {
			throw new InvalidArgumentException( 'ReviewSubmissionEvaluation decision payload is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id'    => $command_id,
					'evaluation_id' => $evaluation_id,
					'expected'      => array(
						'official_review_hash' => $expected_official_review_hash,
						'official_review_id'   => $expected_official_review_id,
						'result_hash'          => $expected_result_hash,
						'result_id'            => $expected_result_id,
						'review_no'            => $expected_review_no,
					),
					'payload'       => array(
						'decision'       => $decision,
						'item_decisions' => $this->item_decisions,
						'reason'         => $this->reason,
					),
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
	 * Expected result id.
	 *
	 * @return string
	 */
	public function expected_result_id(): string {
		return $this->expected_result_id;
	}
	/**
	 * Expected result hash.
	 *
	 * @return string
	 */
	public function expected_result_hash(): string {
		return $this->expected_result_hash;
	}
	/**
	 * Expected official review id.
	 *
	 * @return string|null
	 */
	public function expected_official_review_id(): ?string {
		return $this->expected_official_review_id;
	}
	/**
	 * Expected official review hash.
	 *
	 * @return string|null
	 */
	public function expected_official_review_hash(): ?string {
		return $this->expected_official_review_hash;
	}
	/**
	 * Expected review no.
	 *
	 * @return int
	 */
	public function expected_review_no(): int {
		return $this->expected_review_no;
	}
	/**
	 * Decision.
	 *
	 * @return string
	 */
	public function decision(): string {
		return $this->decision;
	}
	/**
	 * Item decisions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function item_decisions(): array {
		return $this->item_decisions;
	}
	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string|null
	 */
	public function reason(): ?string {
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
			$this->actor_user_id . '|submission.evaluation.review|' . $this->evaluation_id . '|'
				. $this->idempotency_fingerprint
		);
	}

	/**
	 * Normalize decisions.
	 *
	 * @param array<array-key,mixed> $items Items.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_decisions( array $items ): array {
		if ( ! array_is_list( $items ) ) {
			throw new InvalidArgumentException( 'ReviewSubmissionEvaluation item decisions must be a list.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'ReviewSubmissionEvaluation item decision is invalid.' );
			}
			$this->exact_keys( $item, array( 'binding_key', 'source', 'official_points', 'reason_code', 'reason_note' ) );
			$key         = $item['binding_key'] ?? null;
			$source      = $item['source'] ?? null;
			$reason_code = $item['reason_code'] ?? null;
			$reason_note = $this->normalize_reason( $item['reason_note'] ?? null );
			if (
				! is_string( $key ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $key ) || isset( $seen[ $key ] )
				|| ! in_array( $source, array( 'ai_accepted', 'teacher_assigned' ), true )
			) {
				throw new InvalidArgumentException( 'ReviewSubmissionEvaluation item identity is invalid.' );
			}
			$points = $this->decimal( $item['official_points'] ?? null );
			if ( 'ai_accepted' === $source ) {
				if ( null !== $reason_code || null !== $reason_note ) {
					throw new InvalidArgumentException( 'AI-accepted score cannot carry a teacher adjustment reason.' );
				}
			} elseif ( ! is_string( $reason_code ) || ! in_array( $reason_code, self::REASON_CODES, true ) ) {
				throw new InvalidArgumentException( 'Teacher-assigned score requires a stable reason code.' );
			} elseif ( 'other' === $reason_code && null === $reason_note ) {
				throw new InvalidArgumentException( 'Other score adjustment requires a reason note.' );
			}
			$seen[ $key ] = true;
			$result[]     = array(
				'binding_key'     => $key,
				'source'          => $source,
				'official_points' => $points,
				'reason_code'     => $reason_code,
				'reason_note'     => $reason_note,
			);
		}
		usort( $result, static fn ( array $left, array $right ): int => strcmp( $left['binding_key'], $right['binding_key'] ) );
		return $result;
	}

	/**
	 * Decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $matches ) ) {
			throw new InvalidArgumentException( 'ReviewSubmissionEvaluation score is invalid.' );
		}
		return $matches[1] . '.' . str_pad( $matches[2] ?? '', 4, '0' );
	}

	/**
	 * Normalize reason.
	 *
	 * @param string|null $value Value.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_reason( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 2000 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'ReviewSubmissionEvaluation reason is invalid.' );
		}
		return $value;
	}

	/**
	 * Pointer.
	 *
	 * @param string|null $id Id.
	 * @param string|null $hash Hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function pointer( ?string $id, ?string $hash ): void {
		if ( ( null === $id ) !== ( null === $hash ) ) {
			throw new InvalidArgumentException( 'ReviewSubmissionEvaluation official pointer is partial.' );
		}
		if ( null !== $id ) {
			new Public_Id( $id );
			$this->hash( (string) $hash );
		}
	}

	/**
	 * Hash.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'ReviewSubmissionEvaluation hash is invalid.' );
		}
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
		if (
			strlen( $value ) < $minimum || strlen( $value ) > $maximum
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value )
		) {
			throw new InvalidArgumentException( 'ReviewSubmissionEvaluation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'ReviewSubmissionEvaluation item fields are invalid.' );
		}
	}
}
