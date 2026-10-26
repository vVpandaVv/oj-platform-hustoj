<?php
/**
 * Request-local grounding and diagnostic regression with synthetic answers only.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/submission-evidence-validation.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Score_Evidence_Catalog.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Score_Output_Schema.php';

use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Evidence_Catalog;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Output_Schema;

$fixture = g4_s02_fixture( $canonical );
$input   = $fixture['input'];
$output  = $fixture['output'];
$input['operation_payload']['responses'][0]['answer']['text'] = str_repeat( "학생의 원문: 인용과 줄바꿈을 그대로 보존합니다.\n", 12 );
$original_answer = $input['operation_payload']['responses'][0]['answer']['text'];
$catalog         = Submission_Score_Evidence_Catalog::build( $input['operation_payload']['responses'], array() );
g4_s02_same( $original_answer, $input['operation_payload']['responses'][0]['answer']['text'], 'Building excerpts never changes the complete answer.' );
g4_s02_assert( count( $catalog ) > 1, 'Long answers have multiple selectable references.' );
foreach ( $catalog as $entry ) {
	g4_s02_assert( mb_strlen( $entry['quote'], 'UTF-8' ) <= 240, 'Each quote fits the output contract.' );
	g4_s02_assert( str_contains( $original_answer, $entry['quote'] ), 'Each Unicode excerpt is a literal contiguous substring.' );
}
$input['operation_payload']['evidence_catalog'] = $catalog;
$input                          = $rehash_input( $input );
$output['items'][0]['evidence'] = array( array( 'evidence_ref' => 'E2' ) );
$result                         = $normalizer->normalize( $output, $input );
$item                           = json_decode( $result->normalized_json(), true, 64, JSON_THROW_ON_ERROR )['items'][0];
g4_s02_same( '7.5000', $result->candidate_score(), 'References restore evidence without discarding valid points.' );
g4_s02_same( $catalog[1]['quote'], $item['evidence'][0]['quote'], 'Stored evidence is the literal server excerpt.' );
g4_s02_same( $catalog[1]['anchor'], $item['evidence'][0]['anchor'], 'Stored anchor is restored by the server.' );
g4_s02_same( array(), $item['flags'], 'Reference resolution is not a heuristic repair.' );

$assert_reason = static function ( array $candidate, array $request, string $expected ) use ( $normalizer ): void {
	$held      = $normalizer->normalize( $candidate, $request );
	$held_item = json_decode( $held->normalized_json(), true, 64, JSON_THROW_ON_ERROR )['items'][0];
	g4_s02_same( null, $held->candidate_score(), 'Invalid evidence never becomes a total score.' );
	g4_s02_same( $expected, $held_item['abstain_reason'], 'Precise non-private verification reason survives storage.' );
};
$bad           = $output;
$bad['items'][0]['evidence'][0]['evidence_ref'] = 'E999999';
$assert_reason( $bad, $input, 'evidence_reference_unknown' );
$assert_reason( $output, $fixture['input'], 'evidence_reference_unknown' );
$bad['items'][0]['evidence'] = array(
	array(
		'source' => 'response',
		'anchor' => $catalog[0]['anchor'],
		'quote'  => 'Invented quote',
	),
);
$assert_reason( $bad, $input, 'evidence_quote_mismatch' );
$bad['items'][0]['evidence'][0]['quote'] = null;
$assert_reason( $bad, $input, 'evidence_quote_mismatch' );

$cross                                     = $input;
$other                                     = $input['operation_payload']['responses'][0];
$other['response_id']                      = g4_s02_id();
$other['position']                         = 1;
$other['answer']['text']                   = 'Another answer';
$cross['operation_payload']['responses'][] = $other;
$cross['operation_payload']['evidence_catalog'] = Submission_Score_Evidence_Catalog::build( $cross['operation_payload']['responses'], array() );
$cross                       = $rehash_input( $cross );
$bad                         = $output;
$bad['items'][0]['evidence'] = array( array( 'evidence_ref' => end( $cross['operation_payload']['evidence_catalog'] )['evidence_ref'] ) );
$assert_reason( $bad, $cross, 'evidence_scope_mismatch' );
$tampered = $input;
$tampered['operation_payload']['evidence_catalog'][0]['quote'] = 'Teacher answer must not become evidence';
g4_s02_failure( static fn () => $normalizer->normalize( $output, $rehash_input( $tampered ) ), 'output_domain_invalid', 'Even a rehashed forged catalog must fail against the source.' );

$provider_hold                                 = $fixture['output'];
$provider_hold['items'][0]['decision']         = 'abstain';
$provider_hold['items'][0]['candidate_points'] = null;
$provider_hold['items'][0]['abstain_reason']   = 'insufficient_evidence';
$provider_hold['items'][0]['evidence']         = array();
unset( $provider_hold['items'][0]['rationale'] );
$assert_reason( $provider_hold, $fixture['input'], 'insufficient_evidence' );

$empty                      = $fixture['input']['operation_payload']['responses'];
$empty[0]['answer']['text'] = '';
g4_s02_same( array(), Submission_Score_Evidence_Catalog::build( $empty, array() ), 'Empty answers do not get invented supporting excerpts.' );
$empty[0]['answer']['text'] = implode( '', array_map( static fn ( int $index ): string => sprintf( '%04d', $index ) . str_repeat( 'x', 236 ), range( 1, 600 ) ) );
g4_s02_same( 512, count( Submission_Score_Evidence_Catalog::build( $empty, array() ) ), 'Reference projection is bounded without mutating the full answer.' );
g4_s02_same( 240 * 600, strlen( $empty[0]['answer']['text'] ), 'Catalog limits never truncate source answers.' );
$empty[0]['answer']['text'] = str_repeat( 'x', 480 );
g4_s02_same( 1, count( Submission_Score_Evidence_Catalog::build( $empty, array() ) ), 'Duplicate literal evidence is not assigned different selectable references.' );
$unicode_input = $fixture['input'];
$unicode_input['operation_payload']['responses'][0]['answer']['text'] = str_repeat( "e\u{0301}", 250 );
$unicode_input['operation_payload']['evidence_catalog']               = Submission_Score_Evidence_Catalog::build( $unicode_input['operation_payload']['responses'], array() );
$unicode_input = json_decode( $canonical->encode( $rehash_input( $unicode_input ) ), true, 64, JSON_THROW_ON_ERROR );
g4_s02_same( '7.5000', $normalizer->normalize( $output, $unicode_input )->candidate_score(), 'NFC normalization across an excerpt boundary survives actual canonical wire encoding.' );

$schema = Submission_Score_Output_Schema::build( array( 'B1' ), array_column( $catalog, 'evidence_ref' ), $fixture['output']['evaluation_ref'], $fixture['output']['source_hash'] );
g4_s02_same( false, $schema['additionalProperties'], 'Output envelope rejects extra fields.' );
g4_s02_same( array( 'B1' ), $schema['$defs']['item']['anyOf'][0]['properties']['binding_key']['enum'], 'Schema binds the current criterion references.' );
g4_s02_same( array_column( $catalog, 'evidence_ref' ), $schema['$defs']['evidence']['anyOf'][0]['properties']['evidence_ref']['enum'], 'Schema binds the current evidence references.' );

$platform_root = dirname( __DIR__, 3 ) . '/oj-integration-rebuild/oj-platform';
require_once $platform_root . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register( $platform_root );
g4_s02_same( $schema, \CodingPanda\OjPlatform\Modules\Settings\Domain\AiStructuredOutput::fromInput( array( 'output_contract' => array( 'json_schema' => $schema ) ) ), 'The actual addon schema must pass platform bounds and local-reference validation.' );

echo "Evidence references passed: literal Korean excerpts, source/scope isolation, unknown references, empty answers, strict diagnostics and legacy compatibility.\n";
