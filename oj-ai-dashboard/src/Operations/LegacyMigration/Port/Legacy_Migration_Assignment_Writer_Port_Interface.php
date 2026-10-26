<?php
/**
 * D3-D4 migration-only assignment aggregate writer port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * D3-D4 migration-only assignment aggregate writer port.
 */
interface Legacy_Migration_Assignment_Writer_Port_Interface {
	/**
	 * Joins the caller transaction and never commits an import item receipt itself.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 */
	public function write(
		int $run_database_id,
		Legacy_Source_Item $item,
		int $actor_user_id,
		string $created_at_utc
	): Legacy_Target_Write_Result;
}
