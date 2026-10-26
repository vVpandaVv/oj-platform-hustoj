<?php
/**
 * Q21 worksheet, assignment, and student report projector.
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
 * Q21 worksheet, assignment, and student report projector.
 */
final class Worksheet_Report_Detail_Projector {
	/**
	 * Metrics.
	 *
	 * @var Report_Metrics
	 */
	private Report_Metrics $metrics;

	/**
	 * Initialize worksheet report detail projector dependencies and state.
	 *
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @param Report_Metrics|null $metrics Metrics.
	 */
	public function __construct( private Canonical_Json $canonical, ?Report_Metrics $metrics = null ) {
		$this->metrics = $metrics ?? new Report_Metrics();
	}

	/**
	 * Project worksheet report detail projector.
	 *
	 * @param string              $worksheet_id Worksheet public identifier.
	 * @param array<string,mixed> $progress Progress.
	 * @param array<string,mixed> $worksheet Worksheet.
	 * @param array<string,mixed> $assessment Assessment.
	 * @return array{detail:array<string,mixed>,source_manifest:array<string,mixed>,source_manifest_hash:string}
	 */
	public function project( string $worksheet_id, array $progress, array $worksheet, array $assessment ): array {
		$this->public_id( $worksheet_id );
		$this->verify_source( $progress, 'oj_ai.assignment_report_detail_source' );
		$this->verify_source( $worksheet, 'oj_ai.worksheet_report_source' );
		$this->verify_source( $assessment, 'oj_ai.official_assessment_source' );
		$this->exact_keys(
			$progress,
			array( 'assignments', 'context', 'level', 'official_review_refs', 'publication', 'schema_id', 'schema_version', 'selected_assignment_id', 'source_hash', 'students', 'totals', 'updated_at_utc' )
		);
		$this->exact_keys( $worksheet, array( 'publication', 'revision', 'rubric', 'schema_id', 'schema_version', 'source_hash', 'updated_at_utc', 'worksheet' ) );
		$this->exact_keys( $assessment, array( 'criterion_totals', 'normalized_basis_points_sum', 'official_review_count', 'publication', 'released_count', 'reviews', 'schema_id', 'schema_version', 'scored_review_count', 'source_hash', 'updated_at_utc' ) );

		$publication = $this->publication( $progress['publication'] ?? null );
		$this->assert_matching_publication( $publication, $worksheet['publication'] ?? null );
		$this->assert_matching_publication( $publication, $assessment['publication'] ?? null );
		$worksheet_value  = $this->worksheet_value( $worksheet, $worksheet_id );
		$assessment_index = $this->assessment_index( $assessment );
		$reference_index  = $this->reference_index( $progress, $assessment_index['reviews'] );

		$totals = $this->totals( $progress['totals'] ?? null );
		if ( $totals['official_review_count'] !== count( $reference_index['by_review'] ) || $totals['official_review_count'] !== $assessment_index['review_count'] ) {
			$this->stored_invalid();
		}
		$summary                     = $this->progress_value( $totals, array_values( $assessment_index['reviews'] ) );
		$summary['assignment_count'] = $totals['assignment_count'];

		$assignment_values = $progress['assignments'] ?? null;
		if ( ! is_array( $assignment_values ) || ! array_is_list( $assignment_values ) ) {
			$this->stored_invalid();
		}
		$assignments    = array();
		$assignment_ids = array();
		foreach ( $assignment_values as $assignment ) {
			$projected = $this->assignment( $assignment, $reference_index['by_review'], $publication );
			if ( isset( $assignment_ids[ $projected['id'] ] ) ) {
				$this->stored_invalid();
			}
			$assignment_ids[ $projected['id'] ] = true;
			$assignments[]                      = $projected;
		}

		$level                  = $progress['level'] ?? null;
		$selected_assignment_id = $progress['selected_assignment_id'] ?? null;
		if ( ! in_array( $level, array( 'assignments', 'students' ), true ) ) {
			$this->stored_invalid();
		}
		if ( 'assignments' === $level ) {
			if ( null !== $selected_assignment_id || array() !== ( $progress['students'] ?? null ) ) {
				$this->stored_invalid();
			}
		} else {
			$selected_assignment_id = $this->public_id( $selected_assignment_id );
			if ( 1 !== count( $assignments ) || $assignments[0]['id'] !== $selected_assignment_id || 1 !== $totals['assignment_count'] ) {
				$this->stored_invalid();
			}
		}

		$student_values = $progress['students'] ?? null;
		if ( ! is_array( $student_values ) || ! array_is_list( $student_values ) ) {
			$this->stored_invalid();
		}
		$students    = array();
		$student_ids = array();
		foreach ( $student_values as $student ) {
			$projected = $this->student( $student, $reference_index['by_target'], $assessment_index['reviews'] );
			$target_id = $projected['target']['id'];
			if ( isset( $student_ids[ $target_id ] ) ) {
				$this->stored_invalid();
			}
			$student_ids[ $target_id ] = true;
			$students[]                = $projected;
		}

		$context = $progress['context'] ?? null;
		if ( ! is_array( $context ) || array_keys( $context ) !== array( 'type', 'id', 'revision' ) ) {
			$this->stored_invalid();
		}
		foreach ( $context as $value ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				$this->stored_invalid();
			}
		}

		$updated = $this->required_utc( $progress['updated_at_utc'] ?? null );
		$updated = $this->latest( $updated, $this->required_utc( $worksheet['updated_at_utc'] ?? null ) );
		if ( null !== ( $assessment['updated_at_utc'] ?? null ) ) {
			$updated = $this->latest( $updated, $this->required_utc( $assessment['updated_at_utc'] ) );
		}
		$detail = array(
			'worksheet'              => $worksheet_value['worksheet'],
			'publication'            => $worksheet_value['publication'],
			'rubric'                 => $worksheet_value['rubric'],
			'context'                => $context,
			'level'                  => $level,
			'selected_assignment_id' => $selected_assignment_id,
			'summary'                => $summary,
			'criterion_averages'     => $this->criterion_averages( $assessment['criterion_totals'] ?? null ),
			'assignments'            => $assignments,
			'students'               => $students,
			'updated_at_utc'         => $updated,
			'source'                 => array(
				'worksheet'           => array(
					'schema_id'      => $worksheet['schema_id'],
					'schema_version' => $worksheet['schema_version'],
					'hash'           => $worksheet['source_hash'],
				),
				'assignment_detail'   => array(
					'schema_id'      => $progress['schema_id'],
					'schema_version' => $progress['schema_version'],
					'hash'           => $progress['source_hash'],
				),
				'official_assessment' => array(
					'schema_id'      => $assessment['schema_id'],
					'schema_version' => $assessment['schema_version'],
					'hash'           => $assessment['source_hash'],
				),
			),
		);

		$manifest = array(
			'schema_id'      => 'oj_ai.report_source_manifest',
			'schema_version' => '1.0.0',
			'entries'        => array(
				array(
					'publication_id' => $publication['id'],
					'sources'        => array(
						array(
							'owner'          => 'Worksheet',
							'schema_id'      => $worksheet['schema_id'],
							'schema_version' => $worksheet['schema_version'],
							'hash'           => $worksheet['source_hash'],
						),
						array(
							'owner'          => 'AssignmentSubmission',
							'schema_id'      => $progress['schema_id'],
							'schema_version' => $progress['schema_version'],
							'hash'           => $progress['source_hash'],
						),
						array(
							'owner'          => 'Assessment',
							'schema_id'      => $assessment['schema_id'],
							'schema_version' => $assessment['schema_version'],
							'hash'           => $assessment['source_hash'],
						),
					),
				),
			),
		);
		return array(
			'detail'               => $detail,
			'source_manifest'      => $manifest,
			'source_manifest_hash' => hash( 'sha256', $this->canonical->encode( $manifest ) ),
		);
	}

	/**
	 * Worksheet value.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param string              $expected_id Expected id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function worksheet_value( array $source, string $expected_id ): array {
		$worksheet   = $source['worksheet'] ?? null;
		$publication = $source['publication'] ?? null;
		$rubric      = $source['rubric'] ?? null;
		if ( ! is_array( $worksheet ) || ! is_array( $publication ) || ! is_array( $rubric ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $worksheet, array( 'id', 'state', 'subject_label', 'title' ) );
		$this->exact_keys( $publication, array( 'content_hash', 'hash', 'id', 'number', 'published_at_utc', 'state' ) );
		$this->exact_keys( $rubric, array( 'content_hash', 'criterion_count', 'revision_id', 'total_points' ) );
		$id = $this->public_id( $worksheet['id'] ?? null );
		if ( ! hash_equals( $expected_id, $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}
		return array(
			'worksheet'   => array(
				'id'            => $id,
				'title'         => $this->text( $worksheet['title'] ?? null ),
				'state'         => $this->text( $worksheet['state'] ?? null ),
				'subject_label' => null === ( $worksheet['subject_label'] ?? null ) ? null : $this->text( $worksheet['subject_label'] ),
			),
			'publication' => array(
				'id'               => $this->public_id( $publication['id'] ?? null ),
				'hash'             => $this->hash_value( $publication['hash'] ?? null ),
				'number'           => $this->positive_integer( $publication['number'] ?? null ),
				'state'            => $this->text( $publication['state'] ?? null ),
				'published_at_utc' => $this->required_utc( $publication['published_at_utc'] ?? null ),
			),
			'rubric'      => array(
				'revision_id'     => $this->public_id( $rubric['revision_id'] ?? null ),
				'content_hash'    => $this->hash_value( $rubric['content_hash'] ?? null ),
				'total_points'    => $this->decimal( $rubric['total_points'] ?? null ),
				'criterion_count' => $this->non_negative_integer( $rubric['criterion_count'] ?? null ),
			),
		);
	}

	/**
	 * Assessment index.
	 *
	 * @param array<string,mixed> $assessment Assessment.
	 * @return array{reviews:array<string,array<string,mixed>>,review_count:int}
	 */
	private function assessment_index( array $assessment ): array {
		$reviews = $assessment['reviews'] ?? null;
		if ( ! is_array( $reviews ) || ! array_is_list( $reviews ) ) {
			$this->stored_invalid();
		}
		$index     = array();
		$score_sum = 0;
		$released  = 0;
		foreach ( $reviews as $review ) {
			if ( ! is_array( $review ) ) {
				$this->stored_invalid();
			}
			$this->exact_keys( $review, array( 'criteria', 'decision', 'hash', 'id', 'normalized_basis_points', 'release', 'result', 'reviewed_at_utc', 'score_decisions_hash' ) );
			$id = $this->public_id( $review['id'] ?? null );
			if ( isset( $index[ $id ] ) ) {
				$this->stored_invalid();
			}
			$this->hash_value( $review['hash'] ?? null );
			$this->hash_value( $review['score_decisions_hash'] ?? null );
			$this->required_utc( $review['reviewed_at_utc'] ?? null );
			$basis_points = $this->non_negative_integer( $review['normalized_basis_points'] ?? null );
			if ( $basis_points > 10000 ) {
				$this->stored_invalid();
			}
			$score_sum += $basis_points;
			$release    = $review['release'] ?? null;
			if ( null !== $release ) {
				$this->release( $release );
				if ( in_array( $release['state'], array( 'released', 'corrected' ), true ) ) {
					++$released;
				}
			}
			$index[ $id ] = $review;
		}
		$count = $this->non_negative_integer( $assessment['official_review_count'] ?? null );
		if (
			$count !== count( $index ) || $count !== $this->non_negative_integer( $assessment['scored_review_count'] ?? null )
			|| $score_sum !== $this->non_negative_integer( $assessment['normalized_basis_points_sum'] ?? null )
			|| $released !== $this->non_negative_integer( $assessment['released_count'] ?? null )
		) {
			$this->stored_invalid();
		}
		return array(
			'reviews'      => $index,
			'review_count' => $count,
		);
	}

	/**
	 * Reference index.
	 *
	 * @param array<string,mixed>               $progress Progress.
	 * @param array<string,array<string,mixed>> $reviews Reviews.
	 * @return array{by_review:array<string,array<string,mixed>>,by_target:array<string,array<string,mixed>>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function reference_index( array $progress, array $reviews ): array {
		$references = $progress['official_review_refs'] ?? null;
		if ( ! is_array( $references ) || ! array_is_list( $references ) ) {
			$this->stored_invalid();
		}
		$by_review = array();
		$by_target = array();
		foreach ( $references as $reference ) {
			if ( ! is_array( $reference ) ) {
				$this->stored_invalid();
			}
			$this->exact_keys( $reference, array( 'assignment_id', 'release', 'review', 'target_id' ) );
			$assignment_id = $this->public_id( $reference['assignment_id'] ?? null );
			$target_id     = $this->public_id( $reference['target_id'] ?? null );
			$review_value  = $this->reference_value( $reference['review'] ?? null );
			$release_value = null === ( $reference['release'] ?? null ) ? null : $this->reference_value( $reference['release'] );
			$review_id     = $review_value['id'];
			if ( isset( $by_review[ $review_id ] ) || isset( $by_target[ $target_id ] ) || ! isset( $reviews[ $review_id ] ) ) {
				$this->stored_invalid();
			}
			$assessment = $reviews[ $review_id ];
			if ( ! hash_equals( $review_value['hash'], $assessment['hash'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
			}
			$assessment_release = $assessment['release'] ?? null;
			if ( ( null === $release_value ) !== ( null === $assessment_release ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
			}
			if ( null !== $release_value && ( ! hash_equals( $release_value['id'], $assessment_release['id'] ) || ! hash_equals( $release_value['hash'], $assessment_release['hash'] ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
			}
			$value                   = array(
				'assignment_id' => $assignment_id,
				'target_id'     => $target_id,
				'review'        => $assessment,
			);
			$by_review[ $review_id ] = $value;
			$by_target[ $target_id ] = $value;
		}
		if ( count( $by_review ) !== count( $reviews ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}
		return array(
			'by_review' => $by_review,
			'by_target' => $by_target,
		);
	}

	/**
	 * Assignment.
	 *
	 * @param mixed                             $value Raw assignment value; validated before field access.
	 * @param array<string,array<string,mixed>> $reviews Reviews.
	 * @param array{id:string,hash:string}      $publication Publication.
	 * @return array<string,mixed>
	 */
	private function assignment( mixed $value, array $reviews, array $publication ): array {
		if ( ! is_array( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $value, array( 'closes_at_utc', 'due_at_utc', 'hash', 'id', 'official_review_count', 'official_review_refs', 'official_submission_count', 'opens_at_utc', 'state', 'target_count', 'target_snapshot_hash', 'title', 'updated_at_utc' ) );
		$id   = $this->public_id( $value['id'] ?? null );
		$refs = $value['official_review_refs'] ?? null;
		if ( ! is_array( $refs ) || ! array_is_list( $refs ) ) {
			$this->stored_invalid();
		}
		$assignment_reviews = array();
		foreach ( $refs as $ref ) {
			if ( ! is_array( $ref ) || ( $ref['assignment_id'] ?? null ) !== $id || ! is_array( $ref['review'] ?? null ) ) {
				$this->stored_invalid();
			}
			$review_id = $this->public_id( $ref['review']['id'] ?? null );
			if ( ! isset( $reviews[ $review_id ] ) || $reviews[ $review_id ]['assignment_id'] !== $id ) {
				$this->stored_invalid();
			}
			$assignment_reviews[] = $reviews[ $review_id ]['review'];
		}
		$counts = array(
			'target_count'              => $this->non_negative_integer( $value['target_count'] ?? null ),
			'official_submission_count' => $this->non_negative_integer( $value['official_submission_count'] ?? null ),
			'official_review_count'     => $this->non_negative_integer( $value['official_review_count'] ?? null ),
		);
		if ( $counts['official_review_count'] !== count( $assignment_reviews ) ) {
			$this->stored_invalid();
		}
		return array(
			'id'                   => $id,
			'hash'                 => $this->hash_value( $value['hash'] ?? null ),
			'title'                => $this->text( $value['title'] ?? null ),
			'state'                => $this->text( $value['state'] ?? null ),
			'schedule'             => array(
				'opens_at_utc'  => $this->optional_utc( $value['opens_at_utc'] ?? null ),
				'due_at_utc'    => $this->optional_utc( $value['due_at_utc'] ?? null ),
				'closes_at_utc' => $this->optional_utc( $value['closes_at_utc'] ?? null ),
			),
			'target_snapshot_hash' => $this->hash_value( $value['target_snapshot_hash'] ?? null ),
			'progress'             => $this->progress_value( $counts, $assignment_reviews ),
			'updated_at_utc'       => $this->required_utc( $value['updated_at_utc'] ?? null ),
			'publication'          => $publication,
		);
	}

	/**
	 * Student.
	 *
	 * @param mixed                             $value Raw student value; validated before field access.
	 * @param array<string,array<string,mixed>> $by_target By target.
	 * @param array<string,array<string,mixed>> $reviews Reviews.
	 * @return array<string,mixed>
	 */
	private function student( mixed $value, array $by_target, array $reviews ): array {
		if ( ! is_array( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $value, array( 'official_review_ref', 'submission', 'target' ) );
		$target = $value['target'] ?? null;
		if ( ! is_array( $target ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $target, array( 'display_name', 'hash', 'id', 'learner_user_id', 'membership_revision', 'snapshot_hash' ) );
		$target_id = $this->public_id( $target['id'] ?? null );
		$this->hash_value( $target['hash'] ?? null );
		$submission = $this->submission( $value['submission'] ?? null );
		$reference  = $value['official_review_ref'] ?? null;
		$assessment = null;
		if ( null !== $reference ) {
			if ( ! isset( $by_target[ $target_id ] ) || ! is_array( $reference ) || ! is_array( $reference['review'] ?? null ) ) {
				$this->stored_invalid();
			}
			$review_id = $this->public_id( $reference['review']['id'] ?? null );
			if ( ! isset( $reviews[ $review_id ] ) || $by_target[ $target_id ]['review']['id'] !== $review_id ) {
				$this->stored_invalid();
			}
			$assessment = $this->student_assessment( $reviews[ $review_id ] );
		}
		if ( null !== $assessment && null === ( $submission['official_revision'] ?? null ) ) {
			$this->stored_invalid();
		}
		return array(
			'target'              => array(
				'id'                  => $target_id,
				'learner_user_id'     => $this->positive_integer( $target['learner_user_id'] ?? null ),
				'display_name'        => $this->text( $target['display_name'] ?? null ),
				'membership_revision' => $this->text( $target['membership_revision'] ?? null ),
				'snapshot_hash'       => $this->hash_value( $target['snapshot_hash'] ?? null ),
			),
			'submission'          => $submission,
			'official_assessment' => $assessment,
			'status'              => $this->student_status( $submission, $assessment ),
		);
	}

	/**
	 * Submission.
	 *
	 * @param mixed $value Value.
	 * @return null|array<string,mixed>
	 */
	private function submission( mixed $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $value, array( 'hash', 'id', 'official_revision', 'started_at_utc', 'state', 'submitted_at_utc' ) );
		$official = $value['official_revision'] ?? null;
		if ( null !== $official ) {
			$official = $this->reference_value( $official );
		}
		return array(
			'id'                => $this->public_id( $value['id'] ?? null ),
			'state'             => $this->text( $value['state'] ?? null ),
			'started_at_utc'    => $this->optional_utc( $value['started_at_utc'] ?? null ),
			'submitted_at_utc'  => $this->optional_utc( $value['submitted_at_utc'] ?? null ),
			'official_revision' => $official,
		);
	}

	/**
	 * Student assessment.
	 *
	 * @param array<string,mixed> $review Review.
	 * @return array<string,mixed>
	 */
	private function student_assessment( array $review ): array {
		$basis_points = $this->non_negative_integer( $review['normalized_basis_points'] ?? null );
		$result       = $review['result'] ?? null;
		$criteria     = $review['criteria'] ?? null;
		if ( ! is_array( $result ) || ! is_array( $criteria ) || ! array_is_list( $criteria ) ) {
			$this->stored_invalid();
		}
		$result           = $this->reference_value( $result );
		$criterion_values = array();
		foreach ( $criteria as $criterion ) {
			if ( ! is_array( $criterion ) || array_keys( $criterion ) !== array( 'criterion_id', 'normalized_basis_points' ) ) {
				$this->stored_invalid();
			}
			$points = $this->non_negative_integer( $criterion['normalized_basis_points'] ?? null );
			if ( $points > 10000 ) {
				$this->stored_invalid();
			}
			$criterion_values[] = array(
				'criterion_id'            => $this->public_id( $criterion['criterion_id'] ?? null ),
				'normalized_basis_points' => $points,
				'value'                   => $this->metrics->individual_score( $points ),
			);
		}
		return array(
			'review'               => array(
				'id'              => $this->public_id( $review['id'] ?? null ),
				'hash'            => $this->hash_value( $review['hash'] ?? null ),
				'decision'        => $this->text( $review['decision'] ?? null ),
				'reviewed_at_utc' => $this->required_utc( $review['reviewed_at_utc'] ?? null ),
			),
			'result'               => $result,
			'score'                => array(
				'value'                   => $this->metrics->individual_score( $basis_points ),
				'normalized_basis_points' => $basis_points,
			),
			'criteria'             => $criterion_values,
			'release'              => null === ( $review['release'] ?? null ) ? null : $this->release( $review['release'] ),
			'score_decisions_hash' => $this->hash_value( $review['score_decisions_hash'] ?? null ),
		);
	}

	/**
	 * Student status.
	 *
	 * @param null|array<string,mixed> $submission Submission.
	 * @param null|array<string,mixed> $assessment Assessment.
	 * @return string
	 */
	private function student_status( ?array $submission, ?array $assessment ): string {
		if ( null === $submission || null === $submission['official_revision'] ) {
			return 'not_submitted';
		}
		if ( null === $assessment ) {
			return 'review_required';
		}
		$release = $assessment['release'];
		if ( null === $release ) {
			return 'reviewed';
		}
		return match ( $release['state'] ) {
			'released', 'corrected' => 'released',
			'withdrawn' => 'withdrawn',
			default => 'release_stale',
		};
	}

	/**
	 * Progress value.
	 *
	 * @param array<string,int>              $counts Counts.
	 * @param array<int,array<string,mixed>> $reviews Reviews.
	 * @return array<string,mixed>
	 */
	private function progress_value( array $counts, array $reviews ): array {
		$targets      = $counts['target_count'];
		$submissions  = $counts['official_submission_count'];
		$review_count = $counts['official_review_count'];
		if ( $submissions > $targets || $review_count > $submissions || $review_count !== count( $reviews ) ) {
			$this->stored_invalid();
		}
		$released = 0;
		$sum      = 0;
		foreach ( $reviews as $review ) {
			$points = $this->non_negative_integer( $review['normalized_basis_points'] ?? null );
			if ( $points > 10000 ) {
				$this->stored_invalid();
			}
			$sum    += $points;
			$release = $review['release'] ?? null;
			if ( is_array( $release ) && in_array( $release['state'] ?? null, array( 'released', 'corrected' ), true ) ) {
				++$released;
			}
		}
		return array(
			'target_count'          => $targets,
			'submission_rate'       => $this->metrics->ratio( $submissions, $targets ),
			'assessment_rate'       => $this->metrics->ratio( $review_count, $submissions ),
			'release_rate'          => $this->metrics->ratio( $released, $review_count ),
			'average_score'         => $this->metrics->average( $sum, $review_count ),
			'review_required_count' => $submissions - $review_count,
		);
	}

	/**
	 * Totals.
	 *
	 * @param mixed $value Value.
	 * @return array{assignment_count:int,target_count:int,official_submission_count:int,official_review_count:int}
	 */
	private function totals( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $value, array( 'assignment_count', 'official_review_count', 'official_submission_count', 'target_count' ) );
		return array(
			'assignment_count'          => $this->positive_integer( $value['assignment_count'] ?? null ),
			'target_count'              => $this->non_negative_integer( $value['target_count'] ?? null ),
			'official_submission_count' => $this->non_negative_integer( $value['official_submission_count'] ?? null ),
			'official_review_count'     => $this->non_negative_integer( $value['official_review_count'] ?? null ),
		);
	}

	/**
	 * Criterion averages.
	 *
	 * @param mixed $values Values.
	 * @return array<int,array<string,mixed>>
	 */
	private function criterion_averages( mixed $values ): array {
		if ( ! is_array( $values ) || ! array_is_list( $values ) ) {
			$this->stored_invalid();
		}
		$result = array();
		$seen   = array();
		foreach ( $values as $value ) {
			if ( ! is_array( $value ) || array_keys( $value ) !== array( 'criterion_id', 'normalized_basis_points_sum', 'n' ) ) {
				$this->stored_invalid();
			}
			$id = $this->public_id( $value['criterion_id'] ?? null );
			if ( isset( $seen[ $id ] ) ) {
				$this->stored_invalid();
			}
			$seen[ $id ] = true;
			$result[]    = array(
				'criterion_id' => $id,
				'average'      => $this->metrics->average(
					$this->non_negative_integer( $value['normalized_basis_points_sum'] ?? null ),
					$this->non_negative_integer( $value['n'] ?? null )
				),
			);
		}
		return $result;
	}

	/**
	 * Publication.
	 *
	 * @param mixed $value Value.
	 * @return array{id:string,hash:string}
	 */
	private function publication( mixed $value ): array {
		if ( ! is_array( $value ) || ! array_key_exists( 'id', $value ) || ! array_key_exists( 'hash', $value ) ) {
			$this->stored_invalid();
		}
		return array(
			'id'   => $this->public_id( $value['id'] ?? null ),
			'hash' => $this->hash_value( $value['hash'] ?? null ),
		);
	}

	/**
	 * Reject invalid matching publication.
	 *
	 * @param array $expected Expected.
	 * @param mixed $actual Actual.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_matching_publication( array $expected, mixed $actual ): void {
		$actual = $this->publication( $actual );
		if ( ! hash_equals( $expected['id'], $actual['id'] ) || ! hash_equals( $expected['hash'], $actual['hash'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}
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
	 * Release.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 */
	private function release( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $value, array( 'hash', 'id', 'released_at_utc', 'state' ) );
		$state = $value['state'] ?? null;
		if ( ! is_string( $state ) || ! in_array( $state, array( 'released', 'corrected', 'stale', 'withdrawn' ), true ) ) {
			$this->stored_invalid();
		}
		return array(
			'id'              => $this->public_id( $value['id'] ?? null ),
			'hash'            => $this->hash_value( $value['hash'] ?? null ),
			'state'           => $state,
			'released_at_utc' => $this->required_utc( $value['released_at_utc'] ?? null ),
		);
	}

	/**
	 * Verify source.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param string              $schema Schema.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function verify_source( array $source, string $schema ): void {
		if ( $schema !== ( $source['schema_id'] ?? null ) || '1.0.0' !== ( $source['schema_version'] ?? null ) ) {
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
	 * Latest.
	 *
	 * @param string $current Current.
	 * @param string $candidate Candidate.
	 * @return string
	 */
	private function latest( string $current, string $candidate ): string {
		return $candidate > $current ? $candidate : $current;
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
	 * Optional utc.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_utc( mixed $value ): ?string {
		return null === $value ? null : $this->required_utc( $value );
	}

	/**
	 * Decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function decimal( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,4})?$/D', $value ) ) {
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
