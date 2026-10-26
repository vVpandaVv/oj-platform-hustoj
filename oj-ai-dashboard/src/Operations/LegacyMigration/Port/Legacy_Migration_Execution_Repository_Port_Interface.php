<?php
/**
 * Legacy migration execution repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Approved_Legacy_Migration_Run;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy migration execution repository port interface.
 */
interface Legacy_Migration_Execution_Repository_Port_Interface {
	/**
	 * Started receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Execute_Legacy_Migration_Result|null
	 */
	public function started_receipt( string $storage_key, string $request_digest ): ?Execute_Legacy_Migration_Result;
	/**
	 * Approved run.
	 *
	 * @param string $run_id Run id.
	 * @return Approved_Legacy_Migration_Run
	 */
	public function approved_run( string $run_id ): Approved_Legacy_Migration_Run;
	/**
	 * Start.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Execute_Legacy_Migration_Result
	 */
	public function start( array $record ): Execute_Legacy_Migration_Result;
}
