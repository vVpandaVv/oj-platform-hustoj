<?php
/**
 * Legacy source item batch.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A bounded source batch plus the exact restart cursor after its last item.
 */
final class Legacy_Source_Item_Batch {
	/**
	 * Items.
	 *
	 * @var array<int,Legacy_Source_Item>
	 */
	private array $items;

	/**
	 * Initialize legacy source item batch dependencies and state.
	 *
	 * @param array<array-key,mixed> $items Source items to validate.
	 * @param int                    $next_table_index Next table index.
	 * @param int                    $next_after_source_id Next after source id.
	 * @param int                    $next_item_no Next item no.
	 * @param bool                   $exhausted Exhausted.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		array $items,
		private int $next_table_index,
		private int $next_after_source_id,
		private int $next_item_no,
		private bool $exhausted
	) {
		if (
			count( $items ) > 250
			|| $next_table_index < 0
			|| $next_after_source_id < 0
			|| $next_item_no < 1
		) {
			throw new InvalidArgumentException( 'Legacy source item batch cursor is invalid.' );
		}
		$seen = array();
		foreach ( $items as $item ) {
			if ( ! $item instanceof Legacy_Source_Item ) {
				throw new InvalidArgumentException( 'Legacy source item batch contains an invalid item.' );
			}
			$key = $item->source_type() . ':' . $item->source_key();
			if ( isset( $seen[ $key ] ) ) {
				throw new InvalidArgumentException( 'Legacy source item batch contains a duplicate source key.' );
			}
			$seen[ $key ] = true;
		}
		$this->items = array_values( $items );
	}

	/**
	 * Items.
	 *
	 * @return array<int,Legacy_Source_Item>
	 */
	public function items(): array {
		return $this->items;
	}
	/**
	 * Count.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->items );
	}
	/**
	 * Next table index.
	 *
	 * @return int
	 */
	public function next_table_index(): int {
		return $this->next_table_index;
	}
	/**
	 * Next after source id.
	 *
	 * @return int
	 */
	public function next_after_source_id(): int {
		return $this->next_after_source_id;
	}
	/**
	 * Next item no.
	 *
	 * @return int
	 */
	public function next_item_no(): int {
		return $this->next_item_no;
	}
	/**
	 * Exhausted.
	 *
	 * @return bool
	 */
	public function exhausted(): bool {
		return $this->exhausted;
	}
}
