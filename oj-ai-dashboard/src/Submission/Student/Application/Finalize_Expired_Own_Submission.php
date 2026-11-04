<?php
/**
 * Bounded recovery of an authorized learner's saved deadline submission.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Port\Auto_Submission_Commit_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reuses the cron commit path without accepting new answers or bypassing its policy checks.
 */
final class Finalize_Expired_Own_Submission {
	/**
	 * Initialize the shared automatic commit dependencies.
	 *
	 * @param Auto_Submission_Commit_Repository_Port_Interface $commits Automatic submission commits.
	 * @param Public_Id_Generator                              $ids Public identifiers.
	 */
	public function __construct(
		private Auto_Submission_Commit_Repository_Port_Interface $commits,
		private Public_Id_Generator $ids
	) {
	}

	/**
	 * Recover only after the owner, target and current context have been authorized.
	 *
	 * @param array<string,mixed> $submission Verified self-query projection, never request input.
	 * @param int                 $actor_user_id Authenticated learner.
	 * @param string              $context_revision Authorized assignment context revision.
	 * @param string              $now_utc Server time.
	 * @return bool Whether the caller should reload the submission after a commit or race.
	 */
	public function finalize( array $submission, int $actor_user_id, string $context_revision, string $now_utc ): bool {
		$closes = $submission['schedule']['closes_at_utc'] ?? null;
		if (
			! is_string( $closes ) || $now_utc < $closes
			|| ! in_array( $submission['state'] ?? null, array( 'draft', 'reopened', 'conflict' ), true )
			|| ! is_string( $submission['current_revision']['id'] ?? null )
		) {
			return false;
		}
		$identity = hash( 'sha256', $submission['id'] . '|' . $submission['schedule']['hash'] );
		try {
			$this->commits->auto_submit(
				array(
					'assignment_public_id'         => $submission['assignment_id'],
					'learner_user_id'              => $actor_user_id,
					'submission_public_id'         => $submission['id'],
					'receipt_public_id'            => $this->ids->create()->value(),
					'audit_public_id'              => $this->ids->create()->value(),
					'outbox_public_id'             => $this->ids->create()->value(),
					'command_id'                   => 'auto-submit:' . substr( $identity, 0, 32 ),
					'correlation_id'               => 'auto-submit:' . substr( $identity, 0, 32 ),
					'idempotency_storage_key'      => 'submission:' . $identity,
					'occurred_at_utc'              => $now_utc,
					'effective_received_at_utc'    => $closes,
					'expected_state'               => $submission['state'],
					'expected_lock_version'        => $submission['lock_version'],
					'expected_current_revision_id' => $submission['current_revision']['id'],
					'expected_schedule_version'    => $submission['schedule']['version'],
					'expected_schedule_hash'       => $submission['schedule']['hash'],
					'authorized_context_revision'  => $context_revision,
				)
			);
			return true;
		} catch ( Assignment_Failure ) {
			// Disabled auto-submit, unsafe attachments or concurrent changes must not block reading.
			return false;
		}
	}
}
