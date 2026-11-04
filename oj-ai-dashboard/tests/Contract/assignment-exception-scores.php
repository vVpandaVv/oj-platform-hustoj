<?php
/**
 * Post-close exception settings preserve immutable publications and confirmed grades.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/non-submission-grade.php';

$db->update( 'oj_ai_assignment_targets', array( 'state' => 'active' ), array( 'id' => 11 ) );
foreach ( array( 13, 14 ) as $learner ) {
	$db->seed(
		'oj_ai_assignment_targets',
		array(
			'id'              => $learner,
			'public_id'       => $id( $learner ),
			'assignment_id'   => 1,
			'learner_user_id' => $learner,
			'state'           => 'active',
		)
	);
}
$old_exception       = $run(
	array_replace(
		$input,
		array(
			'target_id' => $id( 13 ),
			'category'  => 'long_absence',
			'score'     => '8',
		)
	),
	'old-exception'
);
$targets_before      = $db->get_results( 'SELECT * FROM oj_ai_assignment_targets' );
$publications_before = $db->get_results( 'SELECT * FROM oj_ai_worksheet_publications' );
$submissions_before  = $db->get_results( 'SELECT * FROM oj_ai_submissions' );
$answers_before      = $db->get_results( 'SELECT * FROM oj_ai_submission_revisions' );
$policy_input        = array(
	'scores'           => array(
		'not_submitted' => '1',
		'long_absence'  => '6',
	),
	'reason'           => '종료 후 누락된 기준 보완',
	'expected_hash'    => null,
	'publication_hash' => $hash,
);
$configure           = fn( array $body, string $key, array $context = array() ) => $service->configure( 7, array() === $context ? $scope : $context, $body, $key, 'policy-contract' );
$reject_policy       = function ( array $body, string $key, array $context = array() ) use ( $configure, $db ): void {
	$before = array_map( fn( $table ) => $db->get_results( 'SELECT * FROM ' . $table ), array( 'oj_ai_assignments', 'oj_ai_assignment_targets', 'oj_ai_audit_events', 'oj_ai_outbox_events' ) );
	$failed = false;
	try {
		$configure( $body, $key, $context );
	} catch ( Throwable ) {
		$failed = true;
	}
	grade_assert( $failed, 'Invalid policy command succeeded: ' . $key );
	$after = array_map( fn( $table ) => $db->get_results( 'SELECT * FROM ' . $table ), array( 'oj_ai_assignments', 'oj_ai_assignment_targets', 'oj_ai_audit_events', 'oj_ai_outbox_events' ) );
	grade_assert( $before === $after, 'Rejected policy changed assignments, grades or audit.' );
};
foreach ( array( '-1', '20.0001', 'NaN', '1e1', '0.00001', '' ) as $invalid ) {
	$reject_policy(
		array_replace(
			$policy_input,
			array(
				'scores' => array(
					'not_submitted' => $invalid,
					'long_absence'  => null,
				),
			)
		),
		'invalid-' . $invalid
	);
}
$reject_policy( array_replace( $policy_input, array( 'reason' => '  ' ) ), 'missing-reason' );
$reject_policy( array_replace( $policy_input, array( 'publication_hash' => str_repeat( 'b', 64 ) ) ), 'foreign-publication' );
$reject_policy( $policy_input + array( 'actor_user_id' => 999 ), 'injected-actor' );
$reject_policy( $policy_input, 'foreign-context', array_replace( $scope, array( 'context_id' => 'group:999' ) ) );
foreach ( array( 'oj_ai_audit_events', 'oj_ai_outbox_events' ) as $table ) {
	$db->fail_table = $table;
	$reject_policy( $policy_input, 'rollback-' . $table );
	$db->fail_table = '';
}
$policy_one = $configure( $policy_input, 'policy-one' )['policy'];
grade_assert( 1 === $policy_one['revision'] && '6' === $policy_one['scores']['long_absence'], 'Closed assignment policy not saved.' );
$audit_count = count( $db->get_results( 'SELECT * FROM oj_ai_audit_events' ) );
grade_assert( $configure( $policy_input, 'policy-one' )['replayed'] && $audit_count === count( $db->get_results( 'SELECT * FROM oj_ai_audit_events' ) ), 'Policy replay duplicated audit.' );
$reject_policy( array_replace( $policy_input, array( 'reason' => '다른 사유' ) ), 'policy-one' );
$reject_policy( $policy_input, 'stale-policy' );
grade_assert( $targets_before === $db->get_results( 'SELECT * FROM oj_ai_assignment_targets' ), 'Policy save rewrote existing grades.' );
grade_assert( $canonical->encode( $old_exception['grade'] ) === $canonical->encode( $store->read( $scope )[ $id( 13 ) ]['grade'] ), 'Old v2 exception score no longer readable after policy update.' );
grade_assert( '8.50' === $store->read( $scope )[ $id( 11 ) ]['grade']['score'], 'Existing rubric score changed.' );
$new_grade_input = array_replace(
	$input,
	array(
		'target_id'             => $id( 14 ),
		'category'              => 'long_absence',
		'score'                 => '6',
		'exception_policy_hash' => $policy_one['hash'],
	)
);
$reject( array_replace( $new_grade_input, array( 'exception_policy_hash' => null ) ), 'stale-grade-policy' );
$new_grade = $run( $new_grade_input, 'new-policy-grade' )['grade'];
grade_assert( $canonical->encode( $new_grade['exception_policy'] ) === $canonical->encode( $policy_one ), 'Grade did not pin the applied policy snapshot.' );
$policy_two = $configure(
	array_replace(
		$policy_input,
		array(
			'scores'        => array(
				'not_submitted' => '0',
				'long_absence'  => '9',
			),
			'expected_hash' => $policy_one['hash'],
			'reason'        => '기준 보완 2차',
		)
	),
	'policy-two'
)['policy'];
grade_assert( $canonical->encode( $new_grade ) === $canonical->encode( $store->read( $scope )[ $id( 14 ) ]['grade'] ), 'New v3 grade changed when settings changed.' );
$reject(
	array_replace(
		$new_grade_input,
		array(
			'score'                 => '9',
			'expected_hash'         => $new_grade['hash'],
			'exception_policy_hash' => $policy_two['hash'],
		)
	),
	'cannot-edit-confirmed'
);
$withdrawn = $run(
	array_replace(
		$new_grade_input,
		array(
			'action'        => 'withdraw',
			'expected_hash' => $new_grade['hash'],
		)
	),
	'withdraw-old-policy-grade'
)['grade'];
grade_assert( 'withdrawn' === $withdrawn['status'] && '6' === $withdrawn['score'], 'Prior policy grade cannot be withdrawn.' );
$reject( array_replace( $new_grade_input, array( 'expected_hash' => $withdrawn['hash'] ) ), 'stale-after-withdrawal' );
$updated = $run(
	array_replace(
		$new_grade_input,
		array(
			'score'                 => '9',
			'expected_hash'         => $withdrawn['hash'],
			'exception_policy_hash' => $policy_two['hash'],
		)
	),
	'explicit-reconfirm'
)['grade'];
grade_assert( '9' === $updated['score'] && $canonical->encode( $updated['exception_policy'] ) === $canonical->encode( $policy_two ), 'Explicit reconfirmation failed.' );
$cleared = $configure(
	array_replace(
		$policy_input,
		array(
			'scores'        => array(
				'not_submitted' => null,
				'long_absence'  => null,
			),
			'expected_hash' => $policy_two['hash'],
			'reason'        => '선택 중지',
		)
	),
	'clear-policy'
)['policy'];
grade_assert( $canonical->encode( $updated ) === $canonical->encode( $store->read( $scope )[ $id( 14 ) ]['grade'] ), 'Clearing settings broke confirmed score.' );
grade_assert( $publications_before === $db->get_results( 'SELECT * FROM oj_ai_worksheet_publications' ) && $submissions_before === $db->get_results( 'SELECT * FROM oj_ai_submissions' ) && $answers_before === $db->get_results( 'SELECT * FROM oj_ai_submission_revisions' ), 'Policy operation changed immutable content.' );

// Another closed deployment starts with no exception settings in its publication.
$missing_payload = $payload;
unset( $missing_payload['rubric']['exception_scores'] );
$missing_profile = array(
	'schema_id'    => 'worksheet_grading',
	'payload'      => $missing_payload,
	'payload_hash' => hash( 'sha256', $canonical->encode( $missing_payload ) ),
);
$missing_json    = $canonical->encode( $missing_profile );
$db->seed(
	'oj_ai_worksheet_publications',
	array(
		'public_id'              => $id( 4 ),
		'content_hash'           => $hash,
		'publication_hash'       => $hash,
		'state'                  => 'active',
		'grading_schema_schema'  => 'worksheet_grading',
		'grading_schema_version' => 1,
		'grading_schema_json'    => $missing_json,
		'grading_schema_hash'    => hash( 'sha256', $missing_json ),
	)
);
$db->seed(
	'oj_ai_assignments',
	array(
		'id'                              => 3,
		'public_id'                       => $id( 3 ),
		'context_type'                    => 'group',
		'context_id'                      => 'group:10',
		'context_revision'                => 'revision-1',
		'worksheet_publication_public_id' => $id( 4 ),
		'publication_hash'                => $hash,
		'state'                           => 'closed',
	)
);
$db->seed(
	'oj_ai_assignment_targets',
	array(
		'id'              => 21,
		'public_id'       => $id( 21 ),
		'assignment_id'   => 3,
		'learner_user_id' => 21,
		'state'           => 'active',
	)
);
$other_scope = array_replace( $scope, array( 'assignment_id' => $id( 3 ) ) );
grade_assert( null === $store->read( $other_scope )[ $id( 21 ) ]['grading']['exception_scores']['not_submitted'], 'Missing policy invented a default.' );
$other_policy = $configure( $policy_input, 'new-deployment-policy', $other_scope )['policy'];
$other_grade  = $run(
	array_replace(
		$input,
		array(
			'target_id'             => $id( 21 ),
			'score'                 => '1',
			'exception_policy_hash' => $other_policy['hash'],
		)
	),
	'missing-now-configured',
	$other_scope
)['grade'];
grade_assert( '1' === $other_grade['score'], 'Missing exception score cannot be added after close.' );
grade_assert( null === $store->read( $scope )[ $id( 11 ) ]['grading']['exception_scores']['not_submitted'], 'Settings leaked across deployments.' );
foreach ( array( 'scheduled', 'withdrawn' ) as $state ) {
	$db->update( 'oj_ai_assignments', array( 'state' => $state ), array( 'id' => 1 ) );
	$reject_policy( array_replace( $policy_input, array( 'expected_hash' => $cleared['hash'] ) ), 'policy-state-' . $state );
}
$events      = $db->get_results( "SELECT metadata_json FROM oj_ai_audit_events WHERE action_key = 'assignment.exception_policy_saved' ORDER BY id" );
$last_change = json_decode( $events[1]['metadata_json'], true, 64, JSON_THROW_ON_ERROR );
grade_assert( $last_change['before']['hash'] === $policy_one['hash'] && $last_change['policy']['hash'] === $policy_two['hash'] && 7 === $last_change['policy']['actor_user_id'], 'Policy audit lost before/after, actor or revision.' );
echo "PASS exception policies: post-close additions, v1/v2/v3 grade preservation, snapshots, isolation, bounds, CAS, replay, withdrawal, rollback and immutable content.\n";
