<?php
/**
 * Legacy migration batch repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Batch_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item_Batch;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Running_Legacy_Migration_Run;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy migration batch repository port interface.
 */
interface Legacy_Migration_Batch_Repository_Port_Interface {
	/**
	 * Running run.
	 *
	 * @param string $run_id Run id.
	 * @return Running_Legacy_Migration_Run
	 */
	public function running_run( string $run_id ): Running_Legacy_Migration_Run;
	/**
	 * Commit batch.
	 *
	 * @param Running_Legacy_Migration_Run $run Run.
	 * @param Legacy_Source_Item_Batch     $batch Batch.
	 * @param string                       $processed_at_utc Processed at utc.
	 * @return Legacy_Migration_Batch_Result
	 */
	public function commit_batch( Running_Legacy_Migration_Run $run, Legacy_Source_Item_Batch $batch, string $processed_at_utc ): Legacy_Migration_Batch_Result;
}
