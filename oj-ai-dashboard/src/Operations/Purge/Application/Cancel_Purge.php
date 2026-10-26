<?php
/**
 * Cancel purge.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Operation_Repository_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Site_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cancels C73 before execution or at a serialized batch boundary.
 */
final class Cancel_Purge {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize purge dependencies and state.
	 *
	 * @param Purge_Operation_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Purge_Site_Port_Interface                 $sites Sites.
	 * @param Public_Id_Generator                       $ids Ids.
	 * @param callable|null                             $clock Clock.
	 */
	public function __construct(
		private Purge_Operation_Repository_Port_Interface $repository,
		private Purge_Site_Port_Interface $sites,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute purge.
	 *
	 * @param Cancel_Purge_Command $command Command.
	 * @return Cancel_Purge_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Cancel_Purge_Command $command ): Cancel_Purge_Result {
		try {
			$receipt = $this->repository->cancellation_receipt(
				$command->idempotency_storage_key(),
				$command->request_digest()
			);
			if ( $receipt instanceof Cancel_Purge_Result ) {
				return $receipt;
			}

			$job = $this->repository->job( $command->job_id() );
			if (
				! hash_equals( $job->state(), $command->expected_state() )
				|| ! in_array( $job->state(), array( 'approved', 'running' ), true )
				|| ! hash_equals( $job->preview_hash(), $command->expected_preview_hash() )
			) {
				throw Purge_Failure::because( Purge_Failure::JOB_STATE_CONFLICT );
			}

			return $this->repository->cancel(
				array(
					'actor_user_id'                      => $command->actor_user_id(),
					'audit_public_id'                    => $this->id(),
					'cancelled_at_utc'                   => $this->now(),
					'command_id'                         => $command->command_id(),
					'command_storage_key'                => $command->idempotency_storage_key(),
					'correlation_id'                     => $command->correlation_id(),
					'expected_installation_database_id'  => $job->installation_database_id(),
					'expected_installation_lock_version' => $job->installation_lock_version(),
					'expected_job_database_id'           => $job->database_id(),
					'expected_job_lock_version'          => $job->lock_version(),
					'expected_preview_json_hash'         => $job->preview_json_hash(),
					'expected_state'                     => $job->state(),
					'job_public_id'                      => $job->public_id(),
					'outbox_public_id'                   => $this->id(),
					'preview_hash'                       => $job->preview_hash(),
					'request_digest'                     => $command->request_digest(),
					'site_id'                            => $this->sites->site_id(),
				)
			);
		} catch ( Purge_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::CANCELLATION_INVALID, $error );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE, $error );
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
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}
}
