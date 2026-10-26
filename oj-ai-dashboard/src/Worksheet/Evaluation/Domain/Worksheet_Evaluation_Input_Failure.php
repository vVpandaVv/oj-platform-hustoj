<?php
/**
 * Worksheet evaluation input failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Domain;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries only stable codes and safe diagnostics across the P04 boundary.
 */
final class Worksheet_Evaluation_Input_Failure extends RuntimeException {
	public const INPUT_INVALID   = 'worksheet_evaluation_input_invalid';
	public const INPUT_TOO_LARGE = 'worksheet_evaluation_input_too_large';

	/**
	 * Safe context.
	 *
	 * @var array<string,int|string>
	 */
	private array $safe_context;

	/**
	 * Initialize worksheet evaluation input failure dependencies and state.
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
		parent::__construct( 'Worksheet evaluation input could not be prepared.' );
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
