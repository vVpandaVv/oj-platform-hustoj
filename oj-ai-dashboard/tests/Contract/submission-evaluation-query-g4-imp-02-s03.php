<?php
/**
 * G4-IMP-02-S03 submission evaluation Q16/Q17 query contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * G4-IMP-02-S03 submission evaluation Q16/Q17 query contract.
	 */
	final class WP_Error {
		/**
		 * Initialize wp error dependencies and state.
		 *
		 * @param string              $code Code.
		 * @param string              $message Message.
		 * @param array<string,mixed> $data Data.
		 */
		public function __construct( public string $code, public string $message, public array $data ) {
		}
	}
}

require_once ABSPATH . 'src/Worksheet/Authoring/Domain/Public_Id.php';
require_once ABSPATH . 'src/Worksheet/Authoring/Domain/Canonical_Json.php';
require_once ABSPATH . 'src/Assessment/Configuration/Domain/Assessment_Configuration_Profile.php';
require_once ABSPATH . 'src/Worksheet/Authoring/Application/Read_Worksheets.php';
require_once ABSPATH . 'src/Worksheet/Authoring/Infrastructure/Signed_Cursor_Codec.php';
require_once ABSPATH . 'src/Submission/Assessment/Application/Submission_Evaluation_Failure.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Score_Input_Builder.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Score_Evidence_Catalog.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Score_Output_Schema.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Evaluation_Output_Normalizer.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Evaluation_Query_Projector.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Evaluation_Candidate_Projector.php';
require_once ABSPATH . 'src/Submission/Assessment/Port/Submission_Evaluation_Query_Port_Interface.php';
require_once ABSPATH . 'src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Query.php';
require_once ABSPATH . 'src/Security/Addon_Capabilities.php';
require_once ABSPATH . 'src/Security/Policy/Policy_Value.php';
require_once ABSPATH . 'src/Security/Policy/Context_Scope.php';
require_once ABSPATH . 'src/Security/Policy/Object_Scope.php';
require_once ABSPATH . 'src/Security/Policy/Context_Authorization_Result.php';
require_once ABSPATH . 'src/Security/Policy/Object_Authorization_Result.php';
require_once ABSPATH . 'src/Security/Policy/Access_Policy_Request.php';
require_once ABSPATH . 'src/Security/Policy/Access_Policy_Decision.php';
require_once ABSPATH . 'src/Security/Policy/Port/Access_Policy_Port_Interface.php';
require_once ABSPATH . 'src/Rest/Port/Current_Actor_Port_Interface.php';
require_once ABSPATH . 'src/Rest/Port/Rest_Nonce_Verifier_Port_Interface.php';
require_once ABSPATH . 'src/Rest/Rest_Request_Metadata.php';
require_once ABSPATH . 'src/Rest/Idempotency_Key.php';
require_once ABSPATH . 'src/Rest/Rest_Problem.php';
require_once ABSPATH . 'src/Rest/Rest_Authorization_Result.php';
require_once ABSPATH . 'src/Rest/Rest_Permission_Grant.php';
require_once ABSPATH . 'src/Rest/Correlation_Id_Factory.php';
require_once ABSPATH . 'src/Rest/Rest_Envelope_Factory.php';
require_once ABSPATH . 'src/Rest/Infrastructure/WordPress_Rest_Request_Adapter.php';
require_once ABSPATH . 'src/Rest/Rest_Request_Authorizer.php';
require_once ABSPATH . 'src/Rest/Rest_Permission_Handoff.php';

use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;
use OJAI\Dashboard\Rest\Port\Rest_Nonce_Verifier_Port_Interface;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Candidate_Projector;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Query_Projector;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Query;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;

/**
 * G4 s03 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s03_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s03 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s03_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 s03 id.
 *
 * @return string
 */
function g4_s03_id(): string {
	static $counter = 41000;
	++$counter;
	$id = '01K' . str_pad( (string) $counter, 23, '0', STR_PAD_LEFT );
	g4_s03_assert( Public_Id::is_valid( $id ), 'Fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 s03 fixture.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param array|null     $levels Levels.
 * @param bool           $abstain Whether the evidence could not be verified.
 * @param string         $abstain_reason Stored diagnostic or model abstention reason.
 * @return array{root:array<string,mixed>,input:array<string,mixed>,result:array<string,mixed>,items:array<int,array<string,mixed>>,runs:array<int,array<string,mixed>>}
 */
function g4_s03_fixture( Canonical_Json $canonical, ?array $levels = null, bool $abstain = false, string $abstain_reason = 'insufficient_evidence' ): array {
	$manual = $abstain && 'not_requested' === $abstain_reason;
	$evaluation_id    = g4_s03_id();
	$submission_id    = g4_s03_id();
	$revision_id      = g4_s03_id();
	$publication_id   = g4_s03_id();
	$assignment_id    = g4_s03_id();
	$target_id        = g4_s03_id();
	$policy_id        = g4_s03_id();
	$rubric_id        = g4_s03_id();
	$review_rule_id   = g4_s03_id();
	$run_id           = g4_s03_id();
	$result_id        = g4_s03_id();
	$response_id      = g4_s03_id();
	$criterion_id     = g4_s03_id();
	$source_hash      = str_repeat( 'a', 64 );
	$publication_hash = str_repeat( 'b', 64 );
	$policy_hash      = str_repeat( 'c', 64 );
	$rubric_hash      = str_repeat( 'd', 64 );
	$review_rule_hash = str_repeat( 'e', 64 );
	$binding_key      = str_repeat( 'f', 64 );
	$display_json     = $canonical->encode( array( 'display_name' => 'Student A' ) );

	$authorization      = array(
		'schema_id'             => 'oj_ai.authorization_envelope',
		'schema_version'        => '1.0.0',
		'actor_user_id'         => 42,
		'shell'                 => 'console',
		'surface'               => 'rest',
		'action_key'            => $manual ? 'submission.evaluation.manual' : 'submission.evaluation.enqueue',
		'required_capabilities' => array( $manual ? Addon_Capabilities::REVIEW_ASSESSMENTS : Addon_Capabilities::ASSESS_SUBMISSIONS ),
		'context'               => array(
			'type'      => 'class',
			'id'        => 'class-g4-s03',
			'revision'  => 'context-r1',
			'operation' => 'evaluate',
		),
		'object'                => array(
			'type'                   => 'submission',
			'id'                     => $submission_id,
			'action'                 => 'evaluate',
			'authorized_revision'    => '7',
			'authorized_state'       => 'submitted',
			'actor_relation'         => 'context_staff',
			'official_revision_id'   => $revision_id,
			'official_revision_hash' => $source_hash,
		),
		'checked_at_utc'        => '2026-08-23 10:00:00',
	);
	$authorization_json = $canonical->encode( $authorization );

	$payload    = array(
		'artifacts'       => array(),
		'bindings'        => array(
			array(
				'binding_key'    => $binding_key,
				'criterion_id'   => $criterion_id,
				'label'          => 'Clarity',
				'maximum_points' => '10.0000',
				'position'       => 0,
				'response_id'    => $response_id,
				'scope'          => 'response',
			),
		),
		'instruction'     => array(
			'content_hash'     => str_repeat( '1', 64 ),
			'contract_version' => Submission_Score_Input_Builder::PROMPT_CONTRACT,
			'section_contract' => Submission_Score_Input_Builder::PROMPT_SECTION,
		),
		'operation'       => Submission_Score_Input_Builder::OPERATION,
		'output_contract' => array(
			'schema_id'      => Submission_Score_Input_Builder::OUTPUT_SCHEMA,
			'schema_version' => Submission_Score_Input_Builder::OUTPUT_VERSION,
		),
		'policy'          => array(
			'content_hash' => $policy_hash,
			'revision_ref' => $policy_id,
			'rules'        => array( 'review_required' => true ),
		),
		'responses'       => array(
			array(
				'answer'      => array( 'text' => 'A clear answer.' ),
				'kind'        => 'long_text',
				'label'       => 'Answer',
				'position'    => 0,
				'response_id' => $response_id,
			),
		),
		'rubric'          => array(
			'content_hash' => $rubric_hash,
			'criteria'     => array(
				array(
					'criterion_id'   => $criterion_id,
					'label'          => 'Clarity',
					'maximum_points' => '10.0000',
					'scope'          => 'response',
				),
			),
			'revision_ref' => $rubric_id,
			'total_points' => '10.0000',
		),
		'schema_id'       => Submission_Score_Input_Builder::REQUEST_SCHEMA,
		'schema_version'  => Submission_Score_Input_Builder::REQUEST_VERSION,
		'subject'         => array(
			'evaluation_ref'          => 'EV_' . str_repeat( 'A', 32 ),
			'grading_schema_hash'     => str_repeat( '2', 64 ),
			'locale'                  => 'ko-KR',
			'publication_hash'        => $publication_hash,
			'representation_set_hash' => str_repeat( '3', 64 ),
			'source_hash'             => $source_hash,
		),
	);
	$gateway    = array(
		'operation'          => Submission_Score_Input_Builder::OPERATION,
		'operation_contract' => Submission_Score_Input_Builder::OPERATION_CONTRACT,
		'operation_payload'  => $payload,
		'operation_schema'   => Submission_Score_Input_Builder::OPERATION_SCHEMA,
		'payload_hash'       => hash( 'sha256', $canonical->encode( $payload ) ),
	);
	$input_json = $canonical->encode( $gateway );

	$normalized_item = array(
		'binding_key'      => $binding_key,
		'criterion_id'     => $criterion_id,
		'response_id'      => $response_id,
		'scope'            => 'response',
		'decision'         => 'score',
		'candidate_points' => '7.0000',
		'maximum_points'   => '10.0000',
		'levels'           => $levels,
		'confidence'       => '0.7000',
		'rationale'        => 'The response addresses the task.',
		'abstain_reason'   => null,
		'evidence'         => array(
			array(
				'source' => 'response',
				'anchor' => 'response:' . $response_id,
				'quote'  => 'clear answer',
			),
		),
		'flags'            => array(),
		'position'         => 0,
	);
	if ( $abstain ) {
		$normalized_item = array_replace(
			$normalized_item,
			array(
				'decision'         => 'abstain',
				'candidate_points' => null,
				'confidence'       => '0.0000',
				'rationale'        => null,
				'abstain_reason'   => $abstain_reason,
				'evidence'         => array(),
				'flags'            => $manual ? array() : array( 'evidence_limited', 'teacher_review_required' ),
			)
		);
	}
	$item_flags          = $abstain && ! $manual ? implode( ',', $normalized_item['flags'] ) : null;
	$normalized          = array(
		'schema_id'          => Submission_Evaluation_Output_Normalizer::RESULT_SCHEMA,
		'schema_version'     => Submission_Evaluation_Output_Normalizer::RESULT_VERSION,
		'evaluation_ref'     => $payload['subject']['evaluation_ref'],
		'source_hash'        => $source_hash,
		'provider_status'    => $manual ? 'not_requested' : ( $abstain ? 'abstained' : 'complete' ),
		'candidate_score'    => $normalized_item['candidate_points'],
		'maximum_score'      => '10.0000',
		'overall_confidence' => $normalized_item['confidence'],
		'abstained'          => $abstain,
		'safety_flagged'     => false,
		'summary'            => 'A reviewable candidate score was produced.',
		'items'              => array( $normalized_item ),
		'limitations'        => array(),
	);
	$normalized_json     = $canonical->encode( $normalized );
	$normalized_hash     = hash( 'sha256', $normalized_json );
	$provider_digest     = str_repeat( '4', 64 );
	$result_content_hash = hash(
		'sha256',
		$canonical->encode(
			array(
				'evaluation_id'          => $evaluation_id,
				'normalized_result_hash' => $normalized_hash,
				'provider_output_digest' => $provider_digest,
				'run_id'                 => $run_id,
			)
		)
	);
	$evidence_payload    = array(
		'schema_id'      => Submission_Evaluation_Output_Normalizer::ITEM_EVIDENCE_SCHEMA,
		'schema_version' => Submission_Evaluation_Output_Normalizer::RESULT_VERSION,
		'binding_key'    => $binding_key,
		'decision'       => $normalized_item['decision'],
		'rationale'      => $normalized_item['rationale'],
		'abstain_reason' => $normalized_item['abstain_reason'],
		'evidence'       => $normalized_item['evidence'],
	);
	$evidence_json       = $canonical->encode( $evidence_payload );
	$evidence_hash       = hash( 'sha256', $evidence_json );
	$item_content_hash   = hash(
		'sha256',
		$canonical->encode(
			array(
				'candidate_points' => $normalized_item['candidate_points'],
				'confidence'       => $normalized_item['confidence'],
				'criterion_key'    => $criterion_id,
				'evidence_hash'    => $evidence_hash,
				'flags'            => $item_flags,
				'maximum_points'   => '10.0000',
				'position'         => 0,
				'question_key'     => $response_id,
			)
		)
	);

	$root = array(
		'evaluation_pk'                    => '1',
		'evaluation_id'                    => $evaluation_id,
		'evaluation_lock_version'          => '2',
		'evaluation_state'                 => 'review_required',
		'submission_revision_id'           => $revision_id,
		'submission_revision_hash'         => $source_hash,
		'worksheet_publication_id'         => $publication_id,
		'publication_hash'                 => $publication_hash,
		'policy_revision_id'               => $policy_id,
		'policy_hash'                      => $policy_hash,
		'rubric_revision_id'               => $rubric_id,
		'rubric_hash'                      => $rubric_hash,
		'review_rule_revision_id'          => $review_rule_id,
		'review_rule_hash'                 => $review_rule_hash,
		'prompt_contract_version'          => Submission_Score_Input_Builder::PROMPT_CONTRACT,
		'input_schema_version'             => Submission_Score_Input_Builder::REQUEST_VERSION,
		'output_schema_version'            => Submission_Score_Input_Builder::OUTPUT_VERSION,
		'requested_at_utc'                 => '2026-08-23 10:00:00',
		'evaluation_updated_at_utc'        => '2026-08-23 10:01:00',
		'authorization_envelope_version'   => '1.0.0',
		'authorization_envelope_json'      => $authorization_json,
		'authorization_envelope_hash'      => hash( 'sha256', $authorization_json ),
		'source_revision_content_hash'     => $source_hash,
		'source_revision_no'               => '1',
		'source_revision_state'            => 'saved',
		'submission_id'                    => $submission_id,
		'submission_state'                 => 'submitted',
		'official_revision_id'             => $revision_id,
		'official_review_id'               => null,
		'official_review_hash'             => null,
		'current_release_id'               => null,
		'current_release_hash'             => null,
		'submission_submitted_at_utc'      => '2026-08-23 09:55:00',
		'assignment_id'                    => $assignment_id,
		'assignment_title'                 => 'Writing worksheet',
		'assignment_state'                 => 'open',
		'context_type'                     => 'class',
		'context_id'                       => 'class-g4-s03',
		'context_revision'                 => 'context-r1',
		'assignment_due_at_utc'            => '2026-09-01 23:59:59',
		'assignment_publication_id'        => $publication_id,
		'assignment_publication_hash'      => $publication_hash,
		'target_id'                        => $target_id,
		'target_content_hash'              => str_repeat( '5', 64 ),
		'learner_user_id'                  => '71',
		'membership_revision'              => 'member-r1',
		'display_snapshot_schema'          => 'oj_ai.assignment_target_display',
		'display_snapshot_version'         => '1',
		'display_snapshot_json'            => $display_json,
		'display_snapshot_hash'            => hash( 'sha256', $display_json ),
		'target_snapshot_hash'             => str_repeat( '6', 64 ),
		'target_state'                     => 'active',
		'joined_review_rule_id'            => $review_rule_id,
		'joined_review_rule_hash'          => $review_rule_hash,
		'joined_review_rule_state'         => 'active',
		'review_rule_confidence_threshold' => '0.7500',
		'latest_run_id'                    => $run_id,
		'latest_run_no'                    => '1',
		'latest_run_state'                 => 'succeeded',
		'latest_run_operation_key'         => $manual ? 'manual_prepare' : 'score',
		'latest_run_queued_at_utc'         => '2026-08-23 10:00:00',
		'latest_run_updated_at_utc'        => '2026-08-23 10:01:00',
		'latest_result_id'                 => $result_id,
		'latest_result_content_hash'       => $result_content_hash,
		'latest_candidate_score'           => $normalized['candidate_score'],
		'latest_maximum_score'             => '10.0000',
		'latest_overall_confidence'        => $normalized['overall_confidence'],
		'latest_abstained'                 => $abstain ? '1' : '0',
		'latest_safety_flagged'            => '0',
		'latest_result_summary'            => $normalized['summary'],
		'latest_result_created_at_utc'     => '2026-08-23 10:01:00',
		'item_count'                       => '1',
		'review_needed_count'              => $abstain ? '1' : '0',
		'review_count'                     => '0',
		'latest_review_id'                 => null,
		'latest_review_no'                 => null,
		'latest_review_decision'           => null,
		'official_local_review_no'         => null,
		'feedback_count'                   => '0',
		'latest_feedback_id'               => null,
		'latest_feedback_revision_no'      => null,
		'latest_feedback_state'            => null,
		'release_count'                    => '0',
		'latest_release_id'                => null,
		'latest_release_no'                => null,
		'latest_release_state'             => null,
		'current_local_release_no'         => null,
		'overdue_flag'                     => '0',
		'priority_rank'                    => $abstain ? '4' : '2',
	);

	$input  = array(
		'input_content_hash'        => str_repeat( '7', 64 ),
		'projection_schema_version' => Submission_Score_Input_Builder::OPERATION_CONTRACT,
		'input_schema'              => Submission_Score_Input_Builder::REQUEST_SCHEMA,
		'input_version'             => '1',
		'input_json'                => $input_json,
		'input_hash'                => hash( 'sha256', $input_json ),
		'input_byte_size'           => (string) strlen( $input_json ),
		'redaction_version'         => Submission_Score_Input_Builder::REDACTION_VERSION,
	);
	$result = array(
		'result_pk'                    => '1',
		'result_id'                    => $result_id,
		'result_content_hash'          => $result_content_hash,
		'result_created_at_utc'        => '2026-08-23 10:01:00',
		'result_output_schema_version' => Submission_Score_Input_Builder::OUTPUT_VERSION,
		'result_candidate_score'       => $normalized['candidate_score'],
		'result_maximum_score'         => '10.0000',
		'result_overall_confidence'    => $normalized['overall_confidence'],
		'result_abstained'             => $abstain ? '1' : '0',
		'result_safety_flagged'        => '0',
		'result_summary'               => $normalized['summary'],
		'normalized_result_schema'     => Submission_Evaluation_Output_Normalizer::RESULT_SCHEMA,
		'normalized_result_version'    => '1',
		'normalized_result_json'       => $normalized_json,
		'normalized_result_hash'       => $normalized_hash,
		'provider_output_digest'       => $provider_digest,
		'result_run_id'                => $run_id,
	);
	$items  = array(
		array(
			'item_content_hash'     => $item_content_hash,
			'item_criterion_key'    => $criterion_id,
			'item_question_key'     => $response_id,
			'item_position'         => '0',
			'item_candidate_points' => $normalized_item['candidate_points'],
			'item_maximum_points'   => '10.0000',
			'item_confidence'       => $normalized_item['confidence'],
			'evidence_schema'       => Submission_Evaluation_Output_Normalizer::ITEM_EVIDENCE_SCHEMA,
			'evidence_version'      => '1',
			'evidence_json'         => $evidence_json,
			'evidence_hash'         => $evidence_hash,
			'item_flags'            => $item_flags,
		),
	);
	$runs   = array(
		array(
			'run_id'               => $run_id,
			'run_no'               => '1',
			'run_state'            => 'succeeded',
			'run_operation_key'    => $manual ? 'manual_prepare' : Submission_Score_Input_Builder::OPERATION,
			'run_result_id'        => $result_id,
			'run_available_at_utc' => '2026-08-23 10:00:00',
			'run_queued_at_utc'    => '2026-08-23 10:00:00',
			'run_started_at_utc'   => '2026-08-23 10:00:10',
			'run_finished_at_utc'  => '2026-08-23 10:01:00',
			'run_last_error_code'  => null,
			'attempt_count'        => '1',
		),
	);

	return compact( 'root', 'input', 'result', 'items', 'runs' );
}

/**
 * G4 s03 stored failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s03_stored_failure( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_s03_same( Submission_Evaluation_Failure::STORED_DATA_INVALID, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message . ': expected stored-data failure was not thrown.' );
}

/**
 * G4-IMP-02-S03 submission evaluation Q16/Q17 query contract.
 */
final class G4_S03_Query_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Pages.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	public array $pages;
	/**
	 * Sql.
	 *
	 * @var array<int,string>
	 */
	public array $sql = array();
	/**
	 * Initialize g4 s03 query database dependencies and state.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $pages Pages.
	 */
	public function __construct( array $pages ) {
		$this->pages = $pages;
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		foreach ( $values as $value ) {
			if ( 1 !== preg_match( '/%(s|d)/', $sql, $match ) ) {
				return '';
			}
			$replacement = 'd' === $match[1] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
			$sql         = preg_replace( '/%' . $match[1] . '/', $replacement, $sql, 1 ) ?? '';
		}
		return $sql;
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $sql, string $format ): array {
		unset( $format );
		$this->last_error = '';
		$this->sql[]      = $sql;
		return array_shift( $this->pages ) ?? array();
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, string $format ): ?array {
		unset( $sql, $format );
		$this->last_error = 'unexpected get_row';
		return null;
	}
}

/**
 * G4-IMP-02-S03 submission evaluation Q16/Q17 query contract.
 */
final class G4_S03_Current_Actor implements Current_Actor_Port_Interface {
	/**
	 * Current actor user id.
	 *
	 * @return int
	 */
	public function current_actor_user_id(): int {
		return 42;
	}
}
/**
 * G4-IMP-02-S03 submission evaluation Q16/Q17 query contract.
 */
final class G4_S03_Nonce implements Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Verify g4 s03 nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		unset( $nonce );
		return true;
	}
}
/**
 * G4-IMP-02-S03 submission evaluation Q16/Q17 query contract.
 */
final class G4_S03_Request {
	/**
	 * Get method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return 'GET';
	}
	/**
	 * Get header.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public function get_header( string $name ): string {
		unset( $name );
		return '';
	}
}
/**
 * G4-IMP-02-S03 submission evaluation Q16/Q17 query contract.
 */
final class G4_S03_Alternative_Policy implements Access_Policy_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var array<int,array<int,string>>
	 */
	public array $calls = array();
	/**
	 * Initialize g4 s03 alternative policy dependencies and state.
	 *
	 * @param bool $allow_review Allow review.
	 */
	public function __construct( private bool $allow_review ) {
	}
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->calls[] = $request->required_capabilities();
		if ( $this->allow_review && array( Addon_Capabilities::REVIEW_ASSESSMENTS ) === $request->required_capabilities() ) {
			return Access_Policy_Decision::allowed( $request->correlation_id() );
		}
		return Access_Policy_Decision::denied( 403, 'addon_capability_denied', $request->correlation_id() );
	}
}

$canonical = new Canonical_Json();
$fixture   = g4_s03_fixture( $canonical );
$projector = new Submission_Evaluation_Query_Projector( $canonical );

$list = $projector->list_item( $fixture['root'] );
g4_s03_same( 2, $list['priority']['rank'], 'Low-confidence work inbox priority drifted.' );
g4_s03_same( array( 'low_confidence' ), $list['priority']['reasons'], 'Priority reason drifted.' );
g4_s03_same( 'Student A', $list['learner']['display_name'], 'Safe learner display projection drifted.' );
g4_s03_same( true, $list['submission']['is_source_current'], 'Official source pointer drifted.' );

try {
	$detail = $projector->detail( $fixture['root'], $fixture['input'], $fixture['result'], $fixture['items'], $fixture['runs'], array(), array(), array() );
} catch ( Submission_Evaluation_Failure $failure ) {
	throw new RuntimeException( 'Valid Q17 fixture failed at ' . var_export( $failure->safe_context(), true ), 0, $failure );
}
g4_s03_same( $fixture['result']['result_id'], $detail['selected_result']['id'], 'Exact selected result drifted.' );

$abstain_fixture = g4_s03_fixture( $canonical, null, true );
$abstain_detail  = $projector->detail( $abstain_fixture['root'], $abstain_fixture['input'], $abstain_fixture['result'], $abstain_fixture['items'], $abstain_fixture['runs'], array(), array(), array() );
g4_s03_same( null, $abstain_detail['selected_result']['candidate_score'], 'Recovered evidence must not produce a displayed total.' );
g4_s03_same( null, $abstain_detail['selected_result']['items'][0]['candidate_points'], 'Recovered evidence must remain unscored in Q17.' );
g4_s03_same( 'insufficient_evidence', $abstain_detail['selected_result']['items'][0]['abstain_reason'], 'Q17 must explain why the criterion needs review.' );
g4_s03_same( array(), $abstain_detail['selected_result']['items'][0]['evidence'], 'Q17 must not restore rejected citations.' );
g4_s03_assert( is_array( $abstain_detail['review_precondition'] ), 'Recovered results must retain the manual-review precondition.' );
foreach ( array( 'evidence_reference_unknown', 'evidence_scope_mismatch', 'evidence_quote_mismatch', 'evidence_multiple_invalid' ) as $diagnostic ) {
	$diagnostic_fixture = g4_s03_fixture( $canonical, null, true, $diagnostic );
	$diagnostic_detail  = $projector->detail( $diagnostic_fixture['root'], $diagnostic_fixture['input'], $diagnostic_fixture['result'], $diagnostic_fixture['items'], $diagnostic_fixture['runs'], array(), array(), array() );
	g4_s03_same( $diagnostic, $diagnostic_detail['selected_result']['items'][0]['abstain_reason'], 'Verification reason must survive persisted item/hash checks and Q17 projection.' );
}

$long_levels  = array(
	array(
		'label'     => str_repeat( '가', 249 ) . "\n" . str_repeat( '나', 250 ),
		'level_key' => g4_s03_id(),
		'points'    => '10.0000',
		'position'  => 0,
	),
	array(
		'label'     => 'No evidence.',
		'level_key' => g4_s03_id(),
		'points'    => '0.0000',
		'position'  => 1,
	),
);
$long_fixture = g4_s03_fixture( $canonical, $long_levels );
$long_detail  = $projector->detail( $long_fixture['root'], $long_fixture['input'], $long_fixture['result'], $long_fixture['items'], $long_fixture['runs'], array(), array(), array() );
g4_s03_same( $long_levels, $long_detail['selected_result']['items'][0]['levels'], 'Result detail must preserve 500-character multiline performance descriptions.' );
foreach ( array( str_repeat( '가', 501 ), str_repeat( 'a', 501 ) ) as $invalid_label ) {
	$invalid_levels             = $long_levels;
	$invalid_levels[0]['label'] = $invalid_label;
	$invalid_fixture            = g4_s03_fixture( $canonical, $invalid_levels );
	g4_s03_stored_failure(
		static fn () => $projector->detail( $invalid_fixture['root'], $invalid_fixture['input'], $invalid_fixture['result'], $invalid_fixture['items'], $invalid_fixture['runs'], array(), array(), array() ),
		'Oversized result descriptions must fail by character count, not UTF-8 byte count.'
	);
}

$historical_failure                             = $fixture;
$failed_run_id                                  = g4_s03_id();
$historical_failure['root']['latest_run_id']    = $failed_run_id;
$historical_failure['root']['latest_run_no']    = '2';
$historical_failure['root']['latest_run_state'] = 'failed';
$historical_failure['runs'][]                   = array_replace(
	$fixture['runs'][0],
	array(
		'run_id'                 => $failed_run_id,
		'run_no'                 => '2',
		'run_state'              => 'failed',
		'run_result_id'          => null,
		'run_last_error_code'    => 'worker_exception',
		'run_last_error_message' => 'Authorization: Bearer secret-fixture-token; private student content',
	)
);
$safe_failure                                   = $projector->detail( $historical_failure['root'], $fixture['input'], $fixture['result'], $fixture['items'], $historical_failure['runs'], array(), array(), array() );
g4_s03_same( 'worker_exception', $safe_failure['runs'][0]['last_error_code'], 'Historical failures must retain a stable recovery code.' );
g4_s03_same( null, $safe_failure['runs'][0]['last_error_message'], 'Historical exception content must not reach the teacher projection.' );
g4_s03_assert( ! str_contains( $canonical->encode( $safe_failure ), 'secret-fixture-token' ), 'Historical private exception content leaked.' );
g4_s03_same( '7.0000', $detail['selected_result']['candidate_score'], 'Candidate score projection drifted.' );
g4_s03_same( 1, count( $detail['selected_result']['items'] ), 'Exact result item projection drifted.' );
g4_s03_same(
	array(
		'expected_result_id'            => $fixture['result']['result_id'],
		'expected_result_hash'          => $fixture['result']['result_content_hash'],
		'expected_official_review_id'   => null,
		'expected_official_review_hash' => null,
		'expected_review_no'            => 0,
	),
	$detail['review_precondition'],
	'Q17 must project opaque C34 compare-and-swap values without rendering them.'
);
$encoded = $canonical->encode( $detail );
foreach ( array( 'provider_output_digest', 'raw_response', 'raw_prompt', 'provider_key', 'model_key', 'authorization_envelope' ) as $forbidden ) {
	g4_s03_assert( ! str_contains( $encoded, $forbidden ), 'Teacher safe projection leaked: ' . $forbidden );
}

// Exercise the current writer's added field as well as the legacy fixture above.
require_once ABSPATH . 'src/Worksheet/Authoring/Domain/Worksheet_Document_Profile.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Worksheet_Context.php';
$context_projector = new \OJAI\Dashboard\Submission\Assessment\Domain\Submission_Worksheet_Context();
$worksheet_context = $context_projector->project(
	array(
		'title' => 'Comparison task',
		'nodes' => array(
			array(
				'type'     => 'p',
				'children' => array( array( 'text' => 'Compare 5 cm and 2 cm.' ) ),
			),
		),
	)
);
$with_payload      = static function ( array $payload ) use ( $fixture, $canonical ): array {
	$row                          = $fixture['input'];
	$gateway                      = json_decode( $row['input_json'], true, 128, JSON_THROW_ON_ERROR );
	$gateway['operation_payload'] = $payload;
	$gateway['payload_hash']      = hash( 'sha256', $canonical->encode( $payload ) );
	$row['input_json']            = $canonical->encode( $gateway );
	$row['input_hash']            = hash( 'sha256', $row['input_json'] );
	$row['input_byte_size']       = strlen( $row['input_json'] );
	return $row;
};
$current_payload   = json_decode( $fixture['input']['input_json'], true, 128, JSON_THROW_ON_ERROR )['operation_payload'];
$reference_payload = $current_payload;
foreach ( $reference_payload['bindings'] as $index => &$binding_row ) {
	$binding_row['binding_ref'] = 'B' . ( $index + 1 );
}
unset( $binding_row );
$reference_payload['output_contract']['allowed_binding_keys'] = array_column( $reference_payload['bindings'], 'binding_ref' );
$reference_input  = $with_payload( $reference_payload );
$reference_detail = $projector->detail( $fixture['root'], $reference_input, $fixture['result'], $fixture['items'], $fixture['runs'], array(), array(), array() );
g4_s03_same( 'B1', $reference_detail['source']['bindings'][0]['binding_ref'], 'Current reference inputs must remain readable alongside historical inputs.' );
$structured_payload                                   = $reference_payload;
$structured_payload['evidence_catalog']               = \OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Evidence_Catalog::build( $structured_payload['responses'], $structured_payload['artifacts'] );
$structured_payload['output_contract']['json_schema'] = \OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Output_Schema::build(
	$structured_payload['output_contract']['allowed_binding_keys'],
	array_column( $structured_payload['evidence_catalog'], 'evidence_ref' ),
	$structured_payload['subject']['evaluation_ref'],
	$structured_payload['subject']['source_hash']
);
$structured_input                                     = $with_payload( $structured_payload );
$structured_detail                                    = $projector->detail( $fixture['root'], $structured_input, $fixture['result'], $fixture['items'], $fixture['runs'], array(), array(), array() );
g4_s03_same( $reference_detail, $structured_detail, 'Structured input metadata must remain readable without leaking into the teacher projection.' );
$invalid_structured = array();
foreach ( array(
	null,
	'invalid',
	array(
		array(
			'evidence_ref' => 'E999',
			'quote'        => 'forged',
		),
	),
) as $bad_catalog ) {
	$invalid                     = $structured_payload;
	$invalid['evidence_catalog'] = $bad_catalog;
	$invalid_structured[]        = $invalid;
}
foreach ( array( null, array(), array( 'type' => 'string' ) ) as $bad_schema ) {
	$invalid                                   = $structured_payload;
	$invalid['output_contract']['json_schema'] = $bad_schema;
	$invalid_structured[]                      = $invalid;
}
$invalid = $structured_payload;
$invalid['output_contract']['json_schema']['properties']['source_hash']['const'] = str_repeat( '0', 64 );
$invalid_structured[]                           = $invalid;
$invalid                                        = $structured_payload;
$invalid['output_contract']['unknown_metadata'] = 'not allowed';
$invalid_structured[]                           = $invalid;
foreach ( $invalid_structured as $invalid_payload ) {
	g4_s03_stored_failure(
		static fn () => $projector->detail( $fixture['root'], $with_payload( $invalid_payload ), $fixture['result'], $fixture['items'], $fixture['runs'], array(), array(), array() ),
		'Malformed provider metadata, forged catalog and wrong schema subject must remain rejected.'
	);
}
$corrupt_structured               = $structured_input;
$corrupt_structured['input_hash'] = str_repeat( '0', 64 );
g4_s03_stored_failure(
	static fn () => $projector->detail( $fixture['root'], $corrupt_structured, $fixture['result'], $fixture['items'], $fixture['runs'], array(), array(), array() ),
	'Structured metadata must not bypass immutable input hashes.'
);
$current_payload['worksheet_context'] = $worksheet_context;
$current_input                        = $with_payload( $current_payload );
$current_detail                       = $projector->detail( $fixture['root'], $current_input, $fixture['result'], $fixture['items'], $fixture['runs'], array(), array(), array() );
g4_s03_same( $detail, $current_detail, 'Worksheet context must not break or expand the safe teacher result projection.' );
foreach ( array(
	array_replace( $current_payload, array( 'unknown_context' => array() ) ),
	array_replace( $current_payload, array( 'worksheet_context' => null ) ),
	array_replace( $current_payload, array( 'worksheet_context' => array( 'title' => 'Task' ) ) ),
	array_replace(
		$current_payload,
		array(
			'worksheet_context' => array(
				'title'  => 'Task',
				'blocks' => 'invalid',
			),
		)
	),
	array_replace(
		$current_payload,
		array(
			'worksheet_context' => array(
				'title'  => 'Task',
				'blocks' => array( array( 'raw_prompt' => 'private' ) ),
			),
		)
	),
) as $invalid_payload ) {
	$invalid_input = $with_payload( $invalid_payload );
	g4_s03_stored_failure(
		static fn () => $projector->detail( $fixture['root'], $invalid_input, $fixture['result'], $fixture['items'], $fixture['runs'], array(), array(), array() ),
		'Unknown, malformed or private worksheet input must remain rejected.'
	);
}
$corrupt_input               = $current_input;
$corrupt_input['input_hash'] = str_repeat( '0', 64 );
g4_s03_stored_failure(
	static fn () => $projector->detail( $fixture['root'], $corrupt_input, $fixture['result'], $fixture['items'], $fixture['runs'], array(), array(), array() ),
	'Worksheet context must not bypass the immutable input hash.'
);

$tampered                           = $fixture['result'];
$tampered['normalized_result_hash'] = str_repeat( '9', 64 );
g4_s03_stored_failure(
	static fn () => $projector->detail( $fixture['root'], $fixture['input'], $tampered, $fixture['items'], $fixture['runs'], array(), array(), array() ),
	'Normalized result hash tampering must fail closed.'
);
$private_input = $fixture['input'];
$decoded_input = json_decode( $private_input['input_json'], true, 128, JSON_THROW_ON_ERROR );
$decoded_input['operation_payload']['responses'][0]['answer']['raw_response'] = 'private';
$decoded_input['payload_hash']    = hash( 'sha256', $canonical->encode( $decoded_input['operation_payload'] ) );
$private_input['input_json']      = $canonical->encode( $decoded_input );
$private_input['input_hash']      = hash( 'sha256', $private_input['input_json'] );
$private_input['input_byte_size'] = (string) strlen( $private_input['input_json'] );
g4_s03_stored_failure(
	static fn () => $projector->detail( $fixture['root'], $private_input, $fixture['result'], $fixture['items'], $fixture['runs'], array(), array(), array() ),
	'Forbidden source projection keys must fail closed.'
);

$second                              = $fixture['root'];
$second['evaluation_id']             = g4_s03_id();
$second['latest_run_id']             = g4_s03_id();
$second['latest_result_id']          = g4_s03_id();
$second['evaluation_updated_at_utc'] = '2026-08-23 10:02:00';
$database                            = new G4_S03_Query_Database( array( array( $fixture['root'], $second ), array( $second ) ) );
$cursors                             = new Signed_Cursor_Codec( $canonical, 'g4-s03-signed-cursor-secret' );
$query                               = new WordPress_Submission_Evaluation_Query( $canonical, $cursors, $database );
$page                                = $query->list_context( 42, 'class', 'class-g4-s03', 'context-r1', '', '', '', 1 );
g4_s03_same( 1, count( $page['items'] ), 'Q16 first page size drifted.' );
g4_s03_assert( '' !== $page['next_cursor'] && strlen( $page['next_cursor'] ) <= 191, 'Q16 signed cursor is missing or too long.' );
$decoded_cursor = $cursors->decode( $page['next_cursor'], 'q16' );
g4_s03_same( array( 'f', 'i', 'p', 't' ), array_keys( $decoded_cursor ), 'Q16 cursor fields drifted.' );
$next = $query->list_context( 42, 'class', 'class-g4-s03', 'context-r1', '', '', $page['next_cursor'], 1 );
g4_s03_same( $second['evaluation_id'], $next['items'][0]['id'], 'Q16 cursor continuation drifted.' );
g4_s03_assert( str_contains( $database->sql[1], 'HAVING (priority_rank <' ), 'Q16 cursor predicate must remain server-side.' );
g4_s03_assert( str_contains( $database->sql[1], 'oj_ai_submission_evaluations' ), 'Q16 must query the literal add-on table.' );
try {
	$query->list_context( 43, 'class', 'class-g4-s03', 'context-r1', '', '', $page['next_cursor'], 1 );
	throw new RuntimeException( 'A cursor must not cross actor scope.' );
} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
}
$tampered_cursor = substr( $page['next_cursor'], 0, -1 ) . ( str_ends_with( $page['next_cursor'], 'a' ) ? 'b' : 'a' );
try {
	$query->list_context( 42, 'class', 'class-g4-s03', 'context-r1', '', '', $tampered_cursor, 1 );
	throw new RuntimeException( 'A tampered cursor must fail.' );
} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
}

$candidate            = array(
	'submission_id'            => $fixture['root']['submission_id'],
	'submission_state'         => 'submitted',
	'official_revision_id'     => $fixture['root']['submission_revision_id'],
	'official_revision_hash'   => $fixture['root']['submission_revision_hash'],
	'submitted_at_utc'         => $fixture['root']['submission_submitted_at_utc'],
	'assignment_id'            => $fixture['root']['assignment_id'],
	'assignment_title'         => $fixture['root']['assignment_title'],
	'assignment_state'         => 'open',
	'target_state'             => 'active',
	'learner_user_id'          => $fixture['root']['learner_user_id'],
	'membership_revision'      => $fixture['root']['membership_revision'],
	'display_snapshot_schema'  => $fixture['root']['display_snapshot_schema'],
	'display_snapshot_version' => $fixture['root']['display_snapshot_version'],
	'display_snapshot_json'    => $fixture['root']['display_snapshot_json'],
	'display_snapshot_hash'    => $fixture['root']['display_snapshot_hash'],
);
$candidate_projection = ( new Submission_Evaluation_Candidate_Projector( $canonical ) )->project( $candidate );
g4_s03_same( 'candidate', $candidate_projection['kind'], 'Unevaluated submission kind drifted.' );
g4_s03_same( 'not_evaluated', $candidate_projection['state'], 'Unevaluated submission state drifted.' );
g4_s03_same( 'Student A', $candidate_projection['learner']['display_name'], 'Candidate learner display drifted.' );
g4_s03_same( $fixture['root']['submission_revision_hash'], $candidate_projection['submission']['official_revision_hash'], 'Candidate official source hash drifted.' );

$invalid_candidate                          = $candidate;
$invalid_candidate['display_snapshot_hash'] = str_repeat( '0', 64 );
g4_s03_stored_failure(
	static fn () => ( new Submission_Evaluation_Candidate_Projector( $canonical ) )->project( $invalid_candidate ),
	'Tampered candidate display snapshot must fail closed.'
);

$older_candidate                         = $candidate;
$older_candidate['submission_id']        = g4_s03_id();
$older_candidate['official_revision_id'] = g4_s03_id();
$older_candidate['submitted_at_utc']     = '2026-08-23 09:54:00';
$candidate_database                      = new G4_S03_Query_Database( array( array( $candidate, $older_candidate ), array( $older_candidate ) ) );
$candidate_query                         = new WordPress_Submission_Evaluation_Query( $canonical, $cursors, $candidate_database );
$candidate_page                          = $candidate_query->list_unevaluated_context( 42, 'class', 'class-g4-s03', 'context-r1', '', '', 1 );
g4_s03_same( 1, count( $candidate_page['items'] ), 'Unevaluated candidate first page size drifted.' );
g4_s03_same( $candidate['submission_id'], $candidate_page['items'][0]['id'], 'Unevaluated candidate projection drifted.' );
g4_s03_assert( '' !== $candidate_page['next_cursor'], 'Unevaluated candidate cursor is missing.' );
$candidate_cursor = $cursors->decode( $candidate_page['next_cursor'], 'q16c' );
g4_s03_same( array( 'f', 'i', 't' ), array_keys( $candidate_cursor ), 'Unevaluated candidate cursor fields drifted.' );
$candidate_next = $candidate_query->list_unevaluated_context( 42, 'class', 'class-g4-s03', 'context-r1', '', $candidate_page['next_cursor'], 1 );
g4_s03_same( $older_candidate['submission_id'], $candidate_next['items'][0]['id'], 'Unevaluated candidate cursor continuation drifted.' );
g4_s03_assert(
	str_contains( $candidate_database->sql[0], 'NOT EXISTS (SELECT 1 FROM oj_ai_submission_evaluations' )
	&& str_contains( $candidate_database->sql[0], 'evaluated.submission_revision_public_id = s.official_revision_public_id' )
	&& str_contains( $candidate_database->sql[0], 'evaluated.submission_revision_hash = revision.content_hash' ),
	'Unevaluated candidate query must exclude an evaluation for the exact official source.'
);
g4_s03_assert( str_contains( $candidate_database->sql[0], 'FROM oj_ai_submissions s' ), 'Candidate query must use literal add-on tables.' );
g4_s03_assert(
	! str_contains( $candidate_database->sql[0], 'a.public_id =' ),
	'Unevaluated candidate query must not filter by assignment when omitted.'
);
try {
	$candidate_query->list_unevaluated_context( 43, 'class', 'class-g4-s03', 'context-r1', '', $candidate_page['next_cursor'], 1 );
	throw new RuntimeException( 'A candidate cursor must not cross actor scope.' );
} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
}

$assignment_id_filter          = $fixture['root']['assignment_id'];
$other_assignment_id           = g4_s03_id();
$assignment_candidate_database = new G4_S03_Query_Database( array( array( $candidate, $older_candidate ), array( $older_candidate ) ) );
$assignment_candidate_query    = new WordPress_Submission_Evaluation_Query( $canonical, $cursors, $assignment_candidate_database );
$assignment_candidate_page     = $assignment_candidate_query->list_unevaluated_context( 42, 'class', 'class-g4-s03', 'context-r1', $assignment_id_filter, '', 1 );
g4_s03_assert(
	str_contains( $assignment_candidate_database->sql[0], "AND a.public_id = '" . $assignment_id_filter . "'" ),
	'Unevaluated candidate query must filter by assignment when requested.'
);
try {
	$assignment_candidate_query->list_unevaluated_context( 42, 'class', 'class-g4-s03', 'context-r1', $other_assignment_id, $assignment_candidate_page['next_cursor'], 1 );
	throw new RuntimeException( 'A candidate cursor must not cross assignment scope.' );
} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
}

$correlation_counter = 0;
$correlations        = new Correlation_Id_Factory(
	static function () use ( &$correlation_counter ): string {
		++$correlation_counter;
		return 'correlation-g4-s03-' . $correlation_counter;
	}
);
$policy              = new G4_S03_Alternative_Policy( true );
$handoff             = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new G4_S03_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new G4_S03_Nonce(), $correlations ),
	new Rest_Envelope_Factory(),
	$correlations
);
$request             = new G4_S03_Request();
$allowed             = $handoff->check_any(
	$request,
	Access_Policy_Request::SHELL_CONSOLE,
	'submission.evaluation.view',
	array( array( Addon_Capabilities::ASSESS_SUBMISSIONS ), array( Addon_Capabilities::REVIEW_ASSESSMENTS ) )
);
g4_s03_same( true, $allowed, 'Reviewer-only capability alternative must be allowed.' );
g4_s03_same(
	array( array( Addon_Capabilities::ASSESS_SUBMISSIONS ), array( Addon_Capabilities::REVIEW_ASSESSMENTS ) ),
	$policy->calls,
	'Capability alternatives must be evaluated in declared order.'
);
g4_s03_assert( null !== $handoff->take( $request ), 'Allowed any-of authorization must hand off one grant.' );

$denied_policy  = new G4_S03_Alternative_Policy( false );
$denied_handoff = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new G4_S03_Current_Actor() ),
	new Rest_Request_Authorizer( $denied_policy, new G4_S03_Nonce(), $correlations ),
	new Rest_Envelope_Factory(),
	$correlations
);
$denied         = $denied_handoff->check_any(
	new G4_S03_Request(),
	Access_Policy_Request::SHELL_CONSOLE,
	'submission.evaluation.view',
	array( array( Addon_Capabilities::ASSESS_SUBMISSIONS ), array( Addon_Capabilities::REVIEW_ASSESSMENTS ) )
);
g4_s03_assert( $denied instanceof WP_Error && 403 === ( $denied->data['status'] ?? null ), 'Missing both alternatives must remain a 403 denial.' );

$manual_fixture = g4_s03_fixture( $canonical, array( array( 'label' => 'Complete', 'points' => '10.0000', 'level_key' => g4_s03_id(), 'position' => 0 ), array( 'label' => 'Absent', 'points' => '0.0000', 'level_key' => g4_s03_id(), 'position' => 1 ) ), true, 'not_requested' );
$manual_detail = $projector->detail( $manual_fixture['root'], $manual_fixture['input'], $manual_fixture['result'], $manual_fixture['items'], $manual_fixture['runs'], array(), array(), array() );
g4_s03_same( 'not_requested', $manual_detail['selected_result']['provider_status'], 'Manual rubric is not an AI response.' );
g4_s03_same( null, $manual_detail['selected_result']['candidate_score'], 'Manual rubric must remain unscored.' );
g4_s03_same( 'manual_prepare', $manual_detail['evaluation']['latest_run']['operation'], 'Inbox identifies teacher preparation.' );
g4_s03_assert( null !== $manual_detail['review_precondition'], 'Prepared manual rubric must allow teacher review.' );
$manual_fixture['runs'][0]['run_operation_key'] = 'score';
try {
	$projector->detail( $manual_fixture['root'], $manual_fixture['input'], $manual_fixture['result'], $manual_fixture['items'], $manual_fixture['runs'], array(), array(), array() );
	throw new RuntimeException( 'An AI run must not claim a locally prepared manual result.' );
} catch ( Submission_Evaluation_Failure $failure ) {
	// Cross-mode result/run links fail closed.
	g4_s03_same( Submission_Evaluation_Failure::STORED_DATA_INVALID, $failure->reason(), 'Invalid manual provenance must fail closed.' );
}

fwrite( STDOUT, "G4-IMP-02-S03 passed: Q16/Q17 safe projection, manual rubric, unevaluated submission candidates, exact result links, signed cursor scope, and capability alternatives are frozen.\n" );
