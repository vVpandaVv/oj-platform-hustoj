<?php
/**
 * Assessment owned flat review/release score lookup for cross-worksheet
 * student report views (no per-publication or per-criterion grouping).
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Port\Official_Assessment_Score_Lookup_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves only current official pointers supplied by the Submission owner.
 */
final class WordPress_Official_Assessment_Score_Lookup_Query implements Official_Assessment_Score_Lookup_Port_Interface {
	private const TABLE_RESULTS  = 'oj_ai_submission_evaluation_results';
	private const TABLE_REVIEWS  = 'oj_ai_submission_evaluation_reviews';
	private const TABLE_RELEASES = 'oj_ai_submission_release_revisions';
	private const CHUNK_SIZE     = 500;

	/**
	 * Initialize WordPress official assessment score lookup query dependencies and state.
	 *
	 * @param object|null $database Database.
	 */
	public function __construct( private ?object $database = null ) {
	}

	/**
	 * By review refs.
	 *
	 * @param array<array-key,mixed> $review_refs Review refs.
	 * @return array<int,array{review_id:string,normalized_basis_points:int,release_state:?string}>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function by_review_refs( array $review_refs ): array {
		if ( ! array_is_list( $review_refs ) || count( $review_refs ) > 5000 ) {
			$this->stored_invalid();
		}

		$expectations         = array();
		$release_expectations = array();
		foreach ( $review_refs as $ref ) {
			if ( ! is_array( $ref ) ) {
				$this->stored_invalid();
			}
			$this->exact_keys( $ref, array( 'release', 'review' ) );
			$review = $ref['review'] ?? null;
			if ( ! is_array( $review ) || array_keys( $review ) !== array( 'id', 'hash' ) ) {
				$this->stored_invalid();
			}
			$review_id = $this->public_id( $review['id'] ?? null );
			if ( isset( $expectations[ $review_id ] ) ) {
				// The same review is referenced by more than one assignment/target: harmless, keep the first hash pin.
				continue;
			}
			$expectations[ $review_id ] = $this->hash_value( $review['hash'] ?? null );

			$release = $ref['release'] ?? null;
			if ( null === $release ) {
				continue;
			}
			if ( ! is_array( $release ) || array_keys( $release ) !== array( 'id', 'hash' ) ) {
				$this->stored_invalid();
			}
			$release_id                          = $this->public_id( $release['id'] ?? null );
			$release_expectations[ $release_id ] = array(
				'review_id' => $review_id,
				'hash'      => $this->hash_value( $release['hash'] ?? null ),
			);
		}
		if ( array() === $expectations ) {
			return array();
		}

		$reviews = array();
		foreach ( array_chunk( array_keys( $expectations ), self::CHUNK_SIZE ) as $ids ) {
			foreach ( $this->review_rows( $ids ) as $row ) {
				$reviews[ $this->public_id( $row['review_id'] ?? null ) ] = $row;
			}
		}
		if ( count( $reviews ) !== count( $expectations ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}

		$releases = array();
		foreach ( array_chunk( array_keys( $release_expectations ), self::CHUNK_SIZE ) as $ids ) {
			foreach ( $this->release_rows( $ids ) as $row ) {
				$releases[ $this->public_id( $row['release_id'] ?? null ) ] = $row;
			}
		}
		if ( count( $releases ) !== count( $release_expectations ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}

		$result = array();
		foreach ( $expectations as $review_id => $expected_hash ) {
			$row = $reviews[ $review_id ];
			if ( ! hash_equals( $expected_hash, $this->hash_value( $row['review_hash'] ?? null ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
			}
			$decision = $row['decision'] ?? null;
			if ( ! is_string( $decision ) || ! in_array( $decision, array( 'accepted', 'adjusted' ), true ) ) {
				$this->stored_invalid();
			}
			$official_units = $this->decimal_units( $row['official_score'] ?? null );
			$maximum_units  = $this->decimal_units( $row['maximum_score'] ?? null );
			if ( $maximum_units < 1 || $official_units > $maximum_units ) {
				$this->stored_invalid();
			}

			$release_state = null;
			foreach ( $release_expectations as $release_id => $expected_release ) {
				if ( $expected_release['review_id'] !== $review_id ) {
					continue;
				}
				$release_row = $releases[ $release_id ];
				if ( ! hash_equals( $expected_release['hash'], $this->hash_value( $release_row['release_hash'] ?? null ) ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
				}
				$state = $release_row['state'] ?? null;
				if ( ! is_string( $state ) || ! in_array( $state, array( 'released', 'corrected', 'stale', 'withdrawn' ), true ) ) {
					$this->stored_invalid();
				}
				$release_state = $state;
			}

			$result[] = array(
				'review_id'               => $review_id,
				'normalized_basis_points' => $this->normalized_basis_points( $official_units, $maximum_units ),
				'release_state'           => $release_state,
			);
		}
		return $result;
	}

	/**
	 * Review rows.
	 *
	 * @param array<int,string> $ids Ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function review_rows( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}
		$sql = 'SELECT review.public_id AS review_id, review.content_hash AS review_hash, review.decision, '
			. 'review.official_score, result.maximum_score '
			. 'FROM ' . self::TABLE_REVIEWS . ' review INNER JOIN ' . self::TABLE_RESULTS . ' result '
			. 'ON result.id = review.submission_evaluation_result_id '
			. 'WHERE review.public_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%s' ) ) . ') '
			. 'ORDER BY review.public_id ASC';
		return $this->get_results( $this->prepare( $sql, $ids ) );
	}

	/**
	 * Release rows.
	 *
	 * @param array<int,string> $ids Ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function release_rows( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}
		$sql = 'SELECT release_row.public_id AS release_id, release_row.content_hash AS release_hash, release_row.state '
			. 'FROM ' . self::TABLE_RELEASES . ' release_row '
			. 'WHERE release_row.public_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%s' ) ) . ') '
			. 'ORDER BY release_row.public_id ASC';
		return $this->get_results( $this->prepare( $sql, $ids ) );
	}

	/**
	 * Decimal units.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function decimal_units( mixed $value ): int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $matches ) ) {
			$this->stored_invalid();
		}
		return ( (int) $matches[1] * 10000 ) + (int) str_pad( $matches[2] ?? '', 4, '0' );
	}

	/**
	 * Normalized basis points.
	 *
	 * @param int $value Value.
	 * @param int $maximum Maximum.
	 * @return int
	 */
	private function normalized_basis_points( int $value, int $maximum ): int {
		return intdiv( ( $value * 10000 ) + intdiv( $maximum, 2 ), $maximum );
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
