<?php
/**
 * Enqueue one cutoff-bounded assignment target page for AI scoring.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Port\Assignment_Evaluation_Batch_Receipt_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Assignment_Evaluation_Batch_Source_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Control_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Enqueuer_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C30 orchestrates independent C29/C31 commands and persists only a page receipt.
 */
final class Enqueue_Assignment_Evaluations {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize enqueue assignment evaluations dependencies and state.
	 *
	 * @param Assignment_Evaluation_Batch_Source_Port_Interface            $sources Sources.
	 * @param Submission_Evaluation_Enqueuer_Port_Interface                $submissions Submissions.
	 * @param Assignment_Evaluation_Batch_Receipt_Port_Interface           $receipts Receipts.
	 * @param Public_Id_Generator                                          $ids Ids.
	 * @param Canonical_Json                                               $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                                $clock Clock.
	 * @param Retry_Submission_Evaluation|null                             $retry Existing single-evaluation retry operation.
	 * @param Submission_Evaluation_Control_Repository_Port_Interface|null $controls Retry snapshots.
	 */
	public function __construct(
		private Assignment_Evaluation_Batch_Source_Port_Interface $sources,
		private Submission_Evaluation_Enqueuer_Port_Interface $submissions,
		private Assignment_Evaluation_Batch_Receipt_Port_Interface $receipts,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null,
		private ?Retry_Submission_Evaluation $retry = null,
		private ?Submission_Evaluation_Control_Repository_Port_Interface $controls = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute enqueue assignment evaluations.
	 *
	 * @param Enqueue_Assignment_Evaluations_Command $command Command.
	 * @return Enqueue_Assignment_Evaluations_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Enqueue_Assignment_Evaluations_Command $command ): Enqueue_Assignment_Evaluations_Result {
		$receipt = $this->receipts->find_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Enqueue_Assignment_Evaluations_Result ) {
			return $receipt;
		}

		$processed_at = ( $this->clock )();
		$this->assert_utc( $processed_at );
		if ( $command->source_cutoff_utc() > $processed_at ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::CUTOFF_INVALID );
		}

		try {
			$page = $this->sources->page(
				$command->assignment_id(),
				$command->authorized_context_type(),
				$command->authorized_context_id(),
				$command->authorized_context_revision(),
				$command->source_cutoff_utc(),
				$command->item_cursor(),
				$command->limit()
			);
		} catch ( Assignment_Evaluation_Failure | InvalidArgumentException $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		if ( null === $page ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::NOT_FOUND );
		}
		$this->assert_page( $page, $command );

		$items = array();
		foreach ( $page['items'] as $source_item ) {
			$items[] = $this->enqueue_item( $source_item, $command );
		}
		$counts = array(
			'created'  => 0,
			'existing' => 0,
			'failed'   => 0,
			'skipped'  => 0,
			'total'    => count( $items ),
		);
		foreach ( $items as $item ) {
			++$counts[ $item['status'] ];
		}
		$receipt_material = array(
			'assignment_id'     => $command->assignment_id(),
			'counts'            => $counts,
			'item_cursor'       => $command->item_cursor(),
			'items'             => $items,
			'next_item_cursor'  => $page['next_cursor'],
			'processed_at_utc'  => $processed_at,
			'schema_id'         => Enqueue_Assignment_Evaluations_Result::RECEIPT_SCHEMA,
			'schema_version'    => Enqueue_Assignment_Evaluations_Result::RECEIPT_VERSION,
			'source_cutoff_utc' => $command->source_cutoff_utc(),
		);
		$result           = new Enqueue_Assignment_Evaluations_Result(
			$command->assignment_id(),
			$command->source_cutoff_utc(),
			$command->item_cursor(),
			$page['next_cursor'],
			$items,
			$counts,
			$processed_at,
			hash( 'sha256', $this->canonical->encode( $receipt_material ) ),
			false
		);

		return $this->receipts->store_receipt(
			array(
				'actor_user_id'           => $command->actor_user_id(),
				'assignment_public_id'    => $command->assignment_id(),
				'command_id'              => $command->command_id(),
				'correlation_id'          => $command->correlation_id(),
				'idempotency_storage_key' => $command->idempotency_storage_key(),
				'outbox_public_id'        => $this->ids->create()->value(),
				'processed_at_utc'        => $processed_at,
				'request_digest'          => $command->request_digest(),
			),
			$result
		);
	}

	/**
	 * Enqueue item.
	 *
	 * @param array<string,mixed>                    $item Item.
	 * @param Enqueue_Assignment_Evaluations_Command $command Command.
	 * @return array<string,mixed>
	 */
	private function enqueue_item( array $item, Enqueue_Assignment_Evaluations_Command $command ): array {
		$this->assert_source_item( $item );
		$target_id     = (string) $item['target_id'];
		$submission_id = is_string( $item['submission_id'] ) ? $item['submission_id'] : null;
		$revision_id   = is_string( $item['official_revision_id'] ) ? $item['official_revision_id'] : null;

		if ( 'active' !== $item['target_state'] ) {
			return $this->non_success_item( $target_id, $submission_id, $revision_id, 'skipped', 'target_inactive' );
		}
		if ( null === $submission_id ) {
			return $this->non_success_item( $target_id, null, null, 'skipped', 'submission_missing' );
		}
		if ( null !== $item['submitted_at_utc'] && $item['submitted_at_utc'] > $command->source_cutoff_utc() ) {
			return $this->non_success_item( $target_id, $submission_id, $revision_id, 'skipped', 'outside_source_cutoff' );
		}
		if ( ! in_array( $item['submission_state'], array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true ) ) {
			return $this->non_success_item( $target_id, $submission_id, $revision_id, 'skipped', 'submission_not_official' );
		}
		if ( null === $revision_id ) {
			return $this->non_success_item( $target_id, $submission_id, null, 'skipped', 'official_revision_missing' );
		}
		if ( ( $item['has_evaluation'] ?? false ) && null === ( $item['failed_evaluation_id'] ?? null ) ) {
			return $this->non_success_item( $target_id, $submission_id, $revision_id, 'skipped', 'evaluation_exists' );
		}
		if ( null === $item['submitted_at_utc'] ) {
			return $this->non_success_item( $target_id, $submission_id, $revision_id, 'failed', 'submission_source_invalid' );
		}
		$revision_hash = $item['official_revision_hash'];
		if ( ! is_string( $revision_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $revision_hash ) ) {
			return $this->non_success_item( $target_id, $submission_id, $revision_id, 'failed', 'submission_source_invalid' );
		}

		$child_seed = $this->canonical->encode(
			array(
				'batch_command_id' => $command->command_id(),
				'batch_digest'     => $command->request_digest(),
				'target_id'        => $target_id,
			)
		);
		try {
			if ( null !== ( $item['failed_evaluation_id'] ?? null ) ) {
				$receipt = $this->retry_failed_item( $item, $command, $child_seed );
			} else {
				$receipt = $this->submissions->execute(
					new Enqueue_Submission_Evaluation_Command(
						$command->actor_user_id(),
						$submission_id,
						'batch-item-' . substr( hash( 'sha256', $child_seed ), 0, 48 ),
						$revision_id,
						$revision_hash,
						hash( 'sha256', $command->idempotency_fingerprint() . '|target|' . $target_id ),
						'batch-' . substr( hash( 'sha256', $command->correlation_id() . '|' . $target_id ), 0, 48 ),
						$command->authorized_context_type(),
						$command->authorized_context_id(),
						$command->authorized_context_revision(),
						(string) $item['submission_lock_version'],
						(string) $item['submission_state'],
						'context_staff',
						$this->canonical
					)
				);
			}
		} catch ( Submission_Evaluation_Failure $failure ) {
			return $this->non_success_item( $target_id, $submission_id, $revision_id, 'failed', $failure->reason() );
		} catch ( Throwable ) {
			return $this->non_success_item( $target_id, $submission_id, $revision_id, 'failed', 'submission_evaluation_unavailable' );
		}

		return array(
			'evaluation_id'          => $receipt->evaluation_id(),
			'reason'                 => null,
			'run_id'                 => $receipt->run_id(),
			'status'                 => $receipt->replayed() ? 'existing' : 'created',
			'submission_id'          => $receipt->submission_id(),
			'submission_revision_id' => $receipt->submission_revision_id(),
			'target_id'              => $target_id,
		);
	}

	/**
	 * Reuse C31 so failed attempts remain in the same evaluation history.
	 *
	 * @param array<string,mixed>                    $item Official submission source.
	 * @param Enqueue_Assignment_Evaluations_Command $command Batch command.
	 * @param string                                 $child_seed Stable per-target command seed.
	 * @return Submission_Evaluation_Control_Result
	 * @throws \Throwable Propagates source validation and retry failures to the per-item mapper.
	 */
	private function retry_failed_item( array $item, Enqueue_Assignment_Evaluations_Command $command, string $child_seed ): Submission_Evaluation_Control_Result {
		if ( null === $this->retry || null === $this->controls ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable domain exception mapped at the REST boundary.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		$evaluation_id = (string) $item['failed_evaluation_id'];
		$snapshot      = $this->controls->find_snapshot( $evaluation_id );
		if (
			null === $snapshot || 'failed' !== ( $snapshot['evaluation_state'] ?? null ) || 'failed' !== ( $snapshot['latest_run_state'] ?? null )
			|| ( $snapshot['submission_id'] ?? null ) !== $item['submission_id']
			|| ( $snapshot['submission_revision_id'] ?? null ) !== $item['official_revision_id']
			|| ( $snapshot['source_hash'] ?? null ) !== $item['official_revision_hash']
			|| true !== ( $snapshot['source_is_current'] ?? false )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable domain exception mapped at the REST boundary.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::SOURCE_STALE );
		}
		return $this->retry->execute(
			new Retry_Submission_Evaluation_Command(
				$command->actor_user_id(),
				$evaluation_id,
				'batch-retry-' . substr( hash( 'sha256', $child_seed ), 0, 48 ),
				'failed',
				(string) $snapshot['latest_run_id'],
				(int) $snapshot['latest_run_no'],
				$snapshot['latest_result_id'],
				'Retry failed evaluation through assignment bulk grading.',
				hash( 'sha256', $command->idempotency_fingerprint() . '|retry-target|' . $item['target_id'] ),
				'batch-' . substr( hash( 'sha256', $command->correlation_id() . '|' . $item['target_id'] ), 0, 48 ),
				(string) $snapshot['evaluation_lock_version'],
				'failed',
				$command->actor_relation(),
				$command->authorized_context_type(),
				$command->authorized_context_id(),
				$command->authorized_context_revision(),
				$this->canonical
			)
		);
	}

	/**
	 * Non success item.
	 *
	 * @param string      $target_id Target id.
	 * @param string|null $submission_id Submission public identifier.
	 * @param string|null $revision_id Revision id.
	 * @param string      $status Status.
	 * @param string      $reason Reason.
	 * @return array<string,mixed>
	 */
	private function non_success_item(
		string $target_id,
		?string $submission_id,
		?string $revision_id,
		string $status,
		string $reason
	): array {
		return array(
			'evaluation_id'          => null,
			'reason'                 => $reason,
			'run_id'                 => null,
			'status'                 => $status,
			'submission_id'          => $submission_id,
			'submission_revision_id' => $revision_id,
			'target_id'              => $target_id,
		);
	}

	/**
	 * Reject invalid page.
	 *
	 * @param array<string,mixed>                    $page Page.
	 * @param Enqueue_Assignment_Evaluations_Command $command Command.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_page( array $page, Enqueue_Assignment_Evaluations_Command $command ): void {
		$this->exact_keys( $page, array( 'assignment_id', 'context_id', 'context_revision', 'context_type', 'current_revision', 'items', 'next_cursor', 'source_cutoff_utc', 'state' ) );
		if (
			! hash_equals( $command->assignment_id(), (string) $page['assignment_id'] )
			|| ! hash_equals( $command->source_cutoff_utc(), (string) $page['source_cutoff_utc'] )
			|| $command->authorized_context_type() !== $page['context_type']
			|| $command->authorized_context_id() !== $page['context_id']
			|| $command->authorized_context_revision() !== $page['context_revision']
			|| ! is_array( $page['items'] ) || ! array_is_list( $page['items'] )
			|| count( $page['items'] ) > $command->limit()
			|| ! is_string( $page['next_cursor'] )
			|| strlen( $page['next_cursor'] ) > Enqueue_Assignment_Evaluations_Command::MAXIMUM_CURSOR
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}
		if (
			(string) $command->expected_schedule_version() !== (string) $page['current_revision']
			|| $command->expected_state() !== $page['state']
			|| $command->authorized_revision() !== (string) $page['current_revision']
			|| $command->authorized_state() !== $page['state']
			|| 'context_staff' !== $command->actor_relation()
		) {
			throw Assignment_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Assignment_Evaluation_Failure::SOURCE_STALE,
				array(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_schedule_version' => (string) $page['current_revision'],
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_state'            => (string) $page['state'],
				)
			);
		}
	}

	/**
	 * Reject invalid source item.
	 *
	 * @param array<string,mixed> $item Item.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_source_item( array $item ): void {
		if ( array_key_exists( 'failed_evaluation_id', $item ) ) {
			if ( null !== $item['failed_evaluation_id'] ) {
				try {
					if ( true !== ( $item['has_evaluation'] ?? false ) || ! is_string( $item['failed_evaluation_id'] ) ) {
						throw new InvalidArgumentException( 'Invalid failed evaluation source.' );
					}
					new Public_Id( $item['failed_evaluation_id'] );
				} catch ( InvalidArgumentException ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable domain exception mapped at the REST boundary.
					throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
				}
			}
			unset( $item['failed_evaluation_id'] );
		}
		// Older source adapters remain readable; the WordPress source always supplies this guard.
		if ( array_key_exists( 'has_evaluation', $item ) ) {
			if ( ! is_bool( $item['has_evaluation'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable application failure, not UI output.
				throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
			}
			unset( $item['has_evaluation'] );
		}
		$this->exact_keys( $item, array( 'official_revision_hash', 'official_revision_id', 'submission_id', 'submission_lock_version', 'submission_state', 'submitted_at_utc', 'target_id', 'target_state' ) );
		try {
			new Public_Id( (string) ( $item['target_id'] ?? '' ) );
			if ( null !== ( $item['submission_id'] ?? null ) ) {
				new Public_Id( (string) $item['submission_id'] );
			}
			if ( null !== ( $item['official_revision_id'] ?? null ) ) {
				new Public_Id( (string) $item['official_revision_id'] );
			}
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}
		if (
			! in_array( $item['target_state'] ?? null, array( 'active', 'revoked' ), true )
			|| ( null !== ( $item['submission_id'] ?? null ) && (
				! is_int( $item['submission_lock_version'] ?? null ) || $item['submission_lock_version'] < 0
				|| ! in_array( $item['submission_state'] ?? null, array( 'draft', 'conflict', 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true )
			) )
			|| ( null === ( $item['submission_id'] ?? null ) && (
				null !== ( $item['submission_lock_version'] ?? null ) || null !== ( $item['submission_state'] ?? null )
				|| null !== ( $item['official_revision_id'] ?? null ) || null !== ( $item['official_revision_hash'] ?? null )
				|| null !== ( $item['submitted_at_utc'] ?? null )
			) )
			|| ( null !== ( $item['submitted_at_utc'] ?? null ) && ( ! is_string( $item['submitted_at_utc'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $item['submitted_at_utc'] ) ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Reject unexpected keys before treating an input array as a validated object.
	 *
	 * @param array<array-key,mixed> $value Unvalidated input fields.
	 * @param array<int,string>      $expected Required field names.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		if ( array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Reject invalid utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'EnqueueAssignmentEvaluations clock returned an invalid UTC value.' );
		}
	}
}
