<?php
/**
 * Enqueue worksheet package.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPackage\Application;

use Closure;
use OJAI\Dashboard\Reporting\WorksheetPackage\Port\Worksheet_Package_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPackage\Port\Worksheet_Package_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue worksheet package.
 */
final class Enqueue_Worksheet_Package {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Initialize enqueue worksheet package dependencies and state.
	 *
	 * @param Build_Worksheet_Package_Source                $sources Sources.
	 * @param Worksheet_Package_Repository_Port_Interface   $repository Persistence boundary used by this operation.
	 * @param Worksheet_Package_Queue_Wakeup_Port_Interface $queue Queue.
	 * @param Public_Id_Generator                           $ids Ids.
	 * @param Canonical_Json                                $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                 $clock Clock.
	 */
	public function __construct(
		private Build_Worksheet_Package_Source $sources,
		private Worksheet_Package_Repository_Port_Interface $repository,
		private Worksheet_Package_Queue_Wakeup_Port_Interface $queue,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) { $this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute enqueue worksheet package.
	 *
	 * @param Enqueue_Worksheet_Package_Command $command Command.
	 * @return Enqueue_Worksheet_Package_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Enqueue_Worksheet_Package_Command $command ): Enqueue_Worksheet_Package_Result {
		$receipt = $this->repository->find_command_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Enqueue_Worksheet_Package_Result ) {
			return $receipt;
		}
		$snapshot  = $this->sources->build( $command->actor_user_id(), $command->revision_id(), $command->expected_source_hash(), $command->expected_state() )->snapshot();
		$queued_at = (string) ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::STORAGE_UNAVAILABLE );
		}
		$semantic = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'actor_user_id' => $command->actor_user_id(),
					'source_id'     => $command->revision_id(),
					'source_hash'   => $command->expected_source_hash(),
					'request_hash'  => $snapshot->hash(),
				)
			)
		);
		$record   = array(
			'actor_user_id'           => $command->actor_user_id(),
			'audit_public_id'         => $this->ids->create()->value(),
			'command_id'              => $command->command_id(),
			'command_storage_key'     => $command->idempotency_storage_key(),
			'correlation_id'          => $command->correlation_id(),
			'job_idempotency_key'     => 'pkgjob:' . hash( 'sha256', $semantic . '|' . $command->idempotency_storage_key() ),
			'job_public_id'           => $this->ids->create()->value(),
			'outbox_public_id'        => $this->ids->create()->value(),
			'package_profile_version' => $snapshot::PROFILE_VERSION,
			'queued_at_utc'           => $queued_at,
			'request_digest'          => $command->request_digest(),
			'semantic_hash'           => $semantic,
			'snapshot_hash'           => $snapshot->hash(),
			'snapshot_json'           => $snapshot->json(),
			'source_hash'             => $command->expected_source_hash(),
			'source_public_id'        => $command->revision_id(),
			'source_state'            => $command->expected_state(),
		);
		$result   = $this->repository->enqueue( $record );
		$this->queue->wake();
		return $result;
	}
}
