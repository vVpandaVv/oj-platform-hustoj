<?php
/**
 * Stable worksheet evaluation worker failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable worksheet evaluation worker failure.
 */
final class Worksheet_Evaluation_Worker_Failure extends RuntimeException {
	public const STORAGE_UNAVAILABLE      = 'worksheet_evaluation_worker_storage_unavailable';
	public const INVALID_WORK_ITEM        = 'worksheet_evaluation_worker_item_invalid';
	public const INVALID_GATEWAY_RESPONSE = 'invalid_gateway_response';
	public const OUTPUT_TOO_LARGE         = 'output_too_large';
	public const OUTPUT_SCHEMA_INVALID    = 'output_schema_invalid';
	public const OUTPUT_DOMAIN_INVALID    = 'output_domain_invalid';
	public const OUTPUT_PRIVACY_INVALID   = 'output_privacy_invalid';

	/**
	 * Initialize worksheet evaluation worker failure dependencies and state.
	 *
	 * @param string      $reason Reason.
	 * @param string|null $context Access or execution context for this operation.
	 */
	private function __construct( private string $reason, private ?string $context ) {
		parent::__construct( $reason );
	}

	/**
	 * Create a domain failure carrying the specified reason.
	 *
	 * @param string      $reason Reason.
	 * @param string|null $context Access or execution context for this operation.
	 * @return self
	 */
	public static function because( string $reason, ?string $context = null ): self {
		return new self( $reason, $context );
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
	 * A safe, non-content diagnostic pointer (e.g. a JSON path into the
	 * provider's own output structure) — never the provider's raw text.
	 *
	 * @return string|null
	 */
	public function context(): ?string {
		return $this->context;
	}
}
