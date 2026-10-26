<?php
/**
 * Score download uses the same staff access boundary as assessment details.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-feedback-workbench-g4-imp-04-s04.php';
ob_end_clean();

$policy = new G4_S04_Feedback_UI_Policy();
foreach ( array( array(), array( 'submission.evaluation.review' ), array( 'submission.evaluation.list' ) ) as $denied_actions ) {
	$policy->denied_actions = $denied_actions;
	$html                   = (string) g4_s04_feedback_ui_page( $policy, 'score-download' )->body();
	g4_s04_feedback_ui_assert( str_contains( $html, 'data-review-action="download-scores" hidden disabled' ), 'Authorized staff can discover score download after loading a distribution.' );
	g4_s04_feedback_ui_assert( str_contains( $html, 'data-review-role="score-download-dialog"' ), 'Score scope dialog is required.' );
	g4_s04_feedback_ui_assert( str_contains( $html, '교사 확정 점수만' ), 'Official scores must be independently selectable.' );
}
$policy->denied_actions = array( 'submission.evaluation.list', 'submission.evaluation.review' );
$html                   = (string) g4_s04_feedback_ui_page( $policy, 'score-download-denied' )->body();
g4_s04_feedback_ui_assert( ! str_contains( $html, 'data-review-action="download-scores"' ), 'Unauthorized download action must not render.' );
fwrite( STDOUT, "Score download page contracts passed.\n" );
