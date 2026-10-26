<?php
/**
 * Schema installation or repair result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns stable status and codes without leaking SQL or database errors.
 */
final class Schema_Installation_Result {
	public const INSTALLED        = 'installed';
	public const UPGRADED         = 'upgraded';
	public const ALREADY_READY    = 'already_ready';
	public const REPAIRED         = 'repaired';
	public const REPAIR_REQUIRED  = 'repair_required';
	public const BLOCKED          = 'blocked';
	public const LOCK_UNAVAILABLE = 'lock_unavailable';
	public const FAILED           = 'failed';

	/**
	 * Status.
	 *
	 * @var string
	 */
	private string $status;
	/**
	 * Code.
	 *
	 * @var string
	 */
	private string $code;
	/**
	 * Request correlation identifier for diagnostics.
	 *
	 * @var string
	 */
	private string $correlation_id;

	/**
	 * Completed tables.
	 *
	 * @var array<int,string>
	 */
	private array $completed_tables;

	/**
	 * Issues.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $issues;

	/**
	 * Initialize schema installation result dependencies and state.
	 *
	 * @param string                         $status Status.
	 * @param string                         $code Code.
	 * @param string                         $correlation_id Request correlation identifier for diagnostics.
	 * @param array<int,string>              $completed_tables Completed in this attempt.
	 * @param array<int,array<string,mixed>> $issues Stable diagnostics.
	 */
	public function __construct(
		string $status,
		string $code,
		string $correlation_id,
		array $completed_tables = array(),
		array $issues = array()
	) {
		$this->status           = $status;
		$this->code             = $code;
		$this->correlation_id   = $correlation_id;
		$this->completed_tables = array_values( $completed_tables );
		$this->issues           = $issues;
	}

	/**
	 * Status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Code.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Correlation id.
	 *
	 * @return string
	 */
	public function correlation_id(): string {
		return $this->correlation_id;
	}

	/**
	 * Succeeded.
	 *
	 * @return bool
	 */
	public function succeeded(): bool {
		return in_array( $this->status, array( self::INSTALLED, self::UPGRADED, self::ALREADY_READY, self::REPAIRED ), true );
	}

	/**
	 * Completed tables.
	 *
	 * @return array<int,string>
	 */
	public function completed_tables(): array {
		return $this->completed_tables;
	}

	/**
	 * Issues.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function issues(): array {
		return $this->issues;
	}
}
