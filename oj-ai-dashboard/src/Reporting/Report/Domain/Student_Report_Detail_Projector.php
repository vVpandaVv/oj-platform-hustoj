<?php
/**
 * Student-lens per-student cross-worksheet report live projector.
 *
 * One row per worksheet/assignment a single student has, across every
 * worksheet assigned to them in a context -- the reverse of the
 * worksheet-lens drill-down, which narrows to one worksheet at a time.
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
 * Student-lens per-student cross-worksheet report live projector.
 */
final class Student_Report_Detail_Projector {
	public const METRIC_CONTRACT = Report_Metrics::CONTRACT;

	/**
	 * Initialize student report detail projector dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Project student report detail projector.
	 *
	 * @param array<string,mixed>            $source Source.
	 * @param array<int,array<string,mixed>> $worksheet_sources Worksheet sources.
	 * @param array<int,array<string,mixed>> $score_rows Score rows.
	 * @return array{student:array<string,mixed>,context:array<string,mixed>,items:array<int,array<string,mixed>>,updated_at_utc:string}
	 */
	public function project( array $source, array $worksheet_sources, array $score_rows ): array {
		if ( ! Report_Source_Validator::records( $worksheet_sources, PHP_INT_MAX ) || ! Report_Source_Validator::records( $score_rows, PHP_INT_MAX ) ) {
			$this->stored_invalid();
		}
		$this->verify_source( $source );
		$this->exact_keys( $source, array( 'assignments', 'context', 'schema_id', 'schema_version', 'source_hash', 'target', 'updated_at_utc' ) );

		$context = $source['context'] ?? null;
		if ( ! is_array( $context ) || array_keys( $context ) !== array( 'type', 'id', 'revision' ) ) {
			$this->stored_invalid();
		}
		foreach ( $context as $value ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				$this->stored_invalid();
			}
		}

		$target = $this->target( $source['target'] ?? null );

		$worksheets = array();
		foreach ( $worksheet_sources as $worksheet ) {
			$this->verify_worksheet_source( $worksheet );
			$publication = $this->publication( $worksheet['publication'] ?? null );
			if ( isset( $worksheets[ $publication['id'] ] ) ) {
				$this->stored_invalid();
			}
			$worksheets[ $publication['id'] ] = $worksheet;
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

		$assignment_values = $source['assignments'] ?? null;
		if ( ! is_array( $assignment_values ) || ! array_is_list( $assignment_values ) ) {
			$this->stored_invalid();
		}
		$items            = array();
		$seen_assignments = array();
		foreach ( $assignment_values as $value ) {
			$item = $this->assignment_item( $value, $worksheets, $scores );
			if ( isset( $seen_assignments[ $item['assignment']['id'] ] ) ) {
				$this->stored_invalid();
			}
			$seen_assignments[ $item['assignment']['id'] ] = true;
			$items[]                                       = $item;
		}

		return array(
			'student'        => $target,
			'context'        => $context,
			'items'          => $items,
			'updated_at_utc' => $this->required_utc( $source['updated_at_utc'] ?? null ),
		);
	}

	/**
	 * Assignment item.
	 *
	 * @param mixed                             $value Value.
	 * @param array<string,array<string,mixed>> $worksheets Worksheets.
	 * @param array<string,array<string,mixed>> $scores Scores.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assignment_item( mixed $value, array $worksheets, array $scores ): array {
		if ( ! is_array( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $value, array( 'due_at_utc', 'hash', 'id', 'official_review_ref', 'publication', 'state', 'submission', 'title', 'updated_at_utc' ) );
		$publication = $this->publication( $value['publication'] ?? null );
		if ( ! isset( $worksheets[ $publication['id'] ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}
		$worksheet_source = $worksheets[ $publication['id'] ];
		$this->assert_matching_publication( $publication, $worksheet_source['publication'] ?? null );
		$worksheet_value = $worksheet_source['worksheet'] ?? null;
		if ( ! is_array( $worksheet_value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $worksheet_value, array( 'id', 'state', 'subject_label', 'title' ) );

		$submission = $this->submission( $value['submission'] ?? null );
		$reference  = $value['official_review_ref'] ?? null;
		$assessment = null;
		if ( null !== $reference ) {
			if ( ! is_array( $reference ) ) {
				$this->stored_invalid();
			}
			$this->exact_keys( $reference, array( 'release', 'review' ) );
			$review  = $this->reference_value( $reference['review'] ?? null );
			$release = null === ( $reference['release'] ?? null ) ? null : $this->reference_value( $reference['release'] );
			if ( ! isset( $scores[ $review['id'] ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
			}
			$resolved          = $scores[ $review['id'] ];
			$has_release_state = null !== ( $resolved['release_state'] ?? null );
			if ( ( null !== $release ) !== $has_release_state ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
			}
			$points = $this->non_negative_integer( $resolved['normalized_basis_points'] ?? null );
			if ( $points > 10000 ) {
				$this->stored_invalid();
			}
			$release_state = $resolved['release_state'] ?? null;
			if ( null !== $release_state && ! in_array( $release_state, array( 'released', 'corrected', 'stale', 'withdrawn' ), true ) ) {
				$this->stored_invalid();
			}
			$assessment = array(
				'score'   => array( 'value' => ( new Report_Metrics() )->individual_score( $points ) ),
				'release' => null === $release_state ? null : array( 'state' => $release_state ),
			);
		}
		if ( null !== $assessment && null === ( $submission['official_revision'] ?? null ) ) {
			$this->stored_invalid();
		}

		return array(
			'worksheet'           => array(
				'id'            => $this->public_id( $worksheet_value['id'] ?? null ),
				'title'         => $this->text( $worksheet_value['title'] ?? null ),
				'subject_label' => null === ( $worksheet_value['subject_label'] ?? null ) ? null : $this->text( $worksheet_value['subject_label'] ),
			),
			'assignment'          => array(
				'id'         => $this->public_id( $value['id'] ?? null ),
				'title'      => $this->text( $value['title'] ?? null ),
				'state'      => $this->text( $value['state'] ?? null ),
				'due_at_utc' => $this->optional_utc( $value['due_at_utc'] ?? null ),
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
	 * Extracts {id,hash} from either a bare assignment->publication reference
	 * (exactly those two keys) or a worksheet owner source's richer
	 * `publication` object (id/hash plus content_hash/number/state/...) --
	 * mirrors Worksheet_Report_Projector::publication(), which is lenient
	 * for the same reason.
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
	 * Verify source.
	 *
	 * @param array<string,mixed> $source Source.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function verify_source( array $source ): void {
		if ( 'oj_ai.student_report_detail_source' !== ( $source['schema_id'] ?? null ) || '1.0.0' !== ( $source['schema_version'] ?? null ) ) {
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
	 * Verify worksheet source.
	 *
	 * @param array<string,mixed> $source Source.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function verify_worksheet_source( array $source ): void {
		if ( 'oj_ai.worksheet_report_source' !== ( $source['schema_id'] ?? null ) || '1.0.0' !== ( $source['schema_version'] ?? null ) ) {
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
	 * Optional utc.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_utc( mixed $value ): ?string {
		return null === $value ? null : $this->required_utc( $value );
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
