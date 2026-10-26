<?php
/**
 * StageOwnAttachment use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Storage\Port\Private_File_Staging_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Upload_Profile;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Access_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Attachment_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores one self-owned upload in private quarantine before asynchronous scan.
 */
final class Stage_Own_Attachment {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize stage own attachment dependencies and state.
	 *
	 * @param Student_Assignment_Access_Port_Interface     $access Access.
	 * @param Learning_Context_Query_Port_Interface        $contexts Contexts.
	 * @param Student_Attachment_Repository_Port_Interface $attachments Attachments.
	 * @param Private_File_Staging_Port_Interface          $metadata Metadata.
	 * @param Private_File_Store_Port_Interface            $files Files.
	 * @param Submission_Attachment_Upload_Profile         $profile Profile.
	 * @param Public_Id_Generator                          $ids Ids.
	 * @param callable|null                                $clock Clock.
	 */
	public function __construct(
		private Student_Assignment_Access_Port_Interface $access,
		private Learning_Context_Query_Port_Interface $contexts,
		private Student_Attachment_Repository_Port_Interface $attachments,
		private Private_File_Staging_Port_Interface $metadata,
		private Private_File_Store_Port_Interface $files,
		private Submission_Attachment_Upload_Profile $profile,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute stage own attachment.
	 *
	 * @param Stage_Own_Attachment_Command $command Command.
	 * @return Stage_Own_Attachment_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Stage_Own_Attachment_Command $command ): Stage_Own_Attachment_Result {
		$snapshot = $this->access->submission_authorization_snapshot( $command->submission_id(), $command->actor_user_id() );
		$this->assert_editable_snapshot( $snapshot, $command );
		$this->assert_active_context( $command->actor_user_id(), $snapshot );

		$staging_profile = $this->attachments->staging_profile( $command->submission_id(), $command->actor_user_id() );
		if ( null === $staging_profile ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}
		$this->assert_profile_snapshot( $staging_profile, $snapshot, $command );
		$upload      = $this->profile->inspect( $command->upload(), $staging_profile, $command->response_id(), $command->role() );
		$storage_key = $command->storage_key( $upload['extension'] );
		$existing    = $this->metadata->find_by_storage_key( $storage_key );
		if ( is_array( $existing ) ) {
			return $this->resume_existing( $command, $snapshot, $upload, $existing );
		}

		$this->assert_active_context( $command->actor_user_id(), $snapshot );
		$created_at = ( $this->clock )();
		$this->assert_utc( $created_at );
		$timestamp = strtotime( $created_at . ' UTC' );
		if ( false === $timestamp ) {
			throw new InvalidArgumentException( 'StageOwnAttachment clock cannot be converted.' );
		}
		$expires_at    = gmdate( 'Y-m-d H:i:s', $timestamp + 86400 );
		$file_id       = $this->ids->create()->value();
		$attachment_id = $this->ids->create()->value();
		if ( hash_equals( $file_id, $attachment_id ) ) {
			throw new InvalidArgumentException( 'StageOwnAttachment generated duplicate public IDs.' );
		}

		$file_created     = false;
		$metadata_created = false;
		try {
			$file_created = $this->files->put_quarantined( $upload['tmp_name'], $storage_key, $upload['sha256'] );
			$this->metadata->insert_quarantined(
				array(
					'public_id'       => $file_id,
					'actor_user_id'   => $command->actor_user_id(),
					'created_at_utc'  => $created_at,
					'owner_type'      => 'submission',
					'owner_public_id' => $command->submission_id(),
					'purpose'         => 'submission_attachment',
					'storage_key'     => $storage_key,
					'original_name'   => $upload['original_name'],
					'mime_type'       => $upload['mime_type'],
					'byte_size'       => $upload['byte_size'],
					'sha256'          => $upload['sha256'],
					'scan_state'      => 'pending',
					'retention_class' => 'submission_staging',
					'expires_at_utc'  => $expires_at,
				)
			);
			$metadata_created = true;
			$row              = $this->attachments->stage( $this->attachment_record( $command, $upload, $file_id, $attachment_id, $created_at ) );
			return $this->result( $row, 'pending', $expires_at, false );
		} catch ( Assignment_Failure $failure ) {
			$race = $this->metadata->find_by_storage_key( $storage_key );
			if ( ! $metadata_created && is_array( $race ) ) {
				return $this->resume_existing( $command, $snapshot, $upload, $race );
			}
			if ( $metadata_created ) {
				$this->compensate_metadata_and_file( $file_id, $command->actor_user_id(), $created_at, $storage_key ); } elseif ( $file_created ) {
				$this->compensate_file( $storage_key );
				}
				throw $failure;
		} catch ( Throwable ) {
			$race = $this->metadata->find_by_storage_key( $storage_key );
			if ( ! $metadata_created && is_array( $race ) ) {
				return $this->resume_existing( $command, $snapshot, $upload, $race );
			}
			if ( $metadata_created ) {
				$this->compensate_metadata_and_file( $file_id, $command->actor_user_id(), $created_at, $storage_key ); } elseif ( $file_created ) {
				$this->compensate_file( $storage_key );
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Reject invalid editable snapshot.
	 *
	 * @param array<string,mixed>|null     $snapshot Snapshot.
	 * @param Stage_Own_Attachment_Command $command Command.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_editable_snapshot( ?array $snapshot, Stage_Own_Attachment_Command $command ): void {
		if ( null === $snapshot || 'active' !== ( $snapshot['target_state'] ?? null ) || 'withdrawn' === ( $snapshot['assignment_state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}
		if ( 'open' !== ( $snapshot['assignment_state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		if ( ! in_array( $snapshot['state'] ?? null, array( 'draft', 'conflict', 'reopened' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL );
		}
		if ( $command->expected_state() !== $snapshot['state'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
		}
	}

	/**
	 * Reject invalid active context.
	 *
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_active_context( int $actor_user_id, array $snapshot ): void {
		$contexts = $this->contexts->for_actor( $actor_user_id );
		$key      = (string) $snapshot['context_type'] . ':' . (string) $snapshot['context_id'];
		$context  = $contexts[ $key ] ?? null;
		if ( ! is_array( $context ) || 'active' !== ( $context['lifecycle_state'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}
	}

	/**
	 * Reject invalid profile snapshot.
	 *
	 * @param array<string,mixed>          $profile Profile.
	 * @param array<string,mixed>          $snapshot Snapshot.
	 * @param Stage_Own_Attachment_Command $command Command.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_profile_snapshot( array $profile, array $snapshot, Stage_Own_Attachment_Command $command ): void {
		if (
			( $profile['submission_id'] ?? null ) !== $command->submission_id()
			|| ( $profile['assignment_id'] ?? null ) !== ( $snapshot['assignment_id'] ?? null )
			|| ( $profile['context_type'] ?? null ) !== ( $snapshot['context_type'] ?? null )
			|| ( $profile['context_id'] ?? null ) !== ( $snapshot['context_id'] ?? null )
			|| 'active' !== ( $profile['target_state'] ?? null )
			|| 'open' !== ( $profile['assignment_state'] ?? null )
			|| ! in_array( $profile['state'] ?? null, array( 'draft', 'conflict', 'reopened' ), true )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}
	}

	/**
	 * Resume existing.
	 *
	 * @param Stage_Own_Attachment_Command $command Command.
	 * @param array<string,mixed>          $snapshot Snapshot.
	 * @param array<string,mixed>          $upload Upload.
	 * @param array<string,mixed>          $file File.
	 * @return Stage_Own_Attachment_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function resume_existing(
		Stage_Own_Attachment_Command $command,
		array $snapshot,
		array $upload,
		array $file
	): Stage_Own_Attachment_Result {
		$matches = (int) ( $file['created_by_user_id'] ?? 0 ) === $command->actor_user_id()
			&& 'submission' === ( $file['owner_type'] ?? null )
			&& 'submission_attachment' === ( $file['purpose'] ?? null )
			&& hash_equals( $command->submission_id(), (string) ( $file['owner_public_id'] ?? '' ) )
			&& hash_equals( $upload['original_name'], (string) ( $file['original_name'] ?? '' ) )
			&& hash_equals( $upload['mime_type'], (string) ( $file['mime_type'] ?? '' ) )
			&& $upload['byte_size'] === (int) ( $file['byte_size'] ?? 0 )
			&& hash_equals( $upload['sha256'], (string) ( $file['sha256'] ?? '' ) )
			&& 'submission_staging' === ( $file['retention_class'] ?? null )
			&& in_array( $file['state'] ?? null, array( 'quarantined', 'ready' ), true )
			&& in_array( $file['scan_state'] ?? null, array( 'pending', 'clean' ), true );
		if ( ! $matches ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		$file_id    = (string) ( $file['public_id'] ?? '' );
		$attachment = $this->attachments->find_by_file_public_id( $command->submission_id(), $command->actor_user_id(), $file_id );
		if ( null === $attachment ) {
			$this->assert_active_context( $command->actor_user_id(), $snapshot );
			$occurred_at = ( $this->clock )();
			$this->assert_utc( $occurred_at );
			$attachment = $this->attachments->stage(
				$this->attachment_record( $command, $upload, $file_id, $this->ids->create()->value(), $occurred_at )
			);
		}
		$attachment_response = $attachment['response_id'] ?? $attachment['response_key'] ?? null;
		if (
			$command->response_id() !== $attachment_response
			|| $upload['original_name'] !== ( $attachment['original_name'] ?? null )
			|| $upload['mime_type'] !== ( $attachment['mime_type'] ?? null )
			|| $upload['byte_size'] !== (int) ( $attachment['byte_size'] ?? 0 )
			|| $upload['sha256'] !== ( $attachment['sha256'] ?? null )
			|| ! in_array( $attachment['state'] ?? null, array( 'staged', 'scanning', 'ready' ), true )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		return $this->result(
			array(
				'attachment_id' => $attachment['attachment_id'] ?? $attachment['public_id'] ?? null,
				'response_id'   => $attachment['response_id'] ?? $attachment['response_key'] ?? null,
				'original_name' => $attachment['original_name'] ?? null,
				'mime_type'     => $attachment['mime_type'] ?? null,
				'byte_size'     => $attachment['byte_size'] ?? null,
				'sha256'        => $attachment['sha256'] ?? null,
				'state'         => $attachment['state'],
			),
			(string) $file['scan_state'],
			(string) ( $file['expires_at_utc'] ?? '' ),
			true
		);
	}

	/**
	 * Attachment record.
	 *
	 * @param Stage_Own_Attachment_Command $command Command.
	 * @param array<string,mixed>          $upload Upload.
	 * @param string                       $file_id File id.
	 * @param string                       $attachment_id Attachment id.
	 * @param string                       $occurred_at Occurred at.
	 * @return array<string,mixed>
	 */
	private function attachment_record(
		Stage_Own_Attachment_Command $command,
		array $upload,
		string $file_id,
		string $attachment_id,
		string $occurred_at
	): array {
		return array(
			'actor_user_id'                => $command->actor_user_id(),
			'submission_public_id'         => $command->submission_id(),
			'attachment_public_id'         => $attachment_id,
			'file_public_id'               => $file_id,
			'response_id'                  => $command->response_id(),
			'role'                         => $command->role(),
			'original_name'                => $upload['original_name'],
			'mime_type'                    => $upload['mime_type'],
			'byte_size'                    => $upload['byte_size'],
			'sha256'                       => $upload['sha256'],
			'max_files'                    => $upload['max_files'],
			'occurred_at_utc'              => $occurred_at,
			'expected_state'               => $command->expected_state(),
			'expected_lock_version'        => $command->expected_lock_version(),
			'expected_current_revision_id' => $command->expected_current_revision_id(),
			'expected_schedule_version'    => $command->expected_schedule_version(),
			'expected_schedule_hash'       => $command->expected_schedule_hash(),
			'expected_publication_hash'    => $command->expected_publication_hash(),
			'expected_save_token'          => $command->expected_save_token(),
		);
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $scan_state Scan state.
	 * @param string              $expires_at Expires at.
	 * @param bool                $replayed Replayed.
	 * @return Stage_Own_Attachment_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result( array $row, string $scan_state, string $expires_at, bool $replayed ): Stage_Own_Attachment_Result {
		foreach ( array( 'attachment_id', 'response_id', 'original_name', 'mime_type', 'sha256', 'state' ) as $field ) {
			if ( ! is_string( $row[ $field ] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
		}
		if ( ! is_int( $row['byte_size'] ?? null ) && ! is_string( $row['byte_size'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$this->assert_utc( $expires_at );
		return new Stage_Own_Attachment_Result(
			$row['attachment_id'],
			$row['response_id'],
			$row['original_name'],
			$row['mime_type'],
			(int) $row['byte_size'],
			$row['sha256'],
			$row['state'],
			$scan_state,
			$expires_at,
			$replayed
		);
	}

	/**
	 * Compensate metadata and file.
	 *
	 * @param string $file_id File id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $occurred_at Occurred at.
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	private function compensate_metadata_and_file( string $file_id, int $actor_user_id, string $occurred_at, string $storage_key ): void {
		try {
			$this->files->delete( $storage_key );
			$this->metadata->mark_purged( $file_id, $actor_user_id, $occurred_at );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
			// The private cleanup job owns any quarantined remainder.
		}
	}

	/**
	 * Compensate file.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	private function compensate_file( string $storage_key ): void {
		try {
			$this->files->delete( $storage_key ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// Best-effort recovery must not replace the primary failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
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
			throw new InvalidArgumentException( 'StageOwnAttachment clock is invalid.' );
		}
	}
}
