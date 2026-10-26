<?php
/**
 * StageSubmissionZip use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Storage\Port\Private_File_Staging_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Upload_Profile;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores one validated ZIP source in private quarantine and queues scanning.
 */
final class Stage_Submission_Zip {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize stage submission zip dependencies and state.
	 *
	 * @param Assignment_Repository_Port_Interface     $assignments Assignments.
	 * @param Submission_Zip_Repository_Port_Interface $uploads Uploads.
	 * @param Private_File_Staging_Port_Interface      $metadata Metadata.
	 * @param Private_File_Store_Port_Interface        $files Files.
	 * @param Submission_Zip_Upload_Profile            $profile Profile.
	 * @param Public_Id_Generator                      $ids Ids.
	 * @param Canonical_Json                           $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                            $clock Clock.
	 */
	public function __construct(
		private Assignment_Repository_Port_Interface $assignments,
		private Submission_Zip_Repository_Port_Interface $uploads,
		private Private_File_Staging_Port_Interface $metadata,
		private Private_File_Store_Port_Interface $files,
		private Submission_Zip_Upload_Profile $profile,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute stage submission zip.
	 *
	 * @param Stage_Submission_Zip_Command $command Command.
	 * @return Stage_Submission_Zip_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Stage_Submission_Zip_Command $command ): Stage_Submission_Zip_Result {
		$inspected      = $this->profile->inspect( $command->upload() );
		$request_digest = $command->request_digest( $inspected, $this->canonical );
		$this->assert_assignment_context( $this->assignments->authorization_snapshot( $command->assignment_id() ), $command, false );

		$replay = $this->uploads->find_receipt( $command->idempotency_storage_key(), $request_digest );
		if ( $replay instanceof Stage_Submission_Zip_Result ) {
			return $replay;
		}
		$this->assert_assignment_context( $this->assignments->authorization_snapshot( $command->assignment_id() ), $command, true );

		$created_at = ( $this->clock )();
		$this->assert_utc( $created_at );
		$timestamp = strtotime( $created_at . ' UTC' );
		if ( false === $timestamp ) {
			throw new InvalidArgumentException( 'StageSubmissionZip clock cannot be converted.' );
		}
		$expires_at = gmdate( 'Y-m-d H:i:s', $timestamp + 86400 );
		$upload_id  = $this->ids->create()->value();
		$file_id    = $this->ids->create()->value();
		$audit_id   = $this->ids->create()->value();
		$outbox_id  = $this->ids->create()->value();
		if ( 4 !== count( array_unique( array( $upload_id, $file_id, $audit_id, $outbox_id ) ) ) ) {
			throw new InvalidArgumentException( 'StageSubmissionZip generated duplicate public IDs.' );
		}

		$storage_key      = $command->storage_key();
		$file_created     = false;
		$metadata_created = false;
		try {
			$existing = $this->metadata->find_by_storage_key( $storage_key );
			if ( is_array( $existing ) ) {
				$file_id    = $this->matching_file_id( $existing, $command, $inspected );
				$expires_at = (string) $existing['expires_at_utc'];
			} else {
				$file_created = $this->files->put_quarantined( $inspected['tmp_name'], $storage_key, $inspected['sha256'] );
				try {
					$this->metadata->insert_quarantined(
						array(
							'public_id'       => $file_id,
							'actor_user_id'   => $command->actor_user_id(),
							'created_at_utc'  => $created_at,
							'owner_type'      => 'assignment',
							'owner_public_id' => $command->assignment_id(),
							'purpose'         => 'submission_zip_source',
							'storage_key'     => $storage_key,
							'original_name'   => $inspected['original_name'],
							'mime_type'       => $inspected['mime_type'],
							'byte_size'       => $inspected['byte_size'],
							'sha256'          => $inspected['sha256'],
							'scan_state'      => 'pending',
							'retention_class' => 'submission_zip_staging',
							'expires_at_utc'  => $expires_at,
						)
					);
					$metadata_created = true;
				} catch ( Throwable $metadata_failure ) {
					$race = $this->metadata->find_by_storage_key( $storage_key );
					if ( ! is_array( $race ) ) {
						throw $metadata_failure;
					}
					$file_id    = $this->matching_file_id( $race, $command, $inspected );
					$expires_at = (string) $race['expires_at_utc'];
				}
			}

			$result = $this->uploads->stage(
				array(
					'actor_user_id'               => $command->actor_user_id(),
					'assignment_public_id'        => $command->assignment_id(),
					'upload_public_id'            => $upload_id,
					'file_public_id'              => $file_id,
					'audit_public_id'             => $audit_id,
					'outbox_public_id'            => $outbox_id,
					'command_id'                  => $command->command_id(),
					'correlation_id'              => $command->correlation_id(),
					'idempotency_storage_key'     => $command->idempotency_storage_key(),
					'request_digest'              => $request_digest,
					'occurred_at_utc'             => $created_at,
					'expires_at_utc'              => $expires_at,
					'expected_state'              => $command->expected_state(),
					'expected_schedule_version'   => $command->expected_schedule_version(),
					'authorized_context_type'     => $command->authorized_context_type(),
					'authorized_context_id'       => $command->authorized_context_id(),
					'authorized_context_revision' => $command->authorized_context_revision(),
					'original_name'               => $inspected['original_name'],
					'byte_size'                   => $inspected['byte_size'],
					'sha256'                      => $inspected['sha256'],
					'entry_count'                 => $inspected['entry_count'],
					'compressed_entry_bytes'      => $inspected['compressed_entry_bytes'],
					'uncompressed_bytes'          => $inspected['uncompressed_bytes'],
				)
			);
			return $result;
		} catch ( Zip_Import_Failure $failure ) {
			$replay = $this->safe_receipt( $command, $request_digest );
			if ( $replay instanceof Stage_Submission_Zip_Result ) {
				return $replay;
			}
			$this->compensate( $metadata_created, $file_created, $file_id, $command->actor_user_id(), $created_at, $storage_key );
			throw $failure;
		} catch ( Throwable ) {
			$replay = $this->safe_receipt( $command, $request_digest );
			if ( $replay instanceof Stage_Submission_Zip_Result ) {
				return $replay;
			}
			$this->compensate( $metadata_created, $file_created, $file_id, $command->actor_user_id(), $created_at, $storage_key );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Reject invalid assignment context.
	 *
	 * @param array<string,mixed>|null     $snapshot Snapshot.
	 * @param Stage_Submission_Zip_Command $command Command.
	 * @param bool                         $check_expected Check expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_assignment_context( ?array $snapshot, Stage_Submission_Zip_Command $command, bool $check_expected ): void {
		if (
			null === $snapshot
			|| ( $snapshot['context_type'] ?? null ) !== $command->authorized_context_type()
			|| ( $snapshot['context_id'] ?? null ) !== $command->authorized_context_id()
			|| ( $snapshot['context_revision'] ?? null ) !== $command->authorized_context_revision()
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::NOT_FOUND );
		}
		if ( $check_expected && (
			( $snapshot['state'] ?? null ) !== $command->expected_state()
			|| (string) ( $snapshot['current_revision'] ?? '' ) !== (string) $command->expected_schedule_version()
		) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
		}
	}

	/**
	 * Matching file id.
	 *
	 * @param array<string,mixed>          $file File.
	 * @param Stage_Submission_Zip_Command $command Command.
	 * @param array<string,mixed>          $inspected Inspected.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function matching_file_id( array $file, Stage_Submission_Zip_Command $command, array $inspected ): string {
		$matches = (int) ( $file['created_by_user_id'] ?? $file['actor_user_id'] ?? 0 ) === $command->actor_user_id()
			&& 'assignment' === ( $file['owner_type'] ?? null )
			&& $command->assignment_id() === ( $file['owner_public_id'] ?? null )
			&& 'submission_zip_source' === ( $file['purpose'] ?? null )
			&& $inspected['original_name'] === ( $file['original_name'] ?? null )
			&& 'application/zip' === ( $file['mime_type'] ?? null )
			&& $inspected['byte_size'] === (int) ( $file['byte_size'] ?? 0 )
			&& hash_equals( $inspected['sha256'], (string) ( $file['sha256'] ?? '' ) )
			&& 'submission_zip_staging' === ( $file['retention_class'] ?? null )
			&& 'quarantined' === ( $file['state'] ?? null )
			&& 'pending' === ( $file['scan_state'] ?? null )
			&& is_string( $file['expires_at_utc'] ?? null );
		if ( ! $matches ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::IDEMPOTENCY_CONFLICT );
		}
		$file_id = (string) ( $file['public_id'] ?? '' );
		try {
			new \OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id( $file_id );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORED_DATA_INVALID );
		}
		return $file_id;
	}

	/**
	 * Safe receipt.
	 *
	 * @param Stage_Submission_Zip_Command $command Command.
	 * @param string                       $request_digest Request digest.
	 * @return Stage_Submission_Zip_Result|null
	 */
	private function safe_receipt( Stage_Submission_Zip_Command $command, string $request_digest ): ?Stage_Submission_Zip_Result {
		try {
			return $this->uploads->find_receipt( $command->idempotency_storage_key(), $request_digest );
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Compensate.
	 *
	 * @param bool   $metadata_created Metadata created.
	 * @param bool   $file_created File created.
	 * @param string $file_id File id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $occurred_at Occurred at.
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	private function compensate( bool $metadata_created, bool $file_created, string $file_id, int $actor_user_id, string $occurred_at, string $storage_key ): void {
		if ( $metadata_created ) {
			try {
				$this->metadata->mark_purged( $file_id, $actor_user_id, $occurred_at ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
				// Best-effort recovery must not replace the primary failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
				}
		}
		if ( $metadata_created || $file_created ) {
			try {
				$this->files->delete( $storage_key ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
				// Best-effort recovery must not replace the primary failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
				}
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
			throw new InvalidArgumentException( 'StageSubmissionZip clock returned invalid UTC.' );
		}
	}
}
