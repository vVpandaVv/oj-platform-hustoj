<?php
/**
 * Create the first or corrected immutable student result release.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Result_Release_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create the first or corrected immutable student result release.
 */
final class Release_Submission_Result {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize release submission result dependencies and state.
	 *
	 * @param Submission_Feedback_Repository_Port_Interface       $sources Sources.
	 * @param Submission_Result_Release_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Submission_Result_Projection_Builder                $projections Projections.
	 * @param Public_Id_Generator                                 $ids Ids.
	 * @param Canonical_Json                                      $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                       $clock Clock.
	 */
	public function __construct(
		private Submission_Feedback_Repository_Port_Interface $sources,
		private Submission_Result_Release_Repository_Port_Interface $repository,
		private Submission_Result_Projection_Builder $projections,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute release submission result.
	 *
	 * @param Release_Submission_Result_Command $command Command.
	 * @return Submission_Result_Release_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Release_Submission_Result_Command $command ): Submission_Result_Release_Result {
		$receipt = $this->repository->find_command_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Submission_Result_Release_Result ) {
			return $receipt;
		}

		$snapshot = $this->repository->find_owned_snapshot( $command->actor_user_id(), $command->evaluation_id() );
		$source   = $this->sources->find_owned_source( $command->actor_user_id(), $command->evaluation_id() );
		if ( null === $snapshot || null === $source ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}
		$this->assert_expected( $command, $snapshot, $source );
		$is_correction       = 'correction' === $command->mode();
		$release_no          = $is_correction ? (int) $snapshot['release_count'] + 1 : 1;
		$state               = $is_correction ? 'corrected' : 'released';
		$previous_release_id = $is_correction ? $command->expected_current_release_id() : null;

		$occurred_at = $this->now();
		$feedback    = null;
		if ( 'shown' === $command->feedback_visibility() ) {
			if ( 'approved' !== ( $snapshot['latest_feedback_state'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::FEEDBACK_NOT_APPROVED );
			}
			$feedback = array(
				'hash'    => $snapshot['latest_feedback_profile_hash'],
				'json'    => $snapshot['latest_feedback_json'],
				'schema'  => $snapshot['latest_feedback_schema'],
				'state'   => $snapshot['latest_feedback_state'],
				'version' => $snapshot['latest_feedback_version'],
			);
		}
		try {
			$projection = $this->projections->build(
				$source,
				(string) $snapshot['worksheet_title'],
				$command->score_visibility(),
				$command->feedback_visibility(),
				$feedback,
				$state,
				$occurred_at
			);
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::RELEASE_PROJECTION_INVALID );
		}

		$release_id   = $this->ids->create()->value();
		$content_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_id'              => $command->evaluation_id(),
					'feedback_id'                => $command->expected_feedback_revision_id(),
					'previous_release_id'        => $previous_release_id,
					'release_no'                 => $release_no,
					'release_reason_code'        => $command->reason_code(),
					'release_reason_note'        => $command->reason_note(),
					'released_at_utc'            => $occurred_at,
					'review_id'                  => $command->expected_official_review_id(),
					'state'                      => $state,
					'student_projection_hash'    => $projection->hash(),
					'student_projection_schema'  => $projection->schema(),
					'student_projection_version' => $projection->version(),
					'visible_score'              => $projection->visible_score(),
				)
			)
		);

		return $this->repository->append_release(
			array(
				'actor_user_id'                    => $command->actor_user_id(),
				'audit_public_id'                  => $this->ids->create()->value(),
				'command_id'                       => $command->command_id(),
				'command_storage_key'              => $command->idempotency_storage_key(),
				'content_hash'                     => $content_hash,
				'context_id'                       => $snapshot['context_id'],
				'context_revision'                 => $snapshot['context_revision'],
				'context_type'                     => $snapshot['context_type'],
				'correlation_id'                   => $command->correlation_id(),
				'evaluation_public_id'             => $command->evaluation_id(),
				'expected_current_release_hash'    => $command->expected_current_release_hash(),
				'expected_current_release_id'      => $command->expected_current_release_id(),
				'expected_evaluation_lock_version' => $snapshot['evaluation_lock_version'],
				'expected_evaluation_state'        => $snapshot['evaluation_state'],
				'expected_feedback_revision_hash'  => $command->expected_feedback_revision_hash(),
				'expected_feedback_revision_id'    => $command->expected_feedback_revision_id(),
				'expected_official_review_hash'    => $command->expected_official_review_hash(),
				'expected_official_review_id'      => $command->expected_official_review_id(),
				'expected_submission_lock_version' => $snapshot['submission_lock_version'],
				'outbox_public_id'                 => $this->ids->create()->value(),
				'previous_release_id'              => $previous_release_id,
				'projection_hash'                  => $projection->hash(),
				'projection_json'                  => $projection->json(),
				'projection_schema'                => $projection->schema(),
				'projection_version'               => $projection->version(),
				'reason_code'                      => $command->reason_code(),
				'reason_note'                      => $command->reason_note(),
				'release_no'                       => $release_no,
				'release_public_id'                => $release_id,
				'released_at_utc'                  => $occurred_at,
				'request_digest'                   => $command->request_digest(),
				'state'                            => $state,
				'visible_score'                    => $projection->visible_score(),
			)
		);
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param Release_Submission_Result_Command $command Command.
	 * @param array<string,mixed>               $snapshot Snapshot.
	 * @param array<string,mixed>               $source Source.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( Release_Submission_Result_Command $command, array $snapshot, array $source ): void {
		$required = array( 'context_id', 'context_revision', 'context_type', 'current_release_hash', 'current_release_id', 'current_release_no', 'current_release_state', 'evaluation_lock_version', 'evaluation_state', 'latest_feedback_revision_hash', 'latest_feedback_revision_id', 'latest_feedback_review_id', 'official_review_hash', 'official_review_id', 'release_count', 'submission_lock_version', 'worksheet_title' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $snapshot ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
		}
		$current_id    = $snapshot['current_release_id'];
		$current_hash  = $snapshot['current_release_hash'];
		$current_no    = $snapshot['current_release_no'];
		$current_state = $snapshot['current_release_state'];
		$release_count = $snapshot['release_count'];
		if ( ( null === $current_id ) !== ( null === $current_hash ) || ! is_int( $current_no ) || ! is_int( $release_count ) || $current_no < 0 || $release_count < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		if ( null === $current_id ) {
			if ( 0 !== $current_no || 0 !== $release_count || null !== $current_state ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
		} elseif ( ! is_string( $current_id ) || ! is_string( $current_hash ) || $current_no < 1 || ( ( $snapshot['current_release_same_evaluation'] ?? true ) && $current_no !== $release_count ) || ! in_array( $current_state, array( 'released', 'corrected', 'withdrawn', 'stale' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		foreach ( array( 'context_id', 'context_revision', 'context_type', 'evaluation_id', 'evaluation_lock_version', 'evaluation_state', 'official_review_hash', 'official_review_id' ) as $key ) {
			if ( ! array_key_exists( $key, $source ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
		}
		$feedback_stale = 'shown' === $command->feedback_visibility() && (
			$command->expected_feedback_revision_id() !== $snapshot['latest_feedback_revision_id']
			|| $command->expected_feedback_revision_hash() !== $snapshot['latest_feedback_revision_hash']
			|| $command->expected_official_review_id() !== $snapshot['latest_feedback_review_id']
		);
		$stale          =
			'context_staff' !== $command->actor_relation()
			|| 'reviewed' !== $snapshot['evaluation_state']
			|| ! hash_equals( $command->authorized_revision(), (string) $snapshot['evaluation_lock_version'] )
			|| ! hash_equals( $command->authorized_state(), (string) $snapshot['evaluation_state'] )
			|| ! hash_equals( $command->context_type(), (string) $snapshot['context_type'] )
			|| ! hash_equals( $command->context_id(), (string) $snapshot['context_id'] )
			|| ! hash_equals( $command->context_revision(), (string) $snapshot['context_revision'] )
			|| $command->expected_current_release_id() !== $snapshot['current_release_id']
			|| $command->expected_current_release_hash() !== $snapshot['current_release_hash']
			|| ! hash_equals( $command->expected_official_review_id(), (string) $snapshot['official_review_id'] )
			|| ! hash_equals( $command->expected_official_review_hash(), (string) $snapshot['official_review_hash'] )
			|| $feedback_stale
			|| ! hash_equals( $command->evaluation_id(), (string) $source['evaluation_id'] )
			|| (int) $snapshot['evaluation_lock_version'] !== (int) $source['evaluation_lock_version']
			|| ! hash_equals( (string) $snapshot['official_review_id'], (string) $source['official_review_id'] )
			|| ! hash_equals( (string) $snapshot['official_review_hash'], (string) $source['official_review_hash'] )
			|| ! hash_equals( (string) $snapshot['context_type'], (string) $source['context_type'] )
			|| ! hash_equals( (string) $snapshot['context_id'], (string) $source['context_id'] )
			|| ! hash_equals( (string) $snapshot['context_revision'], (string) $source['context_revision'] );
		if ( $stale ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::RELEASE_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Safe state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return array<string,int|string>
	 */
	private function safe_state( array $snapshot ): array {
		return array(
			'current_feedback_revision_hash' => (string) ( $snapshot['latest_feedback_revision_hash'] ?? '' ),
			'current_feedback_revision_id'   => (string) ( $snapshot['latest_feedback_revision_id'] ?? '' ),
			'current_official_review_hash'   => (string) ( $snapshot['official_review_hash'] ?? '' ),
			'current_official_review_id'     => (string) ( $snapshot['official_review_id'] ?? '' ),
			'current_release_hash'           => (string) ( $snapshot['current_release_hash'] ?? '' ),
			'current_release_id'             => (string) ( $snapshot['current_release_id'] ?? '' ),
		);
	}

	/**
	 * Now.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		$value = ( $this->clock )();
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'ReleaseSubmissionResult clock returned an invalid UTC value.' );
		} return $value;
	}
}
