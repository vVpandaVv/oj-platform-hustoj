<?php
/**
 * Stable C30 assignment evaluation failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries only public recovery evidence for a batch-level failure.
 */
final class Assignment_Evaluation_Failure extends RuntimeException {
	public const NOT_FOUND            = 'assignment_evaluation_not_found';
	public const SOURCE_STALE         = 'assignment_evaluation_source_stale';
	public const CUTOFF_INVALID       = 'assignment_evaluation_cutoff_invalid';
	public const IDEMPOTENCY_CONFLICT = 'assignment_evaluation_idempotency_conflict';
	public const STORAGE_UNAVAILABLE  = 'assignment_evaluation_storage_unavailable';
	public const STORED_DATA_INVALID  = 'assignment_evaluation_stored_data_invalid';

	/**
	 * Safe context.
	 *
	 * @var array<string,int|string>
	 */
	private array $safe_context;

	/**
	 * Initialize assignment evaluation failure dependencies and state.
	 *
	 * @param string                   $reason Reason.
	 * @param array<string,int|string> $safe_context Safe context.
	 */
	private function __construct( private string $reason, array $safe_context = array() ) {
		parent::__construct( $reason );
		$this->safe_context = $safe_context;
	}

	/**
	 * Create a domain failure carrying the specified reason.
	 *
	 * @param string                   $reason Reason.
	 * @param array<string,int|string> $safe_context Safe context.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function because( string $reason, array $safe_context = array() ): self {
		if ( ! in_array( $reason, self::reasons(), true ) ) {
			throw new InvalidArgumentException( 'Unknown assignment evaluation failure reason.' );
		}
		return new self( $reason, $safe_context );
	}

	/**
	 * Reasons.
	 *
	 * @return array<int,string>
	 */
	public static function reasons(): array {
		return array(
			self::NOT_FOUND,
			self::SOURCE_STALE,
			self::CUTOFF_INVALID,
			self::IDEMPOTENCY_CONFLICT,
			self::STORAGE_UNAVAILABLE,
			self::STORED_DATA_INVALID,
		);
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
	 * @return array<string,int|string>
	 */
	public function safe_context(): array {
		return $this->safe_context;
	}
}
