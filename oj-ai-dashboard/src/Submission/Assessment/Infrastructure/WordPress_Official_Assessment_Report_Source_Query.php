<?php
/**
 * Assessment owned official report source projection.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use JsonException;
use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Port\Official_Assessment_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves only current official pointers supplied by the Submission owner.
 */
final class WordPress_Official_Assessment_Report_Source_Query implements Official_Assessment_Source_Query_Port_Interface {
	private const TABLE_RESULTS  = 'oj_ai_submission_evaluation_results';
	private const TABLE_REVIEWS  = 'oj_ai_submission_evaluation_reviews';
	private const TABLE_RELEASES = 'oj_ai_submission_release_revisions';

	private const SCHEMA_ID      = 'oj_ai.official_assessment_source';
	private const SCHEMA_VERSION = '1.0.0';
	private const SCORE_SCHEMA   = 'oj_ai.submission_score_decisions';
	private const CHUNK_SIZE     = 500;

	/**
	 * Initialize WordPress official assessment report source query dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private ?object $database = null,
		private ?Canonical_Json $canonical = null
	) {
		$this->canonical = $canonical ?? new Canonical_Json();
	}

	/**
	 * By official reviews.
	 *
	 * @param array<int,array<string,mixed>> $progress_sources Progress sources.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function by_official_reviews( array $progress_sources ): array {
		if ( ! array_is_list( $progress_sources ) || count( $progress_sources ) > 100 ) {
			$this->stored_invalid();
		}

		$sources              = array();
		$order                = array();
		$review_expectations  = array();
		$release_expectations = array();
		foreach ( $progress_sources as $progress ) {
			$this->assert_progress_source( $progress );
			$publication    = $progress['publication'];
			$publication_id = $publication['id'];
			if ( isset( $sources[ $publication_id ] ) ) {
				$this->stored_invalid();
			}
			$sources[ $publication_id ] = array(
				'schema_id'                   => self::SCHEMA_ID,
				'schema_version'              => self::SCHEMA_VERSION,
				'publication'                 => $publication,
				'official_review_count'       => count( $progress['official_review_refs'] ),
				'scored_review_count'         => 0,
				'normalized_basis_points_sum' => 0,
				'released_count'              => 0,
				'criterion_totals'            => array(),
				'reviews'                     => array(),
				'updated_at_utc'              => null,
			);
			$order[]                    = $publication_id;

			foreach ( $progress['official_review_refs'] as $reference ) {
				$this->collect_expectation(
					$reference,
					$publication_id,
					$review_expectations,
					$release_expectations
				);
			}
		}

		$review_index = array();
		foreach ( array_chunk( array_keys( $review_expectations ), self::CHUNK_SIZE ) as $review_ids ) {
			foreach ( $this->review_rows( $review_ids ) as $row ) {
				$this->append_review( $row, $review_expectations, $sources, $review_index );
			}
		}
		if ( count( $review_index ) !== count( $review_expectations ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}

		$seen_releases = array();
		foreach ( array_chunk( array_keys( $release_expectations ), self::CHUNK_SIZE ) as $release_ids ) {
			foreach ( $this->release_rows( $release_ids ) as $row ) {
				$this->append_release( $row, $release_expectations, $sources, $review_index, $seen_releases );
			}
		}
		if ( count( $seen_releases ) !== count( $release_expectations ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}

		$result = array();
		foreach ( $order as $publication_id ) {
			$source = $sources[ $publication_id ];
			if ( count( $source['reviews'] ) !== $source['official_review_count'] ) {
				$this->stored_invalid();
			}
			ksort( $source['criterion_totals'], SORT_STRING );
			$source['criterion_totals'] = array_values( $source['criterion_totals'] );
			$source['source_hash']      = hash( 'sha256', $this->canonical->encode( $source ) );
			$result[]                   = $source;
		}
		return $result;
	}

	/**
	 * Reject invalid progress source.
	 *
	 * @param array<string,mixed> $progress Progress.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_progress_source( array $progress ): void {
		if (
			'oj_ai.assignment_progress_source' !== ( $progress['schema_id'] ?? null )
			|| '1.0.0' !== ( $progress['schema_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_SCHEMA_UNSUPPORTED );
		}
		$stored_hash = $this->hash_value( $progress['source_hash'] ?? null );
		$unsigned    = $progress;
		unset( $unsigned['source_hash'] );
		if ( ! hash_equals( $stored_hash, hash( 'sha256', $this->canonical->encode( $unsigned ) ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}
		$publication = $progress['publication'] ?? null;
		$references  = $progress['official_review_refs'] ?? null;
		if (
			! is_array( $publication ) || array_keys( $publication ) !== array( 'id', 'hash' )
			|| ! is_array( $references ) || ! array_is_list( $references )
			|| ! is_int( $progress['official_review_count'] ?? null )
			|| count( $references ) !== $progress['official_review_count']
		) {
			$this->stored_invalid();
		}
		$this->public_id( $publication['id'] ?? null );
		$this->hash_value( $publication['hash'] ?? null );
	}

	/**
	 * Collect expectation.
	 *
	 * @param array<string,mixed>               $reference Reference.
	 * @param string                            $publication_id Publication id.
	 * @param array<string,array<string,mixed>> &$reviews Reviews.
	 * @param array<string,array<string,mixed>> &$releases Releases.
	 * @return void
	 */
	private function collect_expectation(
		array $reference,
		string $publication_id,
		array &$reviews,
		array &$releases
	): void {
		if ( array_keys( $reference ) !== array( 'review', 'release' ) ) {
			$this->stored_invalid();
		}
		$review = $reference['review'] ?? null;
		if ( ! is_array( $review ) || array_keys( $review ) !== array( 'id', 'hash' ) ) {
			$this->stored_invalid();
		}
		$review_id = $this->public_id( $review['id'] ?? null );
		if ( isset( $reviews[ $review_id ] ) ) {
			$this->stored_invalid();
		}
		$reviews[ $review_id ] = array(
			'publication_id' => $publication_id,
			'hash'           => $this->hash_value( $review['hash'] ?? null ),
		);

		$release = $reference['release'] ?? null;
		if ( null === $release ) {
			return;
		}
		if ( ! is_array( $release ) || array_keys( $release ) !== array( 'id', 'hash' ) ) {
			$this->stored_invalid();
		}
		$release_id = $this->public_id( $release['id'] ?? null );
		if ( isset( $releases[ $release_id ] ) ) {
			$this->stored_invalid();
		}
		$releases[ $release_id ] = array(
			'publication_id' => $publication_id,
			'review_id'      => $review_id,
			'hash'           => $this->hash_value( $release['hash'] ?? null ),
		);
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
			. 'review.official_score, review.score_overrides_schema, review.score_overrides_version, '
			. 'review.score_overrides_json, review.score_overrides_hash, review.created_at_utc, '
			. 'result.public_id AS result_id, result.content_hash AS result_hash, result.maximum_score '
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
		$sql = 'SELECT release_row.public_id AS release_id, release_row.content_hash AS release_hash, release_row.state, '
			. 'release_row.released_at_utc, review.public_id AS review_id '
			. 'FROM ' . self::TABLE_RELEASES . ' release_row INNER JOIN ' . self::TABLE_REVIEWS . ' review '
			. 'ON review.id = release_row.submission_review_id '
			. 'WHERE release_row.public_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%s' ) ) . ') '
			. 'ORDER BY release_row.public_id ASC';
		return $this->get_results( $this->prepare( $sql, $ids ) );
	}

	/**
	 * Append review.
	 *
	 * @param array<string,mixed>                                  $row Row.
	 * @param array<string,array<string,mixed>>                    $expectations Expectations.
	 * @param array<string,array<string,mixed>>                    &$sources Sources.
	 * @param array<string,array{publication_id:string,index:int}> &$index Index.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function append_review( array $row, array $expectations, array &$sources, array &$index ): void {
		$review_id = $this->public_id( $row['review_id'] ?? null );
		if ( ! isset( $expectations[ $review_id ] ) || isset( $index[ $review_id ] ) ) {
			$this->stored_invalid();
		}
		$expected    = $expectations[ $review_id ];
		$review_hash = $this->hash_value( $row['review_hash'] ?? null );
		if ( ! hash_equals( $expected['hash'], $review_hash ) ) {
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
		$score = $this->score_decisions( $row );
		if ( $score['official_units'] !== $official_units || $score['maximum_units'] !== $maximum_units ) {
			$this->stored_invalid();
		}

		$publication_id                          = $this->public_id( $expected['publication_id'] );
		$normalized                              = $this->normalized_basis_points( $official_units, $maximum_units );
		$review                                  = array(
			'id'                      => $review_id,
			'hash'                    => $review_hash,
			'decision'                => $decision,
			'result'                  => array(
				'id'   => $this->public_id( $row['result_id'] ?? null ),
				'hash' => $this->hash_value( $row['result_hash'] ?? null ),
			),
			'score_decisions_hash'    => $score['hash'],
			'normalized_basis_points' => $normalized,
			'criteria'                => $score['criteria'],
			'release'                 => null,
			'reviewed_at_utc'         => $this->required_utc( $row['created_at_utc'] ?? null ),
		);
		$review_index                            = count( $sources[ $publication_id ]['reviews'] );
		$sources[ $publication_id ]['reviews'][] = $review;
		++$sources[ $publication_id ]['scored_review_count'];
		$sources[ $publication_id ]['normalized_basis_points_sum'] += $normalized;
		$sources[ $publication_id ]['updated_at_utc']               = $this->latest(
			$sources[ $publication_id ]['updated_at_utc'],
			$review['reviewed_at_utc']
		);
		foreach ( $score['criteria'] as $criterion ) {
			$key = $criterion['criterion_id'];
			if ( ! isset( $sources[ $publication_id ]['criterion_totals'][ $key ] ) ) {
				$sources[ $publication_id ]['criterion_totals'][ $key ] = array(
					'criterion_id'                => $key,
					'normalized_basis_points_sum' => 0,
					'n'                           => 0,
				);
			}
			$sources[ $publication_id ]['criterion_totals'][ $key ]['normalized_basis_points_sum'] += $criterion['normalized_basis_points'];
			++$sources[ $publication_id ]['criterion_totals'][ $key ]['n'];
		}
		$index[ $review_id ] = array(
			'publication_id' => $publication_id,
			'index'          => $review_index,
		);
	}

	/**
	 * Append release.
	 *
	 * @param array<string,mixed>                                  $row Row.
	 * @param array<string,array<string,mixed>>                    $expectations Expectations.
	 * @param array<string,array<string,mixed>>                    &$sources Sources.
	 * @param array<string,array{publication_id:string,index:int}> $review_index Review index.
	 * @param array<string,bool>                                   &$seen Seen.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function append_release( array $row, array $expectations, array &$sources, array $review_index, array &$seen ): void {
		$release_id = $this->public_id( $row['release_id'] ?? null );
		if ( ! isset( $expectations[ $release_id ] ) || isset( $seen[ $release_id ] ) ) {
			$this->stored_invalid();
		}
		$expected     = $expectations[ $release_id ];
		$release_hash = $this->hash_value( $row['release_hash'] ?? null );
		$review_id    = $this->public_id( $row['review_id'] ?? null );
		if ( ! hash_equals( $expected['hash'], $release_hash ) || ! hash_equals( $expected['review_id'], $review_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}
		$state = $row['state'] ?? null;
		if ( ! is_string( $state ) || ! in_array( $state, array( 'released', 'corrected', 'stale', 'withdrawn' ), true ) ) {
			$this->stored_invalid();
		}
		if ( ! isset( $review_index[ $review_id ] ) ) {
			$this->stored_invalid();
		}
		$position    = $review_index[ $review_id ];
		$released_at = $this->required_utc( $row['released_at_utc'] ?? null );
		$release     = array(
			'id'              => $release_id,
			'hash'            => $release_hash,
			'state'           => $state,
			'released_at_utc' => $released_at,
		);
		$sources[ $position['publication_id'] ]['reviews'][ $position['index'] ]['release'] = $release;
		if ( in_array( $state, array( 'released', 'corrected' ), true ) ) {
			++$sources[ $position['publication_id'] ]['released_count'];
		}
		$sources[ $position['publication_id'] ]['updated_at_utc'] = $this->latest(
			$sources[ $position['publication_id'] ]['updated_at_utc'],
			$released_at
		);
		$seen[ $release_id ]                                      = true;
	}

	/**
	 * Score decisions.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array{hash:string,official_units:int,maximum_units:int,criteria:array<int,array<string,int|string>>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function score_decisions( array $row ): array {
		if ( self::SCORE_SCHEMA !== ( $row['score_overrides_schema'] ?? null ) || 1 !== $this->positive_integer( $row['score_overrides_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_SCHEMA_UNSUPPORTED );
		}
		$json = $row['score_overrides_json'] ?? null;
		$hash = $this->hash_value( $row['score_overrides_hash'] ?? null );
		if ( ! is_string( $json ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			$this->stored_invalid();
		}
		try {
			$payload = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			$this->stored_invalid();
		}
		if (
			! is_array( $payload ) || array_is_list( $payload )
			|| self::SCORE_SCHEMA !== ( $payload['schema_id'] ?? null )
			|| '1.0.0' !== ( $payload['schema_version'] ?? null )
			|| ! is_array( $payload['items'] ?? null ) || ! array_is_list( $payload['items'] )
			|| array() === $payload['items']
		) {
			$this->stored_invalid();
		}
		$official_total = 0;
		$maximum_total  = 0;
		$criteria       = array();
		$seen           = array();
		$bindings       = array();
		foreach ( $payload['items'] as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				$this->stored_invalid();
			}
			$criterion_id = $this->public_id( $item['criterion_id'] ?? null );
			$binding      = array_key_exists( 'binding_key', $item ) ? $this->hash_value( $item['binding_key'] ) : null;
			if ( ( null !== $binding && isset( $bindings[ $binding ] ) )
				|| ( isset( $seen[ $criterion_id ] ) && ( null === $binding || ! $seen[ $criterion_id ]['bound'] ) ) ) {
				$this->stored_invalid();
			}
			if ( null !== $binding ) {
				$bindings[ $binding ] = true;
			}
			$official = $this->decimal_units( $item['official_points'] ?? null );
			$maximum  = $this->decimal_units( $item['maximum_points'] ?? null );
			if ( $maximum < 1 || $official > $maximum ) {
				$this->stored_invalid();
			}
			$official_total += $official;
			$maximum_total  += $maximum;
			if ( ! isset( $seen[ $criterion_id ] ) ) {
				$seen[ $criterion_id ] = array(
					'official' => 0,
					'maximum'  => 0,
					'bound'    => null !== $binding,
				);
			}
			$seen[ $criterion_id ]['official'] += $official;
			$seen[ $criterion_id ]['maximum']  += $maximum;
		}
		// One criterion may grade several responses; normalize their combined points.
		foreach ( $seen as $criterion_id => $points ) {
			$criteria[] = array(
				'criterion_id'            => $criterion_id,
				'normalized_basis_points' => $this->normalized_basis_points( $points['official'], $points['maximum'] ),
			);
		}
		return array(
			'hash'           => $hash,
			'official_units' => $official_total,
			'maximum_units'  => $maximum_total,
			'criteria'       => $criteria,
		);
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
	 * Latest.
	 *
	 * @param string|null $current Current.
	 * @param string      $candidate Candidate.
	 * @return string
	 */
	private function latest( ?string $current, string $candidate ): string {
		return null === $current || $candidate > $current ? $candidate : $current;
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
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 1 ) {
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
