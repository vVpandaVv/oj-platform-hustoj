<?php
/**
 * Reclaim and scan at most one staged student attachment.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Content_Inspector;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Content_Verdict;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Malware_Verdict;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Scan_Item;
use OJAI\Dashboard\Submission\Student\Port\Student_Attachment_Scan_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Submission_Attachment_Malware_Scanner_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps type parsing and malware scanning outside the metadata transaction.
 */
final class Run_Next_Submission_Attachment_Scan {
	public const LEASE_SECONDS  = 300;
	public const MAX_FILE_BYTES = 25 * 1024 * 1024;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize submission attachment scan dependencies and state.
	 *
	 * @param Student_Attachment_Scan_Repository_Port_Interface    $repository Persistence boundary used by this operation.
	 * @param Private_File_Read_Port_Interface                     $files Files.
	 * @param Submission_Attachment_Malware_Scanner_Port_Interface $malware Malware.
	 * @param Submission_Attachment_Content_Inspector              $content Content.
	 * @param Public_Id_Generator                                  $ids Ids.
	 * @param callable|null                                        $clock Clock.
	 */
	public function __construct(
		private Student_Attachment_Scan_Repository_Port_Interface $repository,
		private Private_File_Read_Port_Interface $files,
		private Submission_Attachment_Malware_Scanner_Port_Interface $malware,
		private Submission_Attachment_Content_Inspector $content,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Run once.
	 *
	 * @return Run_Submission_Attachment_Scan_Result
	 */
	public function run_once(): Run_Submission_Attachment_Scan_Result {
		$now  = $this->now();
		$item = $this->repository->claim_due( $now, $this->minus_seconds( $now, self::LEASE_SECONDS ) );
		if ( null === $item ) {
			return new Run_Submission_Attachment_Scan_Result( Run_Submission_Attachment_Scan_Result::IDLE );
		}

		try {
			return $this->execute_claimed( $item );
		} catch ( Throwable ) {
			// A claimed attachment must never be left silently stuck on an
			// unanticipated error -- record it as rejected (a fixed, safe
			// reason code, never the exception's message or the file's own
			// content) so the teacher UI shows a real state instead of the
			// lease dangling and being silently retried forever.
			return $this->reject( $item, 'attachment_worker_exception' );
		}
	}

	/**
	 * Execute claimed.
	 *
	 * @param Submission_Attachment_Scan_Item $item Item.
	 * @return Run_Submission_Attachment_Scan_Result
	 */
	private function execute_claimed( Submission_Attachment_Scan_Item $item ): Run_Submission_Attachment_Scan_Result {
		if ( ! $item->metadata_matches() ) {
			return $this->reject( $item, 'attachment_metadata_mismatch' );
		}

		try {
			$path = $this->files->readable_path( $item->storage_key(), $item->sha256() );
		} catch ( Throwable ) {
			return $this->retry( $item, 'attachment_storage_unavailable' );
		}
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error.
		if ( false === $size ) {
			return $this->retry( $item, 'attachment_storage_unavailable' );
		}
		if ( $size < 1 || $size > self::MAX_FILE_BYTES || $size !== $item->byte_size() ) {
			return $this->reject( $item, 'attachment_size_mismatch' );
		}

		try {
			$malware = $this->malware->scan( $path );
		} catch ( Throwable ) {
			return $this->retry( $item, 'attachment_scanner_unavailable' );
		}
		if ( Submission_Attachment_Malware_Verdict::UNAVAILABLE === $malware->state() ) {
			return $this->retry( $item, 'attachment_scanner_unavailable' );
		}
		if ( Submission_Attachment_Malware_Verdict::INFECTED === $malware->state() ) {
			return $this->reject( $item, 'attachment_malware_detected' );
		}

		try {
			$content = $this->content->inspect( $path, $item );
		} catch ( Throwable ) {
			return $this->retry( $item, 'attachment_inspector_unavailable' );
		}
		if ( Submission_Attachment_Content_Verdict::UNAVAILABLE === $content->state() ) {
			return $this->retry( $item, (string) $content->reason_code() );
		}
		if ( Submission_Attachment_Content_Verdict::REJECTED === $content->state() ) {
			return $this->reject( $item, (string) $content->reason_code() );
		}

		$committed = $this->repository->complete(
			$item,
			'ready',
			null,
			$this->ids->create()->value(),
			$this->ids->create()->value(),
			$this->now()
		);
		return new Run_Submission_Attachment_Scan_Result(
			$committed ? Run_Submission_Attachment_Scan_Result::READY : Run_Submission_Attachment_Scan_Result::STALE,
			$item->attachment_id()
		);
	}

	/**
	 * Reject.
	 *
	 * @param Submission_Attachment_Scan_Item $item Item.
	 * @param string                          $reason_code Reason code.
	 * @return Run_Submission_Attachment_Scan_Result
	 */
	private function reject( Submission_Attachment_Scan_Item $item, string $reason_code ): Run_Submission_Attachment_Scan_Result {
		$committed = $this->repository->complete(
			$item,
			'rejected',
			$reason_code,
			$this->ids->create()->value(),
			$this->ids->create()->value(),
			$this->now()
		);
		return new Run_Submission_Attachment_Scan_Result(
			$committed ? Run_Submission_Attachment_Scan_Result::REJECTED : Run_Submission_Attachment_Scan_Result::STALE,
			$item->attachment_id(),
			$committed ? $reason_code : null
		);
	}

	/**
	 * Retry.
	 *
	 * @param Submission_Attachment_Scan_Item $item Item.
	 * @param string                          $reason_code Reason code.
	 * @return Run_Submission_Attachment_Scan_Result
	 */
	private function retry( Submission_Attachment_Scan_Item $item, string $reason_code ): Run_Submission_Attachment_Scan_Result {
		return new Run_Submission_Attachment_Scan_Result(
			Run_Submission_Attachment_Scan_Result::RETRY_WAIT,
			$item->attachment_id(),
			$reason_code
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
			throw new \RuntimeException( 'Submission attachment scan clock is invalid.' );
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
			throw new \RuntimeException( 'Submission attachment scan clock cannot be converted.' );
		}
		return $date->modify( '-' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
	}
}
