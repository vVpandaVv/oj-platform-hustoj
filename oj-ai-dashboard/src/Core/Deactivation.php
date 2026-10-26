<?php
/**
 * Plugin deactivation handler.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Core;

use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Migration_Worker_Module;
use OJAI\Dashboard\Operations\Purge\Infrastructure\Purge_Worker_Module;
use OJAI\Dashboard\Reporting\ReportExport\Infrastructure\Report_Export_Worker_Module;
use OJAI\Dashboard\Reporting\ResultPdf\Infrastructure\Result_Pdf_Worker_Module;
use OJAI\Dashboard\Reporting\SubmissionBundle\Infrastructure\Submission_Bundle_Worker_Module;
use OJAI\Dashboard\Reporting\WorksheetPackage\Infrastructure\Worksheet_Package_Worker_Module;
use OJAI\Dashboard\Reporting\SubmissionPdf\Infrastructure\Submission_Pdf_Worker_Module;
use OJAI\Dashboard\Reporting\WorksheetPdf\Infrastructure\Worksheet_Pdf_Worker_Module;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\Submission_Evaluation_Worker_Module;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\Submission_Feedback_Worker_Module;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\Student_Note_Worker_Module;
use OJAI\Dashboard\Submission\Student\Infrastructure\Auto_Submit_Due_Worker_Module;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Attachment_Cleanup_Worker_Module;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Attachment_Scan_Worker_Module;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\Submission_Zip_Cleanup_Worker_Module;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\Submission_Zip_Scan_Worker_Module;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\Worksheet_Evaluation_Worker_Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles deactivation tasks.
 */
final class Deactivation {
	/**
	 * Run deactivation tasks.
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'oj_ai_dashboard_auto_submit_due' );
		}
		Legacy_Migration_Worker_Module::clear();
		Purge_Worker_Module::clear();
		Submission_Attachment_Scan_Worker_Module::clear();
		Submission_Zip_Scan_Worker_Module::clear();
		Submission_Zip_Cleanup_Worker_Module::clear();
		\OJAI\Dashboard\Reporting\Export\Infrastructure\Export_Cleanup_Worker_Module::clear();
		Submission_Attachment_Cleanup_Worker_Module::clear();
		Auto_Submit_Due_Worker_Module::clear();
		Worksheet_Evaluation_Worker_Module::clear();
		\OJAI\Dashboard\ExternalAssessment\Assessment_Module::clear();
		Submission_Evaluation_Worker_Module::clear();
		Submission_Feedback_Worker_Module::clear();
		Student_Note_Worker_Module::clear();
		Report_Export_Worker_Module::clear();
		Submission_Bundle_Worker_Module::clear();
		Submission_Pdf_Worker_Module::clear();
		Result_Pdf_Worker_Module::clear();
		Worksheet_Pdf_Worker_Module::clear();
		Worksheet_Package_Worker_Module::clear();
	}
}
