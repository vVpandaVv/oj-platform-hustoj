<?php
/**
 * C53 cancel queued export job use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\Export\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Export\Port\Export_Control_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C53 cancel queued export job use case.
 */
final class Cancel_Export_Job {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Initialize export job dependencies and state.
	 *
	 * @param Export_Control_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                      $ids Ids.
	 * @param callable|null                            $clock Clock.
	 */
	public function __construct( private Export_Control_Repository_Port_Interface $repository, private Public_Id_Generator $ids, ?callable $clock = null ) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}
	/**
	 * Execute export job.
	 *
	 * @param Cancel_Export_Job_Command $command Command.
	 * @return Export_Control_Result
	 */
	public function execute( Cancel_Export_Job_Command $command ): Export_Control_Result {
		$receipt = $this->repository->find_command_receipt( $command->idempotency_storage_key(), $command->request_digest(), Export_Control_Result::OPERATION_CANCEL );
		if ( $receipt instanceof Export_Control_Result ) {
			return $receipt;
		}
		return $this->repository->cancel(
			array(
				'actor_user_id'        => $command->actor_user_id(),
				'audit_public_id'      => $this->ids->create()->value(),
				'command_id'           => $command->command_id(),
				'command_storage_key'  => $command->idempotency_storage_key(),
				'correlation_id'       => $command->correlation_id(),
				'expected_source_hash' => $command->expected_source_hash(),
				'expected_state'       => $command->expected_state(),
				'job_public_id'        => $command->job_id(),
				'occurred_at_utc'      => $this->now(),
				'outbox_public_id'     => $this->ids->create()->value(),
				'reason'               => $command->reason(),
				'request_digest'       => $command->request_digest(),
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
			throw new InvalidArgumentException( 'CancelExportJob clock is invalid.' );
		} return $value;
	}
}
