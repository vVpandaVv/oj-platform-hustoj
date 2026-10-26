<?php
/**
 * Stable worksheet template import failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable worksheet template import failure.
 */
final class Worksheet_Template_Import_Failure extends RuntimeException {
	public const INVALID_UPLOAD          = 'worksheet_template_upload_invalid';
	public const LIMIT_EXCEEDED          = 'worksheet_template_limit_exceeded';
	public const PACKAGE_INVALID         = 'worksheet_template_package_invalid';
	public const VERSION_UNSUPPORTED     = 'worksheet_template_version_unsupported';
	public const ENVIRONMENT_UNSUPPORTED = 'worksheet_template_environment_unsupported';
	public const STORAGE_UNAVAILABLE     = 'worksheet_template_storage_unavailable';

	/**
	 * Initialize worksheet template import failure dependencies and state.
	 *
	 * @param string $reason Reason.
	 */
	private function __construct( private string $reason ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Constructor promotion stores domain state and private construction enforces factory use; this override is not redundant.
		parent::__construct( $reason );
	}

	/**
	 * Invalid upload.
	 *
	 * @return self
	 */
	public static function invalid_upload(): self {
		return new self( self::INVALID_UPLOAD );
	}

	/**
	 * Limit exceeded.
	 *
	 * @return self
	 */
	public static function limit_exceeded(): self {
		return new self( self::LIMIT_EXCEEDED );
	}

	/**
	 * Package invalid.
	 *
	 * @return self
	 */
	public static function package_invalid(): self {
		return new self( self::PACKAGE_INVALID );
	}

	/**
	 * Version unsupported.
	 *
	 * @return self
	 */
	public static function version_unsupported(): self {
		return new self( self::VERSION_UNSUPPORTED );
	}

	/**
	 * Environment unsupported.
	 *
	 * @return self
	 */
	public static function environment_unsupported(): self {
		return new self( self::ENVIRONMENT_UNSUPPORTED );
	}

	/**
	 * Storage unavailable.
	 *
	 * @return self
	 */
	public static function storage_unavailable(): self {
		return new self( self::STORAGE_UNAVAILABLE );
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
