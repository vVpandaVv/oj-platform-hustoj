<?php
/**
 * ArchiveWorksheet and RestoreWorksheet use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Lifecycle_Repository_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ArchiveWorksheet and RestoreWorksheet use case.
 */
final class Change_Worksheet_Lifecycle {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize change worksheet lifecycle dependencies and state.
	 *
	 * @param Worksheet_Lifecycle_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                           $ids Ids.
	 * @param callable|null                                 $clock Clock.
	 */
	public function __construct(
		private Worksheet_Lifecycle_Repository_Port_Interface $repository,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute change worksheet lifecycle.
	 *
	 * @param Worksheet_Lifecycle_Command $command Command.
	 * @return Worksheet_Lifecycle_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Worksheet_Lifecycle_Command $command ): Worksheet_Lifecycle_Result {
		$receipt = $this->repository->find_command_receipt(
			$command->actor_user_id(),
			$command->operation(),
			$command->idempotency_storage_key(),
			$command->request_digest()
		);
		if ( $receipt instanceof Worksheet_Lifecycle_Result ) {
			return $receipt;
		}

		$changed_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $changed_at ) ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle clock returned an invalid UTC value.' );
		}

		return $this->repository->change_state(
			array(
				'actor_user_id'           => $command->actor_user_id(),
				'operation'               => $command->operation(),
				'worksheet_public_id'     => $command->worksheet_id(),
				'command_id'              => $command->command_id(),
				'expected_state'          => $command->expected_state(),
				'expected_revision_id'    => $command->expected_revision_id(),
				'expected_lock_version'   => $command->expected_lock_version(),
				'idempotency_storage_key' => $command->idempotency_storage_key(),
				'request_digest'          => $command->request_digest(),
				'correlation_id'          => $command->correlation_id(),
				'changed_at_utc'          => $changed_at,
				'audit_public_id'         => $this->ids->create()->value(),
				'outbox_public_id'        => $this->ids->create()->value(),
			)
		);
	}
}
