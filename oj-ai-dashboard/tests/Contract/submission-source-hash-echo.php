<?php
/**
 * Recover provider hash formatting only within a verified evaluation request.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Submission\Assessment\Application\Run_Submission_Evaluation_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Worker_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Gateway_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Evidence_Catalog;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Output_Schema;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Work_Repository;

require __DIR__ . '/submission-evaluation-worker-g4-imp-02-s02.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Score_Evidence_Catalog.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Score_Output_Schema.php';

$echo_fixture  = g4_s02_fixture( $canonical );
$echo_baseline = $normalizer->normalize( $echo_fixture['output'], $echo_fixture['input'] );
$input_before  = $canonical->encode( $echo_fixture['input'] );
$expected_hash = $echo_fixture['source_hash'];
$echoes        = array(
	$expected_hash,
	strtoupper( $expected_hash ),
	" \n" . $expected_hash . "\t",
	'SHA-256: ' . strtoupper( $expected_hash ),
	implode( ' ', str_split( $expected_hash, 8 ) ),
	substr( $expected_hash, 0, 63 ),
	'SHA256: ' . substr( $expected_hash, 0, 32 ),
	'',
	null,
	'<source_hash>',
);
foreach ( $echoes as $echo ) {
	$output                = $echo_fixture['output'];
	$output['source_hash'] = $echo;
	$before                = $output;
	$result                = $normalizer->normalize( $output, $echo_fixture['input'] );
	g4_s02_same( $echo_baseline->normalized_hash(), $result->normalized_hash(), 'Metadata formatting must not change any grading fact or score.' );
	g4_s02_same( $before, $output, 'Raw provider output must not be mutated.' );
	$gateway  = Submission_Evaluation_Gateway_Result::success( $output, $route, 'echo-format', 125 );
	$scenario = g4_s02_worker_scenario( $echo_fixture, $canonical, $route, $gateway );
	g4_s02_same( Run_Submission_Evaluation_Result::SUCCEEDED, $scenario['worker']->run_once()->outcome(), 'Valid score with a malformed echo must complete.' );
	g4_s02_same( 1, $scenario['execution']->execute_calls, 'Hash echo repair must never call AI again.' );
	g4_s02_same( 2, $scenario['source_port']->calls, 'Source must still be checked before and after execution.' );
	g4_s02_same( 2, $scenario['policy_port']->calls, 'Teacher permissions must still be rechecked.' );
	g4_s02_same( 2, $scenario['consent_port']->calls, 'AI consent must still be rechecked.' );
	g4_s02_same( $echo_baseline->normalized_hash(), $scenario['repository']->success?->normalized_hash(), 'The queued result must preserve the checked score and source.' );
}
g4_s02_same( $input_before, $canonical->encode( $echo_fixture['input'] ), 'Repair must not expand or rewrite the provider input.' );

$structured = $echo_fixture;
$structured['input']['operation_payload']['bindings'][0]['binding_ref']     = 'B1';
$structured['input']['operation_payload']['evidence_catalog']               = Submission_Score_Evidence_Catalog::build( $structured['input']['operation_payload']['responses'], array() );
$structured['input']['operation_payload']['output_contract']['json_schema'] = Submission_Score_Output_Schema::build( array( 'B1' ), array( 'E1' ), $structured['evaluation_ref'], $expected_hash );
$structured['input']['payload_hash']                                        = hash( 'sha256', $canonical->encode( $structured['input']['operation_payload'] ) );
$structured['output']['items'][0]['binding_key']                            = 'B1';
$structured['output']['items'][0]['evidence']                               = array( array( 'evidence_ref' => 'E1' ) );
$structured_baseline                 = $normalizer->normalize( $structured['output'], $structured['input'] );
$structured['output']['source_hash'] = '<source_hash>';
g4_s02_same( $structured_baseline->normalized_hash(), $normalizer->normalize( $structured['output'], $structured['input'] )->normalized_hash(), 'Current structured output and B1/E1 references must retain the same checked score after hash repair.' );

$other_hash = hash( 'sha256', 'another official submission' );
foreach ( array( $other_hash, strtoupper( $other_hash ), 'SHA256: ' . $other_hash, $expected_hash . ' ' . $other_hash, substr( $other_hash, 0, 32 ), 'sha256:' . substr( $other_hash, 0, 32 ), 123, false, array(), array( 'hash' => $expected_hash ), str_repeat( 'x', 257 ) ) as $invalid_echo ) {
	$output                = $echo_fixture['output'];
	$output['source_hash'] = $invalid_echo;
	g4_s02_failure( static fn () => $normalizer->normalize( $output, $echo_fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_SCHEMA_INVALID, 'Conflicting hash or invalid metadata shape must fail closed.' );
}
foreach ( $echoes as $echo ) {
	$output                   = $echo_fixture['output'];
	$output['source_hash']    = $echo;
	$output['evaluation_ref'] = 'EV_' . str_repeat( 'B', 32 );
	g4_s02_failure( static fn () => $normalizer->normalize( $output, $echo_fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_SCHEMA_INVALID, 'Repair must never accept another evaluation reference.' );
}
$missing = $echo_fixture['output'];
unset( $missing['source_hash'] );
g4_s02_failure( static fn () => $normalizer->normalize( $missing, $echo_fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_SCHEMA_INVALID, 'Required output keys remain required.' );
$tampered_input = $echo_fixture['input'];
$tampered_input['operation_payload']['subject']['source_hash'] = $other_hash;
g4_s02_failure( static fn () => $normalizer->normalize( $echo_fixture['output'], $tampered_input ), Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, 'The input checksum is never repaired.' );
$tampered_input['operation_payload']['subject']['source_hash'] = '<invalid server hash>';
$tampered_input['payload_hash']                                = hash( 'sha256', $canonical->encode( $tampered_input['operation_payload'] ) );
g4_s02_failure( static fn () => $normalizer->normalize( $echo_fixture['output'], $tampered_input ), Submission_Evaluation_Worker_Failure::OUTPUT_SCHEMA_INVALID, 'The stored source hash remains strict.' );

$repairable                                      = $echo_fixture['output'];
$repairable['source_hash']                       = '<source_hash>';
$too_many_points                                 = $repairable;
$too_many_points['items'][0]['candidate_points'] = '100';
g4_s02_failure( static fn () => $normalizer->normalize( $too_many_points, $echo_fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, 'A repaired hash cannot authorize an invalid score.' );
$unknown_binding                            = $repairable;
$unknown_binding['items'][0]['binding_key'] = hash( 'sha256', 'another binding' );
g4_s02_failure( static fn () => $normalizer->normalize( $unknown_binding, $echo_fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, 'A repaired hash cannot authorize another binding.' );
$bad_evidence                                     = $repairable;
$bad_evidence['items'][0]['evidence'][0]['quote'] = 'fabricated student text';
g4_s02_same( null, $normalizer->normalize( $bad_evidence, $echo_fixture['input'] )->candidate_score(), 'Unverified evidence must still withhold the score.' );

$repair_gateway = Submission_Evaluation_Gateway_Result::success( $repairable, $route, 'echo-recovery', 125 );
$scenario       = g4_s02_worker_scenario( $echo_fixture, $canonical, $route, $repair_gateway, null, array( true, false ) );
g4_s02_same( Run_Submission_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Postflight consent revocation must still discard a repaired result.' );
g4_s02_same( null, $scenario['repository']->success, 'A repaired result cannot bypass consent.' );
$scenario = g4_s02_worker_scenario( $echo_fixture, $canonical, $route, $repair_gateway, array( $route, g4_s02_route( str_repeat( '8', 64 ) ) ) );
g4_s02_same( Run_Submission_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Postflight routing drift must still discard a repaired result.' );

$stored_result = $normalizer->normalize( $repairable, $echo_fixture['input'] );
$running_item  = g4_s02_item( $echo_fixture['input'], $route, 'running' );
$database      = new G4_S02_Database( g4_s02_tables( $running_item ) );
$repository    = new WordPress_Submission_Evaluation_Work_Repository( $canonical, $database );
g4_s02_same( true, $repository->complete_success( $running_item, $repair_gateway, $stored_result, g4_s02_id(), g4_s02_id(), g4_s02_id(), '2026-08-23 12:00:00' ), 'Repaired result must persist through the real result repository.' );
g4_s02_same( 1, count( $database->tables['oj_ai_submission_evaluation_results'] ), 'Only one result may be stored.' );
g4_s02_same( 'review_required', $database->tables['oj_ai_submission_evaluations'][0]['state'], 'Recovery must never approve or publish scores.' );
g4_s02_assert( ! str_contains( $canonical->encode( $database->tables ), '<source_hash>' ), 'Malformed provider metadata must not enter stored scoring records.' );
fwrite( STDOUT, "PASS source hash echo: same-request recovery, conflicting identity rejection, immutable input, strict score/evidence checks, one AI call, guarded worker and review-only persistence.\n" );
