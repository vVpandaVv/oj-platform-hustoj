<?php
/**
 * WordPress submission bundle attachment reader.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Submission_Bundle_Failure;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Attachment_Read_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves only the exact clean private file frozen in a bundle request.
 */
final class WordPress_Submission_Bundle_Attachment_Reader implements Submission_Bundle_Attachment_Read_Port_Interface {
	private const TABLE_ATTACHMENTS = 'oj_ai_submission_attachments';
	private const TABLE_FILES       = 'oj_ai_private_files';

	/**
	 * Initialize WordPress submission bundle attachment reader dependencies and state.
	 *
	 * @param Private_File_Read_Port_Interface $files Files.
	 * @param object|null                      $database Database.
	 */
	public function __construct( private Private_File_Read_Port_Interface $files, private ?object $database = null ) {
	}

	/**
	 * Readable path.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param array  $attachment Attachment.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function readable_path( string $submission_id, array $attachment ): string {
		new Public_Id( $submission_id );
		$this->assert_attachment( $attachment );
		$row = $this->row(
			$this->prepare(
				'SELECT a.public_id AS attachment_id, a.file_public_id, a.original_name, a.mime_type, a.byte_size, a.sha256, a.state AS attachment_state, '
				. 'f.owner_type, f.owner_public_id, f.purpose, f.storage_key, f.original_name AS file_original_name, f.mime_type AS file_mime_type, '
				. 'f.byte_size AS file_byte_size, f.sha256 AS file_sha256, f.state AS file_state, f.scan_state '
				. 'FROM ' . self::TABLE_ATTACHMENTS . ' a INNER JOIN ' . self::TABLE_FILES . ' f ON f.public_id = a.file_public_id '
				. 'WHERE a.public_id = %s AND a.file_public_id = %s LIMIT 1',
				array( $attachment['id'], $attachment['file_id'] )
			)
		);
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_STALE );
		}
		if (
			! hash_equals( (string) $attachment['id'], (string) ( $row['attachment_id'] ?? '' ) )
			|| ! hash_equals( (string) $attachment['file_id'], (string) ( $row['file_public_id'] ?? '' ) )
			|| ! hash_equals( (string) $attachment['original_name'], (string) ( $row['original_name'] ?? '' ) )
			|| ! hash_equals( (string) $attachment['mime_type'], (string) ( $row['mime_type'] ?? '' ) )
			|| (int) $attachment['byte_size'] !== $this->positive_int( $row['byte_size'] ?? null )
			|| ! hash_equals( (string) $attachment['sha256'], (string) ( $row['sha256'] ?? '' ) )
			|| 'bound' !== ( $row['attachment_state'] ?? null ) || 'submission' !== ( $row['owner_type'] ?? null )
			|| ! hash_equals( $submission_id, (string) ( $row['owner_public_id'] ?? '' ) ) || 'submission_attachment' !== ( $row['purpose'] ?? null )
			|| ! hash_equals( (string) $attachment['original_name'], (string) ( $row['file_original_name'] ?? '' ) )
			|| ! hash_equals( (string) $attachment['mime_type'], (string) ( $row['file_mime_type'] ?? '' ) )
			|| (int) $attachment['byte_size'] !== $this->positive_int( $row['file_byte_size'] ?? null )
			|| ! hash_equals( (string) $attachment['sha256'], (string) ( $row['file_sha256'] ?? '' ) )
			|| 'ready' !== ( $row['file_state'] ?? null ) || 'clean' !== ( $row['scan_state'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_STALE );
		}
		try {
			return $this->files->readable_path( (string) $row['storage_key'], (string) $attachment['sha256'] ); } catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
			}
	}

	/**
	 * Reject invalid attachment.
	 *
	 * @param array $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_attachment( array $value ): void {
		$expected = array( 'archive_path', 'byte_size', 'file_id', 'id', 'mime_type', 'original_name', 'response_key', 'sha256' );
		$actual   = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Submission bundle attachment is invalid.' );
		}
		new Public_Id( (string) $value['id'] );
		new Public_Id( (string) $value['file_id'] );
		if ( ! is_int( $value['byte_size'] ) || $value['byte_size'] < 1 || 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $value['sha256'] ) ) {
			throw new InvalidArgumentException( 'Submission bundle attachment evidence is invalid.' );
		}
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param array  $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->db()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		} return $prepared;
	}
	/**
	 * Row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function row( string $sql ): ?array {
		$db = $this->db();
		$this->reset( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_ok( $db );
		if ( null === $row ) {
			return null;
		} if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		} return $row;
	}
	/**
	 * Db.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function db(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) || ! method_exists( $db, 'prepare' ) || ! method_exists( $db, 'get_row' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		} return $db;
	}
	/**
	 * Reset.
	 *
	 * @param object $db Db.
	 * @return void
	 */
	private function reset( object $db ): void {
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
	}
	/**
	 * Reject invalid ok.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_ok( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		}
	}
	/**
	 * Positive int.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_int( mixed $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		} if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		} throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORED_DATA_INVALID );
	}
}
