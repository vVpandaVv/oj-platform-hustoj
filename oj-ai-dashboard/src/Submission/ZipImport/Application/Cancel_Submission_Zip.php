<?php
/**
 * C21 submission ZIP cancellation use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Finalization_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seals an unimported ZIP staging root and schedules deferred cleanup.
 */
final class Cancel_Submission_Zip {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize submission zip dependencies and state.
	 *
	 * @param Submission_Zip_Finalization_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                                   $ids Ids.
	 * @param callable|null                                         $clock Clock.
	 */
	public function __construct(
		private Submission_Zip_Finalization_Repository_Port_Interface $repository,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute submission zip.
	 *
	 * @param Cancel_Submission_Zip_Command $command Command.
	 * @return Cancel_Submission_Zip_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Cancel_Submission_Zip_Command $command ): Cancel_Submission_Zip_Result {
		$receipt = $this->repository->find_cancellation_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Cancel_Submission_Zip_Result ) {
			return $receipt;
		}
		$occurred_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at ) ) {
			throw new InvalidArgumentException( 'Submission ZIP cancellation clock returned an invalid UTC value.' );
		}
		$timestamp = strtotime( $occurred_at . ' UTC' );
		if ( false === $timestamp ) {
			throw new InvalidArgumentException( 'Submission ZIP cancellation time is invalid.' );
		}

		return $this->repository->cancel(
			array(
				'actor_user_id'               => $command->actor_user_id(),
				'audit_public_id'             => $this->ids->create()->value(),
				'authorized_context_id'       => $command->authorized_context_id(),
				'authorized_context_revision' => $command->authorized_context_revision(),
				'authorized_context_type'     => $command->authorized_context_type(),
				'cleanup_after_utc'           => gmdate( 'Y-m-d H:i:s', $timestamp + 86400 ),
				'command_id'                  => $command->command_id(),
				'correlation_id'              => $command->correlation_id(),
				'expected_mapping_version'    => $command->expected_mapping_version(),
				'idempotency_storage_key'     => $command->idempotency_storage_key(),
				'occurred_at_utc'             => $occurred_at,
				'outbox_public_id'            => $this->ids->create()->value(),
				'request_digest'              => $command->request_digest(),
				'upload_id'                   => $command->upload_id(),
			)
		);
	}
}
