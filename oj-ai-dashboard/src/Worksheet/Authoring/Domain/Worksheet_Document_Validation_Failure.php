<?php
/**
 * Stable worksheet document validation failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries a stable reason while retaining the document path for diagnostics.
 */
final class Worksheet_Document_Validation_Failure extends InvalidArgumentException {
	public const SCHEMA_UNSUPPORTED   = 'worksheet_schema_unsupported';
	public const NODE_NOT_ALLOWED     = 'worksheet_node_not_allowed';
	public const PROPERTY_NOT_ALLOWED = 'worksheet_property_not_allowed';
	public const ID_INVALID           = 'worksheet_id_invalid';
	public const LIMIT_EXCEEDED       = 'worksheet_limit_exceeded';

	/**
	 * Reason.
	 *
	 * @var string
	 */
	private string $reason;
	/**
	 * Diagnostic path.
	 *
	 * @var string
	 */
	private string $diagnostic_path;

	/**
	 * Diagnostic context.
	 *
	 * @var array<string,int|string>
	 */
	private array $diagnostic_context;

	/**
	 * Initialize worksheet document validation failure dependencies and state.
	 *
	 * @param string                   $reason Reason.
	 * @param string                   $path Path.
	 * @param array<string,int|string> $diagnostic_context Diagnostic context.
	 */
	private function __construct( string $reason, string $path, array $diagnostic_context = array() ) {
		parent::__construct( $reason . ' at ' . $path );
		$this->reason             = $reason;
		$this->diagnostic_path    = $path;
		$this->diagnostic_context = $diagnostic_context;
	}

	/**
	 * Schema unsupported.
	 *
	 * @param string $path Path.
	 * @return self
	 */
	public static function schema_unsupported( string $path ): self {
		return new self( self::SCHEMA_UNSUPPORTED, $path );
	}

	/**
	 * Node not allowed.
	 *
	 * @param string $path Path.
	 * @return self
	 */
	public static function node_not_allowed( string $path ): self {
		return new self( self::NODE_NOT_ALLOWED, $path );
	}

	/**
	 * Property not allowed.
	 *
	 * @param string $path Path.
	 * @return self
	 */
	public static function property_not_allowed( string $path ): self {
		return new self( self::PROPERTY_NOT_ALLOWED, $path );
	}

	/**
	 * Id invalid.
	 *
	 * @param string $path Path.
	 * @return self
	 */
	public static function id_invalid( string $path ): self {
		return new self( self::ID_INVALID, $path );
	}

	/**
	 * Limit exceeded.
	 *
	 * @param string $path Path.
	 * @param string $limit Maximum number of entries requested.
	 * @param int    $actual Actual.
	 * @param int    $maximum Maximum.
	 * @return self
	 */
	public static function limit_exceeded( string $path, string $limit, int $actual, int $maximum ): self {
		return new self(
			self::LIMIT_EXCEEDED,
			$path,
			array(
				'limit'   => $limit,
				'actual'  => $actual,
				'maximum' => $maximum,
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
	 * Diagnostic context.
	 *
	 * @return array<string,int|string>
	 */
	public function diagnostic_context(): array {
		return $this->diagnostic_context;
	}
}
