<?php
/**
 * WordPress schema installation runner factory.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Infrastructure;

use OJAI\Dashboard\Database\Foundation\Migration_Plan;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Database\Foundation\Schema_Installation_Runner;
use OJAI\Dashboard\Database\Foundation\Schema_Postcondition_Verifier;
use OJAI\Dashboard\Database\Foundation\Schema_Preflight;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the complete schema installation graph from the WordPress DB adapter.
 */
final class WordPress_Schema_Installation_Runner_Factory {
	/**
	 * Create WordPress schema installation runner factory.
	 *
	 * @param object $database Database.
	 * @return Schema_Installation_Runner
	 */
	public static function create( object $database ): Schema_Installation_Runner {
		$catalog   = new Schema_Catalog();
		$inspector = new WordPress_Schema_Inspector( $database );

		return new Schema_Installation_Runner(
			$catalog,
			new Migration_Plan( $catalog ),
			new Schema_Preflight( $catalog ),
			$inspector,
			new WordPress_Schema_Lock( $database ),
			new WordPress_Schema_Ledger( $database ),
			new WordPress_Schema_Statement_Executor( $database ),
			new Schema_Postcondition_Verifier( $catalog, $inspector )
		);
	}
}
