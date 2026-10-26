<?php
/**
 * G5-IMP-07-S03-D3-D6 evaluation lineage migration contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G5-IMP-07-S03-D3-D6 evaluation lineage migration contract.
	 */
	final class Normalizer {
		public const FORM_C = 4;
		/**
		 * Normalize.
		 *
		 * @param string $value Value.
		 * @param int    $form Form.
		 * @return string
		 */
		public static function normalize( string $value, int $form = self::FORM_C ): string {
			unset( $form );
			return $value;
		}
	}
}

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Evaluation_Projector;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Evaluation_Writer;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Evaluation_Writer_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 d3d6 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d6_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 d3d6 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d6_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 d3d6 invalid.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d6_invalid( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message . ': no invalid argument was raised.' );
}

/**
 * G5 d3d6 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function g5_d3d6_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	g5_d3d6_assert( Public_Id::is_valid( $id ), 'Fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G5-IMP-07-S03-D3-D6 evaluation lineage migration contract.
 */
final class G5_D3D6_Evaluation_Port implements Legacy_Migration_Evaluation_Writer_Port_Interface {
	/**
	 * Types.
	 *
	 * @var array<int,string>
	 */
	public array $types = array();

	/**
	 * Write.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 */
	public function write( int $run_database_id, Legacy_Source_Item $item, int $actor_user_id, string $created_at_utc ): Legacy_Target_Write_Result {
		g5_d3d6_same( 71, $run_database_id, 'Evaluation dispatch changed the import run.' );
		g5_d3d6_same( 17, $actor_user_id, 'Evaluation dispatch changed the actor.' );
		g5_d3d6_same( '2026-08-26 12:00:00', $created_at_utc, 'Evaluation dispatch changed the timestamp.' );
		$this->types[] = $item->source_type();
		return new Legacy_Target_Write_Result( 'submission_evaluation_relation', g5_d3d6_id( 900 ), hash( 'sha256', $item->source_type() ) );
	}
}

g5_d3d6_same(
	250,
	( new ReflectionClass( WordPress_Legacy_Migration_Evaluation_Writer::class ) )->getConstant( 'MAXIMUM_RELATIONS' ),
	'Evaluation migration relation limit changed.'
);

$canonical        = new Canonical_Json();
$projector        = new Legacy_Migration_Evaluation_Projector( $canonical );
$evaluation_id    = g5_d3d6_id( 1 );
$review_id        = g5_d3d6_id( 2 );
$result_id        = g5_d3d6_id( 3 );
$response_id      = g5_d3d6_id( 4 );
$criterion_id     = g5_d3d6_id( 5 );
$policy_id        = g5_d3d6_id( 6 );
$rubric_id        = g5_d3d6_id( 7 );
$review_rule_id   = g5_d3d6_id( 8 );
$submission_hash  = hash( 'sha256', 'd3-d6-submission-revision' );
$publication_hash = hash( 'sha256', 'd3-d6-publication' );
$grading_hash     = hash( 'sha256', 'd3-d6-grading' );
$binding_key      = hash( 'sha256', 'd3-d6-binding' );
$grading_payload  = array(
	'bindings'    => array(
		array(
			'binding_key'    => $binding_key,
			'criterion_id'   => $criterion_id,
			'label'          => '설명과 근거',
			'maximum_points' => '10.0000',
			'position'       => 0,
			'response_id'    => $response_id,
			'scope'          => 'response',
		),
	),
	'policy'      => array(
		'content_hash' => hash( 'sha256', 'd3-d6-policy' ),
		'revision_id'  => $policy_id,
	),
	'review_rule' => array(
		'content_hash' => hash( 'sha256', 'd3-d6-review-rule' ),
		'revision_id'  => $review_rule_id,
	),
	'rubric'      => array(
		'content_hash' => hash( 'sha256', 'd3-d6-rubric' ),
		'revision_id'  => $rubric_id,
		'total_points' => '10.0000',
	),
);
$response_payload = array(
	'items' => array(
		array(
			'response_id' => $response_id,
			'kind'        => 'long_text',
			'label'       => '생각을 설명하세요',
			'required'    => true,
			'position'    => 0,
			'constraints' => array( 'max_chars' => 2000 ),
		),
	),
);
$score_input      = $projector->score_input(
	'EV_' . str_repeat( 'A', 32 ),
	$submission_hash,
	$publication_hash,
	$grading_hash,
	$grading_payload,
	$response_payload,
	array( $response_id => '근거를 들어 해결 과정을 설명했습니다.' ),
	'ko-KR'
);
g5_d3d6_same( Submission_Score_Input_Builder::OPERATION_CONTRACT, $score_input['gateway_input']['operation_contract'], 'Historical score input did not use the current operation contract.' );
g5_d3d6_same( Submission_Score_Input_Builder::REQUEST_SCHEMA, $score_input['gateway_input']['operation_payload']['schema_id'], 'Historical score request schema drifted.' );
g5_d3d6_assert( ! str_contains( $score_input['input_json'], 'raw_ai_response' ), 'Historical score input leaked the legacy provider field.' );

$legacy_result = array(
	'id'             => 301,
	'total_score'    => '8.00',
	'max_score'      => '10.00',
	'review_comment' => '설명은 명확하며 근거를 조금 더 구체화하면 좋습니다.',
);
$legacy_items  = array(
	array(
		'id'          => 401,
		'question_id' => '91',
		'score'       => '8.00',
		'max_score'   => '10.00',
		'reason_text' => '핵심 과정과 결론을 제시했습니다.',
	),
);
$normalized    = $projector->score_result( $legacy_result, $legacy_items, $score_input, array( '91' => $response_id ) );
g5_d3d6_same( '8.0000', $normalized->candidate_score(), 'Historical score total was not normalized exactly.' );
g5_d3d6_same( '10.0000', $normalized->maximum_score(), 'Historical maximum score was not normalized exactly.' );
g5_d3d6_assert( in_array( 'teacher_review_required', $normalized->items()[0]['flags'], true ), 'Historical evidence limitation did not require teacher review.' );
$score_decisions = $projector->accepted_score_decisions( $normalized );
g5_d3d6_same( 'ai_accepted', $score_decisions[0]['source'], 'Accepted historical score did not preserve review provenance.' );

$review_hash   = hash( 'sha256', 'd3-d6-official-review' );
$result_hash   = hash( 'sha256', 'd3-d6-result' );
$review_source = array(
	'evaluation_id'            => $evaluation_id,
	'official_review_id'       => $review_id,
	'official_review_hash'     => $review_hash,
	'result_id'                => $result_id,
	'result_hash'              => $result_hash,
	'submission_revision_hash' => $submission_hash,
	'score_input'              => $score_input['gateway_input'],
	'score_decisions'          => $score_decisions,
);
$feedback      = $projector->feedback( $review_source, '풀이의 순서를 유지하고 마지막 근거를 한 문장 더 적어 보세요.' );
g5_d3d6_same( Submission_Feedback_Input_Builder::RESULT_SCHEMA, $feedback['result']->profile()['schema_id'], 'Historical feedback did not pass the current result normalizer.' );
g5_d3d6_same( '풀이의 순서를 유지하고 마지막 근거를 한 문장 더 적어 보세요.', $feedback['result']->profile()['overall_feedback'], 'Historical feedback body was not preserved.' );
$note = $projector->student_note( $review_source, '문제 해결 과정을 차분히 설명하고 피드백을 반영함.' );
g5_d3d6_same( '문제 해결 과정을 차분히 설명하고 피드백을 반영함.', $note['result']->profile()['summary']['text'], 'Historical student note body was not preserved.' );
$release = $projector->release(
	$review_source,
	'평가 이관 활동지',
	array(
		'state'   => 'approved',
		'schema'  => Submission_Feedback_Input_Builder::RESULT_SCHEMA,
		'version' => 1,
		'json'    => $feedback['result']->profile_json(),
		'hash'    => $feedback['result']->profile_hash(),
	),
	'released',
	'2026-08-26 12:30:00'
);
g5_d3d6_same( Submission_Result_Projection::SCHEMA, $release->schema(), 'Historical publication did not use the student-safe release projection.' );
g5_d3d6_same( '8.0000', $release->visible_score(), 'Historical release changed the official score.' );
g5_d3d6_assert( ! str_contains( $release->json(), 'provider' ) && ! str_contains( $release->json(), 'confidence' ), 'Student release leaked provider-only evidence.' );

$drifted_result                = $legacy_result;
$drifted_result['total_score'] = '7.00';
g5_d3d6_invalid(
	static fn (): mixed => $projector->score_result( $drifted_result, $legacy_items, $score_input, array( '91' => $response_id ) ),
	'Aggregate/item score drift was accepted'
);
$file_response                     = $response_payload;
$file_response['items'][0]['kind'] = 'file_upload';
g5_d3d6_invalid(
	static fn (): mixed => $projector->score_input( 'EV_' . str_repeat( 'A', 32 ), $submission_hash, $publication_hash, $grading_hash, $grading_payload, $file_response, array( $response_id => 'legacy-file' ), 'ko-KR' ),
	'D3-D6 accepted a file answer before D3-D7'
);

$profile          = new Legacy_Source_Profile_0_1_2();
$port             = new G5_D3D6_Evaluation_Port();
$target_writer    = new WordPress_Legacy_Target_Writer( $canonical, null, null, $profile, null, null, null, $port );
$evaluation_types = array(
	'assessment_plans',
	'assessment_runs',
	'grading_inputs',
	'rubric_snapshots',
	'grading_items',
	'assessment_results',
	'regrade_reviews',
	'feedback',
	'feedback_approvals',
	'special_notes',
);
foreach ( $evaluation_types as $index => $type ) {
	$record       = array_fill_keys( $profile->columns( $type ), null );
	$record['id'] = $index + 1;
	$checksum     = hash(
		'sha256',
		$canonical->encode(
			array(
				'profile'     => Legacy_Source_Profile_0_1_2::PROFILE,
				'source_key'  => (string) ( $index + 1 ),
				'source_type' => $type,
				'record'      => $record,
			)
		)
	);
	$item         = new Legacy_Source_Item( $index + 1, $type, $index + 1, $checksum, $record );
	$result       = $target_writer->write( 71, $item, 17, '2026-08-26 12:00:00' );
	g5_d3d6_same( 'submission_evaluation_relation', $result->target_type(), 'Evaluation source did not use the dedicated owner port.' );
}
g5_d3d6_same( $evaluation_types, $port->types, 'The ten evaluation source types were not dispatched in order.' );
$unsupported_writer = new WordPress_Legacy_Target_Writer( $canonical, null, null, $profile );
$first_type         = $evaluation_types[0];
$first_record       = array_fill_keys( $profile->columns( $first_type ), null );
$first_record['id'] = 1;
$first_checksum     = hash(
	'sha256',
	$canonical->encode(
		array(
			'profile'     => Legacy_Source_Profile_0_1_2::PROFILE,
			'source_key'  => '1',
			'source_type' => $first_type,
			'record'      => $first_record,
		)
	)
);
try {
	$unsupported_writer->write( 71, new Legacy_Source_Item( 1, $first_type, 1, $first_checksum, $first_record ), 17, '2026-08-26 12:00:00' );
	throw new RuntimeException( 'Missing evaluation owner port did not fail closed.' );
} catch ( Legacy_Target_Transformation_Failure $failure ) {
	g5_d3d6_same( Legacy_Target_Transformation_Failure::UNSUPPORTED, $failure->reason(), 'Missing evaluation owner port raised the wrong failure.' );
}

$writer_source    = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Evaluation_Writer.php' );
$projector_source = file_get_contents( $root . '/src/Operations/LegacyMigration/Domain/Legacy_Migration_Evaluation_Projector.php' );
$dispatch_source  = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Target_Writer.php' );
g5_d3d6_assert( is_string( $writer_source ) && is_string( $projector_source ) && is_string( $dispatch_source ), 'D3-D6 runtime source could not be read.' );
foreach ( array(
	"'oj_ai_submission_evaluations'",
	"'oj_ai_submission_evaluation_inputs'",
	"'oj_ai_evaluation_runs'",
	"'oj_ai_submission_evaluation_results'",
	"'oj_ai_submission_evaluation_items'",
	"'oj_ai_submission_evaluation_reviews'",
	"'oj_ai_submission_feedback_revisions'",
	"'oj_ai_submission_release_revisions'",
	"'oj_ai_student_note_revisions'",
) as $literal_table ) {
	g5_d3d6_assert( str_contains( $writer_source, $literal_table ), 'D3-D6 writer lost a literal target table: ' . $literal_table );
}
foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'START TRANSACTION', 'COMMIT', 'ROLLBACK', 'oj_ai_audit_events', 'oj_ai_outbox_events' ) as $forbidden ) {
	g5_d3d6_assert( ! str_contains( $writer_source . $projector_source, $forbidden ), 'D3-D6 runtime crossed a frozen boundary: ' . $forbidden );
}
g5_d3d6_assert( ! preg_match( "/['\"]raw_ai_response['\"]\s*=>/", $writer_source ), 'D3-D6 persisted the raw provider response.' );
g5_d3d6_assert( Ojai_Php_Source_Contract::contains( $writer_source, 'MAXIMUM_RELATIONS = 250' ), 'D3-D6 relation cap drifted.' );
g5_d3d6_assert( str_contains( $writer_source, "'legacy_inflight_cancelled'" ) && str_contains( $writer_source, "'legacy_result_missing'" ), 'D3-D6 did not make incomplete legacy runs terminal.' );
g5_d3d6_assert( str_contains( $writer_source, 'result_database_id' ), 'D3-D6 review rows are not rebound to inserted result IDs.' );
g5_d3d6_assert( str_contains( $dispatch_source, 'Legacy_Migration_Evaluation_Writer_Port_Interface' ), 'D3-D6 owner port dispatch is missing.' );
g5_d3d6_assert( ! str_contains( $writer_source . $projector_source . $dispatch_source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'D3-D6 referenced a platform implementation namespace.' );

echo "G5-IMP-07-S03-D3-D6 contract passed.\n";
