<?php
/**
 * Remove an unbound self-owned submission attachment.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Access_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Attachment_Removal_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Closes DB metadata first, then idempotently removes the private bytes.
 */
final class Remove_Own_Staged_Attachment {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize remove own staged attachment dependencies and state.
	 *
	 * @param Student_Assignment_Access_Port_Interface  $access Access.
	 * @param Learning_Context_Query_Port_Interface     $contexts Contexts.
	 * @param Student_Attachment_Removal_Port_Interface $attachments Attachments.
	 * @param Private_File_Store_Port_Interface         $files Files.
	 * @param Public_Id_Generator                       $ids Ids.
	 * @param callable|null                             $clock Clock.
	 */
	public function __construct(
		private Student_Assignment_Access_Port_Interface $access,
		private Learning_Context_Query_Port_Interface $contexts,
		private Student_Attachment_Removal_Port_Interface $attachments,
		private Private_File_Store_Port_Interface $files,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute remove own staged attachment.
	 *
	 * @param Remove_Own_Staged_Attachment_Command $command Command.
	 * @return Remove_Own_Staged_Attachment_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Remove_Own_Staged_Attachment_Command $command ): Remove_Own_Staged_Attachment_Result {
		$snapshot = $this->access->submission_authorization_snapshot( $command->submission_id(), $command->actor_user_id() );
		$this->assert_editable_snapshot( $snapshot, $command );
		$this->assert_active_context( $command->actor_user_id(), $snapshot );

		$receipt = $this->attachments->find_removal_receipt(
			$command->submission_id(),
			$command->actor_user_id(),
			$command->idempotency_storage_key(),
			$command->request_digest()
		);
		if ( is_array( $receipt ) ) {
			$this->remove_bytes( $receipt );
			return $this->result( $receipt, true );
		}

		$context     = $this->assert_active_context( $command->actor_user_id(), $snapshot );
		$occurred_at = ( $this->clock )();
		$this->assert_utc( $occurred_at );
		$audit_id  = $this->ids->create()->value();
		$outbox_id = $this->ids->create()->value();
		if ( hash_equals( $audit_id, $outbox_id ) ) {
			throw new InvalidArgumentException( 'RemoveOwnStagedAttachment generated duplicate public IDs.' );
		}

		$row = $this->attachments->remove_staged(
			array(
				'actor_user_id'                => $command->actor_user_id(),
				'submission_public_id'         => $command->submission_id(),
				'attachment_public_id'         => $command->attachment_id(),
				'audit_public_id'              => $audit_id,
				'outbox_public_id'             => $outbox_id,
				'command_id'                   => $command->command_id(),
				'correlation_id'               => $command->correlation_id(),
				'idempotency_storage_key'      => $command->idempotency_storage_key(),
				'request_digest'               => $command->request_digest(),
				'occurred_at_utc'              => $occurred_at,
				'expected_state'               => $command->expected_state(),
				'expected_lock_version'        => $command->expected_lock_version(),
				'expected_current_revision_id' => $command->expected_current_revision_id(),
				'expected_schedule_version'    => $command->expected_schedule_version(),
				'expected_schedule_hash'       => $command->expected_schedule_hash(),
				'expected_publication_hash'    => $command->expected_publication_hash(),
				'expected_save_token'          => $command->expected_save_token(),
				'authorized_context_revision'  => (string) $context['revision'],
			)
		);
		$this->remove_bytes( $row );
		return $this->result( $row, (bool) ( $row['replayed'] ?? false ) );
	}

	/**
	 * Reject invalid editable snapshot.
	 *
	 * @param array<string,mixed>|null             $snapshot Snapshot.
	 * @param Remove_Own_Staged_Attachment_Command $command Command.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_editable_snapshot( ?array $snapshot, Remove_Own_Staged_Attachment_Command $command ): void {
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
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_active_context( int $actor_user_id, array $snapshot ): array {
		$contexts = $this->contexts->for_actor( $actor_user_id );
		$key      = (string) $snapshot['context_type'] . ':' . (string) $snapshot['context_id'];
		$context  = $contexts[ $key ] ?? null;
		if ( ! is_array( $context ) || 'active' !== $context['lifecycle_state'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}
		return $context;
	}

	/**
	 * Remove bytes.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function remove_bytes( array $row ): void {
		$storage_key = $row['storage_key'] ?? null;
		if ( ! is_string( $storage_key ) || '' === $storage_key ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		try {
			$this->files->delete( $storage_key );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param bool                $replayed Replayed.
	 * @return Remove_Own_Staged_Attachment_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result( array $row, bool $replayed ): Remove_Own_Staged_Attachment_Result {
		if ( 'removed' !== ( $row['state'] ?? null ) || ! is_string( $row['attachment_id'] ?? null ) || ! is_string( $row['removed_at_utc'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		return new Remove_Own_Staged_Attachment_Result( $row['attachment_id'], $row['removed_at_utc'], $replayed );
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
			throw new InvalidArgumentException( 'RemoveOwnStagedAttachment clock is invalid.' );
		}
	}
}
