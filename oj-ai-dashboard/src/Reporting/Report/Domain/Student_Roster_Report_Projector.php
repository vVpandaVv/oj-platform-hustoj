<?php
/**
 * Student-lens roster report live projector.
 *
 * One row per student in a context, summarizing that student's activity
 * across every worksheet assigned to them -- the reverse shape of the
 * worksheet-lens report, which is one row per worksheet.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Domain;

use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student-lens roster report live projector.
 */
final class Student_Roster_Report_Projector {
	public const METRIC_CONTRACT = Report_Metrics::CONTRACT;

	/**
	 * Metrics.
	 *
	 * @var Report_Metrics
	 */
	private Report_Metrics $metrics;

	/**
	 * Initialize student roster report projector dependencies and state.
	 *
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @param Report_Metrics|null $metrics Metrics.
	 */
	public function __construct( private Canonical_Json $canonical, ?Report_Metrics $metrics = null ) {
		$this->metrics = $metrics ?? new Report_Metrics();
	}

	/**
	 * Project student roster report projector.
	 *
	 * @param array<int,array<string,mixed>> $roster_sources Roster sources.
	 * @param array<int,array<string,mixed>> $score_rows Score rows.
	 * @return array{items:array<int,array<string,mixed>>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function project( array $roster_sources, array $score_rows ): array {
		if ( ! Report_Source_Validator::records( $roster_sources, PHP_INT_MAX ) || ! Report_Source_Validator::records( $score_rows, PHP_INT_MAX ) ) {
			$this->stored_invalid();
		}
		$scores = array();
		foreach ( $score_rows as $row ) {
			$this->exact_keys( $row, array( 'normalized_basis_points', 'release_state', 'review_id' ) );
			$review_id = $this->public_id( $row['review_id'] ?? null );
			if ( isset( $scores[ $review_id ] ) ) {
				$this->stored_invalid();
			}
			$scores[ $review_id ] = $row;
		}

		$items         = array();
		$seen_learners = array();
		foreach ( $roster_sources as $source ) {
			$this->verify_source( $source );
			$this->exact_keys(
				$source,
				array( 'assignment_count', 'official_review_count', 'official_review_refs', 'official_submission_count', 'schema_id', 'schema_version', 'source_hash', 'target', 'updated_at_utc' )
			);
			$target = $this->target( $source['target'] ?? null );
			if ( isset( $seen_learners[ $target['learner_user_id'] ] ) ) {
				$this->stored_invalid();
			}
			$seen_learners[ $target['learner_user_id'] ] = true;

			$assignment_count      = $this->non_negative_integer( $source['assignment_count'] ?? null );
			$submission_count      = $this->non_negative_integer( $source['official_submission_count'] ?? null );
			$declared_review_count = $this->non_negative_integer( $source['official_review_count'] ?? null );
			$refs                  = $source['official_review_refs'] ?? null;
			if ( ! is_array( $refs ) || ! array_is_list( $refs ) ) {
				$this->stored_invalid();
			}
			$review_count = count( $refs );
			if ( $review_count !== $declared_review_count || $submission_count > $assignment_count || $review_count > $submission_count ) {
				$this->stored_invalid();
			}

			$released_count = 0;
			$score_sum      = 0;
			$seen_reviews   = array();
			foreach ( $refs as $ref ) {
				if ( ! is_array( $ref ) ) {
					$this->stored_invalid();
				}
				$this->exact_keys( $ref, array( 'release', 'review' ) );
				$review    = $this->reference_value( $ref['review'] ?? null );
				$release   = null === ( $ref['release'] ?? null ) ? null : $this->reference_value( $ref['release'] );
				$review_id = $review['id'];
				if ( isset( $seen_reviews[ $review_id ] ) || ! isset( $scores[ $review_id ] ) ) {
					$this->stored_invalid();
				}
				$seen_reviews[ $review_id ] = true;
				$resolved                   = $scores[ $review_id ];
				$has_release_state          = null !== ( $resolved['release_state'] ?? null );
				if ( ( null !== $release ) !== $has_release_state ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
				}
				$points = $this->non_negative_integer( $resolved['normalized_basis_points'] ?? null );
				if ( $points > 10000 ) {
					$this->stored_invalid();
				}
				$score_sum += $points;
				if ( in_array( $resolved['release_state'] ?? null, array( 'released', 'corrected' ), true ) ) {
					++$released_count;
				}
			}
			if ( count( $seen_reviews ) !== $review_count ) {
				$this->stored_invalid();
			}

			$items[] = array(
				'target'         => $target,
				'summary'        => array(
					'target_count'          => $assignment_count,
					'submission_rate'       => $this->metrics->ratio( $submission_count, $assignment_count ),
					'assessment_rate'       => $this->metrics->ratio( $review_count, $submission_count ),
					'release_rate'          => $this->metrics->ratio( $released_count, $review_count ),
					'average_score'         => $this->metrics->individual_average( $score_sum, $review_count ),
					'review_required_count' => $submission_count - $review_count,
				),
				'updated_at_utc' => $this->required_utc( $source['updated_at_utc'] ?? null ),
			);
		}

		return array( 'items' => $items );
	}

	/**
	 * Target.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 */
	private function target( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $value, array( 'display_name', 'hash', 'id', 'learner_user_id', 'membership_revision', 'snapshot_hash' ) );
		return array(
			'id'                  => $this->public_id( $value['id'] ?? null ),
			'learner_user_id'     => $this->positive_integer( $value['learner_user_id'] ?? null ),
			'display_name'        => $this->text( $value['display_name'] ?? null ),
			'membership_revision' => $this->text( $value['membership_revision'] ?? null ),
			'snapshot_hash'       => $this->hash_value( $value['snapshot_hash'] ?? null ),
		);
	}

	/**
	 * Reference value.
	 *
	 * @param mixed $value Value.
	 * @return array{id:string,hash:string}
	 */
	private function reference_value( mixed $value ): array {
		if ( ! is_array( $value ) || array_keys( $value ) !== array( 'id', 'hash' ) ) {
			$this->stored_invalid();
		}
		return array(
			'id'   => $this->public_id( $value['id'] ?? null ),
			'hash' => $this->hash_value( $value['hash'] ?? null ),
		);
	}

	/**
	 * Verify source.
	 *
	 * @param array<string,mixed> $source Source.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function verify_source( array $source ): void {
		if ( 'oj_ai.student_roster_source' !== ( $source['schema_id'] ?? null ) || '1.0.0' !== ( $source['schema_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_SCHEMA_UNSUPPORTED );
		}
		$stored_hash = $this->hash_value( $source['source_hash'] ?? null );
		$unsigned    = $source;
		unset( $unsigned['source_hash'] );
		if ( ! hash_equals( $stored_hash, hash( 'sha256', $this->canonical->encode( $unsigned ) ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $keys Keys.
	 * @return void
	 */
	private function exact_keys( array $value, array $keys ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		if ( $actual !== $keys ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function public_id( mixed $value ): string {
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function text( mixed $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			$this->stored_invalid();
		}
		return $this->canonical->text( $value );
	}

	/**
	 * Required utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function required_utc( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( mixed $value ): int {
		$value = $this->non_negative_integer( $value );
		if ( $value < 1 ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Report_Query_Failure::because( Report_Query_Failure::STORED_DATA_INVALID );
	}
}
