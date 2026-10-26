<?php
/**
 * Read student file content.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Student\Application;

use Closure;
use OJAI\Dashboard\Storage\Domain\Worksheet_Asset_Upload_Profile;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Upload_Profile;
use OJAI\Dashboard\Submission\Student\Port\Student_File_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses current student projections, never teacher documents or a shared asset manifest.
 */
final class Read_Student_File_Content {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Initialize student file content dependencies and state.
	 *
	 * @param Read_Student_Assignments          $assignments Assignments.
	 * @param Read_Own_Submission               $submissions Submissions.
	 * @param Student_File_Query_Port_Interface $metadata Metadata.
	 * @param Private_File_Read_Port_Interface  $files Files.
	 * @param callable|null                     $clock Clock.
	 */
	public function __construct(
		private Read_Student_Assignments $assignments,
		private Read_Own_Submission $submissions,
		private Student_File_Query_Port_Interface $metadata,
		private Private_File_Read_Port_Interface $files,
		?callable $clock = null
	) { $this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Open.
	 *
	 * @param int    $actor Actor.
	 * @param string $kind Kind.
	 * @param string $source_id Source id.
	 * @param string $file_id File id.
	 * @return Student_File_Content
	 */
	public function open( int $actor, string $kind, string $source_id, string $file_id ): Student_File_Content {
		$metadata = $this->authorize( $actor, $kind, $source_id, $file_id );
		$path     = $this->files->readable_path( $metadata['storage_key'], $metadata['sha256'] );
		return new Student_File_Content( $path, $metadata );
	}

	/**
	 * Authorize.
	 *
	 * @param int    $actor Actor.
	 * @param string $kind Kind.
	 * @param string $source_id Source id.
	 * @param string $file_id File id.
	 * @return array<string,mixed> Internal comparison only; never a REST payload.
	 */
	public function authorize( int $actor, string $kind, string $source_id, string $file_id ): array {
		new Public_Id( $source_id );
		new Public_Id( $file_id );
		if ( $actor < 1 ) {
			$this->deny();
		}
		if ( 'asset' === $kind ) {
			$assignment = $this->assignments->detail( $actor, $source_id )['assignment'];
			if ( ! $this->has_asset( $assignment['student_document']['payload']['nodes'] ?? array(), $file_id ) ) {
				$this->deny();
			}
			$row = $this->metadata->asset( $actor, $source_id, $file_id );
			$this->require_fields( $row, array( 'publication_id', 'assignment_publication_hash' ) );
			if ( null === $row || $file_id !== $row['public_id'] || $assignment['worksheet_publication_id'] !== $row['publication_id']
				|| $assignment['publication_hash'] !== $row['assignment_publication_hash'] ) {
				$this->deny();
			}
			$limit = Worksheet_Asset_Upload_Profile::MAX_FILE_BYTES;
		} elseif ( 'attachment' === $kind ) {
			$submission = $this->submissions->read( $actor, $source_id )['submission'];
			$assignment = $this->assignments->detail( $actor, $submission['assignment_id'] )['assignment'];
			if ( ! is_array( $assignment['student_document'] ?? null ) ) {
				$this->deny();
			}
			$row = $this->metadata->attachment( $actor, $source_id, $file_id );
			$this->require_fields( $row, array( 'attachment_id', 'response_key', 'attachment_state', 'submission_revision_id', 'attachment_creator', 'attachment_name', 'attachment_mime', 'attachment_size', 'attachment_hash' ) );
			$listed = null;
			foreach ( $submission['attachments'] as $attachment ) {
				if ( $file_id === $attachment['id'] ) {
					$listed = $attachment;
					break;
				}
			}
			if ( null === $listed || ! in_array( $listed['state'], array( 'ready', 'bound' ), true )
				|| $listed['state'] !== $row['attachment_state'] || $listed['response_id'] !== $row['response_key']
				|| $row['attachment_name'] !== $row['original_name'] || $row['attachment_mime'] !== $row['mime_type']
				|| (int) $row['attachment_size'] !== (int) $row['byte_size'] || $row['attachment_hash'] !== $row['sha256']
				|| (int) $row['attachment_creator'] !== (int) $row['created_by_user_id'] ) {
				$this->deny();
			}
			$scopes        = $listed['revision_scope'];
			$response_kind = $this->response_kind( $assignment, $listed['response_id'] );
			if ( ! in_array( $response_kind, array( 'file_upload', 'image_upload', 'drawing' ), true ) ) {
				$this->deny();
			}
			if ( in_array( 'staged', $scopes, true ) ) {
				if ( null !== $row['submission_revision_id'] || 'ready' !== $row['attachment_state'] || $actor !== (int) $row['attachment_creator'] ) {
					$this->deny();
				}
			} elseif ( ! $this->answer_references( $submission, $assignment, $listed['response_id'], $file_id, $scopes ) ) {
				$this->deny();
			}
			$limit = Submission_Attachment_Upload_Profile::MAX_FILE_BYTES;
		} else {
			$this->deny();
		}
		$now    = ( $this->clock )();
		$expiry = $row['expires_at_utc'];
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $now )
			|| 'ready' !== $row['state'] || 'clean' !== $row['scan_state'] || null !== $row['purged_at_utc']
			|| ( null !== $expiry && ( ! is_string( $expiry ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $expiry ) || $expiry <= $now ) )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $row['sha256'] )
			|| (int) $row['byte_size'] < 1 || (int) $row['byte_size'] > $limit
			|| '' === $row['original_name'] || strlen( $row['original_name'] ) > 255
			|| 1 !== preg_match( '//u', $row['original_name'] ) || preg_match( '/[\x00-\x1f\x7f\/\\\\]/', $row['original_name'] )
			|| preg_match( '/[\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', $row['original_name'] ) ) {
			$this->deny();
		}
		$row['byte_size'] = (int) $row['byte_size'];
		return $row;
	}

	/**
	 * Require fields.
	 *
	 * @param array<string,mixed>|null $row Row.
	 * @param array<int,string>        $extra Extra.
	 * @return void
	 */
	private function require_fields( ?array $row, array $extra ): void {
		$required = array_merge( $extra, array( 'public_id', 'created_by_user_id', 'storage_key', 'original_name', 'mime_type', 'byte_size', 'sha256', 'state', 'scan_state', 'expires_at_utc', 'purged_at_utc' ) );
		if ( null === $row || array() !== array_diff( $required, array_keys( $row ) ) ) {
			$this->deny();
		}
		foreach ( array( 'public_id', 'storage_key', 'original_name', 'mime_type', 'sha256', 'state', 'scan_state' ) as $key ) {
			if ( ! is_string( $row[ $key ] ) ) {
				$this->deny();
			}
		}
	}

	/**
	 * Has asset.
	 *
	 * @param array  $nodes Nodes.
	 * @param string $id Id.
	 * @param int    $depth Depth.
	 * @return bool
	 */
	private function has_asset( array $nodes, string $id, int $depth = 0 ): bool {
		if ( $depth > 32 ) {
			return false;
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = $node['type'] ?? '';
			if ( in_array( $type, array( 'img', 'audio', 'video', 'file', 'oj_ai_drawing' ), true )
				&& ( $id === ( $node['asset_id'] ?? null ) || ( 'video' === $type && $id === ( $node['poster_asset_id'] ?? null ) ) ) ) {
				return true;
			}
			if ( isset( $node['children'] ) && is_array( $node['children'] ) && $this->has_asset( $node['children'], $id, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Response kind.
	 *
	 * @param array  $assignment Assignment.
	 * @param string $response_id Response id.
	 * @return string|null
	 */
	private function response_kind( array $assignment, string $response_id ): ?string {
		foreach ( $assignment['response_schema']['payload']['items'] ?? array() as $item ) {
			if ( $response_id === $item['response_id'] ) {
				return $item['kind'];
			}
		}
		return null;
	}

	/**
	 * Answer references.
	 *
	 * @param array  $submission Submission.
	 * @param array  $assignment Assignment.
	 * @param string $response_id Response id.
	 * @param string $id Id.
	 * @param array  $scopes Scopes.
	 * @return bool
	 */
	private function answer_references( array $submission, array $assignment, string $response_id, string $id, array $scopes ): bool {
		$kind = $this->response_kind( $assignment, $response_id );
		foreach ( array( 'current', 'official' ) as $scope ) {
			if ( ! in_array( $scope, $scopes, true ) ) {
				continue;
			}
			$value = $submission[ $scope . '_revision' ]['response']['answers'][ $response_id ] ?? null;
			if ( ! is_array( $value ) ) {
				continue;
			}
			if ( in_array( $kind, array( 'file_upload', 'image_upload' ), true ) && array_is_list( $value ) && in_array( $id, $value, true ) ) {
				return true;
			}
			if ( 'drawing' === $kind && ( $id === ( $value['document_attachment_id'] ?? null ) || $id === ( $value['preview_attachment_id'] ?? null ) ) ) {
				return true;
			}
		}
		return false;
	}
	/**
	 * Deny.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function deny(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
	}
}
