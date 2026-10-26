<?php
/**
 * Database preflight result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable result returned before any schema lock or DDL is attempted.
 */
final class Schema_Preflight_Result {
	public const FRESH   = 'fresh';
	public const READY   = 'ready';
	public const BLOCKED = 'blocked';

	/**
	 * Status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Issues.
	 *
	 * @var array<int,array{code:string,message:string,table?:string}>
	 */
	private array $issues;

	/**
	 * Present tables.
	 *
	 * @var array<int,string>
	 */
	private array $present_tables;

	/**
	 * Missing tables.
	 *
	 * @var array<int,string>
	 */
	private array $missing_tables;

	/**
	 * Unexpected tables.
	 *
	 * @var array<int,string>
	 */
	private array $unexpected_tables;

	/**
	 * Initialize schema preflight result dependencies and state.
	 *
	 * @param string                                                     $status Result status.
	 * @param array<int,array{code:string,message:string,table?:string}> $issues Blocking issues.
	 * @param array<int,string>                                          $present_tables Present target tables.
	 * @param array<int,string>                                          $missing_tables Missing target tables.
	 * @param array<int,string>                                          $unexpected_tables Unknown literal tables.
	 */
	public function __construct(
		string $status,
		array $issues,
		array $present_tables,
		array $missing_tables,
		array $unexpected_tables
	) {
		$this->status            = $status;
		$this->issues            = $issues;
		$this->present_tables    = $present_tables;
		$this->missing_tables    = $missing_tables;
		$this->unexpected_tables = $unexpected_tables;
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
	 * Can install.
	 *
	 * @return bool
	 */
	public function can_install(): bool {
		return self::FRESH === $this->status;
	}

	/**
	 * Is ready.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		return self::READY === $this->status;
	}

	/**
	 * Is blocked.
	 *
	 * @return bool
	 */
	public function is_blocked(): bool {
		return self::BLOCKED === $this->status;
	}

	/**
	 * Issues.
	 *
	 * @return array<int,array{code:string,message:string,table?:string}>
	 */
	public function issues(): array {
		return $this->issues;
	}

	/**
	 * Present tables.
	 *
	 * @return array<int,string>
	 */
	public function present_tables(): array {
		return $this->present_tables;
	}

	/**
	 * Missing tables.
	 *
	 * @return array<int,string>
	 */
	public function missing_tables(): array {
		return $this->missing_tables;
	}

	/**
	 * Unexpected tables.
	 *
	 * @return array<int,string>
	 */
	public function unexpected_tables(): array {
		return $this->unexpected_tables;
	}
}
