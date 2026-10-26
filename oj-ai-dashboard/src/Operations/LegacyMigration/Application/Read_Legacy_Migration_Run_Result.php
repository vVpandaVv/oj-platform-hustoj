<?php
/**
 * Read legacy migration run result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe Q29 migration-run projection.
 */
final class Read_Legacy_Migration_Run_Result {
	/**
	 * Initialize legacy migration run result dependencies and state.
	 *
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private array $payload ) {
		$expected = array(
			'id',
			'state',
			'source',
			'plan_hash',
			'preview',
			'approval',
			'checkpoint',
			'items',
			'error',
			'created_at_utc',
			'updated_at_utc',
		);
		$actual   = array_keys( $payload );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected || ! is_string( $payload['id'] ?? null ) || ! Public_Id::is_valid( $payload['id'] ) ) {
			throw new InvalidArgumentException( 'Legacy migration run result is invalid.' );
		}
	}

	/**
	 * Payload.
	 *
	 * @return array<string,mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}
}
