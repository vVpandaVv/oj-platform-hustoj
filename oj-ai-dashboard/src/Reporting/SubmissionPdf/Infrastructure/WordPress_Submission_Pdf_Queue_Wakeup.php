<?php
/**
 * WordPress submission pdf queue wakeup.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Infrastructure;

use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Submission_Pdf_Queue_Wakeup_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress submission pdf queue wakeup.
 */
final class WordPress_Submission_Pdf_Queue_Wakeup implements Submission_Pdf_Queue_Wakeup_Port_Interface {
	public const HOOK = 'oj_ai_dashboard_submission_pdf_worker';

	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void {
		try {
			if (
				function_exists( 'wp_next_scheduled' )
				&& function_exists( 'wp_schedule_single_event' )
				&& false === wp_next_scheduled( self::HOOK )
			) {
				wp_schedule_single_event( time(), self::HOOK );
			}
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
		}
	}
}
