<?php
/**
 * Worksheet owned report source projection.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use JsonException;
use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Port\Worksheet_Report_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents Reporting from joining Worksheet owner tables directly.
 */
final class WordPress_Worksheet_Report_Source_Query implements Worksheet_Report_Source_Query_Port_Interface {
	private const TABLE_WORKSHEETS   = 'oj_ai_worksheets';
	private const TABLE_REVISIONS    = 'oj_ai_worksheet_revisions';
	private const TABLE_PUBLICATIONS = 'oj_ai_worksheet_publications';

	private const SCHEMA_ID      = 'oj_ai.worksheet_report_source';
	private const SCHEMA_VERSION = '1.0.0';

	/**
	 * Initialize WordPress worksheet report source query dependencies and state.
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
	 * By publications.
	 *
	 * @param array<array-key,mixed> $publications Publications.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function by_publications( array $publications ): array {
		if ( array() === $publications ) {
			return array();
		}
		if ( ! array_is_list( $publications ) || count( $publications ) > 100 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::STORED_DATA_INVALID );
		}

		$expected = array();
		$order    = array();
		foreach ( $publications as $reference ) {
			if ( ! is_array( $reference ) || array_keys( $reference ) !== array( 'id', 'hash' ) ) {
				$this->stored_invalid();
			}
			$id   = $this->public_id( $reference['id'] ?? null );
			$hash = $this->hash_value( $reference['hash'] ?? null );
			if ( isset( $expected[ $id ] ) ) {
				if ( ! hash_equals( $expected[ $id ], $hash ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
				}
				continue;
			}
			$expected[ $id ] = $hash;
			$order[]         = $id;
		}

		$sql   = 'SELECT w.public_id AS worksheet_id, w.title, w.state AS worksheet_state, w.updated_at_utc AS worksheet_updated_at_utc, '
			. 'r.public_id AS revision_id, r.document_hash AS revision_hash, '
			. 'p.public_id AS publication_id, p.publication_hash, p.publication_no, p.state AS publication_state, '
			. 'p.published_at_utc, p.content_hash AS publication_content_hash, '
			. 'p.grading_schema_json, p.grading_schema_hash '
			. 'FROM ' . self::TABLE_PUBLICATIONS . ' p '
			. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = p.worksheet_id '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' r ON r.id = p.worksheet_revision_id '
			. 'WHERE p.public_id IN (' . implode( ', ', array_fill( 0, count( $order ), '%s' ) ) . ')';
		$rows  = $this->get_results( $this->prepare( $sql, $order ) );
		$found = array();
		foreach ( $rows as $row ) {
			$source = $this->source( $row, $expected );
			$id     = $source['publication']['id'];
			if ( isset( $found[ $id ] ) ) {
				$this->stored_invalid();
			}
			$found[ $id ] = $source;
		}
		if ( count( $found ) !== count( $expected ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}

		$result = array();
		foreach ( $order as $id ) {
			$result[] = $found[ $id ];
		}
		return $result;
	}

	/**
	 * Source.
	 *
	 * @param array<string,mixed>  $row Row.
	 * @param array<string,string> $expected Expected.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source( array $row, array $expected ): array {
		$publication_id   = $this->public_id( $row['publication_id'] ?? null );
		$publication_hash = $this->hash_value( $row['publication_hash'] ?? null );
		if ( ! isset( $expected[ $publication_id ] ) || ! hash_equals( $expected[ $publication_id ], $publication_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
		}
		$title             = $row['title'] ?? null;
		$worksheet_state   = $row['worksheet_state'] ?? null;
		$publication_state = $row['publication_state'] ?? null;
		if (
			! is_string( $title ) || '' === trim( $title )
			|| ! is_string( $worksheet_state ) || ! in_array( $worksheet_state, array( 'draft', 'active', 'archived' ), true )
			|| ! is_string( $publication_state ) || ! in_array( $publication_state, array( 'active', 'retired' ), true )
		) {
			$this->stored_invalid();
		}

		$grading               = $this->grading_profile( $row );
		$worksheet_updated     = $this->required_utc( $row['worksheet_updated_at_utc'] ?? null );
		$published_at          = $this->required_utc( $row['published_at_utc'] ?? null );
		$source                = array(
			'schema_id'      => self::SCHEMA_ID,
			'schema_version' => self::SCHEMA_VERSION,
			'worksheet'      => array(
				'id'            => $this->public_id( $row['worksheet_id'] ?? null ),
				'title'         => $this->canonical->text( $title ),
				'state'         => $worksheet_state,
				'subject_label' => null,
			),
			'revision'       => array(
				'id'   => $this->public_id( $row['revision_id'] ?? null ),
				'hash' => $this->hash_value( $row['revision_hash'] ?? null ),
			),
			'publication'    => array(
				'id'               => $publication_id,
				'hash'             => $publication_hash,
				'content_hash'     => $this->hash_value( $row['publication_content_hash'] ?? null ),
				'number'           => $this->positive_integer( $row['publication_no'] ?? null ),
				'state'            => $publication_state,
				'published_at_utc' => $published_at,
			),
			'rubric'         => $grading,
			'updated_at_utc' => $worksheet_updated > $published_at ? $worksheet_updated : $published_at,
		);
		$source['source_hash'] = hash( 'sha256', $this->canonical->encode( $source ) );
		return $source;
	}

	/**
	 * Grading profile.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function grading_profile( array $row ): array {
		$json        = $row['grading_schema_json'] ?? null;
		$stored_hash = $this->hash_value( $row['grading_schema_hash'] ?? null );
		if ( ! is_string( $json ) || ! hash_equals( $stored_hash, hash( 'sha256', $json ) ) ) {
			$this->stored_invalid();
		}
		try {
			$profile = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			$this->stored_invalid();
		}
		if ( ! is_array( $profile ) || array_is_list( $profile ) ) {
			$this->stored_invalid();
		}
		if ( 'oj_ai.grading_schema' !== ( $profile['schema_id'] ?? null ) || '1.1.0' !== ( $profile['schema_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_SCHEMA_UNSUPPORTED );
		}
		$payload = $profile['payload'] ?? null;
		if (
			! is_array( $payload ) || array_is_list( $payload )
			|| ! is_string( $profile['payload_hash'] ?? null )
			|| ! hash_equals( (string) $profile['payload_hash'], hash( 'sha256', $this->canonical->encode( $payload ) ) )
		) {
			$this->stored_invalid();
		}
		$rubric   = $payload['rubric'] ?? null;
		$bindings = $payload['bindings'] ?? null;
		if ( ! is_array( $rubric ) || array_is_list( $rubric ) || ! is_array( $bindings ) || ! array_is_list( $bindings ) ) {
			$this->stored_invalid();
		}
		$total = $rubric['total_points'] ?? null;
		if ( ! is_string( $total ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,4})?$/D', $total ) ) {
			$this->stored_invalid();
		}
		return array(
			'revision_id'     => $this->public_id( $rubric['revision_id'] ?? null ),
			'content_hash'    => $this->hash_value( $rubric['content_hash'] ?? null ),
			'total_points'    => $total,
			'criterion_count' => count( $bindings ),
		);
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
