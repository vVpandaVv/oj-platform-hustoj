<?php
/**
 * Report export writer port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Port;

use OJAI\Dashboard\Reporting\Export\Domain\Generated_Export_File;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report export writer port interface.
 */
interface Report_Export_Writer_Port_Interface {
	/**
	 * Write.
	 *
	 * @param Report_Export_Request_Snapshot $snapshot Snapshot.
	 * @param string                         $source_manifest_json Source manifest json.
	 * @return Generated_Export_File
	 */
	public function write( Report_Export_Request_Snapshot $snapshot, string $source_manifest_json ): Generated_Export_File;
}
