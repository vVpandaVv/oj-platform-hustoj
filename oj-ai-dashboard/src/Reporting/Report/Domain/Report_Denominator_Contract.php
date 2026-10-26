<?php
/**
 * Report denominator contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report denominator contract.
 */
final class Report_Denominator_Contract {
	public const SCHEMA_ID = 'oj_ai.report_denominator_contract';
	public const VERSION   = '1.0.0';

	/**
	 * Payload.
	 *
	 * @return array<string,mixed>
	 */
	public static function payload(): array {
		$population_included = array(
			'assignment_state_match',
			'assignment_period_match',
			'active_target_snapshot',
			'submission_state_match',
		);
		$population_excluded = array(
			'withdrawn_assignment',
			'assignment_state_filtered',
			'assignment_period_filtered',
			'inactive_target_snapshot',
			'submission_state_filtered',
		);

		return array(
			'schema_id'      => self::SCHEMA_ID,
			'schema_version' => self::VERSION,
			'metrics'        => array(
				'target_count'    => array(
					'denominator_key'       => 'target_count',
					'included_reason_codes' => $population_included,
					'excluded_reason_codes' => $population_excluded,
				),
				'submission_rate' => array(
					'denominator_key'       => 'target_count',
					'numerator_key'         => 'official_submission_count',
					'included_reason_codes' => array_merge( $population_included, array( 'official_submission_pointer_valid' ) ),
					'excluded_reason_codes' => array_merge( $population_excluded, array( 'official_submission_missing_or_invalid' ) ),
				),
				'assessment_rate' => array(
					'denominator_key'       => 'official_submission_count',
					'numerator_key'         => 'official_review_count',
					'included_reason_codes' => array( 'official_submission_pointer_valid', 'official_review_pointer_valid' ),
					'excluded_reason_codes' => array( 'no_official_submission', 'official_review_missing_or_invalid' ),
				),
				'release_rate'    => array(
					'denominator_key'       => 'official_review_count',
					'numerator_key'         => 'released_or_corrected_count',
					'included_reason_codes' => array( 'official_review_pointer_valid', 'current_release_released_or_corrected' ),
					'excluded_reason_codes' => array( 'no_official_review', 'release_missing', 'release_stale', 'release_withdrawn' ),
				),
				'average_score'   => array(
					'denominator_key'       => 'scored_official_review_count',
					'numerator_key'         => 'normalized_official_score_sum',
					'included_reason_codes' => array( 'official_review_accepted_or_adjusted', 'official_score_valid' ),
					'excluded_reason_codes' => array( 'review_rejected', 'score_missing_or_invalid', 'abstain_unresolved' ),
				),
			),
		);
	}
}
