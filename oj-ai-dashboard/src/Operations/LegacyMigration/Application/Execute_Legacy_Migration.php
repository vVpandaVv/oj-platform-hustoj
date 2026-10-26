<?php
/**
 * Execute legacy migration.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Execution_Repository_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Mapping_Decision_Validator_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Site_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Inspector_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Revalidates an approved run and durably starts its resumable C62 job.
 */
final class Execute_Legacy_Migration {
	public const CHECKPOINT_PROFILE = 'oj_ai.legacy_migration_checkpoint@1.0.0';
	public const CHECKPOINT_SCHEMA  = 'oj_ai.legacy_migration_checkpoint';
	public const CHECKPOINT_VERSION = 1;
	public const BATCH_SIZE         = 250;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize execute legacy migration dependencies and state.
	 *
	 * @param Legacy_Source_Inspector_Port_Interface                $sources Sources.
	 * @param Legacy_Migration_Site_Port_Interface                  $sites Sites.
	 * @param Legacy_Migration_Execution_Repository_Port_Interface  $repository Persistence boundary used by this operation.
	 * @param Legacy_Migration_Queue_Wakeup_Port_Interface          $queue Queue.
	 * @param Public_Id_Generator                                   $ids Ids.
	 * @param Canonical_Json                                        $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                         $clock Clock.
	 * @param Legacy_Mapping_Decision_Validator_Port_Interface|null $mapping_validator Mapping validator.
	 */
	public function __construct(
		private Legacy_Source_Inspector_Port_Interface $sources,
		private Legacy_Migration_Site_Port_Interface $sites,
		private Legacy_Migration_Execution_Repository_Port_Interface $repository,
		private Legacy_Migration_Queue_Wakeup_Port_Interface $queue,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null,
		private ?Legacy_Mapping_Decision_Validator_Port_Interface $mapping_validator = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute execute legacy migration.
	 *
	 * @param Execute_Legacy_Migration_Command $command Command.
	 * @return Execute_Legacy_Migration_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Execute_Legacy_Migration_Command $command ): Execute_Legacy_Migration_Result {
		try {
			$receipt = $this->repository->started_receipt( $command->idempotency_storage_key(), $command->request_digest() );
			if ( $receipt instanceof Execute_Legacy_Migration_Result ) {
				return $receipt;
			}

			$run = $this->repository->approved_run( $command->run_id() );
			if (
				'approved' !== $command->expected_state()
				|| ! hash_equals( $run->source_fingerprint(), $command->expected_source_fingerprint() )
				|| ! hash_equals( $run->plan_hash(), $command->expected_plan_hash() )
			) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
			}

			if ( Legacy_Migration_Plan::PREVIOUS_PROFILE === ( $run->plan()['profile'] ?? null ) ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::MAPPING_REQUIRED );
			}
			$site_id      = $this->sites->site_id();
			$source       = $this->sources->inspect( $run->source_profile() );
			$plans        = new Legacy_Migration_Plan( $this->canonical );
			$current_plan = $plans->rebuild( $site_id, $source, $run->plan() );
			$plans->assert_valid( $current_plan );
			if (
				! hash_equals( $run->source_fingerprint(), $source->source_fingerprint() )
				|| ! hash_equals( $run->source_fingerprint(), (string) ( $current_plan['source']['fingerprint'] ?? '' ) )
				|| ! hash_equals( $run->plan_hash(), (string) ( $current_plan['plan_hash'] ?? '' ) )
			) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_STALE );
			}
			$this->assert_executable_plan( $current_plan );
			if ( array() !== ( $current_plan['mapping_manifest'] ?? array() ) ) {
				if ( ! $this->mapping_validator instanceof Legacy_Mapping_Decision_Validator_Port_Interface ) {
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::MAPPING_REQUIRED );
				}
				$this->mapping_validator->validate(
					$command->actor_user_id(),
					$current_plan['mapping_manifest'],
					$current_plan['mapping_decisions']
				);
			}

			$started_at_utc  = $this->now();
			$checkpoint      = $this->checkpoint( $run->source_profile(), $run->source_fingerprint(), $run->plan_hash() );
			$checkpoint_json = $this->canonical->encode( $checkpoint );
			$result          = $this->repository->start(
				array(
					'actor_user_id'         => $command->actor_user_id(),
					'audit_public_id'       => $this->id(),
					'checkpoint'            => $checkpoint,
					'checkpoint_hash'       => hash( 'sha256', $checkpoint_json ),
					'checkpoint_json'       => $checkpoint_json,
					'command_id'            => $command->command_id(),
					'command_storage_key'   => $command->idempotency_storage_key(),
					'correlation_id'        => $command->correlation_id(),
					'expected_lock_version' => $run->lock_version(),
					'item_total'            => $run->item_total(),
					'outbox_public_id'      => $this->id(),
					'plan_hash'             => $run->plan_hash(),
					'preview_hash'          => $run->preview_hash(),
					'request_digest'        => $command->request_digest(),
					'run_database_id'       => $run->database_id(),
					'run_public_id'         => $run->public_id(),
					'site_id'               => $site_id,
					'source_fingerprint'    => $run->source_fingerprint(),
					'started_at_utc'        => $started_at_utc,
				)
			);
			if ( ! $result->replayed() ) {
				try {
					$this->queue->wake( $result->run_id() );
				} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
					// The durable running checkpoint remains authoritative for recovery.
				}
			}

			return $result;
		} catch ( Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::EXECUTION_INVALID, $error );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Reject invalid executable plan.
	 *
	 * @param array<string,mixed> $plan Plan.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_executable_plan( array $plan ): void {
		$counts = $plan['counts'] ?? null;
		if ( ! is_array( $counts ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::EXECUTION_INVALID );
		}
		$conflicts      = $counts['conflicts'] ?? null;
		$mapping_needed = $counts['mapping_needed'] ?? null;
		if ( ! is_int( $conflicts ) || $conflicts < 0 || ! is_int( $mapping_needed ) || $mapping_needed < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::EXECUTION_INVALID );
		}
		if ( $conflicts > 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_CONFLICT );
		}
		try {
			( new Legacy_Migration_Plan( $this->canonical ) )->assert_executable( $plan );
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::MAPPING_REQUIRED );
		}
	}

	/**
	 * Checkpoint.
	 *
	 * @param string $source_profile Source profile.
	 * @param string $source_fingerprint Source fingerprint.
	 * @param string $plan_hash Plan hash.
	 * @return array<string,mixed>
	 */
	private function checkpoint( string $source_profile, string $source_fingerprint, string $plan_hash ): array {
		return array(
			'profile'    => self::CHECKPOINT_PROFILE,
			'phase'      => 'running',
			'batch_size' => self::BATCH_SIZE,
			'source'     => array(
				'profile'     => $source_profile,
				'fingerprint' => $source_fingerprint,
			),
			'plan_hash'  => $plan_hash,
			'cursor'     => array(
				'table_index'     => 0,
				'after_source_id' => 0,
				'next_item_no'    => 1,
			),
			'totals'     => array(
				'success' => 0,
				'failure' => 0,
				'skipped' => 0,
			),
		);
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
