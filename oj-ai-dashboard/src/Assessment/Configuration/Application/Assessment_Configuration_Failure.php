<?php
/**
 * Assessment configuration application failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Assessment\Configuration\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assessment configuration application failure.
 */
final class Assessment_Configuration_Failure extends RuntimeException {
	public const VALIDATION_FAILED    = 'assessment_configuration_validation_failed';
	public const NOT_FOUND            = 'assessment_configuration_not_found';
	public const STATE_CONFLICT       = 'assessment_configuration_state_conflict';
	public const IDEMPOTENCY_CONFLICT = 'assessment_configuration_idempotency_conflict';
	public const STORED_DATA_INVALID  = 'assessment_configuration_stored_data_invalid';
	public const STORAGE_UNAVAILABLE  = 'assessment_configuration_storage_unavailable';

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
