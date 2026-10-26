<?php
/**
 * Append-only assignment roster storage.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Storage boundary for teacher-selected late joiners. */
interface Assignment_Target_Repository_Port_Interface {
	/**
	 * Read assignment and every existing target, including inactive ones.
	 *
	 * @param string $assignment_id Assignment public ID.
	 * @return array<string,mixed>|null
	 */
	public function target_snapshot( string $assignment_id ): ?array;

	/**
	 * Read an exact append receipt before collecting the current roster.
	 *
	 * @param string $key Scoped idempotency key.
	 * @param string $digest Canonical request digest.
	 * @return array<string,mixed>|null
	 */
	public function target_receipt( string $key, string $digest ): ?array;

	/**
	 * Append selected targets atomically without changing existing rows.
	 *
	 * @param array<string,mixed> $record Validated command and fresh platform evidence.
	 * @return array<string,mixed>
	 */
	public function add_targets( array $record ): array;
}
