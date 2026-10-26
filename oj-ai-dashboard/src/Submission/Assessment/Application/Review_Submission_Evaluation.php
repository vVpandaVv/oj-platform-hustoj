<?php
/**
 * Normalize and append one immutable teacher review for a submission score result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Review_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize and append one immutable teacher review for a submission score result.
 */
final class Review_Submission_Evaluation {
	public const SCORE_DECISIONS_SCHEMA  = 'oj_ai.submission_score_decisions';
	public const SCORE_DECISIONS_VERSION = 1;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize review submission evaluation dependencies and state.
	 *
	 * @param Submission_Evaluation_Review_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                                    $ids Ids.
	 * @param Canonical_Json                                         $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                          $clock Clock.
	 */
	public function __construct(
		private Submission_Evaluation_Review_Repository_Port_Interface $repository,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute review submission evaluation.
	 *
	 * @param Review_Submission_Evaluation_Command $command Command.
	 * @return Submission_Evaluation_Review_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Review_Submission_Evaluation_Command $command ): Submission_Evaluation_Review_Result {
		$receipt = $this->repository->find_command_receipt(
			$command->idempotency_storage_key(),
			$command->request_digest()
		);
		if ( $receipt instanceof Submission_Evaluation_Review_Result ) {
			return $receipt;
		}

		$snapshot = $this->repository->find_owned_snapshot( $command->actor_user_id(), $command->evaluation_id() );
		if ( null === $snapshot ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}
		$this->assert_expected( $command, $snapshot );
		$items                = $this->result_items( $snapshot['result_items'] ?? null );
		$score_decisions      = null;
		$score_decisions_json = null;
		$score_decisions_hash = null;
		$official_score       = null;
		if ( in_array( $command->decision(), array( 'accepted', 'adjusted' ), true ) ) {
			$score_decisions      = 'accepted' === $command->decision()
				? $this->accept_items( $items )
				: $this->adjust_items( $items, $command->item_decisions() );
			$official_score       = $this->total( $score_decisions );
			$score_payload        = array(
				'schema_id'      => self::SCORE_DECISIONS_SCHEMA,
				'schema_version' => '1.0.0',
				'items'          => $score_decisions,
			);
			$score_decisions_json = $this->canonical->encode( $score_payload );
			$score_decisions_hash = hash( 'sha256', $score_decisions_json );
		}

		$review_no    = $command->expected_review_no() + 1;
		$review_id    = $this->ids->create()->value();
		$occurred_at  = $this->now();
		$content_hash = $this->content_hash(
			$command->evaluation_id(),
			$command->expected_result_id(),
			$command->expected_result_hash(),
			$review_no,
			$command->decision(),
			$official_score,
			$score_decisions_hash,
			$command->reason(),
			$snapshot['latest_review_id']
		);

		$stale_release_id = 'rejected' !== $command->decision()
			&& in_array( $snapshot['current_release_state'], array( 'released', 'corrected' ), true )
			? $this->ids->create()->value() : null;

		return $this->repository->append_review(
			array(
				'actor_user_id'                      => $command->actor_user_id(),
				'command_id'                         => $command->command_id(),
				'command_storage_key'                => $command->idempotency_storage_key(),
				'request_digest'                     => $command->request_digest(),
				'correlation_id'                     => $command->correlation_id(),
				'occurred_at_utc'                    => $occurred_at,
				'context_type'                       => $snapshot['context_type'],
				'context_id'                         => $snapshot['context_id'],
				'context_revision'                   => $snapshot['context_revision'],
				'evaluation_public_id'               => $command->evaluation_id(),
				'expected_evaluation_state'          => $snapshot['evaluation_state'],
				'expected_evaluation_lock_version'   => $snapshot['evaluation_lock_version'],
				'submission_public_id'               => $snapshot['submission_id'],
				'submission_revision_public_id'      => $snapshot['submission_revision_id'],
				'submission_revision_hash'           => $snapshot['submission_revision_hash'],
				'expected_submission_lock_version'   => $snapshot['submission_lock_version'],
				'expected_result_public_id'          => $command->expected_result_id(),
				'expected_result_hash'               => $command->expected_result_hash(),
				'expected_official_review_public_id' => $command->expected_official_review_id(),
				'expected_official_review_hash'      => $command->expected_official_review_hash(),
				'expected_current_release_public_id' => $snapshot['current_release_id'],
				'expected_current_release_hash'      => $snapshot['current_release_hash'],
				'expected_current_release_state'     => $snapshot['current_release_state'],
				'expected_review_no'                 => $command->expected_review_no(),
				'expected_previous_review_public_id' => $snapshot['latest_review_id'],
				'review_public_id'                   => $review_id,
				'stale_release_public_id'            => $stale_release_id,
				'review_no'                          => $review_no,
				'decision'                           => $command->decision(),
				'official_score'                     => $official_score,
				'score_decisions'                    => $score_decisions,
				'score_decisions_json'               => $score_decisions_json,
				'score_decisions_hash'               => $score_decisions_hash,
				'reason'                             => $command->reason(),
				'content_hash'                       => $content_hash,
				'audit_public_id'                    => $this->ids->create()->value(),
				'outbox_public_id'                   => $this->ids->create()->value(),
			)
		);
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param Review_Submission_Evaluation_Command $command Command.
	 * @param array<string,mixed>                  $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( Review_Submission_Evaluation_Command $command, array $snapshot ): void {
		$required = array(
			'evaluation_lock_version',
			'evaluation_state',
			'context_type',
			'context_id',
			'context_revision',
			'submission_id',
			'submission_revision_id',
			'submission_revision_hash',
			'submission_lock_version',
			'official_review_id',
			'official_review_hash',
			'current_release_id',
			'current_release_hash',
			'current_release_state',
			'result_id',
			'result_hash',
			'result_items',
			'latest_review_id',
			'latest_review_no',
		);
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $snapshot ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID, array( 'detail' => 'assert_expected.snapshot (missing key: ' . $key . ')' ) );
			}
		}
		$current_release_id    = $snapshot['current_release_id'];
		$current_release_hash  = $snapshot['current_release_hash'];
		$current_release_state = $snapshot['current_release_state'];
		if (
			( null === $current_release_id ) !== ( null === $current_release_hash )
			|| ( null === $current_release_id ) !== ( null === $current_release_state )
			|| ( null !== $current_release_state && ! in_array( $current_release_state, array( 'released', 'corrected', 'withdrawn', 'stale' ), true ) )
			|| ! is_int( $snapshot['submission_lock_version'] ) || $snapshot['submission_lock_version'] < 0
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID, array( 'detail' => 'assert_expected.current_release (id/hash/state consistency or submission_lock_version)' ) );
		}
		if (
			'context_staff' !== $command->actor_relation()
			|| ! hash_equals( $command->authorized_revision(), (string) $snapshot['evaluation_lock_version'] )
			|| ! hash_equals( $command->authorized_state(), (string) $snapshot['evaluation_state'] )
			|| ! hash_equals( $command->expected_result_id(), (string) $snapshot['result_id'] )
			|| ! hash_equals( $command->expected_result_hash(), (string) $snapshot['result_hash'] )
			|| $command->expected_review_no() !== (int) $snapshot['latest_review_no']
			|| $command->expected_official_review_id() !== $snapshot['official_review_id']
			|| $command->expected_official_review_hash() !== $snapshot['official_review_hash']
		) {
			throw Submission_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Submission_Evaluation_Failure::REVIEW_SOURCE_STALE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$this->safe_state( $snapshot )
			);
		}
		$approved = 'reviewed' === $snapshot['evaluation_state'] && null !== $snapshot['official_review_id'];
		if ( ( 'withdrawn' === $command->decision() ) !== $approved ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable conflict; no user data is emitted.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::REVIEW_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Result items.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result_items( $value ): array {
		if (
			! is_array( $value ) || ! array_is_list( $value ) || array() === $value
			|| count( $value ) > Submission_Score_Input_Builder::MAXIMUM_BINDINGS
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID, array( 'detail' => 'result_items (shape or count)' ) );
		}
		$result = array();
		$seen   = array();
		foreach ( $value as $position => $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID, array( 'detail' => 'result_items[' . $position . ']' ) );
			}
			$this->exact_keys( $item, array( 'binding_key', 'criterion_id', 'response_id', 'scope', 'position', 'candidate_points', 'maximum_points', 'levels', 'flags', 'safety_review_required' ) );
			$key = $this->stored_hash( $item['binding_key'] ?? null );
			if ( isset( $seen[ $key ] ) || $position !== $item['position'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID, array( 'detail' => 'result_items[' . $position . '] (duplicate binding or position)' ) );
			}
			$criterion = $this->stored_public_id( $item['criterion_id'] ?? null );
			$response  = null === ( $item['response_id'] ?? null ) ? null : $this->stored_public_id( $item['response_id'] );
			$scope     = $item['scope'] ?? null;
			$candidate = null === ( $item['candidate_points'] ?? null ) ? null : $this->stored_decimal( $item['candidate_points'] );
			$maximum   = $this->stored_decimal( $item['maximum_points'] ?? null );
			$levels    = $this->stored_levels( $item['levels'] ?? null );
			$flags     = $this->stored_flags( $item['flags'] ?? null );
			$safety    = $item['safety_review_required'] ?? null;
			if (
				! in_array( $scope, array( 'response', 'submission' ), true )
				|| ( 'response' === $scope ) !== ( null !== $response )
				|| ! is_bool( $safety )
				|| $safety !== in_array( 'safety_review_required', $flags, true )
				|| ( null !== $candidate && $this->units( $candidate ) > $this->units( $maximum ) )
				|| ( null !== $levels && null !== $candidate && ! in_array( $this->units( $candidate ), array_map( fn ( array $level ): int => $this->units( $level['points'] ), $levels ), true ) )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID, array( 'detail' => 'result_items[' . $position . '] (scope, safety flag, or candidate_points vs maximum/levels)' ) );
			}
			$seen[ $key ] = true;
			$result[]     = array(
				'binding_key'            => $key,
				'criterion_id'           => $criterion,
				'response_id'            => $response,
				'scope'                  => $scope,
				'position'               => $position,
				'candidate_points'       => $candidate,
				'maximum_points'         => $maximum,
				'levels'                 => $levels,
				'flags'                  => $flags,
				'safety_review_required' => $safety,
			);
		}
		return $result;
	}

	/**
	 * Accept items.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function accept_items( array $items ): array {
		$result = array();
		foreach ( $items as $item ) {
			if ( null === $item['candidate_points'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::REVIEW_ITEMS_INCOMPLETE );
			}
			if ( $item['safety_review_required'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::SAFETY_REVIEW_REQUIRED );
			}
			$result[] = $this->official_item( $item, 'ai_accepted', $item['candidate_points'], null, null );
		}
		return $result;
	}

	/**
	 * Adjust items.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<int,array<string,mixed>> $changes Changes.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function adjust_items( array $items, array $changes ): array {
		if ( count( $items ) !== count( $changes ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::REVIEW_ITEMS_INCOMPLETE );
		}
		$by_key = array();
		foreach ( $changes as $change ) {
			$by_key[ $change['binding_key'] ] = $change;
		}
		$result        = array();
		$teacher_count = 0;
		foreach ( $items as $item ) {
			$change = $by_key[ $item['binding_key'] ] ?? null;
			if ( ! is_array( $change ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::REVIEW_ITEMS_INCOMPLETE );
			}
			$points = (string) $change['official_points'];
			$source = (string) $change['source'];
			if (
				$this->units( $points ) > $this->units( $item['maximum_points'] )
				|| ( null !== $item['levels'] && ! in_array( $this->units( $points ), array_map( fn ( array $level ): int => $this->units( $level['points'] ), $item['levels'] ), true ) )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::REVIEW_SCORE_INVALID );
			}
			if ( 'ai_accepted' === $source ) {
				if (
					null === $item['candidate_points']
					|| ! hash_equals( (string) $item['candidate_points'], $points )
					|| $item['safety_review_required']
				) {
					throw Submission_Evaluation_Failure::because(
						$item['safety_review_required']
							// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
							? Submission_Evaluation_Failure::SAFETY_REVIEW_REQUIRED
							// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
							: Submission_Evaluation_Failure::REVIEW_SCORE_INVALID
					);
				}
			} else {
				++$teacher_count;
				if ( $item['safety_review_required'] && null === $change['reason_note'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::SAFETY_REVIEW_REQUIRED );
				}
			}
			$result[] = $this->official_item(
				$item,
				$source,
				$points,
				$change['reason_code'],
				$change['reason_note']
			);
			unset( $by_key[ $item['binding_key'] ] );
		}
		if ( array() !== $by_key ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::REVIEW_ITEMS_INCOMPLETE );
		}
		if ( 0 === $teacher_count ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::REVIEW_SCORE_INVALID );
		}
		return $result;
	}

	/**
	 * Official item.
	 *
	 * @param array<string,mixed> $item Item.
	 * @param string              $source Source.
	 * @param string              $points Points.
	 * @param string|null         $reason_code Reason code.
	 * @param string|null         $reason_note Reason note.
	 * @return array<string,mixed>
	 */
	private function official_item( array $item, string $source, string $points, ?string $reason_code, ?string $reason_note ): array {
		return array(
			'binding_key'     => $item['binding_key'],
			'criterion_id'    => $item['criterion_id'],
			'response_id'     => $item['response_id'],
			'scope'           => $item['scope'],
			'position'        => $item['position'],
			'source'          => $source,
			'official_points' => $points,
			'maximum_points'  => $item['maximum_points'],
			'reason_code'     => $reason_code,
			'reason_note'     => $reason_note,
		);
	}

	/**
	 * Total.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function total( array $items ): string {
		$units = 0;
		foreach ( $items as $item ) {
			$units += $this->units( (string) $item['official_points'] );
			if ( $units > 999999999999 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::REVIEW_SCORE_INVALID );
			}
		}
		return intdiv( $units, 10000 ) . '.' . str_pad( (string) ( $units % 10000 ), 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Units.
	 *
	 * @param string $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function units( string $value ): int {
		if ( 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})\.([0-9]{4})$/D', $value, $matches ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return ( (int) $matches[1] * 10000 ) + (int) $matches[2];
	}

	/**
	 * Stored decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_decimal( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $matches ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $matches[1] . '.' . str_pad( $matches[2] ?? '', 4, '0' );
	}

	/**
	 * Stored flags.
	 *
	 * @param mixed $value Value.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_flags( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > count( Submission_Evaluation_Output_Normalizer::FLAGS ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$result = array();
		foreach ( $value as $flag ) {
			if ( ! is_string( $flag ) || ! in_array( $flag, Submission_Evaluation_Output_Normalizer::FLAGS, true ) || in_array( $flag, $result, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
			$result[] = $flag;
		}
		sort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Stored public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_public_id( $value ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		try {
			new Public_Id( $value ); } catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
			return $value;
	}

	/**
	 * Stored hash.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_hash( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Stored levels.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_levels( $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) < 2 || count( $value ) > 10 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID, array( 'detail' => 'stored_levels (shape or count)' ) );
		}
		$result     = array();
		$seen_keys  = array();
		$seen_units = array();
		foreach ( $value as $position => $level ) {
			if ( ! is_array( $level ) || array_is_list( $level ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID, array( 'detail' => 'stored_levels[' . $position . ']' ) );
			}
			$this->exact_keys( $level, array( 'label', 'level_key', 'points', 'position' ) );
			$id = $this->stored_public_id( $level['level_key'] ?? null );
			if ( isset( $seen_keys[ $id ] ) || $position !== ( $level['position'] ?? null ) || ! is_string( $level['label'] ?? null ) || '' === trim( $level['label'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID, array( 'detail' => 'stored_levels[' . $position . '] (key, position, or label)' ) );
			}
			$points = $this->stored_decimal( $level['points'] ?? null );
			$units  = $this->units( $points );
			if ( isset( $seen_units[ $units ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID, array( 'detail' => 'stored_levels[' . $position . '].points (duplicate)' ) );
			}
			$seen_keys[ $id ]     = true;
			$seen_units[ $units ] = true;
			$result[]             = array(
				'label'     => $level['label'],
				'level_key' => $id,
				'points'    => $points,
				'position'  => $position,
			);
		}
		return $result;
	}

	/**
	 * Content hash.
	 *
	 * @param string      $evaluation_id Evaluation id.
	 * @param string      $result_id Result id.
	 * @param string      $result_hash Result hash.
	 * @param int         $review_no Review no.
	 * @param string      $decision Decision.
	 * @param string|null $official_score Official score.
	 * @param string|null $score_decisions_hash Score decisions hash.
	 * @param string|null $reason Reason.
	 * @param string|null $previous_review_id Previous review id.
	 * @return string
	 */
	private function content_hash(
		string $evaluation_id,
		string $result_id,
		string $result_hash,
		int $review_no,
		string $decision,
		?string $official_score,
		?string $score_decisions_hash,
		?string $reason,
		?string $previous_review_id
	): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'decision'             => $decision,
					'evaluation_id'        => $evaluation_id,
					'official_score'       => $official_score,
					'previous_review_id'   => $previous_review_id,
					'reason'               => $reason,
					'result_hash'          => $result_hash,
					'result_id'            => $result_id,
					'review_no'            => $review_no,
					'score_decisions_hash' => $score_decisions_hash,
				)
			)
		);
	}

	/**
	 * Safe state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return array<string,int|string>
	 */
	private function safe_state( array $snapshot ): array {
		return array(
			'current_result_id'               => (string) ( $snapshot['result_id'] ?? '' ),
			'current_result_hash'             => (string) ( $snapshot['result_hash'] ?? '' ),
			'current_review_no'               => (int) ( $snapshot['latest_review_no'] ?? 0 ),
			'current_official_review_id'      => (string) ( $snapshot['official_review_id'] ?? '' ),
			'current_official_review_hash'    => (string) ( $snapshot['official_review_hash'] ?? '' ),
			'current_release_id'              => (string) ( $snapshot['current_release_id'] ?? '' ),
			'current_release_hash'            => (string) ( $snapshot['current_release_hash'] ?? '' ),
			'current_submission_lock_version' => (int) ( $snapshot['submission_lock_version'] ?? 0 ),
		);
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
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
			throw new InvalidArgumentException( 'ReviewSubmissionEvaluation clock returned an invalid UTC value.' );
		}
		return $value;
	}
}
