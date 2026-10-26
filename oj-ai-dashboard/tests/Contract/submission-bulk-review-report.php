<?php
/**
 * Bulk review/report page authorization and fixture rendering.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-feedback-workbench-g4-imp-04-s04.php';
ob_end_clean();

$policy = new G4_S04_Feedback_UI_Policy();
$html   = (string) g4_s04_feedback_ui_page( $policy, 'bulk-review-report' )->body();
foreach ( array( 'accept', 'feedback', 'note' ) as $action ) {
	g4_s04_feedback_ui_assert( str_contains( $html, 'data-workflow-bulk="' . $action . '" hidden disabled' ), 'Bulk action is missing or enabled before scope load.' );
}
g4_s04_feedback_ui_assert( str_contains( $html, 'data-review-role="workflow-dialog"' ), 'Confirmation must be discoverable.' );

$policy->denied_actions = array( 'submission.evaluation.review' );
$denied                 = (string) g4_s04_feedback_ui_page( $policy, 'bulk-review-denied' )->body();
g4_s04_feedback_ui_assert( ! str_contains( $denied, 'data-workflow-bulk="accept"' ), 'Denied score approval must not render.' );
g4_s04_feedback_ui_assert( ! str_contains( $denied, 'data-workflow-bulk="feedback"' ), 'Denied feedback request must not render.' );
$policy->denied_actions = array( 'submission.student_note.manage' );
$denied                 = (string) g4_s04_feedback_ui_page( $policy, 'bulk-note-denied' )->body();
g4_s04_feedback_ui_assert( ! str_contains( $denied, 'data-workflow-bulk="note"' ), 'Denied special-note request must not render.' );

if ( '1' === getenv( 'OJAI_BULK_FIXTURE' ) ) {
	// This CLI-only fixture emits the production page, never a web response.
	fwrite( STDOUT, $html );
} else {
	fwrite( STDOUT, "Bulk review/report page contracts passed.\n" );
}
