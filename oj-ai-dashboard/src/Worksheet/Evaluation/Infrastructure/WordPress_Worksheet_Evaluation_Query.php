<?php
/**
 * Literal-table Q09 and Q10 worksheet evaluation query adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Query_Projector;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Query_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Literal-table Q09 and Q10 worksheet evaluation query adapter.
 */
final class WordPress_Worksheet_Evaluation_Query implements Worksheet_Evaluation_Query_Port_Interface {
	private const TABLE_EVALUATIONS = 'oj_ai_worksheet_evaluations';
	private const TABLE_RESULTS     = 'oj_ai_worksheet_evaluation_results';
	private const TABLE_ITEMS       = 'oj_ai_worksheet_evaluation_items';
	private const TABLE_REVIEWS     = 'oj_ai_worksheet_evaluation_reviews';
	private const TABLE_RUNS        = 'oj_ai_evaluation_runs';
	private const TABLE_ATTEMPTS    = 'oj_ai_evaluation_attempts';
	private const TABLE_WORKSHEETS  = 'oj_ai_worksheets';
	private const TABLE_REVISIONS   = 'oj_ai_worksheet_revisions';

	/**
	 * Projector.
	 *
	 * @var Worksheet_Evaluation_Query_Projector
	 */
	private Worksheet_Evaluation_Query_Projector $projector;
	/**
	 * Cursors.
	 *
	 * @var Signed_Cursor_Codec
	 */
	private Signed_Cursor_Codec $cursors;

	/**
	 * Initialize WordPress worksheet evaluation query dependencies and state.
	 *
	 * @param Canonical_Json           $canonical Canonical JSON encoder used for stable digests.
	 * @param Signed_Cursor_Codec|null $cursors Cursors.
	 * @param object|null              $database Database.
	 */
	public function __construct(
		Canonical_Json $canonical,
		?Signed_Cursor_Codec $cursors = null,
		private ?object $database = null
	) {
		$this->projector = new Worksheet_Evaluation_Query_Projector( $canonical );
		$this->cursors   = $cursors ?? new Signed_Cursor_Codec( $canonical );
	}

	/**
	 * List owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $state State.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @param bool   $latest_only Latest only.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_owned(
		int $actor_user_id,
		string $state,
		string $worksheet_id,
		string $revision_id,
		string $cursor,
		int $limit,
		bool $latest_only = false
	): array {
		try {
			$cursor_payload = $this->cursors->decode( $cursor, 'worksheet_evaluation_list' );
			$scope_hash     = $this->scope_hash( $state, $worksheet_id, $revision_id, $latest_only );
			$sql            = $this->list_select() . ' WHERE w.owner_user_id = %d';
			$values         = array(
				'info',
				'safety',
				'worksheet_evaluation',
				'worksheet_evaluation',
				$actor_user_id,
			);

			// Pick the newest revision/request before filtering; an old review must not replace it.
			if ( $latest_only ) {
				$sql .= ' AND NOT EXISTS (SELECT 1 FROM ' . self::TABLE_EVALUATIONS . ' newer '
					. 'INNER JOIN ' . self::TABLE_REVISIONS . ' newer_revision '
					. 'ON newer_revision.public_id = newer.worksheet_revision_public_id '
					. 'WHERE newer_revision.worksheet_id = w.id AND '
					. '(newer_revision.revision_no > revision.revision_no OR '
					. '(newer_revision.revision_no = revision.revision_no AND newer.id > e.id)))';
			}
			if ( 'stale' === $state ) {
				$sql .= ' AND (w.current_revision_public_id IS NULL OR w.current_revision_public_id <> e.worksheet_revision_public_id)';
			} elseif ( '' !== $state ) {
				$sql     .= ' AND e.state = %s';
				$values[] = $state;
			}
			if ( '' !== $worksheet_id ) {
				$sql     .= ' AND w.public_id = %s';
				$values[] = $worksheet_id;
			}
			if ( '' !== $revision_id ) {
				$sql     .= ' AND e.worksheet_revision_public_id = %s';
				$values[] = $revision_id;
			}
			if ( array() !== $cursor_payload ) {
				$this->exact_keys( $cursor_payload, array( 'f', 'i', 't' ) );
				if ( ! hash_equals( $scope_hash, (string) ( $cursor_payload['f'] ?? '' ) ) ) {
					throw new InvalidArgumentException( 'Worksheet evaluation cursor scope does not match.' );
				}
				$cursor_id   = (string) ( $cursor_payload['i'] ?? '' );
				$cursor_time = (string) ( $cursor_payload['t'] ?? '' );
				new Public_Id( $cursor_id );
				$this->assert_utc( $cursor_time );
				$sql     .= ' AND (e.updated_at_utc < %s OR (e.updated_at_utc = %s AND e.public_id < %s))';
				$values[] = $cursor_time;
				$values[] = $cursor_time;
				$values[] = $cursor_id;
			}

			$sql     .= ' ORDER BY e.updated_at_utc DESC, e.public_id DESC LIMIT %d';
			$values[] = $limit + 1;
			$rows     = $this->rows( $this->prepare( $sql, $values ) );
			$has_more = count( $rows ) > $limit;
			if ( $has_more ) {
				array_pop( $rows );
			}
			$items = array_map(
				fn ( array $row ): array => $this->projector->list_item( $row ),
				$rows
			);
			$next  = '';
			if ( $has_more && array() !== $items ) {
				$last = $items[ count( $items ) - 1 ];
				$next = $this->cursors->encode(
					'worksheet_evaluation_list',
					array(
						'f' => $scope_hash,
						'i' => $last['id'],
						't' => $last['updated_at_utc'],
					)
				);
			}

			return array(
				'items'       => $items,
				'next_cursor' => $next,
			);
		} catch ( InvalidArgumentException | Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_unavailable();
		}
	}

	/**
	 * Find owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_owned( int $actor_user_id, string $evaluation_id ): ?array {
		try {
			$root = $this->row(
				$this->prepare(
					$this->list_select() . ' WHERE e.public_id = %s AND w.owner_user_id = %d LIMIT 1',
					array(
						'info',
						'safety',
						'worksheet_evaluation',
						'worksheet_evaluation',
						$evaluation_id,
						$actor_user_id,
					)
				)
			);
			if ( null === $root ) {
				return null;
			}
			$evaluation_pk = $this->positive_integer( $root['evaluation_pk'] ?? null );
			$result        = null;
			$item_rows     = array();
			$result_id     = $root['result_id'] ?? null;
			$result_pk     = null;
			if ( is_string( $result_id ) && '' !== $result_id ) {
				$result = $this->row(
					$this->prepare(
						'SELECT x.id AS result_pk, x.public_id AS result_id, x.content_hash AS result_content_hash, '
							. 'x.created_at_utc AS result_created_at_utc, x.output_schema_version, x.overall_status, '
							. 'x.overall_confidence, x.summary, x.normalized_result_schema, x.normalized_result_version, '
							. 'x.normalized_result_json, x.normalized_result_hash, x.provider_output_digest, '
							. 'run.public_id AS result_run_id FROM ' . self::TABLE_RESULTS . ' x '
							. 'INNER JOIN ' . self::TABLE_RUNS . ' run ON run.id = x.evaluation_run_id '
							. 'WHERE x.public_id = %s AND x.worksheet_evaluation_id = %d '
							. 'AND run.subject_type = %s AND run.evaluation_public_id = %s LIMIT 1',
						array( $result_id, $evaluation_pk, 'worksheet_evaluation', $evaluation_id )
					)
				);
				if ( null === $result ) {
					throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
				}
				$result_pk = $this->positive_integer( $result['result_pk'] ?? null );
				$item_rows = $this->rows(
					$this->prepare(
						'SELECT content_hash AS item_content_hash, dimension_key AS item_dimension_key, '
							. 'position AS item_position, severity AS item_severity, confidence AS item_confidence, '
							. 'evidence_schema, evidence_version, evidence_json, evidence_hash, '
							. 'recommendation AS item_recommendation FROM ' . self::TABLE_ITEMS . ' '
							. 'WHERE worksheet_evaluation_result_id = %d ORDER BY position ASC',
						array( $result_pk )
					)
				);
			}

			$runs = $this->rows(
				$this->prepare(
					'SELECT run.public_id AS run_id, run.run_no, run.state AS run_state, '
						. 'run.result_public_id AS run_result_id, run.available_at_utc AS run_available_at_utc, '
						. 'run.queued_at_utc AS run_queued_at_utc, run.started_at_utc AS run_started_at_utc, '
						. 'run.finished_at_utc AS run_finished_at_utc, run.last_error_code AS run_last_error_code, '
						. 'run.last_error_message AS run_last_error_message, '
						. '(SELECT COUNT(*) FROM ' . self::TABLE_ATTEMPTS . ' attempt '
						. 'WHERE attempt.evaluation_run_id = run.id) AS attempt_count '
						. 'FROM ' . self::TABLE_RUNS . ' run WHERE run.subject_type = %s '
						. 'AND run.evaluation_public_id = %s ORDER BY run.run_no ASC',
					array( 'worksheet_evaluation', $evaluation_id )
				)
			);

			$reviews = array();
			if ( null !== $result_pk ) {
				$reviews = $this->rows(
					$this->prepare(
						'SELECT review.public_id AS review_id, review.review_no, review.decision AS review_decision, '
							. 'review.note AS review_note, review.item_decisions_schema, review.item_decisions_version, '
							. 'review.item_decisions_json, review.item_decisions_hash, review.created_at_utc AS review_created_at_utc, '
							. 'previous.public_id AS previous_review_public_id, result.public_id AS review_result_id '
							. 'FROM ' . self::TABLE_REVIEWS . ' review '
							. 'INNER JOIN ' . self::TABLE_RESULTS . ' result ON result.id = review.worksheet_evaluation_result_id '
							. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' previous ON previous.id = review.previous_review_id '
							. 'WHERE review.worksheet_evaluation_id = %d AND review.worksheet_evaluation_result_id = %d '
							. 'ORDER BY review.review_no ASC',
						array( $evaluation_pk, $result_pk )
					)
				);
			}

			return $this->projector->detail( $root, $result, $item_rows, $runs, $reviews );
		} catch ( InvalidArgumentException | Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_unavailable();
		}
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorization_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		try {
			$row = $this->row(
				$this->prepare(
					'SELECT e.worksheet_revision_public_id AS current_revision, e.state '
						. 'FROM ' . self::TABLE_EVALUATIONS . ' e '
						. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision '
						. 'ON revision.public_id = e.worksheet_revision_public_id '
						. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = revision.worksheet_id '
						. 'WHERE e.public_id = %s AND w.owner_user_id = %d LIMIT 1',
					array( $evaluation_id, $actor_user_id )
				)
			);
			if ( null === $row ) {
				return null;
			}
			$current_revision = (string) ( $row['current_revision'] ?? '' );
			new Public_Id( $current_revision );
			$state = (string) ( $row['state'] ?? '' );
			if ( ! in_array( $state, array( 'requested', 'queued', 'running', 'review_required', 'reviewed', 'failed', 'cancelled' ), true ) ) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
			}

			return array(
				'current_revision' => $current_revision,
				'state'            => $state,
				'relation'         => 'owner',
			);
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_unavailable();
		}
	}

	/**
	 * List select.
	 *
	 * @return string
	 */
	private function list_select(): string {
		return 'SELECT e.id AS evaluation_pk, e.public_id AS evaluation_id, e.state AS evaluation_state, '
			. 'e.worksheet_revision_public_id AS worksheet_revision_id, e.worksheet_revision_hash, '
			. 'e.requested_at_utc, e.updated_at_utc AS evaluation_updated_at_utc, '
			. 'w.public_id AS worksheet_id, w.title AS worksheet_title, '
			. 'w.current_revision_public_id AS current_revision_id, revision.revision_no, '
			. 'revision.state AS revision_state, latest_run.public_id AS latest_run_id, '
			. 'latest_run.run_no AS latest_run_no, latest_run.state AS latest_run_state, '
			. 'latest_run.queued_at_utc AS latest_run_queued_at_utc, latest_run.updated_at_utc AS latest_run_updated_at_utc, '
			. 'latest_result.public_id AS result_id, latest_result.overall_status, '
			. 'COALESCE((SELECT COUNT(*) FROM ' . self::TABLE_ITEMS . ' finding '
			. 'WHERE finding.worksheet_evaluation_result_id = latest_result.id), 0) AS finding_count, '
			. 'COALESCE((SELECT COUNT(*) FROM ' . self::TABLE_ITEMS . ' attention '
			. 'WHERE attention.worksheet_evaluation_result_id = latest_result.id AND attention.severity <> %s), 0) AS attention_count, '
			. 'COALESCE((SELECT COUNT(*) FROM ' . self::TABLE_ITEMS . ' safety '
			. 'WHERE safety.worksheet_evaluation_result_id = latest_result.id AND safety.dimension_key = %s), 0) AS safety_count, '
			. '(SELECT MAX(review.review_no) FROM ' . self::TABLE_REVIEWS . ' review '
			. 'WHERE review.worksheet_evaluation_id = e.id) AS latest_review_no '
			. 'FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.public_id = e.worksheet_revision_public_id '
			. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = revision.worksheet_id '
			. 'LEFT JOIN ' . self::TABLE_RUNS . ' latest_run ON latest_run.subject_type = %s '
			. 'AND latest_run.evaluation_public_id = e.public_id '
			. 'AND latest_run.run_no = (SELECT MAX(run_no) FROM ' . self::TABLE_RUNS . ' run_no '
			. 'WHERE run_no.subject_type = %s AND run_no.evaluation_public_id = e.public_id) '
			. 'LEFT JOIN ' . self::TABLE_RESULTS . ' latest_result ON latest_result.id = '
			. '(SELECT MAX(result_no.id) FROM ' . self::TABLE_RESULTS . ' result_no '
			. 'WHERE result_no.worksheet_evaluation_id = e.id)';
	}

	/**
	 * Scope hash.
	 *
	 * @param string $state State.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @param bool   $latest_only Latest only.
	 * @return string
	 */
	private function scope_hash( string $state, string $worksheet_id, string $revision_id, bool $latest_only ): string {
		$scope = $state . "\0" . $worksheet_id . "\0" . $revision_id;
		return substr( hash( 'sha256', $scope . ( $latest_only ? "\0latest" : '' ) ), 0, 16 );
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Worksheet evaluation cursor contains unknown fields.' );
		}
	}

	/**
	 * Reject invalid utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation cursor time is invalid.' );
		}
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
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
			throw $this->storage_unavailable();
		}
		return $prepared;
	}

	/**
	 * Row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function row( string $sql ): ?array {
		$row = $this->database()->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) || array_is_list( $row ) || '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			throw $this->storage_unavailable();
		}
		return $row;
	}

	/**
	 * Rows.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rows( string $sql ): array {
		$rows = $this->database()->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			throw $this->storage_unavailable();
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				throw $this->storage_unavailable();
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
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if (
			! is_object( $database )
			|| ! method_exists( $database, 'prepare' )
			|| ! method_exists( $database, 'get_row' )
			|| ! method_exists( $database, 'get_results' )
		) {
			throw $this->storage_unavailable();
		}
		return $database;
	}

	/**
	 * Storage unavailable.
	 *
	 * @return Worksheet_Evaluation_Failure
	 */
	private function storage_unavailable(): Worksheet_Evaluation_Failure {
		return Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
	}
}
