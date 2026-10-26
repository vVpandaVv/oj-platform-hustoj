<?php
/**
 * Purge at most one deferred submission ZIP staging file.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Cleanup_Item;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Cleanup_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps binary deletion inside a repository-controlled root and legal-hold lock.
 */
final class Run_Next_Submission_Zip_Cleanup {
	public const RETRY_SECONDS = 300;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize submission zip cleanup dependencies and state.
	 *
	 * @param Submission_Zip_Cleanup_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Private_File_Store_Port_Interface                $files Files.
	 * @param Public_Id_Generator                              $ids Ids.
	 * @param callable|null                                    $clock Clock.
	 */
	public function __construct(
		private Submission_Zip_Cleanup_Repository_Port_Interface $repository,
		private Private_File_Store_Port_Interface $files,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Run once.
	 *
	 * @return Run_Submission_Zip_Cleanup_Result
	 */
	public function run_once(): Run_Submission_Zip_Cleanup_Result {
		$now  = $this->now();
		$item = $this->repository->claim_due( $now, $this->minus_seconds( $now, self::RETRY_SECONDS ) );
		if ( null === $item ) {
			return new Run_Submission_Zip_Cleanup_Result( Run_Submission_Zip_Cleanup_Result::IDLE );
		}

		try {
			$complete = $this->repository->purge_claimed(
				$item,
				fn ( string $storage_key ): null => $this->delete( $storage_key ),
				$this->ids->create()->value(),
				$this->ids->create()->value(),
				$this->now()
			);
		} catch ( Throwable ) {
			return $this->result( Run_Submission_Zip_Cleanup_Result::RETRY_WAIT, $item, false, 'submission_zip_cleanup_unavailable' );
		}

		if ( null === $complete ) {
			return $this->result( Run_Submission_Zip_Cleanup_Result::STALE, $item, false, null );
		}
		return $this->result( Run_Submission_Zip_Cleanup_Result::PURGED, $item, $complete, null );
	}

	/**
	 * Delete submission zip cleanup.
	 *
	 * @param string $storage_key Storage key.
	 * @return null
	 */
	private function delete( string $storage_key ): null {
		$this->files->delete( $storage_key );
		return null;
	}

	/**
	 * Result.
	 *
	 * @param string                      $state State.
	 * @param Submission_Zip_Cleanup_Item $item Item.
	 * @param bool                        $complete Complete.
	 * @param string|null                 $reason Reason.
	 * @return Run_Submission_Zip_Cleanup_Result
	 */
	private function result( string $state, Submission_Zip_Cleanup_Item $item, bool $complete, ?string $reason ): Run_Submission_Zip_Cleanup_Result {
		return new Run_Submission_Zip_Cleanup_Result(
			$state,
			$item->upload_id(),
			$item->file_id(),
			$item->file_kind(),
			$complete,
			$reason
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
			throw new RuntimeException( 'Submission ZIP cleanup clock is invalid.' );
		}
		return $value;
	}

	/**
	 * Minus seconds.
	 *
	 * @param string $utc Utc.
	 * @param int    $seconds Seconds.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function minus_seconds( string $utc, int $seconds ): string {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $utc, new DateTimeZone( 'UTC' ) );
		if ( false === $date ) {
			throw new RuntimeException( 'Submission ZIP cleanup clock cannot be converted.' );
		}
		return $date->modify( '-' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
	}
}
