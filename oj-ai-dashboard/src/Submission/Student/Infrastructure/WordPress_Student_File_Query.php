<?php
/**
 * WordPress student file query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Port\Student_File_Query_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Joins the file owner to the actor's assignment/submission, never by file ID alone.
 */
final class WordPress_Student_File_Query implements Student_File_Query_Port_Interface {
	/**
	 * Initialize WordPress student file query dependencies and state.
	 *
	 * @param object|null $db Db.
	 */
	public function __construct( private ?object $db = null ) {
	}

	/**
	 * Asset.
	 *
	 * @param int    $actor Actor.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $asset_id Asset id.
	 * @return array|null
	 */
	public function asset( int $actor, string $assignment_id, string $asset_id ): ?array {
		return $this->row(
			'SELECT f.*, p.public_id AS publication_id, a.publication_hash AS assignment_publication_hash '
			. 'FROM oj_ai_assignments a '
			. 'INNER JOIN oj_ai_assignment_targets t ON t.assignment_id = a.id AND t.learner_user_id = %d AND t.state = %s '
			. 'INNER JOIN oj_ai_worksheet_publications p ON p.public_id = a.worksheet_publication_public_id '
			. 'INNER JOIN oj_ai_worksheets w ON w.id = p.worksheet_id '
			. 'INNER JOIN oj_ai_private_files f ON f.owner_public_id = w.public_id AND f.owner_type = %s AND f.purpose = %s '
			. 'WHERE a.public_id = %s AND f.public_id = %s AND a.state IN (%s,%s,%s) LIMIT 1',
			array( $actor, 'active', 'worksheet', 'worksheet_asset', $assignment_id, $asset_id, 'scheduled', 'open', 'closed' )
		);
	}

	/**
	 * Attachment.
	 *
	 * @param int    $actor Actor.
	 * @param string $submission_id Submission public identifier.
	 * @param string $attachment_id Attachment id.
	 * @return array|null
	 */
	public function attachment( int $actor, string $submission_id, string $attachment_id ): ?array {
		return $this->row(
			'SELECT f.*, at.public_id AS attachment_id, at.response_key, at.state AS attachment_state, '
			. 'at.submission_revision_id, at.created_by_user_id AS attachment_creator, '
			. 'at.original_name AS attachment_name, at.mime_type AS attachment_mime, at.byte_size AS attachment_size, at.sha256 AS attachment_hash '
			. 'FROM oj_ai_submissions s '
			. 'INNER JOIN oj_ai_assignments a ON a.id = s.assignment_id '
			. 'INNER JOIN oj_ai_assignment_targets t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id AND t.state = %s '
			. 'INNER JOIN oj_ai_submission_attachments at ON at.submission_id = s.id '
			. 'INNER JOIN oj_ai_private_files f ON f.public_id = at.file_public_id AND f.owner_public_id = s.public_id AND f.owner_type = %s AND f.purpose = %s '
			. 'WHERE s.public_id = %s AND s.learner_user_id = %d AND at.public_id = %s AND a.state IN (%s,%s,%s) LIMIT 1',
			array( 'active', 'submission', 'submission_attachment', $submission_id, $actor, $attachment_id, 'scheduled', 'open', 'closed' )
		);
	}

	/**
	 * Row.
	 *
	 * @param string                $sql Sql.
	 * @param array<int,int|string> $values Values.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function row( string $sql, array $values ): ?array {
		$db = $this->db ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		$db->last_error = '';
		$sql            = $db->prepare( $sql, ...$values );
		if ( ! is_string( $sql ) || '' === $sql ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( '' !== (string) $db->last_error || ( null !== $row && ! is_array( $row ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}
}
