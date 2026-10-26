<?php
/**
 * Render criteria first workflow.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
ob_start();
define( 'OJ_AI_DASHBOARD_URL', '/' );
require dirname( __DIR__ ) . '/Contract/worksheet-workbench-s08.php';
ob_end_clean();
if ( 'legacy' === ( $argv[1] ?? '' ) ) {
	$route     = \OJAI\Dashboard\Platform\Addon_Manifest::CONSOLE_WORKSHEET_EDIT;
	$edit_body = ( new \OJAI\Dashboard\Platform\Worksheet_Workbench_Page_Handler( new S08Policy(), $route, 'edit' ) )->handle(
		new \CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1( $route, 31, array( 'worksheet_id' => $worksheet_id ), array(), 'ko_KR', 'legacy-entry' )
	)->body();
}
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Browser fixture intentionally renders escaped worksheet editor HTML.
echo $edit_body;
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
