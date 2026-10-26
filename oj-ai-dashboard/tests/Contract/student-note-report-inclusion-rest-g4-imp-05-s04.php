<?php
/**
 * G4-IMP-05-S04 C41/C42 REST and authorization contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/student-note-review-approval-rest-g4-imp-05-s03.php';
require_once __DIR__ . '/student-note-report-inclusion-g4-imp-05-s04.php';
ob_end_clean();

use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Submission\Assessment\Presentation\Student_Note_Report_Inclusion_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

/**
 * G4 note report rest body.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param string              $command_id Command identifier used for idempotency.
 * @return array<string,mixed>
 */
function g4_note_report_rest_body( array $snapshot, string $command_id ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_include_in_reports'   => $snapshot['latest_include_in_reports'],
			'expected_official_review_hash' => $snapshot['official_review_hash'],
			'expected_official_review_id'   => $snapshot['official_review_id'],
			'expected_revision_hash'        => $snapshot['latest_revision_hash'],
			'expected_revision_id'          => $snapshot['latest_revision_id'],
			'expected_revision_no'          => $snapshot['latest_revision_no'],
			'expected_state'                => 'approved',
		),
		'payload'    => array(),
	);
}

$report_rest_canonical            = new Canonical_Json();
$report_rest_source               = g4_note_source( $report_rest_canonical, 3 );
$report_rest_excluded             = g4_note_approved_snapshot( $report_rest_source, $report_rest_canonical, false );
$report_rest_include_repository   = new G4_Student_Note_Report_Inclusion_Repository( $report_rest_excluded );
$report_rest_include_policy       = new G4_Student_Note_Rest_Policy(
	Student_Note_Report_Inclusion_Rest_Controller::ACTION_INCLUDE,
	'include_in_reports',
	$report_rest_source['evaluation_id'],
	$report_rest_source['context_id'],
	$report_rest_source['context_revision'],
	$report_rest_excluded['latest_revision_hash'],
	'approved'
);
$report_rest_include_correlations = new Correlation_Id_Factory( static fn (): string => 'correlation-g4-note-include-rest' );
$report_rest_include_envelopes    = new Rest_Envelope_Factory();
$report_rest_include_controller   = new Student_Note_Report_Inclusion_Rest_Controller(
	g4_note_revision_rest_permissions( $report_rest_include_policy, $report_rest_include_correlations, $report_rest_include_envelopes ),
	g4_note_report_application( $report_rest_source, $report_rest_include_repository, $report_rest_canonical, '2026-08-24 21:00:00' ),
	$report_rest_canonical,
	$report_rest_include_envelopes,
	$report_rest_include_correlations
);
$report_rest_include_controller->register_routes();
$report_rest_include_controller->register_routes();
g4_note_same( 4, count( $GLOBALS['g4_note_revision_rest_routes'] ), 'C41/C42 controller must register exactly two routes once beside C39/C40.' );
g4_note_same( Student_Note_Report_Inclusion_Rest_Controller::ROUTE_INCLUDE, $GLOBALS['g4_note_revision_rest_routes'][2][1], 'C41 route drifted.' );
g4_note_same( Student_Note_Report_Inclusion_Rest_Controller::ROUTE_EXCLUDE, $GLOBALS['g4_note_revision_rest_routes'][3][1], 'C42 route drifted.' );

$report_rest_include_body    = g4_note_report_rest_body( $report_rest_excluded, 'command-g4-note-include-rest' );
$report_rest_include_request = new G4_Student_Note_Rest_Request( $report_rest_include_body, $report_rest_source['evaluation_id'], g4_note_revision_rest_context( $report_rest_source ), 'idempotency-g4-note-include-rest' );
g4_note_same( true, $report_rest_include_controller->permissions_include( $report_rest_include_request ), 'C41 permission must use the common REST handoff.' );
$report_rest_include_response = $report_rest_include_controller->include( $report_rest_include_request );
g4_note_assert( $report_rest_include_response instanceof WP_REST_Response, 'C41 success must return a REST response.' );
g4_note_same( 201, $report_rest_include_response->get_status(), 'New C41 revision must return 201.' );
g4_note_same( 'approved', $report_rest_include_response->get_data()['data']['note_revision']['state'], 'C41 response state drifted.' );
g4_note_same( true, $report_rest_include_response->get_data()['data']['note_revision']['include_in_reports'], 'C41 response must expose report inclusion.' );
g4_note_same( Student_Note_Report_Inclusion_Rest_Controller::ACTION_INCLUDE, $report_rest_include_policy->last_request?->action_key(), 'C41 policy action drifted.' );
g4_note_same( 'include_in_reports', $report_rest_include_policy->last_request?->object()?->action(), 'C41 object action drifted.' );

g4_note_same( true, $report_rest_include_controller->permissions_include( $report_rest_include_request ), 'Exact C41 retry must reauthorize.' );
$report_rest_include_replay = $report_rest_include_controller->include( $report_rest_include_request );
g4_note_same( true, $report_rest_include_replay->get_data()['meta']['idempotent_replay'], 'Exact C41 retry must use the durable receipt.' );
g4_note_same( 1, $report_rest_include_repository->append_count, 'Exact C41 retry must append no second revision.' );

$report_rest_include_invalid_body                    = $report_rest_include_body;
$report_rest_include_invalid_body['payload']['note'] = array();
$report_rest_include_invalid_request                 = new G4_Student_Note_Rest_Request( $report_rest_include_invalid_body, $report_rest_source['evaluation_id'], g4_note_revision_rest_context( $report_rest_source ), 'idempotency-g4-note-include-invalid' );
g4_note_same( true, $report_rest_include_controller->permissions_include( $report_rest_include_invalid_request ), 'C41 permission must remain independent of body parsing.' );
$report_rest_include_invalid = $report_rest_include_controller->include( $report_rest_include_invalid_request );
g4_note_assert( $report_rest_include_invalid instanceof WP_Error, 'Non-empty C41 payload must fail.' );
g4_note_same( 422, $report_rest_include_invalid->get_error_data()['status'], 'Non-empty C41 payload must return 422.' );

$report_rest_include_conflict_body    = g4_note_report_rest_body( $report_rest_excluded, 'command-g4-note-include-rest-conflict' );
$report_rest_include_conflict_request = new G4_Student_Note_Rest_Request( $report_rest_include_conflict_body, $report_rest_source['evaluation_id'], g4_note_revision_rest_context( $report_rest_source ), 'idempotency-g4-note-include-rest' );
g4_note_same( true, $report_rest_include_controller->permissions_include( $report_rest_include_conflict_request ), 'C41 conflict request must reauthorize.' );
$report_rest_include_conflict = $report_rest_include_controller->include( $report_rest_include_conflict_request );
g4_note_assert( $report_rest_include_conflict instanceof WP_Error, 'Changed C41 request under one idempotency key must fail.' );
g4_note_same( 409, $report_rest_include_conflict->get_error_data()['status'], 'C41 idempotency conflict must return 409.' );

$report_rest_included             = g4_note_approved_snapshot( $report_rest_source, $report_rest_canonical, true );
$report_rest_exclude_repository   = new G4_Student_Note_Report_Inclusion_Repository( $report_rest_included );
$report_rest_exclude_policy       = new G4_Student_Note_Rest_Policy(
	Student_Note_Report_Inclusion_Rest_Controller::ACTION_EXCLUDE,
	'exclude_from_reports',
	$report_rest_source['evaluation_id'],
	$report_rest_source['context_id'],
	$report_rest_source['context_revision'],
	$report_rest_included['latest_revision_hash'],
	'approved'
);
$report_rest_exclude_correlations = new Correlation_Id_Factory( static fn (): string => 'correlation-g4-note-exclude-rest' );
$report_rest_exclude_envelopes    = new Rest_Envelope_Factory();
$report_rest_exclude_controller   = new Student_Note_Report_Inclusion_Rest_Controller(
	g4_note_revision_rest_permissions( $report_rest_exclude_policy, $report_rest_exclude_correlations, $report_rest_exclude_envelopes ),
	g4_note_report_application( $report_rest_source, $report_rest_exclude_repository, $report_rest_canonical, '2026-08-24 21:01:00' ),
	$report_rest_canonical,
	$report_rest_exclude_envelopes,
	$report_rest_exclude_correlations
);
$report_rest_exclude_body         = g4_note_report_rest_body( $report_rest_included, 'command-g4-note-exclude-rest' );
$report_rest_exclude_request      = new G4_Student_Note_Rest_Request( $report_rest_exclude_body, $report_rest_source['evaluation_id'], g4_note_revision_rest_context( $report_rest_source ), 'idempotency-g4-note-exclude-rest' );
g4_note_same( true, $report_rest_exclude_controller->permissions_exclude( $report_rest_exclude_request ), 'C42 permission must use the common REST handoff.' );
$report_rest_exclude_response = $report_rest_exclude_controller->exclude( $report_rest_exclude_request );
g4_note_assert( $report_rest_exclude_response instanceof WP_REST_Response, 'C42 success must return a REST response.' );
g4_note_same( 201, $report_rest_exclude_response->get_status(), 'New C42 revision must return 201.' );
g4_note_same( false, $report_rest_exclude_response->get_data()['data']['note_revision']['include_in_reports'], 'C42 response must expose report exclusion.' );
g4_note_same( Student_Note_Report_Inclusion_Rest_Controller::ACTION_EXCLUDE, $report_rest_exclude_policy->last_request?->action_key(), 'C42 policy action drifted.' );
g4_note_same( 'exclude_from_reports', $report_rest_exclude_policy->last_request?->object()?->action(), 'C42 object action drifted.' );

$report_rest_wrong_expected = $report_rest_exclude_body;
$report_rest_wrong_expected['expected']['expected_include_in_reports'] = false;
$report_rest_wrong_request = new G4_Student_Note_Rest_Request( $report_rest_wrong_expected, $report_rest_source['evaluation_id'], g4_note_revision_rest_context( $report_rest_source ), 'idempotency-g4-note-exclude-wrong' );
g4_note_same( true, $report_rest_exclude_controller->permissions_exclude( $report_rest_wrong_request ), 'C42 permission must remain independent of body parsing.' );
$report_rest_wrong_response = $report_rest_exclude_controller->exclude( $report_rest_wrong_request );
g4_note_assert( $report_rest_wrong_response instanceof WP_Error, 'C42 no-op expected inclusion must fail.' );
g4_note_same( 422, $report_rest_wrong_response->get_error_data()['status'], 'C42 no-op expected inclusion must return 422.' );

$records_before_revoke            = $report_rest_exclude_repository->append_count;
$report_rest_exclude_policy->deny = true;
$report_rest_denied               = $report_rest_exclude_controller->permissions_exclude( $report_rest_exclude_request );
g4_note_assert( $report_rest_denied instanceof WP_Error, 'Permission revocation must deny C42 before execution.' );
g4_note_same( 403, $report_rest_denied->get_error_data()['status'], 'Revoked C42 permission must return 403.' );
g4_note_same( $records_before_revoke, $report_rest_exclude_repository->append_count, 'Revoked C42 permission must append zero revisions.' );
g4_note_assert( $GLOBALS['g4_note_revision_rest_nonce_calls'] >= 12, 'Every C41/C42 mutation permission check must verify the REST nonce.' );

fwrite( STDOUT, "G4-IMP-05-S04 C41/C42 REST, common authorization, exact empty DTO, replay, conflict, and revocation contracts passed.\n" );
