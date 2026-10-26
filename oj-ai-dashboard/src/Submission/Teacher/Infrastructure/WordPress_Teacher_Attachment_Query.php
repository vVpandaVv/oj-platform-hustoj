<?php
/**
 * WordPress teacher attachment query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Teacher\Infrastructure;

use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Teacher\Port\Teacher_Attachment_Query_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress teacher attachment query.
 */
final class WordPress_Teacher_Attachment_Query implements Teacher_Attachment_Query_Port_Interface {
	/**
	 * Initialize WordPress teacher attachment query dependencies and state.
	 *
	 * @param object|null $db Db.
	 */
	public function __construct( private ?object $db = null ) {
	}

	/**
	 * Find WordPress teacher attachment query.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $attachment_id Attachment id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find( string $submission_id, string $attachment_id ): ?array {
		$db = $this->db ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		$sql = $db->prepare(
			'SELECT f.*, at.public_id AS attachment_id, at.response_key, at.state AS attachment_state, '
			. 'at.submission_revision_id, at.created_by_user_id AS attachment_creator, '
			. 'at.original_name AS attachment_name, at.mime_type AS attachment_mime, '
			. 'at.byte_size AS attachment_size, at.sha256 AS attachment_hash '
			. 'FROM oj_ai_submissions s '
			. 'INNER JOIN oj_ai_submission_attachments at ON at.submission_id = s.id '
			. 'INNER JOIN oj_ai_private_files f ON f.public_id = at.file_public_id '
			. 'AND f.owner_public_id = s.public_id AND f.owner_type = %s AND f.purpose = %s '
			. 'WHERE s.public_id = %s AND at.public_id = %s LIMIT 1',
			'submission',
			'submission_attachment',
			$submission_id,
			$attachment_id
		);
		if ( ! is_string( $sql ) || '' === $sql ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		$db->last_error = '';
		$row            = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( '' !== (string) $db->last_error || ( null !== $row && ! is_array( $row ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}
}
