<?php
/**
 * Lazy WordPress adapter for the existing forward-only schema runner.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\SchemaRepair\Infrastructure;

use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Installation_Runner_Factory;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Repairer_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;
use OJAI\Dashboard\Database\Foundation\Schema_Repair_Authorization;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lazy WordPress adapter for the existing forward-only schema runner.
 */
final class WordPress_Schema_Repairer implements Schema_Repairer_Port_Interface {
	/**
	 * Initialize WordPress schema repairer dependencies and state.
	 *
	 * @param object|null $database Database.
	 */
	public function __construct( private ?object $database = null ) {
	}

	/**
	 * Repair.
	 *
	 * @param Schema_Repair_Authorization $authorization Authorization.
	 * @return Schema_Installation_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function repair( Schema_Repair_Authorization $authorization ): Schema_Installation_Result {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) ) {
			throw new RuntimeException( 'Schema repair database is unavailable.' );
		}

		return WordPress_Schema_Installation_Runner_Factory::create( $database )->repair( $authorization );
	}
}
