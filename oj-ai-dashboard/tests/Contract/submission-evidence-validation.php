<?php
/**
 * Anchored student evidence regressions; all inputs are synthetic.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/submission-evaluation-worker-g4-imp-02-s02.php';

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Worker_Failure;

$evidence_fixture        = g4_s02_fixture( $canonical );
$evidence_input          = $evidence_fixture['input'];
$evidence_output         = $evidence_fixture['output'];
$assert_evidence_failure = static function ( array $output, array $input, string $context ) use ( $normalizer ): void {
	try {
		$normalizer->normalize( $output, $input );
	} catch ( Submission_Evaluation_Worker_Failure $failure ) {
		g4_s02_same( Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, $failure->reason(), 'Invalid citations must fail closed.' );
		g4_s02_same( $context, $failure->context(), 'Failure must identify the exact cause without exposing answer text.' );
		return;
	}
	throw new RuntimeException( 'An invalid citation was accepted.' );
};

$assert_evidence_review = static function ( array $output, array $input, string $scenario, int $kept = 0 ) use ( $normalizer ): void {
	$result = $normalizer->normalize( $output, $input );
	$item   = json_decode( $result->normalized_json(), true, 64, JSON_THROW_ON_ERROR )['items'][0];
	g4_s02_same( null, $result->candidate_score(), $scenario . ': no unverified total.' );
	g4_s02_same( 'abstain', $item['decision'], $scenario . ': item must remain reviewable.' );
	g4_s02_same( null, $item['candidate_points'], $scenario . ': never accept unverified points.' );
	g4_s02_same( null, $item['rationale'], $scenario . ': discard the unsupported rationale.' );
	g4_s02_same( '0.0000', $item['confidence'], $scenario . ': confidence must not imply validation.' );
	g4_s02_assert( str_starts_with( $item['abstain_reason'], 'evidence_' ), $scenario . ': distinguish server verification from model abstention.' );
	g4_s02_same( array( 'evidence_limited', 'teacher_review_required' ), $item['flags'], $scenario . ': require explicit review.' );
	g4_s02_same( $kept, count( $item['evidence'] ), $scenario . ': only verified citations may remain.' );
};

foreach ( array( "A clear\nanswer.", "A clear\r\nanswer.", "A clear\t  answer.", "A clear\u{00a0}answer." ) as $answer ) {
	$evidence_input['operation_payload']['responses'][0]['answer']['text'] = $answer;
	$evidence_input = $rehash_input( $evidence_input );
	$checked        = $normalizer->normalize( $evidence_output, $evidence_input );
	$item           = json_decode( $checked->normalized_json(), true, 64, JSON_THROW_ON_ERROR )['items'][0];
	$literal        = substr( $answer, 2, -1 );
	g4_s02_same( $literal, $item['evidence'][0]['quote'], 'Store the literal source, not the AI whitespace.' );
	g4_s02_same( array( 'teacher_review_required' ), $item['flags'], 'Reformatted prose requires teacher review.' );
	g4_s02_same( '7.5000', $checked->candidate_score(), 'Citation formatting must not change points.' );
	g4_s02_same( $checked->normalized_hash(), $normalizer->normalize( $evidence_output, $evidence_input )->normalized_hash(), 'Repair must be deterministic.' );
}

$evidence_input['operation_payload']['responses'][0]['answer']['text'] = 'A clear answer.';
$evidence_input = $rehash_input( $evidence_input );
foreach ( array( 'Clear answer', 'clear response', 'clear...answer', 'clearanswer', 'clear, answer', 'not a clear answer', 'Answer' ) as $invalid_quote ) {
	$bad                                     = $evidence_output;
	$bad['items'][0]['evidence'][0]['quote'] = $invalid_quote;
	$assert_evidence_review( $bad, $evidence_input, '$.items[0].evidence[0].quote (not found in response)' );
}
$good = $normalizer->normalize( $evidence_output, $evidence_input );
g4_s02_same( array(), $good->items()[0]['flags'], 'Exact citations need no formatting flag.' );

$bad                           = $evidence_output;
$bad['items'][0]['evidence'][] = array(
	'source' => 'response',
	'anchor' => $evidence_fixture['response_id'],
	'quote'  => 'A clear',
);
$bad['items'][0]['evidence'][] = array(
	'source' => 'response',
	'anchor' => $evidence_fixture['response_id'],
	'quote'  => 'invented third quotation',
);
$assert_evidence_review( $bad, $evidence_input, '$.items[0].evidence[2].quote (not found in response)', 2 );

$other_id             = g4_s02_id();
$other                = $evidence_input['operation_payload']['responses'][0];
$other['response_id'] = $other_id;
$other['position']    = 1;
$evidence_input['operation_payload']['responses'][] = $other;
$evidence_input                                     = $rehash_input( $evidence_input );
$bad                                      = $evidence_output;
$bad['items'][0]['evidence'][0]['anchor'] = 'response:' . $other_id;
$assert_evidence_review( $bad, $evidence_input, '$.items[0].evidence[0].anchor (response scope mismatch)' );
$bad['items'][0]['evidence'][0]['anchor'] = 'response:' . g4_s02_id();
$assert_evidence_review( $bad, $evidence_input, '$.items[0].evidence[0].anchor (unknown response)' );

$cross_input = $evidence_input;
$cross_input['operation_payload']['responses'][0]['answer']['text'] = 'A brief response.';
$cross_input = $rehash_input( $cross_input );
$assert_evidence_review( $evidence_output, $cross_input, '$.items[0].evidence[0].quote (not found in response)' );
$cross_input['operation_payload']['bindings'][0]['scope']       = 'submission';
$cross_input['operation_payload']['bindings'][0]['response_id'] = null;
$cross_input = $rehash_input( $cross_input );
$assert_evidence_review( $evidence_output, $cross_input, '$.items[0].evidence[0].quote (not found in response)' );
$valid_other                                      = $evidence_output;
$valid_other['items'][0]['evidence'][0]['anchor'] = 'response:' . $other_id;
g4_s02_same( '7.5000', $normalizer->normalize( $valid_other, $cross_input )->candidate_score(), 'Submission-wide evidence may cite the known matching response.' );

$split_input = $evidence_fixture['input'];
$split_input['operation_payload']['responses'][0]['kind']   = 'table';
$split_input['operation_payload']['responses'][0]['answer'] = array( 'rows' => array( array( 'clear', 'answer' ) ) );
$assert_evidence_review( $evidence_output, $rehash_input( $split_input ), '$.items[0].evidence[0].quote (not found in response)' );
$split_input['operation_payload']['responses'][0]['answer'] = array( 'rows' => array( array( "clear\nanswer" ) ) );
$assert_evidence_review( $evidence_output, $rehash_input( $split_input ), '$.items[0].evidence[0].quote (not found in response)' );

$long_input = $evidence_fixture['input'];
$long_input['operation_payload']['responses'][0]['answer']['text'] = 'clear' . str_repeat( ' ', 240 ) . 'answer';
$assert_evidence_review( $evidence_output, $rehash_input( $long_input ), '$.items[0].evidence[0].quote (not found in response)' );

$duplicate_input = $evidence_fixture['input'];
$duplicate_input['operation_payload']['responses'][0]['answer']['text'] = "clear\nanswer";
$bad                                     = $evidence_output;
$bad['items'][0]['evidence'][]           = $bad['items'][0]['evidence'][0];
$bad['items'][0]['evidence'][1]['quote'] = "clear\nanswer";
$assert_evidence_failure( $bad, $rehash_input( $duplicate_input ), '$.items[0].evidence[1] (duplicate evidence item)' );

$artifact_input                                     = $evidence_input;
$artifact_input['operation_payload']['artifacts'][] = array(
	'anchors'             => array(
		array(
			'anchor' => 'artifact:excerpt',
			'quote'  => 'A clear answer.',
		),
	),
	'artifact_ref'        => 'artifact:sample',
	'extractor_contract'  => 'synthetic.v1',
	'kind'                => 'text',
	'redaction_version'   => 'v1',
	'representation_hash' => str_repeat( 'f', 64 ),
	'response_id'         => $other_id,
	'role'                => 'answer',
	'source_hash'         => str_repeat( 'e', 64 ),
	'state'               => 'ready',
	'text'                => 'A clear answer.',
	'unavailable_reason'  => null,
);
$artifact_input                                     = $rehash_input( $artifact_input );
$bad                            = $evidence_output;
$bad['items'][0]['evidence'][0] = array(
	'source' => 'artifact',
	'anchor' => 'artifact:excerpt',
	'quote'  => 'clear answer',
);
$assert_evidence_review( $bad, $artifact_input, '$.items[0].evidence[0].anchor (artifact scope mismatch)' );
$artifact_input['operation_payload']['artifacts'][0]['response_id'] = $evidence_fixture['response_id'];
$artifact_input = $rehash_input( $artifact_input );
g4_s02_same( '7.5000', $normalizer->normalize( $bad, $artifact_input )->candidate_score(), 'Exact known artifact citations remain supported.' );
$bad['items'][0]['evidence'][0]['quote'] = 'invented';
$assert_evidence_review( $bad, $artifact_input, '$.items[0].evidence[0].quote (not found in artifact)' );
$bad['items'][0]['evidence'][0]['anchor'] = 'artifact:unknown';
$assert_evidence_review( $bad, $artifact_input, '$.items[0].evidence[0].anchor (unknown artifact)' );

foreach ( array( 'response:invalid-id', 'R1' ) as $malformed_anchor ) {
	$bad                                      = $evidence_output;
	$bad['items'][0]['evidence'][0]['anchor'] = $malformed_anchor;
	$assert_evidence_review( $bad, $evidence_fixture['input'], 'Malformed response reference' );
}
$unsafe                      = $bad;
$unsafe['items'][0]['flags'] = array( 'safety_review_required' );
$unsafe_result               = $normalizer->normalize( $unsafe, $evidence_fixture['input'] );
$unsafe_item                 = json_decode( $unsafe_result->normalized_json(), true, 64, JSON_THROW_ON_ERROR )['items'][0];
g4_s02_same( true, $unsafe_result->safety_flagged(), 'Evidence recovery must preserve safety review.' );
g4_s02_same( 'safety_review', $unsafe_item['abstain_reason'], 'Safety reason must survive evidence recovery.' );
$fatal                = $bad;
$fatal['source_hash'] = str_repeat( '0', 64 );
g4_s02_failure( static fn () => $normalizer->normalize( $fatal, $evidence_fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_SCHEMA_INVALID, 'Evidence recovery cannot bypass source identity.' );
$fatal                            = $bad;
$fatal['items'][0]['binding_key'] = str_repeat( '0', 64 );
g4_s02_failure( static fn () => $normalizer->normalize( $fatal, $evidence_fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, 'Evidence recovery cannot bypass binding identity.' );
$fatal                                 = $bad;
$fatal['items'][0]['candidate_points'] = '10.0001';
g4_s02_failure( static fn () => $normalizer->normalize( $fatal, $evidence_fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, 'Evidence recovery cannot bypass point bounds.' );
$fatal                 = $bad;
$fatal['raw_response'] = 'private';
g4_s02_failure( static fn () => $normalizer->normalize( $fatal, $evidence_fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_PRIVACY_INVALID, 'Evidence recovery cannot bypass privacy validation.' );

echo "Student evidence passed: literal recovery, scope isolation, item-level abstention, safety preservation and strict identity/privacy validation.\n";
