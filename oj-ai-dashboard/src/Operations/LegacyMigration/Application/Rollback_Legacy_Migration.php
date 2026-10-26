<?php
/**
 * Rollback legacy migration.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Rollback_Repository_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Site_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compensates only unchanged targets created by one completed legacy run.
 */
final class Rollback_Legacy_Migration {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize rollback legacy migration dependencies and state.
	 *
	 * @param Legacy_Migration_Rollback_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Legacy_Migration_Site_Port_Interface                $sites Sites.
	 * @param Public_Id_Generator                                 $ids Ids.
	 * @param callable|null                                       $clock Clock.
	 */
	public function __construct(
		private Legacy_Migration_Rollback_Repository_Port_Interface $repository,
		private Legacy_Migration_Site_Port_Interface $sites,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Rollback.
	 *
	 * @param Rollback_Legacy_Migration_Command $command Command.
	 * @return Rollback_Legacy_Migration_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function rollback( Rollback_Legacy_Migration_Command $command ): Rollback_Legacy_Migration_Result {
		try {
			$receipt = $this->repository->compensated_receipt(
				$command->idempotency_storage_key(),
				$command->request_digest()
			);
			if ( $receipt instanceof Rollback_Legacy_Migration_Result ) {
				return $receipt;
			}

			return $this->repository->compensate(
				array(
					'actor_user_id'            => $command->actor_user_id(),
					'audit_public_id'          => $this->id(),
					'command_id'               => $command->command_id(),
					'command_storage_key'      => $command->idempotency_storage_key(),
					'compensated_at_utc'       => $this->now(),
					'correlation_id'           => $command->correlation_id(),
					'expected_checkpoint_hash' => $command->expected_checkpoint_hash(),
					'expected_state'           => $command->expected_state(),
					'expected_updated_at_utc'  => $command->expected_updated_at_utc(),
					'outbox_public_id'         => $this->id(),
					'request_digest'           => $command->request_digest(),
					'run_public_id'            => $command->run_id(),
					'site_id'                  => $this->sites->site_id(),
				)
			);
		} catch ( Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::ROLLBACK_INVALID, $error );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	private function id(): string {
		return $this->ids->create()->value();
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}
}
