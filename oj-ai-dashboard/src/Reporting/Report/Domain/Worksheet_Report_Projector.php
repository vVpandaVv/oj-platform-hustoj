<?php
/**
 * Q20 worksheet report live projector.
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
 * Q20 worksheet report live projector.
 */
final class Worksheet_Report_Projector {
	public const METRIC_CONTRACT        = Report_Metrics::CONTRACT;
	public const MINIMUM_AGGREGATE_SIZE = Report_Metrics::MINIMUM_AGGREGATE_SIZE;

	/**
	 * Metrics.
	 *
	 * @var Report_Metrics
	 */
	private Report_Metrics $metrics;

	/**
	 * Initialize worksheet report projector dependencies and state.
	 *
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @param Report_Metrics|null $metrics Metrics.
	 */
	public function __construct( private Canonical_Json $canonical, ?Report_Metrics $metrics = null ) {
		$this->metrics = $metrics ?? new Report_Metrics();
	}

	/**
	 * Project worksheet report projector.
	 *
	 * @param array<int,array<string,mixed>> $progress_sources Progress sources.
	 * @param array<int,array<string,mixed>> $worksheet_sources Worksheet sources.
	 * @param array<int,array<string,mixed>> $assessment_sources Assessment sources.
	 * @return array{items:array<int,array<string,mixed>>,source_manifest:array<string,mixed>,source_manifest_hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function project( array $progress_sources, array $worksheet_sources, array $assessment_sources ): array {
		if ( ! array_is_list( $progress_sources ) || ! array_is_list( $worksheet_sources ) || ! array_is_list( $assessment_sources ) ) {
			$this->stored_invalid();
		}
		$worksheets       = $this->index_sources( $worksheet_sources, 'oj_ai.worksheet_report_source', 'worksheet' );
		$assessments      = $this->index_sources( $assessment_sources, 'oj_ai.official_assessment_source', 'assessment' );
		$items            = array();
		$manifest_entries = array();

		foreach ( $progress_sources as $progress ) {
			$this->verify_source( $progress, 'oj_ai.assignment_progress_source' );
			$this->exact_keys(
				$progress,
				array(
					'assignment_count',
					'assignments',
					'official_review_count',
					'official_review_refs',
					'official_submission_count',
					'publication',
					'schema_id',
					'schema_version',
					'source_hash',
					'target_count',
					'updated_at_utc',
				)
			);
			$publication = $this->publication( $progress['publication'] ?? null );
			$id          = $publication['id'];
			if ( ! isset( $worksheets[ $id ], $assessments[ $id ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
			}
			$worksheet  = $worksheets[ $id ];
			$assessment = $assessments[ $id ];
			$this->assert_matching_publication( $publication, $worksheet['publication'] ?? null );
			$this->assert_matching_publication( $publication, $assessment['publication'] ?? null );
			$items[]            = $this->item( $progress, $worksheet, $assessment );
			$manifest_entries[] = array(
				'publication_id' => $id,
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
			);
			unset( $worksheets[ $id ], $assessments[ $id ] );
		}
		if ( array() !== $worksheets || array() !== $assessments ) {
			$this->stored_invalid();
		}

		$manifest = array(
			'schema_id'      => 'oj_ai.report_source_manifest',
			'schema_version' => '1.0.0',
			'entries'        => $manifest_entries,
		);
		return array(
			'items'                => $items,
			'source_manifest'      => $manifest,
			'source_manifest_hash' => hash( 'sha256', $this->canonical->encode( $manifest ) ),
		);
	}

	/**
	 * Index sources.
	 *
	 * @param array<int,array<string,mixed>> $sources Sources.
	 * @param string                         $schema Schema.
	 * @param string                         $kind Kind.
	 * @return array<string,array<string,mixed>>
	 */
	private function index_sources( array $sources, string $schema, string $kind ): array {
		$result = array();
		foreach ( $sources as $source ) {
			$this->verify_source( $source, $schema );
			if ( 'worksheet' === $kind ) {
				$this->exact_keys( $source, array( 'publication', 'revision', 'rubric', 'schema_id', 'schema_version', 'source_hash', 'updated_at_utc', 'worksheet' ) );
			} else {
				$this->exact_keys( $source, array( 'criterion_totals', 'normalized_basis_points_sum', 'official_review_count', 'publication', 'released_count', 'reviews', 'schema_id', 'schema_version', 'scored_review_count', 'source_hash', 'updated_at_utc' ) );
			}
			$publication = $this->publication( $source['publication'] ?? null );
			if ( isset( $result[ $publication['id'] ] ) ) {
				$this->stored_invalid();
			}
			$result[ $publication['id'] ] = $source;
		}
		return $result;
	}

	/**
	 * Item.
	 *
	 * @param array<string,mixed> $progress Progress.
	 * @param array<string,mixed> $worksheet Worksheet.
	 * @param array<string,mixed> $assessment Assessment.
	 * @return array<string,mixed>
	 */
	private function item( array $progress, array $worksheet, array $assessment ): array {
		$assignment_count        = $this->non_negative_integer( $progress['assignment_count'] ?? null );
		$target_count            = $this->non_negative_integer( $progress['target_count'] ?? null );
		$submission_count        = $this->non_negative_integer( $progress['official_submission_count'] ?? null );
		$review_count            = $this->non_negative_integer( $progress['official_review_count'] ?? null );
		$assessment_review_count = $this->non_negative_integer( $assessment['official_review_count'] ?? null );
		$scored_count            = $this->non_negative_integer( $assessment['scored_review_count'] ?? null );
		$released_count          = $this->non_negative_integer( $assessment['released_count'] ?? null );
		$score_sum               = $this->non_negative_integer( $assessment['normalized_basis_points_sum'] ?? null );
		if (
			$submission_count > $target_count || $review_count > $submission_count
			|| $assessment_review_count !== $review_count || $scored_count > $review_count
			|| $released_count > $review_count || ( 0 === $scored_count && 0 !== $score_sum )
			|| ( $scored_count > 0 && $score_sum > $scored_count * 10000 )
		) {
			$this->stored_invalid();
		}
		$worksheet_value   = $worksheet['worksheet'] ?? null;
		$publication_value = $worksheet['publication'] ?? null;
		$rubric            = $worksheet['rubric'] ?? null;
		if ( ! is_array( $worksheet_value ) || ! is_array( $publication_value ) || ! is_array( $rubric ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $worksheet_value, array( 'id', 'state', 'subject_label', 'title' ) );
		$this->exact_keys( $publication_value, array( 'content_hash', 'hash', 'id', 'number', 'published_at_utc', 'state' ) );
		$this->exact_keys( $rubric, array( 'content_hash', 'criterion_count', 'revision_id', 'total_points' ) );

		$updated           = $this->required_utc( $progress['updated_at_utc'] ?? null );
		$worksheet_updated = $this->required_utc( $worksheet['updated_at_utc'] ?? null );
		if ( $worksheet_updated > $updated ) {
			$updated = $worksheet_updated;
		}
		$assessment_updated = $assessment['updated_at_utc'] ?? null;
		if ( null !== $assessment_updated ) {
			$assessment_updated = $this->required_utc( $assessment_updated );
			if ( $assessment_updated > $updated ) {
				$updated = $assessment_updated;
			}
		}

		return array(
			'worksheet'      => array(
				'id'            => $this->public_id( $worksheet_value['id'] ?? null ),
				'title'         => $this->text( $worksheet_value['title'] ?? null ),
				'state'         => $this->text( $worksheet_value['state'] ?? null ),
				'subject_label' => null === ( $worksheet_value['subject_label'] ?? null ) ? null : $this->text( $worksheet_value['subject_label'] ),
			),
			'publication'    => array(
				'id'               => $this->public_id( $publication_value['id'] ?? null ),
				'hash'             => $this->hash_value( $publication_value['hash'] ?? null ),
				'number'           => $this->positive_integer( $publication_value['number'] ?? null ),
				'state'            => $this->text( $publication_value['state'] ?? null ),
				'published_at_utc' => $this->required_utc( $publication_value['published_at_utc'] ?? null ),
			),
			'rubric'         => array(
				'revision_id'     => $this->public_id( $rubric['revision_id'] ?? null ),
				'content_hash'    => $this->hash_value( $rubric['content_hash'] ?? null ),
				'total_points'    => $this->decimal( $rubric['total_points'] ?? null ),
				'criterion_count' => $this->non_negative_integer( $rubric['criterion_count'] ?? null ),
			),
			'progress'       => array(
				'assignment_count'      => $assignment_count,
				'target_count'          => $target_count,
				'submission_rate'       => $this->metrics->ratio( $submission_count, $target_count ),
				'assessment_rate'       => $this->metrics->ratio( $review_count, $submission_count ),
				'release_rate'          => $this->metrics->ratio( $released_count, $review_count ),
				'average_score'         => $this->metrics->average( $score_sum, $scored_count ),
				'review_required_count' => $submission_count - $review_count,
			),
			'updated_at_utc' => $updated,
			'source'         => array(
				'worksheet'           => array(
					'schema_id'      => $worksheet['schema_id'],
					'schema_version' => $worksheet['schema_version'],
					'hash'           => $worksheet['source_hash'],
				),
				'assignment_progress' => array(
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
