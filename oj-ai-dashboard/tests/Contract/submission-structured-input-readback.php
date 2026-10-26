<?php
/**
 * Current score writer to immutable Q17 readback, without an AI request.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/submission-evaluation-query-g4-imp-02-s03.php';
require __DIR__ . '/submission-assessment-input-g4-imp-01-s02.php';

$stored_fixture                              = g4_s03_fixture( $canonical );
$stored_root                                 = $stored_fixture['root'];
$stored_input                                = $stored_fixture['input'];
$written                                     = $input->operation_payload();
$stored_input['input_json']                  = $input->gateway_input_json();
$stored_input['input_hash']                  = $input->gateway_input_hash();
$stored_input['input_byte_size']             = $input->byte_size();
$stored_root['submission_revision_hash']     = $written['subject']['source_hash'];
$stored_root['source_revision_content_hash'] = $written['subject']['source_hash'];
$stored_root['publication_hash']             = $written['subject']['publication_hash'];
$stored_root['assignment_publication_hash']  = $written['subject']['publication_hash'];
$stored_root['rubric_revision_id']           = $written['rubric']['revision_ref'];
$stored_root['rubric_hash']                  = $written['rubric']['content_hash'];
$authorization                               = json_decode( $stored_root['authorization_envelope_json'], true, 128, JSON_THROW_ON_ERROR );
$authorization['object']['official_revision_hash'] = $written['subject']['source_hash'];
$stored_root['authorization_envelope_json']        = $canonical->encode( $authorization );
$stored_root['authorization_envelope_hash']        = hash( 'sha256', $stored_root['authorization_envelope_json'] );
foreach ( array( 'latest_result_id', 'latest_result_content_hash', 'latest_candidate_score', 'latest_maximum_score', 'latest_overall_confidence', 'latest_abstained', 'latest_safety_flagged', 'latest_result_summary', 'latest_result_created_at_utc' ) as $key ) {
	$stored_root[ $key ] = null;
}
$stored_root['item_count']          = '0';
$stored_root['review_needed_count'] = '0';
$reader                             = new \OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Query_Projector( $canonical );
foreach ( array( 'queued', 'running', 'failed' ) as $state ) {
	$stored_root['evaluation_state']       = $state;
	$stored_root['latest_run_state']       = $state;
	$stored_root['priority_rank']          = 'failed' === $state ? '1' : '0';
	$stored_runs                           = $stored_fixture['runs'];
	$stored_runs[0]['run_state']           = $state;
	$stored_runs[0]['run_result_id']       = null;
	$stored_runs[0]['run_last_error_code'] = 'failed' === $state ? 'output_domain_invalid' : null;
	$readback                              = $reader->detail( $stored_root, $stored_input, null, array(), $stored_runs, array(), array(), array() );
	g4_s03_same( $state, $readback['evaluation']['state'], 'Current writer input must remain readable before a result and after failure.' );
	g4_s03_same( $canonical->encode( $written['responses'] ), $canonical->encode( $readback['source']['responses'] ), 'Readback must preserve the original student response projection.' );
	$public_json = $canonical->encode( $readback );
	foreach ( array( 'evidence_catalog', 'json_schema', 'raw_prompt' ) as $private_key ) {
		g4_s03_assert( ! str_contains( $public_json, '"' . $private_key . '"' ), 'Provider-only metadata must not enter Q17.' );
	}
}

$without_metadata = $input->gateway_input();
unset( $without_metadata['operation_payload']['evidence_catalog'], $without_metadata['operation_payload']['output_contract']['json_schema'] );
// phpcs:disable WordPress.Security.EscapeOutput -- CLI output contains only integer byte counts from synthetic fixtures.
printf(
	"Structured readback passed: queued/running/failed; full source retained. Fixture request bytes: %d; without new provider metadata: %d. These are bytes, not model tokens.\n",
	$input->byte_size(),
	strlen( $canonical->encode( $without_metadata ) )
);
// phpcs:enable WordPress.Security.EscapeOutput
