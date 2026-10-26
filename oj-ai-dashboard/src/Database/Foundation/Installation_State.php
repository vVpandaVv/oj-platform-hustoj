<?php
/**
 * Add-on installation states.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Separates schema installation from platform registration and Runtime readiness.
 */
final class Installation_State {
	public const INSTALLING           = 'installing';
	public const REGISTRATION_PENDING = 'registration_pending';
	public const RUNTIME_READY        = 'runtime_ready';
	public const MAINTENANCE_REQUIRED = 'maintenance_required';
	public const BLOCKED              = 'blocked';

	/**
	 * All.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array(
			self::INSTALLING,
			self::REGISTRATION_PENDING,
			self::RUNTIME_READY,
			self::MAINTENANCE_REQUIRED,
			self::BLOCKED,
		);
	}
}
