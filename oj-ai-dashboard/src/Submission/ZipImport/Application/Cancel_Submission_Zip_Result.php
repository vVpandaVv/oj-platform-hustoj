<?php
/**
 * C21 submission ZIP cancellation receipt.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe cancellation result; staged bytes remain private until cleanup.
 */
final class Cancel_Submission_Zip_Result {
	/**
	 * Initialize submission zip result dependencies and state.
	 *
	 * @param array<string,mixed> $upload Upload.
	 * @param int                 $canceled_by_user_id Canceled by user id.
	 * @param string              $canceled_at_utc Canceled at utc.
	 * @param bool                $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private array $upload,
		private int $canceled_by_user_id,
		private string $canceled_at_utc,
		private bool $replayed
	) {
		$this->exact_keys( $upload, array( 'assignment_id', 'cleanup_after_utc', 'id', 'item_total', 'mapping_version', 'state' ) );
		new Public_Id( is_string( $upload['id'] ?? null ) ? $upload['id'] : '' );
		new Public_Id( is_string( $upload['assignment_id'] ?? null ) ? $upload['assignment_id'] : '' );
		if (
			'canceled' !== ( $upload['state'] ?? null )
			|| ! is_int( $upload['item_total'] ?? null ) || $upload['item_total'] < 0
			|| ! is_int( $upload['mapping_version'] ?? null ) || $upload['mapping_version'] < 0
			|| ! is_string( $upload['cleanup_after_utc'] ?? null )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $upload['cleanup_after_utc'] )
			|| $canceled_by_user_id < 1
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $canceled_at_utc )
		) {
			throw new InvalidArgumentException( 'Submission ZIP cancellation receipt is invalid.' );
		}
	}

	/**
	 * Upload.
	 *
	 * @return array<string,mixed>
	 */
	public function upload(): array {
		return $this->upload;
	}
	/**
	 * Canceled by user id.
	 *
	 * @return int
	 */
	public function canceled_by_user_id(): int {
		return $this->canceled_by_user_id;
	}
	/**
	 * Canceled at utc.
	 *
	 * @return string
	 */
	public function canceled_at_utc(): string {
		return $this->canceled_at_utc;
	}
	/**
	 * Replayed.
	 *
	 * @return bool
	 */
	public function replayed(): bool {
		return $this->replayed;
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Submission ZIP cancellation receipt keys are invalid.' );
		}
	}
}
