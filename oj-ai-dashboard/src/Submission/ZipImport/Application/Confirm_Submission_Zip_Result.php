<?php
/**
 * C20 submission ZIP confirmation receipt.
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
 * Safe aggregate result that preserves imported, excluded, and failed items.
 */
final class Confirm_Submission_Zip_Result {
	/**
	 * Initialize confirm submission zip result dependencies and state.
	 *
	 * @param array<string,mixed>            $upload Upload.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param int                            $confirmed_by_user_id Confirmed by user id.
	 * @param string                         $confirmed_at_utc Confirmed at utc.
	 * @param bool                           $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private array $upload,
		private array $items,
		private int $confirmed_by_user_id,
		private string $confirmed_at_utc,
		private bool $replayed
	) {
		$this->validate_upload( $upload );
		$this->validate_items( $items, (int) $upload['item_total'] );
		if ( $confirmed_by_user_id < 1 || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $confirmed_at_utc ) ) {
			throw new InvalidArgumentException( 'Submission ZIP confirmation receipt metadata is invalid.' );
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
	 * Items.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Confirmed by user id.
	 *
	 * @return int
	 */
	public function confirmed_by_user_id(): int {
		return $this->confirmed_by_user_id;
	}
	/**
	 * Confirmed at utc.
	 *
	 * @return string
	 */
	public function confirmed_at_utc(): string {
		return $this->confirmed_at_utc;
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
	 * Validate upload.
	 *
	 * @param array<string,mixed> $upload Upload.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function validate_upload( array $upload ): void {
		$this->exact_keys( $upload, array( 'assignment_id', 'excluded_total', 'failed_total', 'id', 'imported_total', 'item_total', 'mapping_version', 'state' ) );
		new Public_Id( is_string( $upload['id'] ?? null ) ? $upload['id'] : '' );
		new Public_Id( is_string( $upload['assignment_id'] ?? null ) ? $upload['assignment_id'] : '' );
		foreach ( array( 'excluded_total', 'failed_total', 'imported_total', 'item_total' ) as $field ) {
			if ( ! is_int( $upload[ $field ] ?? null ) || $upload[ $field ] < 0 ) {
				throw new InvalidArgumentException( 'Submission ZIP confirmation receipt count is invalid.' );
			}
		}
		if (
			'completed' !== ( $upload['state'] ?? null )
			|| ! is_int( $upload['mapping_version'] ?? null )
			|| $upload['mapping_version'] < 2
			|| $upload['item_total'] !== $upload['imported_total'] + $upload['excluded_total'] + $upload['failed_total']
		) {
			throw new InvalidArgumentException( 'Submission ZIP confirmation receipt state is invalid.' );
		}
	}

	/**
	 * Validate items.
	 *
	 * @param array<array-key,mixed> $items Items.
	 * @param int                    $item_total Item total.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function validate_items( array $items, int $item_total ): void {
		if ( ! array_is_list( $items ) || count( $items ) !== $item_total ) {
			throw new InvalidArgumentException( 'Submission ZIP confirmation receipt items are invalid.' );
		}
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Submission ZIP confirmation receipt item is invalid.' );
			}
			$this->exact_keys( $item, array( 'error_code', 'item_no', 'result_submission_id', 'state' ) );
			if ( $index + 1 !== ( $item['item_no'] ?? null ) || ! in_array( $item['state'] ?? null, array( 'excluded', 'failed', 'imported' ), true ) ) {
				throw new InvalidArgumentException( 'Submission ZIP confirmation item state is invalid.' );
			}
			if ( 'imported' === $item['state'] ) {
				if ( null !== $item['error_code'] || ! is_string( $item['result_submission_id'] ?? null ) ) {
					throw new InvalidArgumentException( 'Imported submission ZIP item is invalid.' );
				}
				new Public_Id( $item['result_submission_id'] );
				continue;
			}
			if ( null !== $item['result_submission_id'] || ! is_string( $item['error_code'] ?? null ) || 1 !== preg_match( '/^(?:zip_[a-z0-9_]{2,95}|submission_official_exists)$/D', $item['error_code'] ) ) {
				throw new InvalidArgumentException( 'Non-imported submission ZIP item is invalid.' );
			}
		}
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
			throw new InvalidArgumentException( 'Submission ZIP confirmation receipt keys are invalid.' );
		}
	}
}
