<?php
/**
 * Enqueue own worksheet pdf.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPdf\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Own_Worksheet_Pdf_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Worksheet_Pdf_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Worksheet_Pdf_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Build_Worksheet_Print_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the learner's exact assignment publication through the shared PDF queue.
 */
final class Enqueue_Own_Worksheet_Pdf {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Timezone.
	 *
	 * @var Closure
	 */
	private Closure $timezone;

	/**
	 * Initialize enqueue own worksheet pdf dependencies and state.
	 *
	 * @param Own_Worksheet_Pdf_Source_Query_Port_Interface $sources Sources.
	 * @param Build_Worksheet_Print_Projection              $projections Projections.
	 * @param Worksheet_Pdf_Repository_Port_Interface       $repository Persistence boundary used by this operation.
	 * @param Worksheet_Pdf_Queue_Wakeup_Port_Interface     $queue Queue.
	 * @param Public_Id_Generator                           $ids Ids.
	 * @param Canonical_Json                                $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                 $clock Clock.
	 * @param callable|null                                 $timezone Timezone.
	 */
	public function __construct(
		private Own_Worksheet_Pdf_Source_Query_Port_Interface $sources,
		private Build_Worksheet_Print_Projection $projections,
		private Worksheet_Pdf_Repository_Port_Interface $repository,
		private Worksheet_Pdf_Queue_Wakeup_Port_Interface $queue,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null,
		?callable $timezone = null
	) {
		$this->clock    = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
		$this->timezone = null === $timezone
			? static fn (): string => function_exists( 'wp_timezone_string' ) && '' !== wp_timezone_string() ? wp_timezone_string() : 'UTC'
			: Closure::fromCallable( $timezone );
	}

	/**
	 * Execute enqueue own worksheet pdf.
	 *
	 * @param Enqueue_Own_Worksheet_Pdf_Command $command Command.
	 * @return Enqueue_Worksheet_Pdf_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Enqueue_Own_Worksheet_Pdf_Command $command ): Enqueue_Worksheet_Pdf_Result {
		$receipt = $this->repository->find_command_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Enqueue_Worksheet_Pdf_Result ) {
			return $receipt;
		}

		$queued_at = $this->now();
		$source    = $this->sources->find_available( $command->actor_user_id(), $command->assignment_id(), $queued_at );
		if ( null === $source ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::NOT_FOUND );
		}
		$this->assert_source( $source, $command );

		try {
			$projection = $this->projections->build_exact_publication(
				$command->expected_publication_id(),
				$command->expected_publication_hash(),
				$command->profile()
			);
		} catch ( Worksheet_Failure $failure ) {
			$reason = match ( $failure->reason() ) {
				Worksheet_Failure::NOT_FOUND => Worksheet_Pdf_Failure::NOT_FOUND,
				Worksheet_Failure::PRINT_SOURCE_CONFLICT => Worksheet_Pdf_Failure::SOURCE_STALE,
				Worksheet_Failure::STORED_DATA_INVALID => Worksheet_Pdf_Failure::SOURCE_INVALID,
				default => Worksheet_Pdf_Failure::STORAGE_UNAVAILABLE,
			};
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( $reason );
		}

		try {
			$snapshot = Worksheet_Pdf_Request_Snapshot::freeze_own(
				$projection,
				$source,
				array(
					'type'     => $command->context_type(),
					'id'       => $command->context_id(),
					'revision' => $command->expected_context_revision(),
				),
				(string) ( $this->timezone )(),
				$this->canonical
			);
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::SOURCE_INVALID );
		}

		$semantic = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'actor_user_id'    => $command->actor_user_id(),
					'assignment_id'    => $command->assignment_id(),
					'context'          => array(
						'id'       => $command->context_id(),
						'revision' => $command->expected_context_revision(),
						'type'     => $command->context_type(),
					),
					'publication_hash' => $command->expected_publication_hash(),
					'publication_id'   => $command->expected_publication_id(),
					'request_hash'     => $snapshot->hash(),
				)
			)
		);

		$record = array(
			'actor_user_id'            => $command->actor_user_id(),
			'audit_public_id'          => $this->ids->create()->value(),
			'command_id'               => $command->command_id(),
			'command_storage_key'      => $command->idempotency_storage_key(),
			'context_id'               => $command->context_id(),
			'context_revision'         => $command->expected_context_revision(),
			'context_type'             => $command->context_type(),
			'correlation_id'           => $command->correlation_id(),
			'job_idempotency_key'      => 'pdfjob:' . hash( 'sha256', $semantic . '|' . $command->idempotency_storage_key() ),
			'job_public_id'            => $this->ids->create()->value(),
			'outbox_public_id'         => $this->ids->create()->value(),
			'print_profile_version'    => $snapshot->profile_version(),
			'queued_at_utc'            => $queued_at,
			'request_contract_version' => $snapshot->version(),
			'request_digest'           => $command->request_digest(),
			'request_snapshot_schema'  => Worksheet_Pdf_Request_Snapshot::SCHEMA,
			'request_snapshot_version' => Worksheet_Pdf_Request_Snapshot::DATABASE_VERSION,
			'semantic_hash'            => $semantic,
			'snapshot_hash'            => $snapshot->hash(),
			'snapshot_json'            => $snapshot->json(),
			'source_hash'              => $snapshot->source_hash(),
			'source_public_id'         => $snapshot->source_id(),
			'source_state'             => $snapshot->source_state(),
			'source_type'              => $snapshot->source_type(),
		);

		$result = $this->repository->enqueue( $record );
		$this->queue->wake();
		return $result;
	}

	/**
	 * Reject invalid source.
	 *
	 * @param array<string,mixed>               $source Source.
	 * @param Enqueue_Own_Worksheet_Pdf_Command $command Command.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_source( array $source, Enqueue_Own_Worksheet_Pdf_Command $command ): void {
		$expected_keys = array(
			'assignment_id',
			'assignment_lock_version',
			'assignment_state',
			'binding_context_revision',
			'context_id',
			'context_type',
			'publication_hash',
			'publication_id',
			'target_id',
			'target_state',
		);
		$actual_keys   = array_keys( $source );
		sort( $actual_keys, SORT_STRING );
		sort( $expected_keys, SORT_STRING );
		if (
			$actual_keys !== $expected_keys
			|| ! hash_equals( $command->assignment_id(), (string) ( $source['assignment_id'] ?? '' ) )
			|| $command->expected_assignment_lock_version() !== ( $source['assignment_lock_version'] ?? null )
			|| ! hash_equals( $command->expected_assignment_state(), (string) ( $source['assignment_state'] ?? '' ) )
			|| ! hash_equals( $command->context_type(), (string) ( $source['context_type'] ?? '' ) )
			|| ! hash_equals( $command->context_id(), (string) ( $source['context_id'] ?? '' ) )
			|| ! hash_equals( $command->expected_context_revision(), (string) ( $source['binding_context_revision'] ?? '' ) )
			|| ! hash_equals( $command->expected_publication_id(), (string) ( $source['publication_id'] ?? '' ) )
			|| ! hash_equals( $command->expected_publication_hash(), (string) ( $source['publication_hash'] ?? '' ) )
			|| ! hash_equals( $command->expected_target_state(), (string) ( $source['target_state'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::SOURCE_STALE );
		}
	}

	/**
	 * Now.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		$value = (string) ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}
}
