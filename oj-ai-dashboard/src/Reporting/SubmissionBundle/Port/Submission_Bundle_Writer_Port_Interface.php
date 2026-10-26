<?php
/**
 * Submission bundle writer port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Port;

use OJAI\Dashboard\Reporting\Export\Domain\Generated_Export_File;
use OJAI\Dashboard\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission bundle writer port interface.
 */
interface Submission_Bundle_Writer_Port_Interface {
	/**
	 * Write.
	 *
	 * @param Submission_Bundle_Request_Snapshot $snapshot Snapshot.
	 * @return Generated_Export_File
	 */
	public function write( Submission_Bundle_Request_Snapshot $snapshot ): Generated_Export_File;
}
