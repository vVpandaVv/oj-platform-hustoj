<?php
/**
 * Repair addon schema.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\SchemaRepair\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Repairer_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Runtime_Readiness_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;
use OJAI\Dashboard\Operations\SchemaRepair\Domain\Schema_Repair_State;
use OJAI\Dashboard\Operations\SchemaRepair\Port\Schema_Repair_Receipt_Port_Interface;
use OJAI\Dashboard\Operations\SchemaRepair\Port\Schema_Repair_State_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repair addon schema.
 */
final class Repair_Addon_Schema {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize repair addon schema dependencies and state.
	 *
	 * @param Schema_Repair_State_Port_Interface      $states States.
	 * @param Schema_Repair_Receipt_Port_Interface    $receipts Receipts.
	 * @param Schema_Repairer_Port_Interface          $repairer Repairer.
	 * @param Schema_Runtime_Readiness_Port_Interface $postconditions Postconditions.
	 * @param Schema_Catalog                          $catalog Catalog.
	 * @param Closure|null                            $clock Clock.
	 */
	public function __construct(
		private Schema_Repair_State_Port_Interface $states,
		private Schema_Repair_Receipt_Port_Interface $receipts,
		private Schema_Repairer_Port_Interface $repairer,
		private Schema_Runtime_Readiness_Port_Interface $postconditions,
		private Schema_Catalog $catalog,
		?Closure $clock = null
	) {
		$this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Execute repair addon schema.
	 *
	 * @param Repair_Addon_Schema_Command $command Command.
	 * @return Repair_Addon_Schema_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Repair_Addon_Schema_Command $command ): Repair_Addon_Schema_Result {
		$now    = $this->now();
		$record = $command->receipt( $now->format( 'Y-m-d H:i:s' ) );
		try {
			$replay = $this->receipts->claim( $record );
		} catch ( Schema_Repair_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
		if ( $replay instanceof Repair_Addon_Schema_Result ) {
			return $replay->as_replay();
		}

		$authorization = $command->authorization();
		if ( ! $authorization->valid_for( $this->catalog, $now ) ) {
			$this->block( $record, Schema_Repair_Failure::BACKUP_INVALID, $now );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::BACKUP_INVALID );
		}

		$state = $this->states->inspect();
		if ( ! $state->is_repairable() ) {
			if ( $this->postcondition_ready() ) {
				return $this->complete_converged( $record, $now );
			}
			if ( Schema_Repair_State::NOT_REQUIRED === $state->disposition() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Schema_Repair_Failure::because( Schema_Repair_Failure::POSTCONDITION_FAILED );
			}
			$this->block( $record, Schema_Repair_Failure::NOT_AVAILABLE, $now );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::NOT_AVAILABLE );
		}
		if ( ! $state->matches(
			$command->expected_installation_state(),
			$command->expected_catalog_fingerprint(),
			$command->expected_repair_state_hash()
		) ) {
			$this->block( $record, Schema_Repair_Failure::STATE_STALE, $now );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STATE_STALE );
		}

		try {
			$result = $this->repairer->repair( $authorization );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::POSTCONDITION_FAILED );
		}

		if ( ! $result->succeeded() ) {
			if ( 'schema_repair_not_required' === $result->code() && $this->postcondition_ready() ) {
				return $this->complete_converged( $record, $now );
			}
			if ( Schema_Installation_Result::LOCK_UNAVAILABLE === $result->status() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Schema_Repair_Failure::because( Schema_Repair_Failure::LOCK_UNAVAILABLE );
			}
			if ( Schema_Installation_Result::BLOCKED === $result->status() ) {
				$this->block( $record, Schema_Repair_Failure::BLOCKED, $now );
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Schema_Repair_Failure::because( Schema_Repair_Failure::BLOCKED );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::POSTCONDITION_FAILED );
		}

		if ( ! $this->postcondition_ready() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::POSTCONDITION_FAILED );
		}

		return $this->complete( $record, $result, $this->now() );
	}

	/**
	 * Complete converged.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param DateTimeImmutable   $now Clock value supplied for deterministic scheduling.
	 * @return Repair_Addon_Schema_Result
	 */
	private function complete_converged( array $record, DateTimeImmutable $now ): Repair_Addon_Schema_Result {
		$result = new Schema_Installation_Result(
			Schema_Installation_Result::REPAIRED,
			'schema_repair_converged',
			(string) $record['correlation_id']
		);

		return $this->complete( $record, $result, $now );
	}

	/**
	 * Complete.
	 *
	 * @param array<string,mixed>        $record Record.
	 * @param Schema_Installation_Result $result Result.
	 * @param DateTimeImmutable          $now Clock value supplied for deterministic scheduling.
	 * @return Repair_Addon_Schema_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function complete( array $record, Schema_Installation_Result $result, DateTimeImmutable $now ): Repair_Addon_Schema_Result {
		try {
			return $this->receipts->complete( $record, $result, $now->format( 'Y-m-d H:i:s' ) );
		} catch ( Schema_Repair_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Block.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $reason Reason.
	 * @param DateTimeImmutable   $now Clock value supplied for deterministic scheduling.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function block( array $record, string $reason, DateTimeImmutable $now ): void {
		try {
			$this->receipts->block( $record, $reason, $now->format( 'Y-m-d H:i:s' ) );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Postcondition ready.
	 *
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function postcondition_ready(): bool {
		try {
			return $this->postconditions->verify()->is_ready();
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::POSTCONDITION_FAILED );
		}
	}

	/**
	 * Now.
	 *
	 * @return DateTimeImmutable
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): DateTimeImmutable {
		$value = ( $this->clock )();
		if ( ! $value instanceof DateTimeImmutable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}

		return $value->setTimezone( new DateTimeZone( 'UTC' ) );
	}
}
