<?php
/**
 * Worksheet package writer port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPackage\Port;

use OJAI\Dashboard\Reporting\WorksheetPackage\Application\Worksheet_Package_Source;
use OJAI\Dashboard\Reporting\WorksheetPackage\Domain\Worksheet_Package_Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Worksheet package writer port interface.
 */
interface Worksheet_Package_Writer_Port_Interface {
	/**
	 * Write.
	 *
	 * @param Worksheet_Package_Source $source Source.
	 * @return Worksheet_Package_Archive
	 */
	public function write( Worksheet_Package_Source $source ): Worksheet_Package_Archive;
}
