<?php
/**
 * Assignment-owned Q21 report detail source.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assignment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Port\Assignment_Report_Detail_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps target snapshots, submission pointers, and learner labels inside their owner.
 */
final class WordPress_Assignment_Report_Detail_Source_Query implements Assignment_Report_Detail_Source_Query_Port_Interface {
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';
	private const TABLE_REVISIONS   = 'oj_ai_submission_revisions';

	private const SCHEMA_ID         = 'oj_ai.assignment_report_detail_source';
	private const SCHEMA_VERSION    = '1.0.0';
	private const DISPLAY_SCHEMA    = 'oj_ai.assignment_target_display';
	private const ASSIGNMENT_CURSOR = 'report_detail_a';
	private const STUDENT_CURSOR    = 'report_detail_s';

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;
	/**
	 * Cursors.
	 *
	 * @var Signed_Cursor_Codec
	 */
	private Signed_Cursor_Codec $cursors;

	/**
	 * Initialize WordPress assignment report detail source query dependencies and state.
	 *
	 * @param object|null              $database Database.
	 * @param Canonical_Json|null      $canonical Canonical JSON encoder used for stable digests.
	 * @param Signed_Cursor_Codec|null $cursors Cursors.
	 */
	public function __construct(
		private ?object $database = null,
		?Canonical_Json $canonical = null,
		?Signed_Cursor_Codec $cursors = null
	) {
		$this->canonical = $canonical ?? new Canonical_Json();
		$this->cursors   = $cursors ?? new Signed_Cursor_Codec( $this->canonical );
	}

	/**
	 * Read publication.
	 *
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $context_type Context type.
	 * @param string             $context_id Context id.
	 * @param string             $context_revision Context revision.
	 * @param string             $publication_id Publication id.
	 * @param string             $publication_hash Publication hash.
	 * @param string|null        $assignment_id Distribution public identifier.
	 * @param string             $cursor Continuation cursor from the previous page.
	 * @param int                $limit Maximum number of entries requested.
	 * @param Report_Filter|null $filter Filter.
	 * @return null|array{source:array<string,mixed>,next_cursor:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read_publication(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $publication_id,
		string $publication_hash,
		?string $assignment_id,
		string $cursor,
		int $limit,
		?Report_Filter $filter = null
	): ?array {
		if (
			$actor_user_id < 1 || '' === trim( $context_type ) || '' === trim( $context_id )
			|| '' === trim( $context_revision ) || strlen( $cursor ) > 191 || $limit < 1 || $limit > 100
		) {
			throw new InvalidArgumentException( 'Assignment report detail query is invalid.' );
		}
		$this->public_id( $publication_id );
		$this->hash_value( $publication_hash );
		if ( null !== $assignment_id ) {
			$this->public_id( $assignment_id );
		}

		$filter ??= Report_Filter::defaults();
		$level    = null === $assignment_id ? 'assignments' : 'students';
		$scope    = $this->scope_digest(
			$actor_user_id,
			$context_type,
			$context_id,
			$context_revision,
			$publication_id,
			$publication_hash,
			$assignment_id,
			$level,
			$filter
		);
		$position = $this->cursor_position( $cursor, $scope, $level );
		$summary  = $this->summary_row(
			$context_type,
			$context_id,
			$context_revision,
			$publication_id,
			$publication_hash,
			$assignment_id,
			$filter
		);
		if ( null === $summary || 0 === $this->non_negative_integer( $summary['assignment_count'] ?? null ) ) {
			return null;
		}
		if ( null !== $assignment_id && 1 !== $this->non_negative_integer( $summary['assignment_count'] ?? null ) ) {
			$this->stored_invalid();
		}

		$assignment_rows = $this->assignment_rows(
			$context_type,
			$context_id,
			$context_revision,
			$publication_id,
			$publication_hash,
			$assignment_id,
			$position,
			null === $assignment_id ? $limit + 1 : 1,
			$filter
		);
		$has_more        = null === $assignment_id && count( $assignment_rows ) > $limit;
		if ( $has_more ) {
			array_pop( $assignment_rows );
		}
		if ( null !== $assignment_id && 1 !== count( $assignment_rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}

		$assignments        = array();
		$assignment_indexes = array();
		foreach ( $assignment_rows as $row ) {
			$assignment                              = $this->assignment_source( $row );
			$assignment_indexes[ $assignment['id'] ] = count( $assignments );
			$assignments[]                           = $assignment;
		}

		$student_rows     = array();
		$student_has_more = false;
		if ( null !== $assignment_id ) {
			$assignment_pk    = $this->positive_integer( $assignment_rows[0]['assignment_pk'] ?? null );
			$student_rows     = $this->student_rows( $assignment_pk, $position, $limit + 1, $filter );
			$student_has_more = count( $student_rows ) > $limit;
			if ( $student_has_more ) {
				array_pop( $student_rows );
			}
		}

		$review_rows         = $this->review_rows(
			$context_type,
			$context_id,
			$context_revision,
			$publication_id,
			$publication_hash,
			$assignment_id,
			$filter
		);
		$references          = array();
		$reference_by_target = array();
		$seen_reviews        = array();
		foreach ( $review_rows as $row ) {
			$reference = $this->review_reference( $row );
			$review_id = $reference['review']['id'];
			$target_id = $reference['target_id'];
			if ( isset( $seen_reviews[ $review_id ] ) || isset( $reference_by_target[ $target_id ] ) ) {
				$this->stored_invalid();
			}
			$seen_reviews[ $review_id ]        = true;
			$reference_by_target[ $target_id ] = $reference;
			$references[]                      = $reference;
			if ( isset( $assignment_indexes[ $reference['assignment_id'] ] ) ) {
				$assignments[ $assignment_indexes[ $reference['assignment_id'] ] ]['official_review_refs'][] = $reference;
			}
		}

		$totals = $this->totals( $summary );
		if ( count( $references ) !== $totals['official_review_count'] ) {
			$this->stored_invalid();
		}
		foreach ( $assignments as $assignment ) {
			if ( count( $assignment['official_review_refs'] ) !== $assignment['official_review_count'] ) {
				$this->stored_invalid();
			}
		}

		$students = array();
		foreach ( $student_rows as $row ) {
			$student                        = $this->student_source( $row );
			$student['official_review_ref'] = $reference_by_target[ $student['target']['id'] ] ?? null;
			$has_review                     = null !== $student['official_review_ref'];
			$has_official                   = null !== ( $student['submission']['official_revision'] ?? null );
			if ( $has_review && ! $has_official ) {
				$this->stored_invalid();
			}
			$students[] = $student;
		}

		$source                = array(
			'schema_id'              => self::SCHEMA_ID,
			'schema_version'         => self::SCHEMA_VERSION,
			'context'                => array(
				'type'     => $context_type,
				'id'       => $context_id,
				'revision' => $context_revision,
			),
			'publication'            => array(
				'id'   => $publication_id,
				'hash' => $publication_hash,
			),
			'level'                  => $level,
			'selected_assignment_id' => $assignment_id,
			'totals'                 => $totals,
			'assignments'            => $assignments,
			'students'               => $students,
			'official_review_refs'   => $references,
			'updated_at_utc'         => $this->required_utc( $summary['latest_activity_at_utc'] ?? null ),
		);
		$source['source_hash'] = hash( 'sha256', $this->canonical->encode( $source ) );

		$next_cursor = '';
		if ( $has_more && array() !== $assignment_rows ) {
			$last        = $assignment_rows[ count( $assignment_rows ) - 1 ];
			$next_cursor = $this->cursors->encode(
				self::ASSIGNMENT_CURSOR,
				array(
					'i' => $this->public_id( $last['assignment_id'] ?? null ),
					'u' => $this->required_utc( $last['updated_at_utc'] ?? null ),
					'x' => $scope,
				)
			);
		} elseif ( $student_has_more && array() !== $student_rows ) {
			$last        = $student_rows[ count( $student_rows ) - 1 ];
			$next_cursor = $this->cursors->encode(
				self::STUDENT_CURSOR,
				array(
					'i' => $this->public_id( $last['target_id'] ?? null ),
					'u' => $this->positive_integer( $last['learner_user_id'] ?? null ),
					'x' => $scope,
				)
			);
		}

		return array(
			'source'      => $source,
			'next_cursor' => $next_cursor,
		);
	}

	/**
	 * Summary row.
	 *
	 * @param string        $type Type.
	 * @param string        $id Id.
	 * @param string        $revision Revision.
	 * @param string        $publication_id Publication id.
	 * @param string        $publication_hash Publication hash.
	 * @param string|null   $assignment_id Distribution public identifier.
	 * @param Report_Filter $filter Filter.
	 * @return null|array<string,mixed>
	 */
	private function summary_row( string $type, string $id, string $revision, string $publication_id, string $publication_hash, ?string $assignment_id, Report_Filter $filter ): ?array {
		$values            = array( 'active', $type, $id, $revision, $publication_id, $publication_hash );
		$assignment_filter = $this->assignment_constraints( 'a', $filter, $values );
		$sql               = 'SELECT COUNT(DISTINCT a.id) AS assignment_count, COUNT(DISTINCT t.id) AS target_count, '
			. 'COUNT(DISTINCT CASE WHEN s.official_revision_public_id IS NOT NULL THEN s.id END) AS official_submission_count, '
			. 'COUNT(DISTINCT CASE WHEN s.official_review_public_id IS NOT NULL THEN s.id END) AS official_review_count, '
			. 'MAX(CASE WHEN s.updated_at_utc IS NOT NULL AND s.updated_at_utc > a.updated_at_utc THEN s.updated_at_utc ELSE a.updated_at_utc END) AS latest_activity_at_utc '
			. 'FROM ' . self::TABLE_ASSIGNMENTS . ' a '
			. 'LEFT JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.state = %s AND t.learner_user_id IS NOT NULL '
			. 'LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.assignment_id = a.id AND s.learner_user_id = t.learner_user_id '
			. 'WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s '
			. 'AND a.worksheet_publication_public_id = %s AND a.publication_hash = %s AND ' . $assignment_filter
			. ' AND t.id IS NOT NULL AND ' . $this->submission_stage_predicate( 's', $filter );
		if ( null !== $assignment_id ) {
			$sql     .= ' AND a.public_id = %s';
			$values[] = $assignment_id;
		}
		$rows = $this->get_results( $this->prepare( $sql, $values ) );
		if ( count( $rows ) > 1 ) {
			$this->stored_invalid();
		}
		return $rows[0] ?? null;
	}

	/**
	 * Assignment rows.
	 *
	 * @param string              $type Type.
	 * @param string              $id Id.
	 * @param string              $revision Revision.
	 * @param string              $publication_id Publication id.
	 * @param string              $publication_hash Publication hash.
	 * @param string|null         $assignment_id Distribution public identifier.
	 * @param array<string,mixed> $position Position.
	 * @param int                 $limit Maximum number of entries requested.
	 * @param Report_Filter       $filter Filter.
	 * @return array<int,array<string,mixed>>
	 */
	private function assignment_rows( string $type, string $id, string $revision, string $publication_id, string $publication_hash, ?string $assignment_id, array $position, int $limit, Report_Filter $filter ): array {
		$values            = array( 'active', 'active', 'active', $type, $id, $revision, $publication_id, $publication_hash );
		$assignment_filter = $this->assignment_constraints( 'a', $filter, $values );
		$sql               = 'SELECT a.id AS assignment_pk, a.public_id AS assignment_id, '
			. 'a.title_snapshot, a.state, a.opens_at_utc, a.due_at_utc, a.closes_at_utc, a.target_snapshot_hash, a.updated_at_utc, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_TARGETS . ' tc LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' sc '
			. 'ON sc.assignment_id = tc.assignment_id AND sc.learner_user_id = tc.learner_user_id '
			. 'WHERE tc.assignment_id = a.id AND tc.state = %s AND tc.learner_user_id IS NOT NULL AND '
			. $this->submission_stage_predicate( 'sc', $filter ) . ') AS target_count, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_SUBMISSIONS . ' ss INNER JOIN ' . self::TABLE_TARGETS . ' ts '
			. 'ON ts.assignment_id = ss.assignment_id AND ts.learner_user_id = ss.learner_user_id AND ts.state = %s '
			. 'WHERE ss.assignment_id = a.id AND ' . $this->submission_stage_predicate( 'ss', $filter )
			. ' AND ss.official_revision_public_id IS NOT NULL) AS official_submission_count, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_SUBMISSIONS . ' sr INNER JOIN ' . self::TABLE_TARGETS . ' tr '
			. 'ON tr.assignment_id = sr.assignment_id AND tr.learner_user_id = sr.learner_user_id AND tr.state = %s '
			. 'WHERE sr.assignment_id = a.id AND ' . $this->submission_stage_predicate( 'sr', $filter )
			. ' AND sr.official_review_public_id IS NOT NULL) AS official_review_count '
			. 'FROM ' . self::TABLE_ASSIGNMENTS . ' a WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s '
			. 'AND a.worksheet_publication_public_id = %s AND a.publication_hash = %s AND ' . $assignment_filter . ' '
			. 'AND EXISTS (SELECT 1 FROM ' . self::TABLE_TARGETS . ' tf LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' sf '
			. 'ON sf.assignment_id = tf.assignment_id AND sf.learner_user_id = tf.learner_user_id '
			. 'WHERE tf.assignment_id = a.id AND tf.state = %s AND tf.learner_user_id IS NOT NULL AND '
			. $this->submission_stage_predicate( 'sf', $filter ) . ')';
		$values[]          = 'active';
		if ( null !== $assignment_id ) {
			$sql     .= ' AND a.public_id = %s';
			$values[] = $assignment_id;
		} elseif ( array() !== $position ) {
			$sql     .= ' AND (a.updated_at_utc < %s OR (a.updated_at_utc = %s AND a.public_id > %s))';
			$values[] = $position['u'];
			$values[] = $position['u'];
			$values[] = $position['i'];
		}
		$sql     .= ' ORDER BY a.updated_at_utc DESC, a.public_id ASC LIMIT %d';
		$values[] = $limit;
		return $this->get_results( $this->prepare( $sql, $values ) );
	}

	/**
	 * Student rows.
	 *
	 * @param int                 $assignment_pk Assignment pk.
	 * @param array<string,mixed> $position Position.
	 * @param int                 $limit Maximum number of entries requested.
	 * @param Report_Filter       $filter Filter.
	 * @return array<int,array<string,mixed>>
	 */
	private function student_rows( int $assignment_pk, array $position, int $limit, Report_Filter $filter ): array {
		$sql    = 'SELECT t.public_id AS target_id, t.content_hash AS target_hash, t.learner_user_id, t.membership_revision, '
			. 't.display_snapshot_schema, t.display_snapshot_version, t.display_snapshot_json, t.display_snapshot_hash, t.snapshot_hash AS target_snapshot_hash, '
			. 's.public_id AS submission_id, s.updated_at_utc AS submission_updated_at_utc, s.state AS submission_state, s.started_at_utc, s.submitted_at_utc, '
			. 's.official_revision_public_id AS official_revision_id, revision.content_hash AS official_revision_hash '
			. 'FROM ' . self::TABLE_TARGETS . ' t LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' s '
			. 'ON s.assignment_id = t.assignment_id AND s.learner_user_id = t.learner_user_id '
			. 'LEFT JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.submission_id = s.id AND revision.public_id = s.official_revision_public_id '
			. 'WHERE t.assignment_id = %d AND t.state = %s AND t.learner_user_id IS NOT NULL AND '
			. $this->submission_stage_predicate( 's', $filter );
		$values = array( $assignment_pk, 'active' );
		if ( array() !== $position ) {
			$sql     .= ' AND (t.learner_user_id > %d OR (t.learner_user_id = %d AND t.public_id > %s))';
			$values[] = $position['u'];
			$values[] = $position['u'];
			$values[] = $position['i'];
		}
		$sql     .= ' ORDER BY t.learner_user_id ASC, t.public_id ASC LIMIT %d';
		$values[] = $limit;
		return $this->get_results( $this->prepare( $sql, $values ) );
	}

	/**
	 * Review rows.
	 *
	 * @param string        $type Type.
	 * @param string        $id Id.
	 * @param string        $revision Revision.
	 * @param string        $publication_id Publication id.
	 * @param string        $publication_hash Publication hash.
	 * @param string|null   $assignment_id Distribution public identifier.
	 * @param Report_Filter $filter Filter.
	 * @return array<int,array<string,mixed>>
	 */
	private function review_rows( string $type, string $id, string $revision, string $publication_id, string $publication_hash, ?string $assignment_id, Report_Filter $filter ): array {
		$values            = array( 'active', $type, $id, $revision, $publication_id, $publication_hash );
		$assignment_filter = $this->assignment_constraints( 'a', $filter, $values );
		$sql               = 'SELECT a.public_id AS assignment_id, t.public_id AS target_id, s.official_review_public_id AS review_id, '
			. 's.official_review_hash AS review_hash, s.current_release_public_id AS release_id, s.current_release_hash AS release_hash '
			. 'FROM ' . self::TABLE_ASSIGNMENTS . ' a INNER JOIN ' . self::TABLE_TARGETS . ' t '
			. 'ON t.assignment_id = a.id AND t.state = %s AND t.learner_user_id IS NOT NULL '
			. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.assignment_id = a.id AND s.learner_user_id = t.learner_user_id '
			. 'WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s '
			. 'AND a.worksheet_publication_public_id = %s AND a.publication_hash = %s AND ' . $assignment_filter . ' '
			. 'AND ' . $this->submission_stage_predicate( 's', $filter )
			. ' AND s.official_revision_public_id IS NOT NULL AND s.official_review_public_id IS NOT NULL';
		if ( null !== $assignment_id ) {
			$sql     .= ' AND a.public_id = %s';
			$values[] = $assignment_id;
		}
		$sql .= ' ORDER BY a.public_id ASC, t.public_id ASC';
		return $this->get_results( $this->prepare( $sql, $values ) );
	}

	/**
	 * Assignment source.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function assignment_source( array $row ): array {
		$state = $row['state'] ?? null;
		$title = $row['title_snapshot'] ?? null;
		if ( ! is_string( $state ) || ! in_array( $state, Report_Filter::ASSIGNMENT_STATES, true ) || ! is_string( $title ) || '' === trim( $title ) ) {
			$this->stored_invalid();
		}
		$target_count     = $this->non_negative_integer( $row['target_count'] ?? null );
		$submission_count = $this->non_negative_integer( $row['official_submission_count'] ?? null );
		$review_count     = $this->non_negative_integer( $row['official_review_count'] ?? null );
		if ( $submission_count > $target_count || $review_count > $submission_count ) {
			$this->stored_invalid();
		}
		$id         = $this->public_id( $row['assignment_id'] ?? null );
		$updated_at = $this->required_utc( $row['updated_at_utc'] ?? null );
		return array(
			'id'                        => $id,
			'hash'                      => $this->assignment_content_hash( $id, $updated_at ),
			'title'                     => $this->canonical->text( $title ),
			'state'                     => $state,
			'opens_at_utc'              => $this->optional_utc( $row['opens_at_utc'] ?? null ),
			'due_at_utc'                => $this->optional_utc( $row['due_at_utc'] ?? null ),
			'closes_at_utc'             => $this->optional_utc( $row['closes_at_utc'] ?? null ),
			'target_snapshot_hash'      => $this->hash_value( $row['target_snapshot_hash'] ?? null ),
			'target_count'              => $target_count,
			'official_submission_count' => $submission_count,
			'official_review_count'     => $review_count,
			'official_review_refs'      => array(),
			'updated_at_utc'            => $updated_at,
		);
	}

	/**
	 * `oj_ai_assignments` has no stored content-hash column (it uses the MUT
	 * profile, not IMM) -- derive a stable, deterministic staleness marker
	 * from the assignment's public id and its own updated_at_utc instead,
	 * which changes exactly when the row itself changes.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $updated_at_utc Updated at utc.
	 * @return string
	 */
	private function assignment_content_hash( string $assignment_id, string $updated_at_utc ): string {
		return hash( 'sha256', $assignment_id . '|' . $updated_at_utc );
	}

	/**
	 * `oj_ai_submissions` has no stored content-hash column either (MUT
	 * profile, not IMM) -- same derivation as assignment_content_hash(),
	 * scoped to the submission's own id and updated_at_utc.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $updated_at_utc Updated at utc.
	 * @return string
	 */
	private function submission_content_hash( string $submission_id, string $updated_at_utc ): string {
		return hash( 'sha256', $submission_id . '|' . $updated_at_utc );
	}

	/**
	 * Student source.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function student_source( array $row ): array {
		if ( self::DISPLAY_SCHEMA !== ( $row['display_snapshot_schema'] ?? null ) || 1 !== $this->positive_integer( $row['display_snapshot_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_SCHEMA_UNSUPPORTED );
		}
		$display_json = $row['display_snapshot_json'] ?? null;
		$display_hash = $this->hash_value( $row['display_snapshot_hash'] ?? null );
		if ( ! is_string( $display_json ) || ! hash_equals( $display_hash, hash( 'sha256', $display_json ) ) ) {
			$this->stored_invalid();
		}
		try {
			$display = json_decode( $display_json, true, 16, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			$this->stored_invalid();
		}
		if ( ! is_array( $display ) || array_keys( $display ) !== array( 'display_name' ) || ! is_string( $display['display_name'] ) || '' === trim( $display['display_name'] ) ) {
			$this->stored_invalid();
		}

		$submission    = null;
		$submission_id = $row['submission_id'] ?? null;
		if ( null !== $submission_id ) {
			$official_id   = $row['official_revision_id'] ?? null;
			$official_hash = $row['official_revision_hash'] ?? null;
			if ( ( null === $official_id ) !== ( null === $official_hash ) ) {
				$this->stored_invalid();
			}
			$state = $row['submission_state'] ?? null;
			if ( ! is_string( $state ) || '' === trim( $state ) ) {
				$this->stored_invalid();
			}
			$submission = array(
				'id'                => $this->public_id( $submission_id ),
				'hash'              => $this->submission_content_hash( $this->public_id( $submission_id ), $this->required_utc( $row['submission_updated_at_utc'] ?? null ) ),
				'state'             => $state,
				'started_at_utc'    => $this->optional_utc( $row['started_at_utc'] ?? null ),
				'submitted_at_utc'  => $this->optional_utc( $row['submitted_at_utc'] ?? null ),
				'official_revision' => null === $official_id ? null : array(
					'id'   => $this->public_id( $official_id ),
					'hash' => $this->hash_value( $official_hash ),
				),
			);
		}

		return array(
			'target'              => array(
				'id'                  => $this->public_id( $row['target_id'] ?? null ),
				'hash'                => $this->hash_value( $row['target_hash'] ?? null ),
				'learner_user_id'     => $this->positive_integer( $row['learner_user_id'] ?? null ),
				'display_name'        => $this->canonical->text( $display['display_name'] ),
				'membership_revision' => $this->token( $row['membership_revision'] ?? null ),
				'snapshot_hash'       => $this->hash_value( $row['target_snapshot_hash'] ?? null ),
			),
			'submission'          => $submission,
			'official_review_ref' => null,
		);
	}

	/**
	 * Review reference.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function review_reference( array $row ): array {
		$release_id   = $row['release_id'] ?? null;
		$release_hash = $row['release_hash'] ?? null;
		if ( ( null === $release_id ) !== ( null === $release_hash ) ) {
			$this->stored_invalid();
		}
		return array(
			'assignment_id' => $this->public_id( $row['assignment_id'] ?? null ),
			'target_id'     => $this->public_id( $row['target_id'] ?? null ),
			'review'        => array(
				'id'   => $this->public_id( $row['review_id'] ?? null ),
				'hash' => $this->hash_value( $row['review_hash'] ?? null ),
			),
			'release'       => null === $release_id ? null : array(
				'id'   => $this->public_id( $release_id ),
				'hash' => $this->hash_value( $release_hash ),
			),
		);
	}

	/**
	 * Totals.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,int>
	 */
	private function totals( array $row ): array {
		$assignments = $this->non_negative_integer( $row['assignment_count'] ?? null );
		$targets     = $this->non_negative_integer( $row['target_count'] ?? null );
		$submissions = $this->non_negative_integer( $row['official_submission_count'] ?? null );
		$reviews     = $this->non_negative_integer( $row['official_review_count'] ?? null );
		if ( $submissions > $targets || $reviews > $submissions ) {
			$this->stored_invalid();
		}
		return array(
			'assignment_count'          => $assignments,
			'target_count'              => $targets,
			'official_submission_count' => $submissions,
			'official_review_count'     => $reviews,
		);
	}

	/**
	 * Cursor position.
	 *
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param string $scope Scope.
	 * @param string $level Level.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function cursor_position( string $cursor, string $scope, string $level ): array {
		if ( '' === $cursor ) {
			return array();
		}
		$payload = $this->cursors->decode( $cursor, 'assignments' === $level ? self::ASSIGNMENT_CURSOR : self::STUDENT_CURSOR );
		if ( array_keys( $payload ) !== array( 'i', 'u', 'x' ) || ! is_string( $payload['x'] ?? null ) || ! hash_equals( $scope, $payload['x'] ) ) {
			throw new InvalidArgumentException( 'Assignment report detail cursor scope is invalid.' );
		}
		return array(
			'i' => $this->cursor_public_id( $payload['i'] ?? null ),
			'u' => 'assignments' === $level ? $this->cursor_utc( $payload['u'] ?? null ) : $this->cursor_positive_integer( $payload['u'] ?? null ),
		);
	}

	/**
	 * Scope digest.
	 *
	 * @param int           $actor Actor.
	 * @param string        $type Type.
	 * @param string        $id Id.
	 * @param string        $revision Revision.
	 * @param string        $publication Publication.
	 * @param string        $publication_hash Publication hash.
	 * @param string|null   $assignment Assignment.
	 * @param string        $level Level.
	 * @param Report_Filter $filter Filter.
	 * @return string
	 */
	private function scope_digest( int $actor, string $type, string $id, string $revision, string $publication, string $publication_hash, ?string $assignment, string $level, Report_Filter $filter ): string {
		$filter_payload = $filter->payload();
		return substr( hash( 'sha256', $this->canonical->encode( compact( 'actor', 'type', 'id', 'revision', 'publication', 'publication_hash', 'assignment', 'level', 'filter_payload' ) ) ), 0, 16 );
	}

	/**
	 * Cursor public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function cursor_public_id( mixed $value ): string {
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Assignment report detail cursor ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Cursor utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function cursor_utc( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Assignment report detail cursor time is invalid.' );
		}
		return $value;
	}

	/**
	 * Cursor positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function cursor_positive_integer( mixed $value ): int {
		if ( ! is_int( $value ) || $value < 1 ) {
			throw new InvalidArgumentException( 'Assignment report detail cursor learner is invalid.' );
		}
		return $value;
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
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function token( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
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
	 * Assignment constraints.
	 *
	 * @param string           $alias Alias.
	 * @param Report_Filter    $filter Filter.
	 * @param array<int,mixed> &$values Values.
	 * @return string
	 */
	private function assignment_constraints( string $alias, Report_Filter $filter, array &$values ): string {
		$states = $filter->assignment_states();
		$sql    = $alias . '.state IN (' . $this->placeholders( count( $states ) ) . ')';
		foreach ( $states as $state ) {
			$values[] = $state;
		}
		if ( $filter->has_period() ) {
			$sql     .= ' AND COALESCE(' . $alias . '.opens_at_utc, ' . $alias . '.created_at_utc) >= %s'
				. ' AND COALESCE(' . $alias . '.opens_at_utc, ' . $alias . '.created_at_utc) < %s';
			$values[] = $filter->from_utc();
			$values[] = $filter->to_utc();
		}
		return $sql;
	}

	/**
	 * Submission stage predicate.
	 *
	 * @param string        $alias Alias.
	 * @param Report_Filter $filter Filter.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function submission_stage_predicate( string $alias, Report_Filter $filter ): string {
		$selected = array_fill_keys( $filter->submission_states(), true );
		$parts    = array();
		if ( isset( $selected['not_started'] ) ) {
			$parts[] = $alias . '.id IS NULL';
		}
		if ( isset( $selected['in_progress'] ) ) {
			$parts[] = '(' . $alias . '.id IS NOT NULL AND ' . $alias . '.official_revision_public_id IS NULL)';
		}
		if ( isset( $selected['official'] ) ) {
			$parts[] = $alias . '.official_revision_public_id IS NOT NULL';
		}
		if ( array() === $parts ) {
			throw new InvalidArgumentException( 'Assignment report detail submission filter is empty.' );
		}
		return '(' . implode( ' OR ', $parts ) . ')';
	}

	/**
	 * Placeholders.
	 *
	 * @param int $count Count.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function placeholders( int $count ): string {
		if ( $count < 1 || $count > 100 ) {
			throw new InvalidArgumentException( 'Assignment report detail placeholder count is invalid.' );
		}
		return implode( ', ', array_fill( 0, $count, '%s' ) );
	}

	/**
	 * Prepare.
	 *
	 * @param string           $sql Sql.
	 * @param array<int,mixed> $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->database()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}

	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_results( string $sql ): array {
		$db = $this->database();
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
		$rows = $db->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) || ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $rows;
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) || ! method_exists( $db, 'prepare' ) || ! method_exists( $db, 'get_results' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::STORAGE_UNAVAILABLE );
		}
		return $db;
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
