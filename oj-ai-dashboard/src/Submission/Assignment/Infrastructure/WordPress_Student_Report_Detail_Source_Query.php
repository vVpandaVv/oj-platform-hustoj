<?php
/**
 * Assignment and submission owned student-lens per-student cross-worksheet
 * report detail source: every worksheet assignment a single student has in
 * a context, in one page -- the reverse of the worksheet-lens drill-down.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assignment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Port\Student_Report_Detail_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps one learner's cross-worksheet assignment/submission pointers inside their owner.
 */
final class WordPress_Student_Report_Detail_Source_Query implements Student_Report_Detail_Source_Query_Port_Interface {
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';
	private const TABLE_REVISIONS   = 'oj_ai_submission_revisions';

	private const SCHEMA_ID      = 'oj_ai.student_report_detail_source';
	private const SCHEMA_VERSION = '1.0.0';
	private const DISPLAY_SCHEMA = 'oj_ai.assignment_target_display';
	private const CURSOR_KIND    = 'student_detail_list';

	/**
	 * Initialize WordPress student report detail source query dependencies and state.
	 *
	 * @param object|null              $database Database.
	 * @param Canonical_Json|null      $canonical Canonical JSON encoder used for stable digests.
	 * @param Signed_Cursor_Codec|null $cursors Cursors.
	 */
	public function __construct(
		private ?object $database = null,
		private ?Canonical_Json $canonical = null,
		private ?Signed_Cursor_Codec $cursors = null
	) {
		$this->canonical = $canonical ?? new Canonical_Json();
		$this->cursors   = $cursors ?? new Signed_Cursor_Codec( $this->canonical );
	}

	/**
	 * For student.
	 *
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $context_type Context type.
	 * @param string             $context_id Context id.
	 * @param string             $context_revision Context revision.
	 * @param string             $student_target_id Student target id.
	 * @param string             $cursor Continuation cursor from the previous page.
	 * @param int                $limit Maximum number of entries requested.
	 * @param Report_Filter|null $filter Filter.
	 * @return null|array{source:array<string,mixed>,next_cursor:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function for_student(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $student_target_id,
		string $cursor,
		int $limit,
		?Report_Filter $filter = null
	): ?array {
		if (
			$actor_user_id < 1 || '' === trim( $context_type ) || '' === trim( $context_id )
			|| '' === trim( $context_revision ) || strlen( $cursor ) > 191 || $limit < 1 || $limit > 100
		) {
			throw new InvalidArgumentException( 'Student report detail query is invalid.' );
		}
		$this->public_id( $student_target_id );

		$filter   ??= Report_Filter::defaults();
		$target_row = $this->resolve_target( $context_type, $context_id, $context_revision, $student_target_id );
		if ( null === $target_row ) {
			return null;
		}
		$target     = $this->target_value( $target_row );
		$learner_id = $target['learner_user_id'];

		$scope    = $this->scope_digest( $actor_user_id, $context_type, $context_id, $context_revision, $learner_id, $filter );
		$position = $this->cursor_position( $cursor, $scope );
		$rows     = $this->assignment_rows( $context_type, $context_id, $context_revision, $learner_id, $position, $limit + 1, $filter );
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			array_pop( $rows );
		}

		$assignments = array();
		$updated_at  = $this->required_utc( $target_row['target_created_at_utc'] ?? null );
		foreach ( $rows as $row ) {
			$assignment    = $this->assignment_value( $row );
			$assignments[] = $assignment;
			if ( $assignment['updated_at_utc'] > $updated_at ) {
				$updated_at = $assignment['updated_at_utc'];
			}
		}

		$source                = array(
			'schema_id'      => self::SCHEMA_ID,
			'schema_version' => self::SCHEMA_VERSION,
			'context'        => array(
				'type'     => $context_type,
				'id'       => $context_id,
				'revision' => $context_revision,
			),
			'target'         => array(
				'id'                  => $target['id'],
				'hash'                => $target['hash'],
				'learner_user_id'     => $target['learner_user_id'],
				'display_name'        => $target['display_name'],
				'membership_revision' => $target['membership_revision'],
				'snapshot_hash'       => $target['snapshot_hash'],
			),
			'assignments'    => $assignments,
			'updated_at_utc' => $updated_at,
		);
		$source['source_hash'] = hash( 'sha256', $this->canonical->encode( $source ) );

		$next_cursor = '';
		if ( $has_more && array() !== $rows ) {
			$last        = $rows[ count( $rows ) - 1 ];
			$next_cursor = $this->cursors->encode(
				self::CURSOR_KIND,
				array(
					'i' => $this->public_id( $last['assignment_id'] ?? null ),
					'u' => $this->required_utc( $last['updated_at_utc'] ?? null ),
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
	 * Resolve target.
	 *
	 * @param string $type Type.
	 * @param string $id Id.
	 * @param string $revision Revision.
	 * @param string $target_id Target id.
	 * @return null|array<string,mixed>
	 */
	private function resolve_target( string $type, string $id, string $revision, string $target_id ): ?array {
		$sql  = 'SELECT t.public_id AS target_id, t.content_hash AS target_hash, t.learner_user_id, t.membership_revision, '
			. 't.display_snapshot_schema, t.display_snapshot_version, t.display_snapshot_json, t.display_snapshot_hash, '
			. 't.snapshot_hash AS target_snapshot_hash, t.created_at_utc AS target_created_at_utc '
			. 'FROM ' . self::TABLE_TARGETS . ' t INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = t.assignment_id '
			. 'WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s '
			. 'AND t.public_id = %s AND t.state = %s AND t.learner_user_id IS NOT NULL LIMIT 1';
		$rows = $this->get_results( $this->prepare( $sql, array( $type, $id, $revision, $target_id, 'active' ) ) );
		return $rows[0] ?? null;
	}

	/**
	 * Assignment rows.
	 *
	 * @param string              $type Type.
	 * @param string              $id Id.
	 * @param string              $revision Revision.
	 * @param int                 $learner_id Learner id.
	 * @param array<string,mixed> $position Position.
	 * @param int                 $limit Maximum number of entries requested.
	 * @param Report_Filter       $filter Filter.
	 * @return array<int,array<string,mixed>>
	 */
	private function assignment_rows( string $type, string $id, string $revision, int $learner_id, array $position, int $limit, Report_Filter $filter ): array {
		$values            = array( $learner_id, 'active', $type, $id, $revision );
		$assignment_filter = $this->assignment_constraints( 'a', $filter, $values );
		$sql               = 'SELECT a.public_id AS assignment_id, a.worksheet_publication_public_id AS publication_id, a.publication_hash, '
			. 'a.title_snapshot, a.state, a.due_at_utc, a.updated_at_utc, '
			. 's.public_id AS submission_id, s.updated_at_utc AS submission_updated_at_utc, s.state AS submission_state, '
			. 's.started_at_utc, s.submitted_at_utc, s.official_revision_public_id AS official_revision_id, '
			. 'revision.content_hash AS official_revision_hash, '
			. 's.official_review_public_id AS review_id, s.official_review_hash AS review_hash, '
			. 's.current_release_public_id AS release_id, s.current_release_hash AS release_hash '
			. 'FROM ' . self::TABLE_ASSIGNMENTS . ' a '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = %d AND t.state = %s '
			. 'LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.assignment_id = a.id AND s.learner_user_id = t.learner_user_id '
			. 'LEFT JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.submission_id = s.id AND revision.public_id = s.official_revision_public_id '
			. 'WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s AND ' . $assignment_filter . ' '
			. 'AND ' . $this->submission_stage_predicate( 's', $filter );
		if ( array() !== $position ) {
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
	 * Target value.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_value( array $row ): array {
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
		return array(
			'id'                  => $this->public_id( $row['target_id'] ?? null ),
			'hash'                => $this->hash_value( $row['target_hash'] ?? null ),
			'learner_user_id'     => $this->positive_integer( $row['learner_user_id'] ?? null ),
			'display_name'        => $this->canonical->text( $display['display_name'] ),
			'membership_revision' => $this->token( $row['membership_revision'] ?? null ),
			'snapshot_hash'       => $this->hash_value( $row['target_snapshot_hash'] ?? null ),
		);
	}

	/**
	 * Assignment value.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function assignment_value( array $row ): array {
		$state = $row['state'] ?? null;
		$title = $row['title_snapshot'] ?? null;
		if ( ! is_string( $state ) || ! in_array( $state, Report_Filter::ASSIGNMENT_STATES, true ) || ! is_string( $title ) || '' === trim( $title ) ) {
			$this->stored_invalid();
		}
		$id          = $this->public_id( $row['assignment_id'] ?? null );
		$updated_at  = $this->required_utc( $row['updated_at_utc'] ?? null );
		$publication = array(
			'id'   => $this->public_id( $row['publication_id'] ?? null ),
			'hash' => $this->hash_value( $row['publication_hash'] ?? null ),
		);

		$submission    = null;
		$submission_id = $row['submission_id'] ?? null;
		if ( null !== $submission_id ) {
			$official_id   = $row['official_revision_id'] ?? null;
			$official_hash = $row['official_revision_hash'] ?? null;
			if ( ( null === $official_id ) !== ( null === $official_hash ) ) {
				$this->stored_invalid();
			}
			$submission_state = $row['submission_state'] ?? null;
			if ( ! is_string( $submission_state ) || '' === trim( $submission_state ) ) {
				$this->stored_invalid();
			}
			$submission = array(
				'id'                => $this->public_id( $submission_id ),
				'hash'              => $this->submission_content_hash( $this->public_id( $submission_id ), $this->required_utc( $row['submission_updated_at_utc'] ?? null ) ),
				'state'             => $submission_state,
				'started_at_utc'    => $this->optional_utc( $row['started_at_utc'] ?? null ),
				'submitted_at_utc'  => $this->optional_utc( $row['submitted_at_utc'] ?? null ),
				'official_revision' => null === $official_id ? null : array(
					'id'   => $this->public_id( $official_id ),
					'hash' => $this->hash_value( $official_hash ),
				),
			);
		}

		$review_id    = $row['review_id'] ?? null;
		$review_hash  = $row['review_hash'] ?? null;
		$release_id   = $row['release_id'] ?? null;
		$release_hash = $row['release_hash'] ?? null;
		if ( ( null === $review_id ) !== ( null === $review_hash ) || ( null === $release_id ) !== ( null === $release_hash ) ) {
			$this->stored_invalid();
		}
		if ( null !== $release_id && null === $review_id ) {
			$this->stored_invalid();
		}
		$official_review_ref = null === $review_id ? null : array(
			'review'  => array(
				'id'   => $this->public_id( $review_id ),
				'hash' => $this->hash_value( $review_hash ),
			),
			'release' => null === $release_id ? null : array(
				'id'   => $this->public_id( $release_id ),
				'hash' => $this->hash_value( $release_hash ),
			),
		);

		return array(
			'id'                  => $id,
			'hash'                => $this->assignment_content_hash( $id, $updated_at ),
			'title'               => $this->canonical->text( $title ),
			'state'               => $state,
			'due_at_utc'          => $this->optional_utc( $row['due_at_utc'] ?? null ),
			'publication'         => $publication,
			'submission'          => $submission,
			'official_review_ref' => $official_review_ref,
			'updated_at_utc'      => $updated_at,
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
	 * Cursor position.
	 *
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param string $scope Scope.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function cursor_position( string $cursor, string $scope ): array {
		if ( '' === $cursor ) {
			return array();
		}
		$payload = $this->cursors->decode( $cursor, self::CURSOR_KIND );
		if (
			array_keys( $payload ) !== array( 'i', 'u', 'x' )
			|| ! is_string( $payload['x'] ?? null ) || ! hash_equals( $scope, $payload['x'] )
		) {
			throw new InvalidArgumentException( 'Student report detail cursor scope is invalid.' );
		}
		return array(
			'i' => $this->cursor_public_id( $payload['i'] ?? null ),
			'u' => $this->cursor_utc( $payload['u'] ?? null ),
		);
	}

	/**
	 * Scope digest.
	 *
	 * @param int           $actor Actor.
	 * @param string        $type Type.
	 * @param string        $id Id.
	 * @param string        $revision Revision.
	 * @param int           $learner_id Learner id.
	 * @param Report_Filter $filter Filter.
	 * @return string
	 */
	private function scope_digest( int $actor, string $type, string $id, string $revision, int $learner_id, Report_Filter $filter ): string {
		$filter_payload = $filter->payload();
		return substr( hash( 'sha256', $this->canonical->encode( compact( 'actor', 'type', 'id', 'revision', 'learner_id', 'filter_payload' ) ) ), 0, 16 );
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
			throw new InvalidArgumentException( 'Student report detail cursor ID is invalid.' );
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
			throw new InvalidArgumentException( 'Student report detail cursor time is invalid.' );
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
			throw new InvalidArgumentException( 'Student report detail submission filter is empty.' );
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
			throw new InvalidArgumentException( 'Student report detail placeholder count is invalid.' );
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
