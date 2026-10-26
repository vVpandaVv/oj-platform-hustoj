<?php
/**
 * C33 teacher review command for one worksheet evaluation result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C33 teacher review command for one worksheet evaluation result.
 */
final class Review_Worksheet_Evaluation_Command {
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
	 * Initialize review worksheet evaluation command dependencies and state.
	 *
	 * @param int                            $actor_user_id Authenticated actor from the access context.
	 * @param string                         $evaluation_id Evaluation id.
	 * @param string                         $command_id Command identifier used for idempotency.
	 * @param string                         $expected_state Expected state.
	 * @param string                         $expected_result_id Expected result id.
	 * @param int                            $expected_review_no Expected review no.
	 * @param array<int,array<string,mixed>> $item_decisions Item decisions.
	 * @param bool                           $complete_review Complete review.
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
		private string $expected_state,
		private string $expected_result_id,
		private int $expected_review_no,
		array $item_decisions,
		private bool $complete_review,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $authorized_revision,
		private string $authorized_state,
		private string $actor_relation,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 || $expected_review_no < 0 || count( $item_decisions ) > 100 ) {
			throw new InvalidArgumentException( 'ReviewWorksheetEvaluation command identity is invalid.' );
		}
		new Public_Id( $evaluation_id );
		new Public_Id( $expected_result_id );
		new Public_Id( $authorized_revision );
		$this->safe_token( $command_id, 'command_id', 8, 128 );
		$this->safe_token( $correlation_id, 'correlation_id', 1, 100 );
		if (
			! in_array( $expected_state, array( 'review_required', 'reviewed' ), true )
			|| ! in_array( $authorized_state, array( 'review_required', 'reviewed' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| 'owner' !== $actor_relation
			|| ( array() === $item_decisions && ! $complete_review )
		) {
			throw new InvalidArgumentException( 'ReviewWorksheetEvaluation command evidence is invalid.' );
		}

		$this->item_decisions = $this->normalize_decisions( $item_decisions );
		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id'    => $command_id,
					'evaluation_id' => $evaluation_id,
					'expected'      => array(
						'expected_result_id' => $expected_result_id,
						'expected_review_no' => $expected_review_no,
						'expected_state'     => $expected_state,
					),
					'payload'       => array(
						'complete_review' => $complete_review,
						'item_decisions'  => $this->item_decisions,
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
	 * Expected state.
	 *
	 * @return string
	 */
	public function expected_state(): string {
		return $this->expected_state;
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
	 * Expected review no.
	 *
	 * @return int
	 */
	public function expected_review_no(): int {
		return $this->expected_review_no;
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
	 * Complete review.
	 *
	 * @return bool
	 */
	public function complete_review(): bool {
		return $this->complete_review;
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
			$this->actor_user_id . '|worksheet.evaluation.review|' . $this->evaluation_id . '|'
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
			throw new InvalidArgumentException( 'ReviewWorksheetEvaluation decisions must be a list.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'ReviewWorksheetEvaluation decision is invalid.' );
			}
			$this->exact_keys(
				$item,
				array( 'finding_key', 'decision', 'reason_code', 'note', 'resolution_revision_id', 'resolution_source_hash' )
			);
			$key             = $item['finding_key'] ?? null;
			$decision        = $item['decision'] ?? null;
			$reason          = $item['reason_code'] ?? null;
			$note            = $item['note'] ?? null;
			$resolution_id   = $item['resolution_revision_id'] ?? null;
			$resolution_hash = $item['resolution_source_hash'] ?? null;
			if (
				! is_string( $key )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $key )
				|| isset( $seen[ $key ] )
				|| ! is_string( $decision )
				|| ! in_array( $decision, array( 'resolved', 'deferred', 'dismissed' ), true )
			) {
				throw new InvalidArgumentException( 'ReviewWorksheetEvaluation decision identity is invalid.' );
			}
			if ( null !== $reason ) {
				$this->reason( $reason );
			}
			if ( null !== $note ) {
				$this->note( $note );
			}
			if ( 'resolved' === $decision ) {
				if ( ! is_string( $resolution_id ) || ! is_string( $resolution_hash ) ) {
					throw new InvalidArgumentException( 'Resolved finding requires a revision and hash.' );
				}
				new Public_Id( $resolution_id );
				if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $resolution_hash ) ) {
					throw new InvalidArgumentException( 'Resolved finding hash is invalid.' );
				}
			} elseif ( null !== $resolution_id || null !== $resolution_hash || null === $reason || null === $note ) {
				throw new InvalidArgumentException( 'Deferred or dismissed finding requires a reason and note.' );
			}
			$seen[ $key ] = true;
			$result[]     = array(
				'finding_key'            => $key,
				'decision'               => $decision,
				'reason_code'            => $reason,
				'note'                   => $note,
				'resolution_revision_id' => $resolution_id,
				'resolution_source_hash' => $resolution_hash,
			);
		}
		usort( $result, static fn ( array $left, array $right ): int => strcmp( $left['finding_key'], $right['finding_key'] ) );
		return $result;
	}

	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function reason( $value ): void {
		if ( ! is_string( $value ) || strlen( $value ) > 64 || 1 !== preg_match( '/^[a-z][a-z0-9_.-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'ReviewWorksheetEvaluation reason is invalid.' );
		}
	}

	/**
	 * Note.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function note( $value ): void {
		if (
			! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 4000
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value )
		) {
			throw new InvalidArgumentException( 'ReviewWorksheetEvaluation note is invalid.' );
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
			throw new InvalidArgumentException( 'ReviewWorksheetEvaluation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
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
			throw new InvalidArgumentException( 'ReviewWorksheetEvaluation decision fields are invalid.' );
		}
	}
}
