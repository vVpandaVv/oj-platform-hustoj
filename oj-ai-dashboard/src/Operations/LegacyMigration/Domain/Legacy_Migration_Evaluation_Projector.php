<?php
/**
 * D3-D6 historical evaluation contract projector.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Domain;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Normalized_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Evidence_Catalog;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reprojects historical rows through the current score, feedback, note, and release contracts.
 */
final class Legacy_Migration_Evaluation_Projector {
	/**
	 * Score outputs.
	 *
	 * @var Submission_Evaluation_Output_Normalizer
	 */
	private Submission_Evaluation_Output_Normalizer $score_outputs;
	/**
	 * Feedback inputs.
	 *
	 * @var Submission_Feedback_Input_Builder
	 */
	private Submission_Feedback_Input_Builder $feedback_inputs;
	/**
	 * Feedback outputs.
	 *
	 * @var Submission_Feedback_Output_Normalizer
	 */
	private Submission_Feedback_Output_Normalizer $feedback_outputs;
	/**
	 * Note inputs.
	 *
	 * @var Student_Note_Input_Builder
	 */
	private Student_Note_Input_Builder $note_inputs;
	/**
	 * Note outputs.
	 *
	 * @var Student_Note_Output_Normalizer
	 */
	private Student_Note_Output_Normalizer $note_outputs;
	/**
	 * Releases.
	 *
	 * @var Submission_Result_Projection_Builder
	 */
	private Submission_Result_Projection_Builder $releases;

	/**
	 * Initialize legacy migration evaluation projector dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
		$this->score_outputs    = new Submission_Evaluation_Output_Normalizer( $canonical );
		$this->feedback_inputs  = new Submission_Feedback_Input_Builder( $canonical );
		$this->feedback_outputs = new Submission_Feedback_Output_Normalizer( $canonical );
		$this->note_inputs      = new Student_Note_Input_Builder( $canonical );
		$this->note_outputs     = new Student_Note_Output_Normalizer( $canonical );
		$this->releases         = new Submission_Result_Projection_Builder( $this->feedback_inputs, $this->feedback_outputs, $canonical );
	}

	/**
	 * Score input.
	 *
	 * @param string                 $evaluation_reference Evaluation reference.
	 * @param string                 $submission_revision_hash Submission revision hash.
	 * @param string                 $publication_hash Publication hash.
	 * @param string                 $grading_schema_hash Grading schema hash.
	 * @param array<string,mixed>    $grading_payload Current P03 payload.
	 * @param array<string,mixed>    $response_payload Current P02 payload.
	 * @param array<array-key,mixed> $answers Official revision answers to validate.
	 * @param string                 $locale Locale.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function score_input(
		string $evaluation_reference,
		string $submission_revision_hash,
		string $publication_hash,
		string $grading_schema_hash,
		array $grading_payload,
		array $response_payload,
		array $answers,
		string $locale
	): array {
		if (
			1 !== preg_match( '/^EV_[A-Z2-7]{32}$/D', $evaluation_reference )
			|| ! $this->is_hash( $submission_revision_hash )
			|| ! $this->is_hash( $publication_hash )
			|| ! $this->is_hash( $grading_schema_hash )
			|| 1 !== preg_match( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/D', $locale )
			|| ( array() !== $answers && array_is_list( $answers ) )
		) {
			throw new InvalidArgumentException( 'Legacy evaluation score source is invalid.' );
		}

		foreach ( array( 'bindings', 'policy', 'review_rule', 'rubric' ) as $key ) {
			if ( ! array_key_exists( $key, $grading_payload ) ) {
				throw new InvalidArgumentException( 'Legacy evaluation grading payload is incomplete.' );
			}
		}
		$bindings = $this->bindings( $grading_payload['bindings'] );
		$items    = $response_payload['items'] ?? null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			throw new InvalidArgumentException( 'Legacy evaluation response profile is invalid.' );
		}
		$responses       = $this->responses( $items, $answers );
		$known_responses = array_fill_keys( array_column( $responses, 'response_id' ), true );
		foreach ( $bindings as $binding ) {
			if ( 'response' === $binding['scope'] && ! isset( $known_responses[ $binding['response_id'] ] ) ) {
				throw new InvalidArgumentException( 'Legacy evaluation grading binding is orphaned.' );
			}
		}

		$policy                  = $this->pin( $grading_payload['policy'], false );
		$rubric                  = $this->pin( $grading_payload['rubric'], true );
		$review                  = $this->pin( $grading_payload['review_rule'], false );
		$representation_set_hash = hash( 'sha256', $this->canonical->encode( array() ) );
		$criteria                = array_map(
			static fn ( array $binding ): array => array(
				'criterion_id'   => $binding['criterion_id'],
				'label'          => $binding['label'],
				'maximum_points' => $binding['maximum_points'],
				'position'       => $binding['position'],
			),
			$bindings
		);
		$payload                 = array(
			'artifacts'       => array(),
			'bindings'        => $bindings,
			'instruction'     => array(
				'content_hash'     => Submission_Score_Input_Builder::PROMPT_CONTENT_HASH,
				'contract_version' => Submission_Score_Input_Builder::PROMPT_CONTRACT,
				'section_contract' => Submission_Score_Input_Builder::PROMPT_SECTION,
			),
			'operation'       => Submission_Score_Input_Builder::OPERATION,
			'output_contract' => array(
				'schema_id'      => Submission_Score_Input_Builder::OUTPUT_SCHEMA,
				'schema_version' => Submission_Score_Input_Builder::OUTPUT_VERSION,
			),
			'policy'          => array(
				'content_hash' => $policy['content_hash'],
				'revision_ref' => $policy['revision_id'],
				'rules'        => array( 'historical_only' => true ),
			),
			'responses'       => $responses,
			'rubric'          => array(
				'content_hash' => $rubric['content_hash'],
				'criteria'     => $criteria,
				'revision_ref' => $rubric['revision_id'],
				'total_points' => $rubric['total_points'],
			),
			'schema_id'       => Submission_Score_Input_Builder::REQUEST_SCHEMA,
			'schema_version'  => Submission_Score_Input_Builder::REQUEST_VERSION,
			'subject'         => array(
				'evaluation_ref'          => $evaluation_reference,
				'grading_schema_hash'     => $grading_schema_hash,
				'locale'                  => $locale,
				'publication_hash'        => $publication_hash,
				'representation_set_hash' => $representation_set_hash,
				'source_hash'             => $submission_revision_hash,
			),
		);
		$payload_json            = $this->canonical->encode( $payload );
		$gateway                 = array(
			'operation'          => Submission_Score_Input_Builder::OPERATION,
			'operation_contract' => Submission_Score_Input_Builder::OPERATION_CONTRACT,
			'operation_payload'  => $payload,
			'operation_schema'   => Submission_Score_Input_Builder::OPERATION_SCHEMA,
			'payload_hash'       => hash( 'sha256', $payload_json ),
		);
		$gateway_json            = $this->canonical->encode( $gateway );
		if ( strlen( $gateway_json ) > Submission_Score_Input_Builder::MAXIMUM_INPUT_BYTES ) {
			throw new InvalidArgumentException( 'Legacy evaluation score input is too large.' );
		}
		$artifacts_json       = $this->canonical->encode( array() );
		$source_snapshot_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'grading_schema_hash'      => $grading_schema_hash,
					'publication_hash'         => $publication_hash,
					'representation_set_hash'  => $representation_set_hash,
					'submission_revision_hash' => $submission_revision_hash,
				)
			)
		);

		return array(
			'artifact_references_hash' => hash( 'sha256', $artifacts_json ),
			'artifact_references_json' => $artifacts_json,
			'binding_manifest_hash'    => hash( 'sha256', $this->canonical->encode( $bindings ) ),
			'gateway_input'            => $gateway,
			'input_hash'               => hash( 'sha256', $gateway_json ),
			'input_json'               => $gateway_json,
			'policy_hash'              => $policy['content_hash'],
			'policy_revision_id'       => $policy['revision_id'],
			'representation_set_hash'  => $representation_set_hash,
			'review_rule_hash'         => $review['content_hash'],
			'review_rule_revision_id'  => $review['revision_id'],
			'rubric_hash'              => $rubric['content_hash'],
			'rubric_revision_id'       => $rubric['revision_id'],
			'source_snapshot_hash'     => $source_snapshot_hash,
		);
	}

	/**
	 * Score result.
	 *
	 * @param array<string,mixed>            $legacy_result Legacy result.
	 * @param array<int,array<string,mixed>> $legacy_items Legacy items.
	 * @param array<string,mixed>            $score_input Score input.
	 * @param array<int|string,string>       $legacy_question_response_ids Legacy question response ids.
	 * @return Submission_Evaluation_Normalized_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function score_result(
		array $legacy_result,
		array $legacy_items,
		array $score_input,
		array $legacy_question_response_ids
	): Submission_Evaluation_Normalized_Result {
		$gateway  = $score_input['gateway_input'] ?? null;
		$payload  = is_array( $gateway ) ? ( $gateway['operation_payload'] ?? null ) : null;
		$bindings = is_array( $payload ) ? ( $payload['bindings'] ?? null ) : null;
		$subject  = is_array( $payload ) ? ( $payload['subject'] ?? null ) : null;
		if ( ! is_array( $gateway ) || ! is_array( $bindings ) || ! array_is_list( $bindings ) || ! is_array( $subject ) ) {
			throw new InvalidArgumentException( 'Legacy evaluation score input is invalid.' );
		}
		$by_response = array();
		$unmapped    = array();
		foreach ( $legacy_items as $item ) {
			$question = $item['question_id'] ?? null;
			if ( ! is_string( $question ) && ! is_int( $question ) ) {
				throw new InvalidArgumentException( 'Legacy grading item identity is invalid.' );
			}
			$key         = (string) $question;
			$response_id = $legacy_question_response_ids[ $key ] ?? ( Public_Id::is_valid( $key ) ? $key : null );
			if ( null === $response_id ) {
				$unmapped[] = $item;
				continue;
			}
			if ( isset( $by_response[ $response_id ] ) ) {
				throw new InvalidArgumentException( 'Legacy grading item response is duplicated.' );
			}
			$by_response[ $response_id ] = $item;
		}

		// Live scoring rejects a text-response citation without a literal quote,
		// so cite the first server-built excerpt of the stored answer.
		$responses = $payload['responses'] ?? null;
		if ( ! is_array( $responses ) || ! array_is_list( $responses ) ) {
			throw new InvalidArgumentException( 'Legacy evaluation score input is invalid.' );
		}
		$excerpts = array();
		foreach ( Submission_Score_Evidence_Catalog::build( $responses, array() ) as $entry ) {
			$excerpts[ $entry['response_id'] ] ??= $entry['quote'];
		}

		$output_items = array();
		foreach ( $bindings as $binding ) {
			$response_id = $binding['response_id'] ?? null;
			$item        = is_string( $response_id ) ? ( $by_response[ $response_id ] ?? null ) : null;
			if ( null === $item && 1 === count( $unmapped ) ) {
				$item = array_shift( $unmapped );
			}
			if ( ! is_array( $item ) ) {
				throw new InvalidArgumentException( 'Legacy grading result is incomplete.' );
			}
			if ( ! is_string( $response_id ) || ! Public_Id::is_valid( $response_id ) ) {
				throw new InvalidArgumentException( 'Legacy grading evidence response is missing.' );
			}
			$score   = $this->decimal( $item['score'] ?? null );
			$maximum = $this->decimal( $item['max_score'] ?? null );
			if ( $maximum !== $this->decimal( $binding['maximum_points'] ?? null ) ) {
				throw new InvalidArgumentException( 'Legacy grading maximum differs from the published rubric.' );
			}
			$rationale      = $this->optional_text( $item['reason_text'] ?? null, 1500 )
				?? '과거 평가 결과를 현재 평가 계약으로 복원했습니다.';
			$output_items[] = array(
				'binding_key'      => $this->hash_value( $binding['binding_key'] ?? null ),
				'decision'         => 'score',
				'candidate_points' => $score,
				'confidence'       => 0.0,
				'rationale'        => $rationale,
				'evidence'         => array(
					array(
						'source' => 'response',
						'anchor' => 'response:' . $response_id,
						'quote'  => $excerpts[ $response_id ] ?? null,
					),
				),
				'flags'            => array( 'evidence_limited', 'teacher_review_required' ),
			);
		}
		if ( array() !== $unmapped || count( $output_items ) !== count( $legacy_items ) ) {
			throw new InvalidArgumentException( 'Legacy grading result contains an unknown item.' );
		}
		$summary    = $this->optional_text( $legacy_result['review_comment'] ?? null, 3000 )
			?? '과거 학생 제출 평가 결과를 현재 계약으로 복원했습니다.';
		$output     = array(
			'schema_id'      => Submission_Score_Input_Builder::OUTPUT_SCHEMA,
			'schema_version' => Submission_Score_Input_Builder::OUTPUT_VERSION,
			'evaluation_ref' => $subject['evaluation_ref'] ?? null,
			'source_hash'    => $subject['source_hash'] ?? null,
			'items'          => $output_items,
			'summary'        => $summary,
			'limitations'    => array( '과거 저장 결과를 AI 재실행 없이 현재 계약으로 복원했습니다.' ),
		);
		$normalized = $this->score_outputs->normalize( $output, $gateway );
		if (
			$this->decimal( $legacy_result['total_score'] ?? null ) !== $normalized->candidate_score()
			|| $this->decimal( $legacy_result['max_score'] ?? null ) !== $normalized->maximum_score()
		) {
			throw new InvalidArgumentException( 'Legacy grading aggregate differs from its items.' );
		}
		return $normalized;
	}

	/**
	 * Accepted score decisions.
	 *
	 * @param Submission_Evaluation_Normalized_Result $result Result.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function accepted_score_decisions( Submission_Evaluation_Normalized_Result $result ): array {
		try {
			$profile = json_decode( $result->normalized_json(), true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Normalized legacy score is invalid.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
		$items = is_array( $profile ) ? ( $profile['items'] ?? null ) : null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			throw new InvalidArgumentException( 'Normalized legacy score items are invalid.' );
		}
		$result_items = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || null === ( $item['candidate_points'] ?? null ) ) {
				throw new InvalidArgumentException( 'Legacy score cannot become an official review.' );
			}
			$result_items[] = array(
				'binding_key'     => $item['binding_key'],
				'criterion_id'    => $item['criterion_id'],
				'response_id'     => $item['response_id'],
				'scope'           => $item['scope'],
				'position'        => $item['position'],
				'source'          => 'ai_accepted',
				'official_points' => $item['candidate_points'],
				'maximum_points'  => $item['maximum_points'],
				'reason_code'     => null,
				'reason_note'     => null,
			);
		}
		return $result_items;
	}

	/**
	 * Feedback.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param mixed               $legacy_text Legacy text.
	 * @return array<string,mixed>
	 */
	public function feedback( array $source, mixed $legacy_text ): array {
		$input    = $this->feedback_inputs->build( $source );
		$payload  = $input->gateway_input()['operation_payload'];
		$criteria = array();
		foreach ( $payload['criteria'] as $criterion ) {
			$criteria[] = array(
				'binding_key' => $criterion['binding_key'],
				'next_step'   => '교사 피드백을 바탕으로 다음 학습을 이어가세요.',
				'strength'    => '평가 기준에 따른 학습 결과를 확인했습니다.',
			);
		}
		$text   = $this->optional_text( $legacy_text, Submission_Feedback_Input_Builder::MAXIMUM_OVERALL_FEEDBACK_CHARACTERS )
			?? '과거 피드백에는 공개할 본문이 없습니다.';
		$output = array(
			'criterion_feedback' => $criteria,
			'feedback_ref'       => $payload['subject']['feedback_ref'],
			'next_steps'         => array(),
			'overall_feedback'   => $text,
			'safety_flags'       => array(),
			'schema_id'          => Submission_Feedback_Input_Builder::OUTPUT_SCHEMA,
			'schema_version'     => Submission_Feedback_Input_Builder::OUTPUT_VERSION,
			'source_hash'        => $payload['subject']['source_hash'],
		);
		return array(
			'input'  => $input,
			'result' => $this->feedback_outputs->normalize( $output, $input->gateway_input() ),
		);
	}

	/**
	 * Student note.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param mixed               $legacy_text Legacy text.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function student_note( array $source, mixed $legacy_text ): array {
		$input   = $this->note_inputs->build( $source );
		$payload = $input->gateway_input()['operation_payload'];
		$known   = $payload['evidence'][0] ?? null;
		if ( ! is_array( $known ) ) {
			throw new InvalidArgumentException( 'Legacy student note lacks score evidence.' );
		}
		$text   = $this->optional_text( $legacy_text, Student_Note_Input_Builder::MAXIMUM_SUMMARY_CHARACTERS )
			?? '과거 특별 기록에는 본문이 없습니다.';
		$key    = $known['evidence_key'] ?? null;
		$output = array(
			'evidence'            => array(
				array(
					'evidence_key' => $key,
					'observation'  => $known['known_observation'] ?? null,
				),
			),
			'note_ref'            => $payload['subject']['note_ref'],
			'safety_flags'        => array(),
			'schema_id'           => Student_Note_Input_Builder::OUTPUT_SCHEMA,
			'schema_version'      => Student_Note_Input_Builder::OUTPUT_VERSION,
			'source_hash'         => $payload['subject']['source_hash'],
			'strengths'           => array(),
			'summary'             => array(
				'evidence_keys' => array( $key ),
				'text'          => $text,
			),
			'support_suggestions' => array(),
		);
		return array(
			'input'  => $input,
			'result' => $this->note_outputs->normalize( $output, $input->gateway_input() ),
		);
	}

	/**
	 * Release.
	 *
	 * @param array<string,mixed>      $source Source.
	 * @param string                   $worksheet_title Worksheet title.
	 * @param array<string,mixed>|null $feedback Feedback.
	 * @param string                   $state State.
	 * @param string                   $released_at_utc Released at utc.
	 * @return \OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection
	 */
	public function release(
		array $source,
		string $worksheet_title,
		?array $feedback,
		string $state,
		string $released_at_utc
	): \OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection {
		return $this->releases->build(
			$source,
			$worksheet_title,
			'total_and_criteria',
			null === $feedback ? 'hidden' : 'shown',
			$feedback,
			$state,
			$released_at_utc
		);
	}

	/**
	 * Bindings.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function bindings( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || count( $value ) > Submission_Score_Input_Builder::MAXIMUM_BINDINGS ) {
			throw new InvalidArgumentException( 'Legacy evaluation bindings are invalid.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $value as $position => $binding ) {
			if ( ! is_array( $binding ) || array_is_list( $binding ) ) {
				throw new InvalidArgumentException( 'Legacy evaluation binding is invalid.' );
			}
			foreach ( array( 'binding_key', 'criterion_id', 'label', 'maximum_points', 'position', 'response_id', 'scope' ) as $key ) {
				if ( ! array_key_exists( $key, $binding ) ) {
					throw new InvalidArgumentException( 'Legacy evaluation binding is incomplete.' );
				}
			}
			$binding_key  = $this->hash_value( $binding['binding_key'] );
			$criterion_id = $this->public_id( $binding['criterion_id'] );
			$response_id  = null === $binding['response_id'] ? null : $this->public_id( $binding['response_id'] );
			$scope        = $binding['scope'];
			if (
				isset( $seen[ $binding_key ] ) || $position !== $binding['position']
				|| ! in_array( $scope, array( 'response', 'submission' ), true )
				|| ( 'response' === $scope && null === $response_id )
				|| ( 'submission' === $scope && null !== $response_id )
			) {
				throw new InvalidArgumentException( 'Legacy evaluation binding identity is invalid.' );
			}
			$projected = array(
				'binding_key'    => $binding_key,
				'criterion_id'   => $criterion_id,
				'label'          => $this->text( $binding['label'], 255 ),
				'maximum_points' => $this->decimal( $binding['maximum_points'] ),
				'position'       => $position,
				'response_id'    => $response_id,
				'scope'          => $scope,
			);
			foreach ( array( 'correct_answer', 'example_answer' ) as $optional ) {
				if ( array_key_exists( $optional, $binding ) ) {
					$projected[ $optional ] = $binding[ $optional ];
				}
			}
			$result[]             = $projected;
			$seen[ $binding_key ] = true;
		}
		return $result;
	}

	/**
	 * Responses.
	 *
	 * @param array<array-key,mixed> $items Response items to validate.
	 * @param array<array-key,mixed> $answers Answers to validate.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function responses( array $items, array $answers ): array {
		if ( count( $items ) > Submission_Score_Input_Builder::MAXIMUM_RESPONSES ) {
			throw new InvalidArgumentException( 'Legacy evaluation response inventory is too large.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $items as $position => $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Legacy evaluation response item is invalid.' );
			}
			$id          = $this->public_id( $item['response_id'] ?? null );
			$kind        = $item['kind'] ?? null;
			$constraints = $item['constraints'] ?? array();
			if ( isset( $seen[ $id ] ) || ! is_string( $kind ) || ! is_array( $constraints ) || ( array() !== $constraints && array_is_list( $constraints ) ) ) {
				throw new InvalidArgumentException( 'Legacy evaluation response identity is invalid.' );
			}
			$value       = array_key_exists( $id, $answers ) ? $answers[ $id ] : null;
			$result[]    = array(
				'answer'      => $this->answer( $kind, $value, $constraints ),
				'kind'        => $kind,
				'label'       => $this->text( $item['label'] ?? null, 255 ),
				'position'    => $position,
				'response_id' => $id,
			);
			$seen[ $id ] = true;
		}
		foreach ( array_keys( $answers ) as $id ) {
			if ( ! is_string( $id ) || ! isset( $seen[ $id ] ) ) {
				throw new InvalidArgumentException( 'Legacy evaluation answer is outside the response profile.' );
			}
		}
		return $result;
	}

	/**
	 * Answer.
	 *
	 * @param string              $kind Kind.
	 * @param mixed               $value Value.
	 * @param array<string,mixed> $constraints Constraints.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function answer( string $kind, mixed $value, array $constraints ): array {
		if ( null === $value ) {
			return array( 'value' => null );
		}
		return match ( $kind ) {
			'short_text', 'long_text' => array( 'text' => $this->text( $value, 32768, true ) ),
			'single_choice' => array( 'option' => $this->option( $constraints, $value ) ),
			'multiple_choice' => array( 'options' => $this->options( $constraints, $value ) ),
			'checkbox' => is_bool( $value ) ? array( 'checked' => $value ) : throw new InvalidArgumentException( 'Legacy checkbox answer is invalid.' ),
			'number' => array( 'decimal' => $this->decimal( $value ) ),
			'table' => is_array( $value ) ? array( 'rows' => $value ) : throw new InvalidArgumentException( 'Legacy table answer is invalid.' ),
			'image_upload', 'file_upload', 'drawing' => throw new InvalidArgumentException( 'Legacy evaluation file answers require D3-D7.' ),
			default => throw new InvalidArgumentException( 'Legacy evaluation response kind is unsupported.' ),
		};
	}

	/**
	 * Option.
	 *
	 * @param array<string,mixed> $constraints Constraints.
	 * @param mixed               $value Value.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function option( array $constraints, mixed $value ): array {
		if ( ! is_string( $value ) || ! is_array( $constraints['options'] ?? null ) ) {
			throw new InvalidArgumentException( 'Legacy choice answer is invalid.' );
		}
		foreach ( $constraints['options'] as $option ) {
			if ( is_array( $option ) && $value === ( $option['option_id'] ?? null ) ) {
				return array(
					'option_id' => $this->public_id( $value ),
					'label'     => $this->text( $option['label'] ?? null, 255 ),
				);
			}
		}
		throw new InvalidArgumentException( 'Legacy choice answer is unknown.' );
	}

	/**
	 * Options.
	 *
	 * @param array<string,mixed> $constraints Constraints.
	 * @param mixed               $value Value.
	 * @return array<int,array<string,string>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function options( array $constraints, mixed $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Legacy multiple-choice answer is invalid.' );
		}
		return array_map( fn ( mixed $id ): array => $this->option( $constraints, $id ), $value );
	}

	/**
	 * Pin.
	 *
	 * @param mixed $value Value.
	 * @param bool  $total Total.
	 * @return array{revision_id:string,content_hash:string,total_points?:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function pin( mixed $value, bool $total ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Legacy grading configuration pin is invalid.' );
		}
		$result = array(
			'revision_id'  => $this->public_id( $value['revision_id'] ?? null ),
			'content_hash' => $this->hash_value( $value['content_hash'] ?? null ),
		);
		if ( $total ) {
			$result['total_points'] = $this->decimal( $value['total_points'] ?? null );
		}
		return $result;
	}

	/**
	 * Decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal( mixed $value ): string {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $matches ) ) {
			throw new InvalidArgumentException( 'Legacy evaluation decimal is invalid.' );
		}
		return $matches[1] . '.' . str_pad( $matches[2] ?? '', 4, '0' );
	}

	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $allow_empty Allow empty.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( mixed $value, int $maximum, bool $allow_empty = false ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Legacy evaluation text is invalid.' );
		}
		$value = trim( $value );
		if ( ( ! $allow_empty && '' === $value ) || $this->length( $value ) > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'Legacy evaluation text is invalid.' );
		}
		return $value;
	}

	/**
	 * Optional text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string|null
	 */
	private function optional_text( mixed $value, int $maximum ): ?string {
		if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return null;
		}
		return $this->text( $value, $maximum );
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Legacy evaluation public ID is invalid.' );
		}
		new Public_Id( $value );
		return $value;
	}

	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || ! $this->is_hash( $value ) ) {
			throw new InvalidArgumentException( 'Legacy evaluation hash is invalid.' );
		}
		return $value;
	}

	/**
	 * Is hash.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function is_hash( string $value ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	/**
	 * Length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}
