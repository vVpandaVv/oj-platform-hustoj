<?php
/**
 * Neutral teaching Context page.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Context;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preserves the opaque next cursor and add-on-observed exclusion count.
 */
final class Teaching_Context_Page {
	/**
	 * Items.
	 *
	 * @var array<int,Teaching_Context_Summary>
	 */
	private array $items;
	/**
	 * Next cursor.
	 *
	 * @var string
	 */
	private string $next_cursor;
	/**
	 * Excluded count.
	 *
	 * @var int
	 */
	private int $excluded_count;

	/**
	 * Initialize teaching context page dependencies and state.
	 *
	 * @param array<array-key,mixed> $items Unvalidated page items.
	 * @param string                 $next_cursor Next cursor.
	 * @param int                    $excluded_count Excluded count.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( array $items, string $next_cursor, int $excluded_count = 0 ) {
		if ( ! array_is_list( $items ) || count( $items ) > Teaching_Context_Query::MAXIMUM_LIMIT ) {
			throw new InvalidArgumentException( 'Teaching Context page items are invalid.' );
		}
		foreach ( $items as $item ) {
			if ( ! $item instanceof Teaching_Context_Summary ) {
				throw new InvalidArgumentException( 'Teaching Context page item type is invalid.' );
			}
		}
		Context_Value::text( $next_cursor, 'next_cursor', Teaching_Context_Query::MAXIMUM_CURSOR, true );
		if ( $excluded_count < 0 || $excluded_count > Teaching_Context_Query::MAXIMUM_LIMIT ) {
			throw new InvalidArgumentException( 'Teaching Context exclusion count is invalid.' );
		}

		$this->items          = $items;
		$this->next_cursor    = $next_cursor;
		$this->excluded_count = $excluded_count;
	}

	/**
	 * Items.
	 *
	 * @return array<int,Teaching_Context_Summary>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Next cursor.
	 *
	 * @return string
	 */
	public function next_cursor(): string {
		return $this->next_cursor;
	}

	/**
	 * Excluded count.
	 *
	 * @return int
	 */
	public function excluded_count(): int {
		return $this->excluded_count;
	}
}
