<?php
/**
 * Export control repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\Export\Port;

use OJAI\Dashboard\Reporting\Export\Application\Export_Control_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export control repository port interface.
 */
interface Export_Control_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @param string $operation Operation.
	 * @return Export_Control_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest, string $operation ): ?Export_Control_Result;
	/**
	 * Cancel.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Export_Control_Result
	 */
	public function cancel( array $record ): Export_Control_Result;
	/**
	 * Retry.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Export_Control_Result
	 */
	public function retry( array $record ): Export_Control_Result;
}
