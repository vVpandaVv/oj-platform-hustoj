<?php
/**
 * Read-only schema ownership and migration ledger port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes only the two ledger reads required by Runtime verification.
 */
interface Schema_Ledger_Reader_Port_Interface {
	/**
	 * Find installation.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_installation(): ?array;

	/**
	 * All migrations.
	 *
	 * @return array<string,array<string,mixed>> Records keyed by migration ID.
	 */
	public function all_migrations(): array;
}
