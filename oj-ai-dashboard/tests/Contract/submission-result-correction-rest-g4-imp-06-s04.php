<?php
/**
 * G4-IMP-06-S04 C43 correction REST and authorization contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-result-release-rest-g4-imp-06-s01.php';
ob_end_clean();

use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Current_Actor;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Nonce_Verifier;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Submission\Assessment\Presentation\Submission_Result_Release_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

/**
 * G4 result correction rest body.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string              $reason_code Reason code.
 * @param string|null         $reason_note Reason note.
 * @return array<string,mixed>
 */
function g4_result_correction_rest_body( array $snapshot, string $command_id, string $reason_code = 'data_correction', ?string $reason_note = null ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_current_release_hash'   => $snapshot['current_release_hash'],
			'expected_current_release_id'     => $snapshot['current_release_id'],
			'expected_feedback_revision_hash' => $snapshot['latest_feedback_revision_hash'],
			'expected_feedback_revision_id'   => $snapshot['latest_feedback_revision_id'],
			'expected_official_review_hash'   => $snapshot['official_review_hash'],
			'expected_official_review_id'     => $snapshot['official_review_id'],
		),
		'payload'    => array(
			'feedback_visibility' => 'shown',
			'mode'                => 'correction',
			'reason_code'         => $reason_code,
			'reason_note'         => $reason_note,
			'score_visibility'    => 'total_and_criteria',
		),
	);
}

$GLOBALS['g4_result_release_rest_routes']      = array();
$GLOBALS['g4_result_release_rest_nonce_calls'] = 0;
$correction_rest_canonical                     = new Canonical_Json();
$correction_rest_fixture                       = g4_release_fixture( $correction_rest_canonical );
$correction_rest_source                        = $correction_rest_fixture['source'];
$correction_rest_repository                    = new G4_Result_Release_Repository( $correction_rest_fixture['snapshot'] );
$correction_rest_application                   = g4_release_application( $correction_rest_source, $correction_rest_repository, $correction_rest_canonical, '2026-08-24 23:50:00' );
$correction_rest_application->execute( g4_release_command( $correction_rest_source, $correction_rest_repository->snapshot, $correction_rest_canonical, 'command-g4-correction-rest-first', str_repeat( 'e', 64 ) ) );

$correction_rest_policy       = new G4_Result_Release_Rest_Policy( $correction_rest_source['evaluation_id'], $correction_rest_source['context_id'], $correction_rest_source['context_revision'], (string) $correction_rest_source['evaluation_lock_version'] );
$correction_rest_correlations = new Correlation_Id_Factory( static fn (): string => 'correlation-g4-result-correction-rest' );
$correction_rest_envelopes    = new Rest_Envelope_Factory();
$correction_rest_permissions  = new Rest_Permission_Handoff( new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ), new Rest_Request_Authorizer( $correction_rest_policy, new WordPress_Rest_Nonce_Verifier(), $correction_rest_correlations ), $correction_rest_envelopes, $correction_rest_correlations );
$correction_rest_controller   = new Submission_Result_Release_Rest_Controller( $correction_rest_permissions, $correction_rest_application, $correction_rest_canonical, $correction_rest_envelopes, $correction_rest_correlations );
$correction_rest_controller->register_routes();
g4_release_same( 1, count( $GLOBALS['g4_result_release_rest_routes'] ), 'C43 correction must reuse the one frozen release route.' );

$correction_rest_body    = g4_result_correction_rest_body( $correction_rest_repository->snapshot, 'command-g4-result-correction-rest', 'data_correction', 'Teacher corrected the official result.' );
$correction_rest_request = new G4_Result_Release_Rest_Request( $correction_rest_body, $correction_rest_source['evaluation_id'], g4_result_release_rest_context( $correction_rest_source ), 'idempotency-g4-result-correction-rest' );
g4_release_same( true, $correction_rest_controller->permissions_release( $correction_rest_request ), 'C43 correction permission must use the existing release handoff.' );
$correction_rest_response = $correction_rest_controller->release( $correction_rest_request );
g4_release_assert( $correction_rest_response instanceof WP_REST_Response, 'C43 correction success must return a REST response.' );
g4_release_same( 201, $correction_rest_response->get_status(), 'New C43 correction must return 201.' );
$correction_rest_data = $correction_rest_response->get_data();
g4_release_same( 'corrected', $correction_rest_data['data']['release']['state'], 'C43 correction response state drifted.' );
g4_release_same( 2, $correction_rest_data['data']['release']['release_no'], 'C43 correction response number drifted.' );
$correction_rest_json = json_encode( $correction_rest_data, JSON_THROW_ON_ERROR );
foreach ( array( 'reason_code', 'reason_note', 'Teacher corrected the official result.', 'visible_score', 'overall_feedback', 'criterion_feedback', 'student_projection_json' ) as $forbidden ) {
	g4_release_assert( ! str_contains( $correction_rest_json, $forbidden ), 'C43 correction response leaked private content: ' . $forbidden );
}

g4_release_same( true, $correction_rest_controller->permissions_release( $correction_rest_request ), 'Exact correction retry must reauthorize.' );
$correction_rest_replay = $correction_rest_controller->release( $correction_rest_request );
g4_release_assert( $correction_rest_replay instanceof WP_REST_Response, 'Exact correction retry must return a REST response.' );
g4_release_same( true, $correction_rest_replay->get_data()['meta']['idempotent_replay'], 'Exact correction retry must use the durable receipt.' );
g4_release_same( 2, $correction_rest_repository->append_count, 'Exact correction retry must append no release.' );

$missing_reason_body = $correction_rest_body;
unset( $missing_reason_body['payload']['reason_code'], $missing_reason_body['payload']['reason_note'] );
$missing_reason_request = new G4_Result_Release_Rest_Request( $missing_reason_body, $correction_rest_source['evaluation_id'], g4_result_release_rest_context( $correction_rest_source ), 'idempotency-g4-result-correction-missing-reason' );
g4_release_same( true, $correction_rest_controller->permissions_release( $missing_reason_request ), 'Malformed correction body must not affect permission evaluation.' );
$missing_reason_response = $correction_rest_controller->release( $missing_reason_request );
g4_release_assert( $missing_reason_response instanceof WP_Error, 'Correction without reason fields must fail.' );
g4_release_same( 422, $missing_reason_response->get_error_data()['status'], 'Correction without reason fields must return 422.' );

$other_without_note         = g4_result_correction_rest_body( $correction_rest_repository->snapshot, 'command-g4-result-correction-other', 'other', null );
$other_without_note_request = new G4_Result_Release_Rest_Request( $other_without_note, $correction_rest_source['evaluation_id'], g4_result_release_rest_context( $correction_rest_source ), 'idempotency-g4-result-correction-other' );
g4_release_same( true, $correction_rest_controller->permissions_release( $other_without_note_request ), 'Other-reason correction must reauthorize.' );
$other_without_note_response = $correction_rest_controller->release( $other_without_note_request );
g4_release_assert( $other_without_note_response instanceof WP_Error, 'Correction reason other without note must fail.' );
g4_release_same( 422, $other_without_note_response->get_error_data()['status'], 'Correction reason other without note must return 422.' );

$stale_correction_body = g4_result_correction_rest_body( $correction_rest_repository->snapshot, 'command-g4-result-correction-stale', 'publication_error', null );
$stale_correction_body['expected']['expected_current_release_hash'] = str_repeat( 'f', 64 );
$stale_correction_request = new G4_Result_Release_Rest_Request( $stale_correction_body, $correction_rest_source['evaluation_id'], g4_result_release_rest_context( $correction_rest_source ), 'idempotency-g4-result-correction-stale' );
g4_release_same( true, $correction_rest_controller->permissions_release( $stale_correction_request ), 'Stale correction must reauthorize before source comparison.' );
$stale_correction_response = $correction_rest_controller->release( $stale_correction_request );
g4_release_assert( $stale_correction_response instanceof WP_Error, 'Stale correction must fail.' );
g4_release_same( 409, $stale_correction_response->get_error_data()['status'], 'Stale correction must return 409.' );

$correction_before_revoke     = $correction_rest_repository->append_count;
$correction_rest_policy->deny = true;
$correction_denied            = $correction_rest_controller->permissions_release( $correction_rest_request );
g4_release_assert( $correction_denied instanceof WP_Error, 'Permission revocation must deny correction before application execution.' );
g4_release_same( 403, $correction_denied->get_error_data()['status'], 'Revoked correction permission must return 403.' );
g4_release_same( $correction_before_revoke, $correction_rest_repository->append_count, 'Revoked correction permission must append zero releases.' );

fwrite( STDOUT, "G4-IMP-06-S04 C43 REST passed: frozen route, correction-only reason DTO, exact current pointer, body-free response, replay, 409 stale, and 403 revocation are enforced.\n" );
