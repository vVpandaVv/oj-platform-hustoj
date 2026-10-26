<?php
/**
 * Curriculum catalog application failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Curriculum\Catalog\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Curriculum catalog application failure.
 */
final class Curriculum_Catalog_Failure extends RuntimeException {
	public const VALIDATION_FAILED    = 'curriculum_catalog_validation_failed';
	public const NOT_FOUND            = 'curriculum_catalog_not_found';
	public const STATE_CONFLICT       = 'curriculum_catalog_state_conflict';
	public const IDEMPOTENCY_CONFLICT = 'curriculum_catalog_idempotency_conflict';
	public const STORED_DATA_INVALID  = 'curriculum_catalog_stored_data_invalid';
	public const STORAGE_UNAVAILABLE  = 'curriculum_catalog_storage_unavailable';

	/**
	 * Create a domain failure carrying the specified reason.
	 *
	 * @param string $reason Reason.
	 * @return self
	 */
	public static function because( string $reason ): self {
		return new self( $reason );
	}

	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->getMessage();
	}
}
