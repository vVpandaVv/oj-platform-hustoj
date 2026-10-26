<?php
/**
 * Submission score input failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries stable reason codes and non-sensitive diagnostics across the input boundary.
 */
final class Submission_Score_Input_Failure extends RuntimeException {
	public const INPUT_INVALID              = 'submission_evaluation_input_invalid';
	public const INPUT_TOO_LARGE            = 'submission_evaluation_input_too_large';
	public const SUBMISSION_NOT_OFFICIAL    = 'submission_not_official';
	public const GRADING_SCHEMA_UNSUPPORTED = 'grading_schema_unsupported';
	public const GRADING_SCHEMA_INVALID     = 'grading_schema_invalid';
	public const ARTIFACT_NOT_READY         = 'artifact_not_ready';

	/**
	 * Safe context.
	 *
	 * @var array<string,int|string>
	 */
	private array $safe_context;

	/**
	 * Initialize submission score input failure dependencies and state.
	 *
	 * @param string                   $reason Reason.
	 * @param string                   $diagnostic_path Diagnostic path.
	 * @param array<string,int|string> $safe_context Safe context.
	 */
	private function __construct(
		private string $reason,
		private string $diagnostic_path,
		array $safe_context = array()
	) {
		parent::__construct( 'Student submission score input could not be prepared.' );
		$this->safe_context = $safe_context;
	}

	/**
	 * Invalid.
	 *
	 * @param string $path Path.
	 * @return self
	 */
	public static function invalid( string $path ): self {
		return new self( self::INPUT_INVALID, $path );
	}

	/**
	 * Not official.
	 *
	 * @param string $path Path.
	 * @return self
	 */
	public static function not_official( string $path = '$.official_revision' ): self {
		return new self( self::SUBMISSION_NOT_OFFICIAL, $path );
	}

	/**
	 * Grading unsupported.
	 *
	 * @param string $path Path.
	 * @return self
	 */
	public static function grading_unsupported( string $path = '$.publication.grading_schema' ): self {
		return new self( self::GRADING_SCHEMA_UNSUPPORTED, $path );
	}

	/**
	 * Grading invalid.
	 *
	 * @param string $path Path.
	 * @return self
	 */
	public static function grading_invalid( string $path ): self {
		return new self( self::GRADING_SCHEMA_INVALID, $path );
	}

	/**
	 * Artifact not ready.
	 *
	 * @param string $path Path.
	 * @param string $state State.
	 * @return self
	 */
	public static function artifact_not_ready( string $path, string $state ): self {
		return new self( self::ARTIFACT_NOT_READY, $path, array( 'state' => $state ) );
	}

	/**
	 * Too large.
	 *
	 * @param int $actual_bytes Actual bytes.
	 * @param int $maximum_bytes Maximum bytes.
	 * @return self
	 */
	public static function too_large( int $actual_bytes, int $maximum_bytes ): self {
		return new self(
			self::INPUT_TOO_LARGE,
			'$',
			array(
				'actual_bytes'  => $actual_bytes,
				'maximum_bytes' => $maximum_bytes,
			)
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
	 * Diagnostic path.
	 *
	 * @return string
	 */
	public function diagnostic_path(): string {
		return $this->diagnostic_path;
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
