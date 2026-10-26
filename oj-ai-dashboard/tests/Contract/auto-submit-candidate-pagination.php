<?php
/**
 * Failed early candidates must not starve later drafts.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
require __DIR__ . '/student-result-navigation.php';

use OJAI\Dashboard\Submission\Student\Domain\Auto_Submit_Assignment_Work_Item;
use OJAI\Dashboard\Submission\Student\Infrastructure\WordPress_Auto_Submit_Assignment_Repository;

$pagination_db = new Student_Result_Navigation_Database();
for ( $number = 1; $number <= 205; ++$number ) {
	$pagination_db->insert(
		'oj_ai_assignment_targets',
		array(
			'id'              => $number,
			'assignment_id'   => 90,
			'learner_user_id' => $number,
			'state'           => 'active',
		)
	);
	$pagination_db->insert(
		'oj_ai_submissions',
		array(
			'id'                         => $number,
			'public_id'                  => $id( $number ),
			'assignment_id'              => 90,
			'learner_user_id'            => $number,
			'state'                      => 'draft',
			'lock_version'               => 0,
			'current_revision_public_id' => null,
		)
	);
}
$repository = new WordPress_Auto_Submit_Assignment_Repository( $pagination_db, $canonical );
$work       = static fn ( int $cursor = 0 ): Auto_Submit_Assignment_Work_Item => new Auto_Submit_Assignment_Work_Item( 1, 1, 90, $id( 90 ), 1, 'class', 'test', 'r1', 1, str_repeat( 'a', 64 ), '2026-09-08 10:00:00', '2026-09-08 10:00:00', 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', $cursor );
$item       = $work();
g3_same( 200, count( $repository->candidates( $item, 200 ) ), 'First batch must respect the frozen bound.' );
g3_same( 200, $item->candidate_cursor(), 'Cursor must advance despite candidates remaining drafts.' );
$restarted = $work( $item->candidate_cursor() );
$later     = $repository->candidates( $restarted, 200 );
g3_same( 5, count( $later ), 'A restarted work item must reach candidates after the first 200 failures.' );
g3_same( $id( 201 ), $later[0]['submission_id'], 'Later drafts must not be starved.' );
g3_same( array(), $repository->candidates( $restarted, 200 ), 'End of scan must be detected.' );
g3_same( 0, $restarted->candidate_cursor(), 'A new sweep must retry previously failed drafts.' );
g3_same( 200, count( $repository->candidates( $restarted, 200 ) ), 'Failures must remain eligible on the next sweep.' );
echo "Auto-submit candidate pagination passed: 205 drafts, restart cursor, no starvation, bounded retry sweep.\n";
