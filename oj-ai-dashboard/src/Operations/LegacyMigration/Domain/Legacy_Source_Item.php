<?php
/**
 * Legacy source item.
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
 * One deterministic, in-memory source row. The record is never a REST projection.
 */
final class Legacy_Source_Item {
	/**
	 * Initialize legacy source item dependencies and state.
	 *
	 * @param int                    $item_no Item no.
	 * @param string                 $source_type Source type.
	 * @param int                    $source_id Source id.
	 * @param string                 $source_checksum Source checksum.
	 * @param array<array-key,mixed> $record Source record to validate.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $item_no,
		private string $source_type,
		private int $source_id,
		private string $source_checksum,
		private array $record
	) {
		if (
			$item_no < 1
			|| $source_id < 1
			|| 1 !== preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $source_type )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_checksum )
			|| array_is_list( $record )
			|| (string) ( $record['id'] ?? '' ) !== (string) $source_id
		) {
			throw new InvalidArgumentException( 'Legacy source item is invalid.' );
		}
	}

	/**
	 * Item no.
	 *
	 * @return int
	 */
	public function item_no(): int {
		return $this->item_no;
	}
	/**
	 * Source type.
	 *
	 * @return string
	 */
	public function source_type(): string {
		return $this->source_type;
	}
	/**
	 * Source id.
	 *
	 * @return int
	 */
	public function source_id(): int {
		return $this->source_id;
	}
	/**
	 * Source key.
	 *
	 * @return string
	 */
	public function source_key(): string {
		return (string) $this->source_id;
	}
	/**
	 * Source checksum.
	 *
	 * @return string
	 */
	public function source_checksum(): string {
		return $this->source_checksum;
	}
	/**
	 * Record.
	 *
	 * @return array<string,mixed>
	 */
	public function record(): array {
		return $this->record;
	}
}
