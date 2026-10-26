<?php
/**
 * Safe Q35 projection over literal add-on tables.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Preview_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Never returns private storage keys, private file IDs, source hashes, or internal PKs.
 */
final class WordPress_Submission_Zip_Preview_Query implements Submission_Zip_Preview_Query_Port_Interface {
	private const TABLE_UPLOADS     = 'oj_ai_zip_uploads';
	private const TABLE_ITEMS       = 'oj_ai_zip_upload_items';
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const MAPPING_SCHEMA    = 'oj_ai.submission_zip_mapping';
	private const VERSION           = 1;

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;

	/**
	 * Initialize WordPress submission zip preview query dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private ?object $database = null, ?Canonical_Json $canonical = null ) {
		$this->canonical = $canonical ?? new Canonical_Json();
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param string $upload_id Upload id.
	 * @return array<string,mixed>|null
	 */
	public function authorization_snapshot( string $upload_id ): ?array {
		new Public_Id( $upload_id );
		$row = $this->get_row(
			$this->prepare(
				'SELECT a.public_id AS assignment_id, a.context_type, a.context_id, a.context_revision FROM '
				. self::TABLE_UPLOADS . ' u INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = u.assignment_id '
				. 'WHERE u.public_id = %s LIMIT 1',
				array( $upload_id )
			)
		);
		if ( null === $row ) {
			return null;
		}
		return array(
			'assignment_id'    => $this->public_id( $row['assignment_id'] ?? null ),
			'context_id'       => $this->token( $row['context_id'] ?? null, 191 ),
			'context_revision' => $this->token( $row['context_revision'] ?? null, 191 ),
			'context_type'     => $this->token( $row['context_type'] ?? null, 16 ),
		);
	}

	/**
	 * Find preview.
	 *
	 * @param string $upload_id Upload id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array<string,mixed>|null
	 */
	public function find_preview( string $upload_id, string $context_type, string $context_id, string $context_revision ): ?array {
		new Public_Id( $upload_id );
		$row = $this->get_row(
			$this->prepare(
				'SELECT u.id AS upload_pk, u.public_id AS upload_id, u.created_at_utc, u.updated_at_utc, u.state, '
				. 'u.mapping_schema, u.mapping_version, u.mapping_json, u.mapping_hash, u.item_total, u.success_total, '
				. 'u.failure_total, a.public_id AS assignment_id FROM ' . self::TABLE_UPLOADS . ' u '
				. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = u.assignment_id '
				. 'WHERE u.public_id = %s AND a.context_type = %s AND a.context_id = %s AND a.context_revision = %s LIMIT 1',
				array( $upload_id, $context_type, $context_id, $context_revision )
			)
		);
		if ( null === $row ) {
			return null;
		}
		if ( self::MAPPING_SCHEMA !== ( $row['mapping_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $row['mapping_version'] ?? null ) ) {
			$this->stored_invalid();
		}

		$state = (string) ( $row['state'] ?? '' );
		if ( ! in_array( $state, array( 'scanning', 'ready', 'rejected', 'mapped', 'confirming', 'completed', 'canceled' ), true ) ) {
			$this->stored_invalid();
		}
		$mapping = $this->decode_json( (string) ( $row['mapping_json'] ?? '' ), $this->hash_value( $row['mapping_hash'] ?? null ) );
		$keys    = array_keys( $mapping );
		sort( $keys, SORT_STRING );
		if ( $keys !== array( 'items', 'mapping_revision', 'source' ) && $keys !== array( 'items', 'mapping_revision', 'scan_error_code', 'source' ) ) {
			$this->stored_invalid();
		}
		$mapping_revision = $this->non_negative_integer( $mapping['mapping_revision'] ?? null );
		$scan_error       = $mapping['scan_error_code'] ?? null;
		if ( null !== $scan_error ) {
			$this->reason( $scan_error );
		}
		$mapping_items = $mapping['items'] ?? null;
		if ( ! is_array( $mapping_items ) || ! array_is_list( $mapping_items ) ) {
			$this->stored_invalid();
		}
		$by_item = array();
		foreach ( $mapping_items as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				$this->stored_invalid();
			}
			$keys = array_keys( $item );
			sort( $keys, SORT_STRING );
			if (
				$keys !== array( 'compatible_response_ids', 'detected_mime_type', 'item_no', 'suggestion' )
				&& $keys !== array( 'compatible_response_ids', 'detected_mime_type', 'item_no', 'selection', 'suggestion' )
			) {
				$this->stored_invalid();
			}
			$item_no = $this->positive_integer( $item['item_no'] ?? null );
			if ( isset( $by_item[ $item_no ] ) || ! is_array( $item['compatible_response_ids'] ?? null ) || ! array_is_list( $item['compatible_response_ids'] ) ) {
				$this->stored_invalid();
			}
			foreach ( $item['compatible_response_ids'] as $response_id ) {
				$this->public_id( $response_id );
			}
			if ( null !== ( $item['detected_mime_type'] ?? null ) ) {
				$this->mime( $item['detected_mime_type'] );
			}
			if ( array_key_exists( 'selection', $item ) ) {
				$this->safe_selection( $item['selection'] );
			}
			$by_item[ $item_no ] = $item;
		}

		$item_total    = $this->non_negative_integer( $row['item_total'] ?? null );
		$success_total = $this->non_negative_integer( $row['success_total'] ?? null );
		$failure_total = $this->non_negative_integer( $row['failure_total'] ?? null );
		$stored_items  = $this->items( $this->positive_integer( $row['upload_pk'] ?? null ), $by_item );
		if ( count( $stored_items ) !== $item_total || $success_total + $failure_total !== $item_total ) {
			$this->stored_invalid();
		}

		return array(
			'assignment_id'   => $this->public_id( $row['assignment_id'] ?? null ),
			'created_at_utc'  => $this->required_utc( $row['created_at_utc'] ?? null ),
			'id'              => $this->public_id( $row['upload_id'] ?? null ),
			'items'           => $stored_items,
			'mapping_version' => $mapping_revision,
			'scan'            => array(
				'error_code'    => $scan_error,
				'failure_total' => $failure_total,
				'item_total'    => $item_total,
				'state'         => $state,
				'success_total' => $success_total,
			),
			'updated_at_utc'  => $this->required_utc( $row['updated_at_utc'] ?? null ),
		);
	}

	/**
	 * Items.
	 *
	 * @param int                            $upload_pk Upload pk.
	 * @param array<int,array<string,mixed>> $mapping Mapping.
	 * @return array<int,array<string,mixed>>
	 */
	private function items( int $upload_pk, array $mapping ): array {
		$rows   = $this->get_results(
			$this->prepare(
				'SELECT item_no, relative_path, state, byte_size, error_code FROM ' . self::TABLE_ITEMS
				. ' WHERE zip_upload_id = %d ORDER BY item_no ASC',
				array( $upload_pk )
			)
		);
		$result = array();
		foreach ( $rows as $index => $row ) {
			$item_no = $this->positive_integer( $row['item_no'] ?? null );
			if ( $item_no !== $index + 1 || ! isset( $mapping[ $item_no ] ) ) {
				$this->stored_invalid();
			}
			$state = (string) ( $row['state'] ?? '' );
			if ( ! in_array( $state, array( 'ready', 'rejected', 'mapped', 'imported', 'failed', 'excluded' ), true ) ) {
				$this->stored_invalid();
			}
			$error = $row['error_code'] ?? null;
			if ( null !== $error ) {
				$this->reason( $error );
			}
			$result[] = array(
				'byte_size'               => $this->non_negative_integer( $row['byte_size'] ?? null ),
				'compatible_response_ids' => $mapping[ $item_no ]['compatible_response_ids'],
				'detected_mime_type'      => $mapping[ $item_no ]['detected_mime_type'],
				'error_code'              => $error,
				'item_no'                 => $item_no,
				'relative_path'           => $this->path( $row['relative_path'] ?? null ),
				'state'                   => $state,
				'selection'               => array_key_exists( 'selection', $mapping[ $item_no ] ) ? $this->safe_selection( $mapping[ $item_no ]['selection'] ) : null,
				'suggestion'              => $this->safe_suggestion( $mapping[ $item_no ]['suggestion'] ?? null ),
			);
		}
		return $result;
	}

	/**
	 * Safe suggestion.
	 *
	 * @param mixed $value Value.
	 * @return array{learner:?array<string,mixed>,response:?array<string,mixed>}
	 */
	private function safe_suggestion( mixed $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $value, array( 'learner', 'response' ) );
		$learner = $value['learner'];
		if ( null !== $learner ) {
			if ( ! is_array( $learner ) || array_is_list( $learner ) ) {
				$this->stored_invalid();
			}
			$this->exact_keys( $learner, array( 'learner_user_id', 'method', 'target_id' ) );
			$learner = array(
				'learner_user_id' => $this->positive_integer( $learner['learner_user_id'] ?? null ),
				'method'          => $this->method( $learner['method'] ?? null ),
				'target_id'       => $this->public_id( $learner['target_id'] ?? null ),
			);
		}
		$response = $value['response'];
		if ( null !== $response ) {
			if ( ! is_array( $response ) || array_is_list( $response ) ) {
				$this->stored_invalid();
			}
			$this->exact_keys( $response, array( 'method', 'response_id' ) );
			$response = array(
				'method'      => $this->method( $response['method'] ?? null ),
				'response_id' => $this->public_id( $response['response_id'] ?? null ),
			);
		}
		return array(
			'learner'  => $learner,
			'response' => $response,
		);
	}

	/**
	 * Safe selection.
	 *
	 * @param mixed $value Value.
	 * @return array{learner_user_id:int,response_id:string,target_id:string}|null
	 */
	private function safe_selection( mixed $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $value, array( 'learner_user_id', 'response_id', 'target_id' ) );
		return array(
			'learner_user_id' => $this->positive_integer( $value['learner_user_id'] ?? null ),
			'response_id'     => $this->public_id( $value['response_id'] ?? null ),
			'target_id'       => $this->public_id( $value['target_id'] ?? null ),
		);
	}

	/**
	 * Method.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function method( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'exact_path_token', 'single_compatible' ), true ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Decode json.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 */
	private function decode_json( string $json, string $hash ): array {
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			$this->stored_invalid();
		}
		try {
			$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			$this->stored_invalid();
		}
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) || $this->canonical->encode( $decoded ) !== $json ) {
			$this->stored_invalid();
		}
		return $decoded;
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
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
		if ( ! is_string( $value ) ) {
			$this->stored_invalid();
		}
		try {
			return ( new Public_Id( $value ) )->value();
		} catch ( Throwable ) {
			$this->stored_invalid();
		}
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
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 */
	private function token( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Mime.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function mime( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Path.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function path( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 512 || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x1F\x7F\\\\]/', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function reason( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:zip_[a-z0-9_]{2,95}|submission_official_exists)$/D', $value ) ) {
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
		throw Zip_Import_Failure::because( Zip_Import_Failure::STORED_DATA_INVALID );
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
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}

	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_row( string $sql ): ?array {
		$db = $this->database();
		$this->reset_error( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_database_ok( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
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
		$this->reset_error( $db );
		$rows = $db->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_database_ok( $db );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
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
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_results' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
	}

	/**
	 * Reset error.
	 *
	 * @param object $db Db.
	 * @return void
	 */
	private function reset_error( object $db ): void {
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
	}
	/**
	 * Reject invalid database ok.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_database_ok( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
