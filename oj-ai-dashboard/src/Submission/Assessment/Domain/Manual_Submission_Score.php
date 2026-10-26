<?php
/**
 * A pinned, unscored rubric prepared locally for a teacher, not an AI response.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local preparation of an unscored, immutable rubric for teacher assessment.
 */
final class Manual_Submission_Score {
	public const OPERATION = 'manual_prepare';
	public const TASK      = 'oj-ai.teacher-assessment.prepare';

	/**
	 * Prepare the empty score sheet from validated, immutable bindings.
	 *
	 * @param Submission_Score_Input $input Validated submission and rubric.
	 * @return array<string,mixed>
	 */
	public static function prepare( Submission_Score_Input $input ): array {
		$payload = $input->operation_payload();
		$items   = array();
		foreach ( $input->binding_manifest() as $binding ) {
			$items[] = array(
				'binding_key'      => $binding['binding_key'],
				'criterion_id'     => $binding['criterion_id'],
				'response_id'      => $binding['response_id'],
				'scope'            => $binding['scope'],
				'position'         => $binding['position'],
				'maximum_points'   => $binding['maximum_points'],
				'levels'           => $binding['levels'] ?? null,
				'decision'         => 'abstain',
				'candidate_points' => null,
				'confidence'       => '0.0000',
				'rationale'        => null,
				'abstain_reason'   => 'not_requested',
				'evidence'         => array(),
				'flags'            => array(),
			);
		}
		return array(
			'schema_id'          => Submission_Evaluation_Output_Normalizer::RESULT_SCHEMA,
			'schema_version'     => Submission_Evaluation_Output_Normalizer::RESULT_VERSION,
			'evaluation_ref'     => $payload['subject']['evaluation_ref'],
			'source_hash'        => $payload['subject']['source_hash'],
			'provider_status'    => 'not_requested',
			'candidate_score'    => null,
			'maximum_score'      => $payload['rubric']['total_points'],
			'overall_confidence' => '0.0000',
			'abstained'          => true,
			'safety_flagged'     => false,
			'summary'            => '교사 직접 채점',
			'items'              => $items,
			'limitations'        => array(),
		);
	}
}
