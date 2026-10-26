<?php
/**
 * C68 submission ZIP item mapping receipt.
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
 * Safe result used by both the REST response and durable outbox replay.
 */
final class Map_Submission_Zip_Items_Result {
	/**
	 * Initialize submission zip items result dependencies and state.
	 *
	 * @param array<string,mixed>            $upload Upload.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param int                            $mapped_by_user_id Mapped by user id.
	 * @param string                         $mapped_at_utc Mapped at utc.
	 * @param bool                           $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private array $upload,
		private array $items,
		private int $mapped_by_user_id,
		private string $mapped_at_utc,
		private bool $replayed
	) {
		$this->validate_upload( $upload );
		$this->validate_items( $items, (int) $upload['item_total'] );
		if ( $mapped_by_user_id < 1 || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $mapped_at_utc ) ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping receipt metadata is invalid.' );
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
	 * Mapped by user id.
	 *
	 * @return int
	 */
	public function mapped_by_user_id(): int {
		return $this->mapped_by_user_id;
	}
	/**
	 * Mapped at utc.
	 *
	 * @return string
	 */
	public function mapped_at_utc(): string {
		return $this->mapped_at_utc;
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
		$this->exact_keys( $upload, array( 'assignment_id', 'excluded_total', 'id', 'imported_total', 'item_total', 'mapped_total', 'mapping_version', 'state' ) );
		new Public_Id( is_string( $upload['id'] ?? null ) ? $upload['id'] : '' );
		new Public_Id( is_string( $upload['assignment_id'] ?? null ) ? $upload['assignment_id'] : '' );
		foreach ( array( 'excluded_total', 'imported_total', 'item_total', 'mapped_total' ) as $field ) {
			if ( ! is_int( $upload[ $field ] ?? null ) || $upload[ $field ] < 0 ) {
				throw new InvalidArgumentException( 'Submission ZIP mapping receipt count is invalid.' );
			}
		}
		if (
			'mapped' !== ( $upload['state'] ?? null )
			|| ! is_int( $upload['mapping_version'] ?? null )
			|| $upload['mapping_version'] < 2
			|| $upload['item_total'] !== $upload['mapped_total'] + $upload['excluded_total'] + $upload['imported_total']
			|| $upload['mapped_total'] + $upload['imported_total'] < 1
		) {
			throw new InvalidArgumentException( 'Submission ZIP mapping receipt state is invalid.' );
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
			throw new InvalidArgumentException( 'Submission ZIP mapping receipt items are invalid.' );
		}
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Submission ZIP mapping receipt item is invalid.' );
			}
			$this->exact_keys( $item, array( 'error_code', 'item_no', 'selection', 'state' ) );
			if ( $index + 1 !== ( $item['item_no'] ?? null ) || ! in_array( $item['state'] ?? null, array( 'mapped', 'excluded', 'imported' ), true ) ) {
				throw new InvalidArgumentException( 'Submission ZIP mapping receipt item state is invalid.' );
			}
			if ( 'excluded' === $item['state'] ) {
				if ( null !== $item['selection'] || ! is_string( $item['error_code'] ?? null ) || 1 !== preg_match( '/^zip_[a-z0-9_]{2,95}$/D', $item['error_code'] ) ) {
					throw new InvalidArgumentException( 'Excluded ZIP mapping receipt item is invalid.' );
				}
				continue;
			}
			if ( null !== $item['error_code'] || ! is_array( $item['selection'] ?? null ) || array_is_list( $item['selection'] ) ) {
				throw new InvalidArgumentException( 'Mapped ZIP receipt selection is invalid.' );
			}
			$this->selection( $item['selection'] );
		}
	}

	/**
	 * Selection.
	 *
	 * @param array<string,mixed> $selection Selection.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function selection( array $selection ): void {
		$this->exact_keys( $selection, array( 'learner_user_id', 'response_id', 'target_id' ) );
		if ( ! is_int( $selection['learner_user_id'] ?? null ) || $selection['learner_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping receipt learner is invalid.' );
		}
		new Public_Id( is_string( $selection['target_id'] ?? null ) ? $selection['target_id'] : '' );
		new Public_Id( is_string( $selection['response_id'] ?? null ) ? $selection['response_id'] : '' );
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
			throw new InvalidArgumentException( 'Submission ZIP mapping receipt keys are invalid.' );
		}
	}
}
