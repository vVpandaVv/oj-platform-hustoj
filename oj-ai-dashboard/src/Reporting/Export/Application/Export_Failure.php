<?php
/**
 * Export failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\Export\Application;

use RuntimeException;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Export failure.
 */
final class Export_Failure extends RuntimeException {
	public const NOT_FOUND            = 'export_not_found';
	public const ACCESS_DENIED        = 'export_access_denied';
	public const EXPIRED              = 'export_expired';
	public const NOT_READY            = 'export_not_ready';
	public const STORAGE_UNAVAILABLE  = 'export_storage_unavailable';
	public const STORED_DATA_INVALID  = 'export_stored_data_invalid';
	public const IDEMPOTENCY_CONFLICT = 'export_idempotency_conflict';
	public const STATE_CONFLICT       = 'export_state_conflict';
	public const NOT_RETRYABLE        = 'export_not_retryable';
	/**
	 * Initialize export failure dependencies and state.
	 *
	 * @param string              $reason Reason.
	 * @param array<string,mixed> $safe_context Safe context.
	 */
	private function __construct( private string $reason, private array $safe_context ) {
		parent::__construct( $reason );
	}
	/**
	 * Create a domain failure carrying the specified reason.
	 *
	 * @param string              $reason Reason.
	 * @param array<string,mixed> $safe_context Safe context.
	 * @return self
	 */
	public static function because( string $reason, array $safe_context = array() ): self {
		return new self( $reason, $safe_context );
	}
	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
	/**
	 * Safe context.
	 *
	 * @return array<string,mixed>
	 */
	public function safe_context(): array {
		return $this->safe_context;
	}
}
