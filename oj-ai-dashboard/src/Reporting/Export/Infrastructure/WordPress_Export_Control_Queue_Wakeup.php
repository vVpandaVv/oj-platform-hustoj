<?php
/**
 * WordPress export control queue wakeup.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Export\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Export\Port\Export_Control_Queue_Wakeup_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes a retried export to the worker that already owns its artifact kind.
 */
final class WordPress_Export_Control_Queue_Wakeup implements Export_Control_Queue_Wakeup_Port_Interface {
	private const HOOKS = array(
		'worksheet_pdf'       => 'oj_ai_dashboard_worksheet_pdf_worker',
		'worksheet_package'   => 'oj_ai_dashboard_worksheet_package_worker',
		'submission_pdf'      => 'oj_ai_dashboard_submission_pdf_worker',
		'result_pdf'          => 'oj_ai_dashboard_result_pdf_worker',
		'report_csv'          => 'oj_ai_dashboard_report_export_worker',
		'report_pdf'          => 'oj_ai_dashboard_report_export_worker',
		'report_json_package' => 'oj_ai_dashboard_report_export_worker',
		'submission_bundle'   => 'oj_ai_dashboard_submission_bundle_worker',
	);

	/**
	 * Wake.
	 *
	 * @param string $artifact_kind Artifact kind.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function wake( string $artifact_kind ): void {
		$hook = self::HOOKS[ $artifact_kind ] ?? null;
		if ( ! is_string( $hook ) ) {
			throw new InvalidArgumentException( 'Export control artifact kind is unsupported.' );
		}
		try {
			if (
				function_exists( 'wp_next_scheduled' )
				&& function_exists( 'wp_schedule_single_event' )
				&& false === wp_next_scheduled( $hook )
			) {
				wp_schedule_single_event( time(), $hook );
			}
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			// The recurring sweep remains the durable fallback.
		}
	}
}
