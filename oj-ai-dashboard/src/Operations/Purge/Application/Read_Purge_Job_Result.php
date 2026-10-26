<?php
/**
 * Read purge job result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe Q36 purge-job status projection.
 */
final class Read_Purge_Job_Result {
	/**
	 * Initialize purge job result dependencies and state.
	 *
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private array $payload ) {
		$expected = array(
			'id',
			'state',
			'preview',
			'approval',
			'checkpoint',
			'legal_hold',
			'error',
			'created_at_utc',
			'updated_at_utc',
			'completed_at_utc',
		);
		$actual   = array_keys( $payload );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected || ! is_string( $payload['id'] ?? null ) || ! Public_Id::is_valid( $payload['id'] ) ) {
			throw new InvalidArgumentException( 'Purge job result is invalid.' );
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
