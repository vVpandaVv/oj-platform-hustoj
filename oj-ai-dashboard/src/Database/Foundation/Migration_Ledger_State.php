<?php
/**
 * Immutable migration ledger states.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the only legal state transitions for schema migration records.
 */
final class Migration_Ledger_State {
	public const PLANNED         = 'planned';
	public const RUNNING         = 'running';
	public const APPLIED         = 'applied';
	public const REPAIR_REQUIRED = 'repair_required';
	public const BLOCKED         = 'blocked';

	/**
	 * All.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array(
			self::PLANNED,
			self::RUNNING,
			self::APPLIED,
			self::REPAIR_REQUIRED,
			self::BLOCKED,
		);
	}

	/**
	 * Can transition.
	 *
	 * @param string $from From.
	 * @param string $to To.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to ): bool {
		$transitions = array(
			self::PLANNED         => array( self::RUNNING, self::BLOCKED ),
			self::RUNNING         => array( self::APPLIED, self::REPAIR_REQUIRED, self::BLOCKED ),
			self::APPLIED         => array(),
			self::REPAIR_REQUIRED => array( self::RUNNING, self::BLOCKED ),
			self::BLOCKED         => array( self::PLANNED ),
		);

		return isset( $transitions[ $from ] ) && in_array( $to, $transitions[ $from ], true );
	}
}
