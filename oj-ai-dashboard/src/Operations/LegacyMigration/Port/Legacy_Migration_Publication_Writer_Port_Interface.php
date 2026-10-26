<?php
/**
 * Legacy migration publication writer port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Publication;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy migration publication writer port interface.
 */
interface Legacy_Migration_Publication_Writer_Port_Interface {
	/**
	 * Ensures the historical-only publication inside the caller-owned assignment transaction.
	 * This method does not create an import item receipt or commit a transaction.
	 *
	 * @param int    $run_database_id Run database id.
	 * @param int    $template_source_id Template source id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $published_at_utc Published at utc.
	 * @return Legacy_Migration_Publication
	 */
	public function ensure_for_assignment(
		int $run_database_id,
		int $template_source_id,
		int $actor_user_id,
		string $published_at_utc
	): Legacy_Migration_Publication;
}
