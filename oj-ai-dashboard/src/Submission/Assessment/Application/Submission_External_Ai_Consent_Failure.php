<?php
/**
 * Student submission external AI consent operation failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student submission external AI consent operation failure.
 */
final class Submission_External_Ai_Consent_Failure extends RuntimeException {
	public const NOT_ROUTABLE         = 'submission_external_ai_not_routable';
	public const ROUTING_CHANGED      = 'submission_external_ai_routing_changed';
	public const STATE_CONFLICT       = 'submission_external_ai_consent_state_conflict';
	public const IDEMPOTENCY_CONFLICT = 'submission_external_ai_consent_idempotency_conflict';
	public const STORED_DATA_INVALID  = 'submission_external_ai_consent_stored_data_invalid';
	public const STORAGE_UNAVAILABLE  = 'submission_external_ai_consent_storage_unavailable';
	public const LOCK_UNAVAILABLE     = 'submission_external_ai_consent_busy';

	/**
	 * Initialize submission external ai consent failure dependencies and state.
	 *
	 * @param string $reason Reason.
	 */
	private function __construct( private string $reason ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Constructor promotion stores domain state and private construction enforces factory use; this override is not redundant.
		parent::__construct( $reason );
	}

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
		return $this->reason;
	}
}
