<?php
/**
 * Bounded export artifact cleanup with retry backoff.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\Export\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Reporting\Export\Port\Export_Cleanup_Repository_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

/**
 * Executes the same retention operation from Cron and the manual command.
 */
final class Run_Next_Export_Cleanup {
	public const IDLE          = 'idle';
	public const PURGED        = 'purged';
	public const STALE         = 'stale';
	public const RETRY_WAIT    = 'retry_wait';
	public const RETRY_SECONDS = 300;
	/**
	 * UTC clock for repeatable retention checks.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Connects cleanup to the private binary store.
	 *
	 * @param Export_Cleanup_Repository_Port_Interface $repository Transactional retention repository.
	 * @param Private_File_Store_Port_Interface        $files Private binary store.
	 * @param Public_Id_Generator                      $ids Event identity generator.
	 * @param Closure|null                             $clock Optional UTC clock.
	 */
	public function __construct(
		private Export_Cleanup_Repository_Port_Interface $repository,
		private Private_File_Store_Port_Interface $files,
		private Public_Id_Generator $ids,
		?Closure $clock = null
	) {
		$this->clock = $clock ?? static fn (): string => gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Returns a stable outcome without leaking storage errors to a cron response.
	 *
	 * @return string
	 */
	public function run_once(): string {
		try {
			$now  = ( $this->clock )();
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $now, new DateTimeZone( 'UTC' ) );
			if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $now ) {
				return self::RETRY_WAIT;
			}
			return $this->repository->purge_next(
				$now,
				$date->modify( '-' . self::RETRY_SECONDS . ' seconds' )->format( 'Y-m-d H:i:s' ),
				fn ( string $key ) => $this->files->delete( $key ),
				$this->ids->create()->value(),
				$this->ids->create()->value()
			);
		} catch ( Throwable ) {
			return self::RETRY_WAIT;
		}
	}
}
