<?php
/**
 * Assignment and submission owned report source projection.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assignment\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Port\Assignment_Progress_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the Q20 denominator and current submission pointers inside their owner.
 */
final class WordPress_Assignment_Progress_Report_Source_Query implements Assignment_Progress_Source_Query_Port_Interface {
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';

	private const SCHEMA_ID      = 'oj_ai.assignment_progress_source';
	private const SCHEMA_VERSION = '1.0.0';
	private const CURSOR_KIND    = 'report_list';

	/**
	 * Initialize WordPress assignment progress report source query dependencies and state.
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
			throw new InvalidArgumentException( 'Assignment report query is invalid.' );
		}

		$filter     ??= Report_Filter::defaults();
		$scope        = $this->scope_digest( $actor_user_id, $context_type, $context_id, $context_revision, $filter );
		$position     = $this->cursor_position( $cursor, $scope );
		$summary_rows = $this->summary_rows( $context_type, $context_id, $context_revision, $position, $limit + 1, $filter );
		$has_more     = count( $summary_rows ) > $limit;
		if ( $has_more ) {
			array_pop( $summary_rows );
		}
		if ( array() === $summary_rows ) {
			return array(
				'items'       => array(),
				'next_cursor' => '',
			);
		}

		$sources      = array();
		$publications = array();
		foreach ( $summary_rows as $row ) {
			$source = $this->summary_source( $row );
			$id     = $source['publication']['id'];
			if ( isset( $sources[ $id ] ) ) {
				$this->stored_invalid();
			}
			$sources[ $id ] = $source;
			$publications[] = $id;
		}

		foreach ( $this->assignment_rows( $context_type, $context_id, $context_revision, $publications, $filter ) as $row ) {
			$publication_id = $this->public_id( $row['publication_id'] ?? null );
			if ( ! isset( $sources[ $publication_id ] ) ) {
				$this->stored_invalid();
			}
			$sources[ $publication_id ]['assignments'][] = $this->assignment_source( $row, $sources[ $publication_id ]['publication']['hash'] );
		}

		$seen_reviews = array();
		foreach ( $this->review_rows( $context_type, $context_id, $context_revision, $publications, $filter ) as $row ) {
			$publication_id = $this->public_id( $row['publication_id'] ?? null );
			if ( ! isset( $sources[ $publication_id ] ) ) {
				$this->stored_invalid();
			}
			$reference = $this->review_reference( $row, $sources[ $publication_id ]['publication']['hash'] );
			if ( isset( $seen_reviews[ $reference['review']['id'] ] ) ) {
				$this->stored_invalid();
			}
			$seen_reviews[ $reference['review']['id'] ]           = true;
			$sources[ $publication_id ]['official_review_refs'][] = $reference;
		}

		$items = array();
		foreach ( $sources as $source ) {
			$this->assert_source_totals( $source );
			$source['source_hash'] = hash( 'sha256', $this->canonical->encode( $source ) );
			$items[]               = $source;
		}

		$next_cursor = '';
		if ( $has_more ) {
			$last        = $summary_rows[ count( $summary_rows ) - 1 ];
			$next_cursor = $this->cursors->encode(
				self::CURSOR_KIND,
				array(
					'p' => $this->public_id( $last['publication_id'] ?? null ),
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
	 * Summary rows.
	 *
	 * @param string              $type Type.
	 * @param string              $id Id.
	 * @param string              $revision Revision.
	 * @param array<string,mixed> $position Position.
	 * @param int                 $limit Maximum number of entries requested.
	 * @param Report_Filter       $filter Filter.
	 * @return array<int,array<string,mixed>>
	 */
	private function summary_rows( string $type, string $id, string $revision, array $position, int $limit, Report_Filter $filter ): array {
		$values            = array( 'active', $type, $id, $revision );
		$assignment_filter = $this->assignment_constraints( 'a', $filter, $values );
		$sql               = 'SELECT a.worksheet_publication_public_id AS publication_id, a.publication_hash, '
			. 'COUNT(DISTINCT a.id) AS assignment_count, COUNT(DISTINCT t.id) AS target_count, '
			. 'COUNT(DISTINCT CASE WHEN s.official_revision_public_id IS NOT NULL THEN s.id END) AS official_submission_count, '
			. 'COUNT(DISTINCT CASE WHEN s.official_review_public_id IS NOT NULL THEN s.id END) AS official_review_count, '
			. 'MAX(CASE WHEN s.updated_at_utc IS NOT NULL AND s.updated_at_utc > a.updated_at_utc THEN s.updated_at_utc ELSE a.updated_at_utc END) AS latest_activity_at_utc '
			. 'FROM ' . self::TABLE_ASSIGNMENTS . ' a '
			. 'LEFT JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.state = %s AND t.learner_user_id IS NOT NULL '
			. 'LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.assignment_id = a.id AND s.learner_user_id = t.learner_user_id '
			. 'WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s '
			. 'AND ' . $assignment_filter . ' AND t.id IS NOT NULL AND ' . $this->submission_stage_predicate( 's', $filter )
			. ' GROUP BY a.worksheet_publication_public_id, a.publication_hash';
		if ( array() !== $position ) {
			$sql     .= ' HAVING latest_activity_at_utc < %s OR (latest_activity_at_utc = %s AND a.worksheet_publication_public_id > %s)';
			$values[] = $position['u'];
			$values[] = $position['u'];
			$values[] = $position['p'];
		}
		$sql     .= ' ORDER BY latest_activity_at_utc DESC, a.worksheet_publication_public_id ASC LIMIT %d';
		$values[] = $limit;
		return $this->get_results( $this->prepare( $sql, $values ) );
	}

	/**
	 * Assignment rows.
	 *
	 * @param string            $type Type.
	 * @param string            $id Id.
	 * @param string            $revision Revision.
	 * @param array<int,string> $publications Publications.
	 * @param Report_Filter     $filter Filter.
	 * @return array<int,array<string,mixed>>
	 */
	private function assignment_rows( string $type, string $id, string $revision, array $publications, Report_Filter $filter ): array {
		$values            = array( 'active', 'active', 'active', $type, $id, $revision );
		$assignment_filter = $this->assignment_constraints( 'a', $filter, $values );
		$sql               = 'SELECT a.public_id AS assignment_id, '
			. 'a.worksheet_publication_public_id AS publication_id, a.publication_hash, a.title_snapshot, a.state, '
			. 'a.due_at_utc, a.target_snapshot_hash, a.updated_at_utc, '
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
			. 'AND ' . $assignment_filter . ' AND a.worksheet_publication_public_id IN (' . $this->placeholders( count( $publications ) ) . ') '
			. 'AND EXISTS (SELECT 1 FROM ' . self::TABLE_TARGETS . ' tf LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' sf '
			. 'ON sf.assignment_id = tf.assignment_id AND sf.learner_user_id = tf.learner_user_id '
			. 'WHERE tf.assignment_id = a.id AND tf.state = %s AND tf.learner_user_id IS NOT NULL AND '
			. $this->submission_stage_predicate( 'sf', $filter ) . ') '
			. 'ORDER BY a.worksheet_publication_public_id ASC, a.public_id ASC';
		$values            = array_merge( $values, $publications, array( 'active' ) );
		return $this->get_results( $this->prepare( $sql, $values ) );
	}

	/**
	 * Review rows.
	 *
	 * @param string            $type Type.
	 * @param string            $id Id.
	 * @param string            $revision Revision.
	 * @param array<int,string> $publications Publications.
	 * @param Report_Filter     $filter Filter.
	 * @return array<int,array<string,mixed>>
	 */
	private function review_rows( string $type, string $id, string $revision, array $publications, Report_Filter $filter ): array {
		$values            = array( 'active', $type, $id, $revision );
		$assignment_filter = $this->assignment_constraints( 'a', $filter, $values );
		$sql               = 'SELECT a.worksheet_publication_public_id AS publication_id, a.publication_hash, '
			. 's.official_review_public_id AS review_id, s.official_review_hash AS review_hash, '
			. 's.current_release_public_id AS release_id, s.current_release_hash AS release_hash '
			. 'FROM ' . self::TABLE_ASSIGNMENTS . ' a INNER JOIN ' . self::TABLE_TARGETS . ' t '
			. 'ON t.assignment_id = a.id AND t.state = %s AND t.learner_user_id IS NOT NULL '
			. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.assignment_id = a.id AND s.learner_user_id = t.learner_user_id '
			. 'WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s AND ' . $assignment_filter . ' '
			. 'AND a.worksheet_publication_public_id IN (' . $this->placeholders( count( $publications ) ) . ') '
			. 'AND ' . $this->submission_stage_predicate( 's', $filter )
			. ' AND s.official_revision_public_id IS NOT NULL AND s.official_review_public_id IS NOT NULL '
			. 'ORDER BY a.worksheet_publication_public_id ASC, s.official_review_public_id ASC';
		$values            = array_merge( $values, $publications );
		return $this->get_results( $this->prepare( $sql, $values ) );
	}

	/**
	 * Summary source.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function summary_source( array $row ): array {
		return array(
			'schema_id'                 => self::SCHEMA_ID,
			'schema_version'            => self::SCHEMA_VERSION,
			'publication'               => array(
				'id'   => $this->public_id( $row['publication_id'] ?? null ),
				'hash' => $this->hash_value( $row['publication_hash'] ?? null ),
			),
			'assignment_count'          => $this->non_negative_integer( $row['assignment_count'] ?? null ),
			'target_count'              => $this->non_negative_integer( $row['target_count'] ?? null ),
			'official_submission_count' => $this->non_negative_integer( $row['official_submission_count'] ?? null ),
			'official_review_count'     => $this->non_negative_integer( $row['official_review_count'] ?? null ),
			'assignments'               => array(),
			'official_review_refs'      => array(),
			'updated_at_utc'            => $this->required_utc( $row['latest_activity_at_utc'] ?? null ),
		);
	}

	/**
	 * Assignment source.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $publication_hash Publication hash.
	 * @return array<string,mixed>
	 */
	private function assignment_source( array $row, string $publication_hash ): array {
		if ( ! hash_equals( $publication_hash, $this->hash_value( $row['publication_hash'] ?? null ) ) ) {
			$this->stored_invalid();
		}
		$title = $row['title_snapshot'] ?? null;
		$state = $row['state'] ?? null;
		if ( ! is_string( $title ) || '' === trim( $title ) || ! is_string( $state ) || ! in_array( $state, Report_Filter::ASSIGNMENT_STATES, true ) ) {
			$this->stored_invalid();
		}
		$id         = $this->public_id( $row['assignment_id'] ?? null );
		$updated_at = $this->required_utc( $row['updated_at_utc'] ?? null );
		return array(
			'id'                        => $id,
			'hash'                      => $this->assignment_content_hash( $id, $updated_at ),
			'title'                     => $this->canonical->text( $title ),
			'state'                     => $state,
			'due_at_utc'                => $this->optional_utc( $row['due_at_utc'] ?? null ),
			'target_snapshot_hash'      => $this->hash_value( $row['target_snapshot_hash'] ?? null ),
			'target_count'              => $this->non_negative_integer( $row['target_count'] ?? null ),
			'official_submission_count' => $this->non_negative_integer( $row['official_submission_count'] ?? null ),
			'official_review_count'     => $this->non_negative_integer( $row['official_review_count'] ?? null ),
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
	 * Review reference.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $publication_hash Publication hash.
	 * @return array<string,mixed>
	 */
	private function review_reference( array $row, string $publication_hash ): array {
		if ( ! hash_equals( $publication_hash, $this->hash_value( $row['publication_hash'] ?? null ) ) ) {
			$this->stored_invalid();
		}
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
		$assignment_count = 0;
		$target_count     = 0;
		$submission_count = 0;
		$review_count     = 0;
		foreach ( $source['assignments'] as $assignment ) {
			++$assignment_count;
			$target_count     += $assignment['target_count'];
			$submission_count += $assignment['official_submission_count'];
			$review_count     += $assignment['official_review_count'];
		}
		if (
			$assignment_count !== $source['assignment_count'] || $target_count !== $source['target_count']
			|| $submission_count !== $source['official_submission_count'] || $review_count !== $source['official_review_count']
			|| count( $source['official_review_refs'] ) !== $source['official_review_count']
		) {
			$this->stored_invalid();
		}
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
			array_keys( $payload ) !== array( 'p', 'u', 'x' )
			|| ! is_string( $payload['x'] ?? null ) || ! hash_equals( $scope, $payload['x'] )
		) {
			throw new InvalidArgumentException( 'Assignment report cursor scope is invalid.' );
		}
		return array(
			'p' => $this->cursor_public_id( $payload['p'] ?? null ),
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
	 * Cursor public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function cursor_public_id( mixed $value ): string {
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Assignment report cursor ID is invalid.' );
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
			throw new InvalidArgumentException( 'Assignment report cursor time is invalid.' );
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
			throw new InvalidArgumentException( 'Assignment report submission filter is empty.' );
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
			throw new InvalidArgumentException( 'Assignment report publication page is invalid.' );
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
