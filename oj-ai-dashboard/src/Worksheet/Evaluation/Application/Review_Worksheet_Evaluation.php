<?php
/**
 * Append one cumulative teacher review for a worksheet evaluation result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Query_Projector;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Review_Repository_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append one cumulative teacher review for a worksheet evaluation result.
 */
final class Review_Worksheet_Evaluation {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize review worksheet evaluation dependencies and state.
	 *
	 * @param Worksheet_Evaluation_Review_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                                   $ids Ids.
	 * @param Canonical_Json                                        $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                         $clock Clock.
	 */
	public function __construct(
		private Worksheet_Evaluation_Review_Repository_Port_Interface $repository,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute review worksheet evaluation.
	 *
	 * @param Review_Worksheet_Evaluation_Command $command Command.
	 * @return Worksheet_Evaluation_Review_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Review_Worksheet_Evaluation_Command $command ): Worksheet_Evaluation_Review_Result {
		$receipt = $this->repository->find_command_receipt(
			$command->idempotency_storage_key(),
			$command->request_digest()
		);
		if ( $receipt instanceof Worksheet_Evaluation_Review_Result ) {
			return $receipt;
		}

		$snapshot = $this->repository->find_owned_snapshot( $command->actor_user_id(), $command->evaluation_id() );
		if ( null === $snapshot ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::NOT_FOUND );
		}
		$this->assert_expected( $command, $snapshot );
		$merged   = $this->merge_decisions(
			$this->decision_list( $snapshot['latest_review_items'] ?? null ),
			$command->item_decisions(),
			$this->finding_keys( $snapshot['finding_keys'] ?? null )
		);
		$decision = $command->complete_review() ? 'completed' : 'in_progress';
		if ( 'completed' === $decision ) {
			$this->assert_complete( $merged, $snapshot['finding_keys'] );
		}

		$review_no    = $command->expected_review_no() + 1;
		$review_id    = $this->ids->create()->value();
		$occurred_at  = $this->now();
		$item_payload = array(
			'schema_id'      => Worksheet_Evaluation_Query_Projector::REVIEW_ITEMS_SCHEMA,
			'schema_version' => '1.0.0',
			'items'          => $merged,
		);
		$item_json    = $this->canonical->encode( $item_payload );
		$item_hash    = hash( 'sha256', $item_json );
		$content_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'decision'            => $decision,
					'evaluation_id'       => $command->evaluation_id(),
					'item_decisions_hash' => $item_hash,
					'previous_review_id'  => $snapshot['latest_review_id'],
					'result_id'           => $command->expected_result_id(),
					'review_no'           => $review_no,
				)
			)
		);

		return $this->repository->append_review(
			array(
				'actor_user_id'                      => $command->actor_user_id(),
				'command_id'                         => $command->command_id(),
				'command_storage_key'                => $command->idempotency_storage_key(),
				'request_digest'                     => $command->request_digest(),
				'correlation_id'                     => $command->correlation_id(),
				'occurred_at_utc'                    => $occurred_at,
				'evaluation_public_id'               => $command->evaluation_id(),
				'worksheet_revision_public_id'       => $snapshot['worksheet_revision_id'],
				'worksheet_revision_hash'            => $snapshot['source_hash'],
				'expected_evaluation_state'          => $command->expected_state(),
				'expected_evaluation_lock_version'   => $snapshot['evaluation_lock_version'],
				'expected_result_public_id'          => $command->expected_result_id(),
				'expected_review_no'                 => $command->expected_review_no(),
				'expected_previous_review_public_id' => $snapshot['latest_review_id'],
				'review_public_id'                   => $review_id,
				'review_no'                          => $review_no,
				'decision'                           => $decision,
				'item_decisions'                     => $merged,
				'item_decisions_json'                => $item_json,
				'item_decisions_hash'                => $item_hash,
				'content_hash'                       => $content_hash,
				'audit_public_id'                    => $this->ids->create()->value(),
				'outbox_public_id'                   => $this->ids->create()->value(),
			)
		);
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param Review_Worksheet_Evaluation_Command $command Command.
	 * @param array<string,mixed>                 $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( Review_Worksheet_Evaluation_Command $command, array $snapshot ): void {
		$required = array(
			'evaluation_state',
			'evaluation_lock_version',
			'worksheet_revision_id',
			'source_hash',
			'result_id',
			'finding_keys',
			'latest_review_id',
			'latest_review_no',
			'latest_review_items',
		);
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $snapshot ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
			}
		}
		if (
			'owner' !== $command->actor_relation()
			|| ! hash_equals( $command->authorized_revision(), (string) $snapshot['worksheet_revision_id'] )
			|| ! hash_equals( $command->authorized_state(), (string) $snapshot['evaluation_state'] )
			|| ! hash_equals( $command->expected_state(), (string) $snapshot['evaluation_state'] )
			|| ! hash_equals( $command->expected_result_id(), (string) $snapshot['result_id'] )
			|| $command->expected_review_no() !== (int) $snapshot['latest_review_no']
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->review_conflict( $snapshot );
		}
	}

	/**
	 * Merge decisions.
	 *
	 * @param array<int,array<string,mixed>> $previous Previous.
	 * @param array<int,array<string,mixed>> $changes Changes.
	 * @param array<int,string>              $finding_keys Finding keys.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function merge_decisions( array $previous, array $changes, array $finding_keys ): array {
		$allowed = array_fill_keys( $finding_keys, true );
		$merged  = array();
		foreach ( $previous as $item ) {
			$key = (string) ( $item['finding_key'] ?? '' );
			if ( ! isset( $allowed[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
			}
			$merged[ $key ] = $item;
		}
		foreach ( $changes as $item ) {
			$key = (string) $item['finding_key'];
			if ( ! isset( $allowed[ $key ] ) ) {
				throw Worksheet_Evaluation_Failure::because(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					Worksheet_Evaluation_Failure::REVIEW_CONFLICT,
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					array( 'unknown_finding_key' => $key )
				);
			}
			$merged[ $key ] = $item;
		}
		ksort( $merged, SORT_STRING );
		return array_values( $merged );
	}

	/**
	 * Reject invalid complete.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<int,string>              $finding_keys Finding keys.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_complete( array $items, array $finding_keys ): void {
		if ( count( $items ) !== count( $finding_keys ) ) {
			throw Worksheet_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Worksheet_Evaluation_Failure::REVIEW_INCOMPLETE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				array( 'remaining_count' => (string) ( count( $finding_keys ) - count( $items ) ) )
			);
		}
		foreach ( $items as $item ) {
			if ( 'deferred' === ( $item['decision'] ?? null ) ) {
				throw Worksheet_Evaluation_Failure::because(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					Worksheet_Evaluation_Failure::REVIEW_INCOMPLETE,
					array( 'remaining_count' => '1' )
				);
			}
		}
	}

	/**
	 * Decision list.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decision_list( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 100 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Finding keys.
	 *
	 * @param mixed $value Value.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function finding_keys( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 100 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}
		foreach ( $value as $key ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $key ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
			}
		}
		return $value;
	}

	/**
	 * Review conflict.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return Worksheet_Evaluation_Failure
	 */
	private function review_conflict( array $snapshot ): Worksheet_Evaluation_Failure {
		return Worksheet_Evaluation_Failure::because(
			Worksheet_Evaluation_Failure::REVIEW_CONFLICT,
			array(
				'current_state'     => (string) ( $snapshot['evaluation_state'] ?? '' ),
				'current_result_id' => (string) ( $snapshot['result_id'] ?? '' ),
				'current_review_no' => (string) ( $snapshot['latest_review_no'] ?? '' ),
			)
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
			throw new InvalidArgumentException( 'ReviewWorksheetEvaluation clock returned an invalid UTC value.' );
		}
		return $value;
	}
}
