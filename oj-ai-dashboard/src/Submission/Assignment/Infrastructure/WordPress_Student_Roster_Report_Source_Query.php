<?php
/**
 * Assignment and submission owned student-lens roster report source
 * projection: one row per student in a context, aggregated across every
 * worksheet assignment that targets them.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assignment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Port\Student_Roster_Report_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the student roster denominators and current submission pointers inside their owner.
 */
final class WordPress_Student_Roster_Report_Source_Query implements Student_Roster_Report_Source_Query_Port_Interface {
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';

	private const SCHEMA_ID      = 'oj_ai.student_roster_source';
	private const SCHEMA_VERSION = '1.0.0';
	private const DISPLAY_SCHEMA = 'oj_ai.assignment_target_display';
	private const CURSOR_KIND    = 'student_roster_list';

	/**
	 * Initialize WordPress student roster report source query dependencies and state.
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
	 * List context.
	 *
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $context_type Context type.
	 * @param string             $context_id Context id.
	 * @param string             $context_revision Context revision.
	 * @param string             $cursor Continuation cursor from the previous page.
	 * @param int                $limit Maximum number of entries requested.
	 * @param Report_Filter|null $filter Filter.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_context(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $cursor,
		int $limit,
		?Report_Filter $filter = null
	): array {
		if (
			$actor_user_id < 1 || '' === trim( $context_type ) || '' === trim( $context_id )
			|| '' === trim( $context_revision ) || strlen( $cursor ) > 191 || $limit < 1 || $limit > 100
		) {
			throw new InvalidArgumentException( 'Student roster report query is invalid.' );
		}

		$filter    ??= Report_Filter::defaults();
		$scope       = $this->scope_digest( $actor_user_id, $context_type, $context_id, $context_revision, $filter );
		$position    = $this->cursor_position( $cursor, $scope );
		$roster_rows = $this->roster_rows( $context_type, $context_id, $context_revision, $position, $limit + 1, $filter );
		$has_more    = count( $roster_rows ) > $limit;
		if ( $has_more ) {
			array_pop( $roster_rows );
		}
		if ( array() === $roster_rows ) {
			return array(
				'items'       => array(),
				'next_cursor' => '',
			);
		}

		$sources     = array();
		$order       = array();
		$learner_ids = array();
		foreach ( $roster_rows as $row ) {
			$source     = $this->roster_source( $row );
			$learner_id = $source['target']['learner_user_id'];
			if ( isset( $sources[ $learner_id ] ) ) {
				$this->stored_invalid();
			}
			$sources[ $learner_id ] = $source;
			$order[]                = $learner_id;
			$learner_ids[]          = $learner_id;
		}

		foreach ( $this->target_rows( $context_type, $context_id, $context_revision, $learner_ids, $filter ) as $row ) {
			$learner_id = $this->positive_integer( $row['learner_user_id'] ?? null );
			if ( ! isset( $sources[ $learner_id ] ) ) {
				$this->stored_invalid();
			}
			$sources[ $learner_id ]['target'] = $this->target_value( $row, $learner_id );
		}
		foreach ( $sources as $learner_id => $source ) {
			if ( ! isset( $source['target']['id'] ) ) {
				$this->stored_invalid();
			}
		}

		foreach ( $this->review_rows( $context_type, $context_id, $context_revision, $learner_ids, $filter ) as $row ) {
			$learner_id = $this->positive_integer( $row['learner_user_id'] ?? null );
			if ( ! isset( $sources[ $learner_id ] ) ) {
				$this->stored_invalid();
			}
			$sources[ $learner_id ]['official_review_refs'][] = $this->review_reference( $row );
		}

		$items = array();
		foreach ( $order as $learner_id ) {
			$source = $sources[ $learner_id ];
			$this->assert_source_totals( $source );
			$source['source_hash'] = hash( 'sha256', $this->canonical->encode( $source ) );
			$items[]               = $source;
		}

		$next_cursor = '';
		if ( $has_more ) {
			$last        = $roster_rows[ count( $roster_rows ) - 1 ];
			$next_cursor = $this->cursors->encode(
				self::CURSOR_KIND,
				array(
					'l' => $this->positive_integer( $last['learner_user_id'] ?? null ),
					'u' => $this->required_utc( $last['latest_activity_at_utc'] ?? null ),
					'x' => $scope,
				)
			);
		}

		return array(
			'items'       => $items,
			'next_cursor' => $next_cursor,
		);
	}

	/**
	 * Roster rows.
	 *
	 * @param string              $type Type.
	 * @param string              $id Id.
	 * @param string              $revision Revision.
	 * @param array<string,mixed> $position Position.
	 * @param int                 $limit Maximum number of entries requested.
	 * @param Report_Filter       $filter Filter.
	 * @return array<int,array<string,mixed>>
	 */
	private function roster_rows( string $type, string $id, string $revision, array $position, int $limit, Report_Filter $filter ): array {
		$values            = array( 'active', $type, $id, $revision );
		$assignment_filter = $this->assignment_constraints( 'a', $filter, $values );
		$sql               = 'SELECT t.learner_user_id, '
			. 'COUNT(DISTINCT t.id) AS assignment_count, '
			. 'COUNT(DISTINCT CASE WHEN s.official_revision_public_id IS NOT NULL THEN s.id END) AS official_submission_count, '
			. 'COUNT(DISTINCT CASE WHEN s.official_review_public_id IS NOT NULL THEN s.id END) AS official_review_count, '
			. 'MAX(CASE WHEN s.updated_at_utc IS NOT NULL AND s.updated_at_utc > a.updated_at_utc THEN s.updated_at_utc ELSE a.updated_at_utc END) AS latest_activity_at_utc '
			. 'FROM ' . self::TABLE_ASSIGNMENTS . ' a '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.state = %s AND t.learner_user_id IS NOT NULL '
			. 'LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.assignment_id = a.id AND s.learner_user_id = t.learner_user_id '
			. 'WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s '
			. 'AND ' . $assignment_filter . ' AND ' . $this->submission_stage_predicate( 's', $filter )
			. ' GROUP BY t.learner_user_id';
		if ( array() !== $position ) {
			$sql     .= ' HAVING latest_activity_at_utc < %s OR (latest_activity_at_utc = %s AND t.learner_user_id > %d)';
			$values[] = $position['u'];
			$values[] = $position['u'];
			$values[] = $position['l'];
		}
		$sql     .= ' ORDER BY latest_activity_at_utc DESC, t.learner_user_id ASC LIMIT %d';
		$values[] = $limit;
		return $this->get_results( $this->prepare( $sql, $values ) );
	}

	/**
	 * Target rows.
	 *
	 * @param string         $type Type.
	 * @param string         $id Id.
	 * @param string         $revision Revision.
	 * @param array<int,int> $learner_ids Learner ids.
	 * @param Report_Filter  $filter Filter.
	 * @return array<int,array<string,mixed>>
	 */
	private function target_rows( string $type, string $id, string $revision, array $learner_ids, Report_Filter $filter ): array {
		if ( array() === $learner_ids ) {
			return array();
		}
		$values            = array( $type, $id, $revision );
		$assignment_filter = $this->assignment_constraints( 'a2', $filter, $values );
		$values[]          = 'active';
		foreach ( $learner_ids as $learner_id ) {
			$values[] = $learner_id;
		}
		$sql = 'SELECT t.learner_user_id, t.public_id AS target_id, t.content_hash AS target_hash, t.membership_revision, '
			. 't.display_snapshot_schema, t.display_snapshot_version, t.display_snapshot_json, t.display_snapshot_hash, '
			. 't.snapshot_hash AS target_snapshot_hash '
			. 'FROM ' . self::TABLE_TARGETS . ' t INNER JOIN ( '
			. 'SELECT t2.learner_user_id AS learner_user_id, MAX(t2.id) AS max_id '
			. 'FROM ' . self::TABLE_TARGETS . ' t2 INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a2 ON a2.id = t2.assignment_id '
			. 'WHERE a2.context_type = %s AND a2.context_id = %s AND a2.context_revision = %s AND ' . $assignment_filter . ' '
			. 'AND t2.state = %s AND t2.learner_user_id IN (' . $this->int_placeholders( count( $learner_ids ) ) . ') '
			. 'GROUP BY t2.learner_user_id'
			. ' ) latest ON latest.max_id = t.id';
		return $this->get_results( $this->prepare( $sql, $values ) );
	}

	/**
	 * Review rows.
	 *
	 * @param string         $type Type.
	 * @param string         $id Id.
	 * @param string         $revision Revision.
	 * @param array<int,int> $learner_ids Learner ids.
	 * @param Report_Filter  $filter Filter.
	 * @return array<int,array<string,mixed>>
	 */
	private function review_rows( string $type, string $id, string $revision, array $learner_ids, Report_Filter $filter ): array {
		if ( array() === $learner_ids ) {
			return array();
		}
		$values            = array( 'active', $type, $id, $revision );
		$assignment_filter = $this->assignment_constraints( 'a', $filter, $values );
		$sql               = 'SELECT t.learner_user_id, '
			. 's.official_review_public_id AS review_id, s.official_review_hash AS review_hash, '
			. 's.current_release_public_id AS release_id, s.current_release_hash AS release_hash '
			. 'FROM ' . self::TABLE_ASSIGNMENTS . ' a INNER JOIN ' . self::TABLE_TARGETS . ' t '
			. 'ON t.assignment_id = a.id AND t.state = %s AND t.learner_user_id IS NOT NULL '
			. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.assignment_id = a.id AND s.learner_user_id = t.learner_user_id '
			. 'WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s AND ' . $assignment_filter . ' '
			. 'AND t.learner_user_id IN (' . $this->int_placeholders( count( $learner_ids ) ) . ') '
			. 'AND ' . $this->submission_stage_predicate( 's', $filter )
			. ' AND s.official_revision_public_id IS NOT NULL AND s.official_review_public_id IS NOT NULL '
			. 'ORDER BY t.learner_user_id ASC, s.official_review_public_id ASC';
		foreach ( $learner_ids as $learner_id ) {
			$values[] = $learner_id;
		}
		return $this->get_results( $this->prepare( $sql, $values ) );
	}

	/**
	 * Roster source.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function roster_source( array $row ): array {
		return array(
			'schema_id'                 => self::SCHEMA_ID,
			'schema_version'            => self::SCHEMA_VERSION,
			'target'                    => array( 'learner_user_id' => $this->positive_integer( $row['learner_user_id'] ?? null ) ),
			'assignment_count'          => $this->non_negative_integer( $row['assignment_count'] ?? null ),
			'official_submission_count' => $this->non_negative_integer( $row['official_submission_count'] ?? null ),
			'official_review_count'     => $this->non_negative_integer( $row['official_review_count'] ?? null ),
			'official_review_refs'      => array(),
			'updated_at_utc'            => $this->required_utc( $row['latest_activity_at_utc'] ?? null ),
		);
	}

	/**
	 * Target value.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param int                 $learner_id Learner id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_value( array $row, int $learner_id ): array {
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
			'learner_user_id'     => $learner_id,
			'display_name'        => $this->canonical->text( $display['display_name'] ),
			'membership_revision' => $this->token( $row['membership_revision'] ?? null ),
			'snapshot_hash'       => $this->hash_value( $row['target_snapshot_hash'] ?? null ),
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
			'review'  => array(
				'id'   => $this->public_id( $row['review_id'] ?? null ),
				'hash' => $this->hash_value( $row['review_hash'] ?? null ),
			),
			'release' => null === $release_id ? null : array(
				'id'   => $this->public_id( $release_id ),
				'hash' => $this->hash_value( $release_hash ),
			),
		);
	}

	/**
	 * Reject invalid source totals.
	 *
	 * @param array<string,mixed> $source Source.
	 * @return void
	 */
	private function assert_source_totals( array $source ): void {
		if (
			count( $source['official_review_refs'] ) !== $source['official_review_count']
			|| $source['official_submission_count'] > $source['assignment_count']
			|| $source['official_review_count'] > $source['official_submission_count']
		) {
			$this->stored_invalid();
		}
	}

	/**
	 * Cursor position.
	 *
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param string $scope Scope.
	 * @return array{}|array{l:int,u:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function cursor_position( string $cursor, string $scope ): array {
		if ( '' === $cursor ) {
			return array();
		}
		$payload = $this->cursors->decode( $cursor, self::CURSOR_KIND );
		if (
			array_keys( $payload ) !== array( 'l', 'u', 'x' )
			|| ! is_string( $payload['x'] ?? null ) || ! hash_equals( $scope, $payload['x'] )
		) {
			throw new InvalidArgumentException( 'Student roster report cursor scope is invalid.' );
		}
		return array(
			'l' => $this->cursor_positive_integer( $payload['l'] ?? null ),
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
	 * @param Report_Filter $filter Filter.
	 * @return string
	 */
	private function scope_digest( int $actor, string $type, string $id, string $revision, Report_Filter $filter ): string {
		$filter_payload = $filter->payload();
		return substr( hash( 'sha256', $this->canonical->encode( compact( 'actor', 'type', 'id', 'revision', 'filter_payload' ) ) ), 0, 16 );
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
			throw new InvalidArgumentException( 'Student roster report cursor learner is invalid.' );
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
			throw new InvalidArgumentException( 'Student roster report cursor time is invalid.' );
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
			throw new InvalidArgumentException( 'Student roster report submission filter is empty.' );
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
			throw new InvalidArgumentException( 'Student roster report publication page is invalid.' );
		}
		return implode( ', ', array_fill( 0, $count, '%s' ) );
	}

	/**
	 * Int placeholders.
	 *
	 * @param int $count Count.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function int_placeholders( int $count ): string {
		if ( $count < 1 || $count > 100 ) {
			throw new InvalidArgumentException( 'Student roster report learner page is invalid.' );
		}
		return implode( ', ', array_fill( 0, $count, '%d' ) );
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
