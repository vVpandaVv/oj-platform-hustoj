<?php
/**
 * Report writing process evidence and historical compatibility regression.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/student-note-draft-g4-imp-05-s01.php';

$source = g4_note_source( $canonical, 3 );
$before = $canonical->encode( $source );
$input  = $builder->build( $source )->gateway_input();
$items  = $input['operation_payload']['process_evidence'];
g4_note_assert( 1 === count( $items ), 'Submission-wide notes must receive the available process excerpt once.' );
$global_keys = array_column( array_filter( $input['operation_payload']['evidence'], static fn ( array $item ): bool => null === $item['response_anchor'] ), 'evidence_key' );
g4_note_assert( $global_keys === $items[0]['evidence_keys'], 'Supplementary excerpts must cite only submission-wide evidence.' );
g4_note_assert( ! str_contains( $canonical->encode( $items ), 'learner@example.test' ) && ! str_contains( $canonical->encode( $items ), 'private.test' ), 'Process evidence must reuse privacy filtering.' );
g4_note_assert( $before === $canonical->encode( $source ), 'Source snapshots must remain immutable.' );
g4_note_assert( $input === $builder->build( $source )->gateway_input(), 'Process evidence must be deterministic.' );

$legacy = $input;
unset( $legacy['operation_payload']['process_evidence'] );
$legacy['operation_payload']['instruction']['contract_version'] = 'student_submission_assessment_prompt.v9';
$legacy['operation_payload']['instruction']['content_hash']     = 'c8a70735654dced3c4b485d4b4bbabc8daf5578907e55e8873559489b02f9105';
$legacy['payload_hash'] = hash( 'sha256', $canonical->encode( $legacy['operation_payload'] ) );
$old_output             = g4_note_output( $legacy );
g4_note_assert( $normalizer->normalize( $old_output, $legacy )->profile() === $normalizer->normalize( $old_output, $input )->profile(), 'Historical narratives and evidence must remain reviewable after the prompt upgrade.' );

$only_response = g4_note_source( $canonical, 1 );
g4_note_assert( array() === $builder->build( $only_response )->gateway_input()['operation_payload']['process_evidence'], 'Response-scoped excerpts must not be duplicated.' );
$payload                                    = $source['score_input']['operation_payload'];
$payload['responses'][0]['kind']            = 'drawing';
$source['score_input']['operation_payload'] = $payload;
$source['score_input']['payload_hash']      = hash( 'sha256', $canonical->encode( $payload ) );
g4_note_assert( array() === $builder->build( $source )->gateway_input()['operation_payload']['process_evidence'], 'Unavailable images must not become observed process.' );

$source  = g4_note_source( $canonical, 3 );
$payload = $source['score_input']['operation_payload'];
for ( $index = 0; $index < 30; ++$index ) {
	$payload['responses'][] = array(
		'response_id' => g4_note_id(),
		'kind'        => 'long_text',
		'answer'      => array( 'text' => str_repeat( '근거를 비교함. ', 200 ) ),
	);
}
$source['score_input']['operation_payload'] = $payload;
$source['score_input']['payload_hash']      = hash( 'sha256', $canonical->encode( $payload ) );
$bounded                                    = $builder->build( $source )->gateway_input();
g4_note_assert( strlen( $canonical->encode( $bounded['operation_payload']['process_evidence'] ) ) <= 6000, 'Supplementary excerpts must stay inside the token-growth budget.' );
g4_note_assert( strlen( $canonical->encode( $bounded ) ) <= 32768, 'The outer request must stay inside its existing limit.' );
echo "Student note process evidence passed: grounding, privacy, source preservation and byte budgets.\n";
