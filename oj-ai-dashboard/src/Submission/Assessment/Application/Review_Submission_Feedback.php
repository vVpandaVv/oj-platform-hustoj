<?php
/**
 * Append one immutable teacher feedback draft or rejection.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Review_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append one immutable teacher feedback draft or rejection.
 */
final class Review_Submission_Feedback {
	public const REJECTION_SCHEMA = 'oj_ai.submission_feedback_rejection';
	public const PROFILE_VERSION  = 1;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize review submission feedback dependencies and state.
	 *
	 * @param Submission_Feedback_Repository_Port_Interface        $sources Sources.
	 * @param Submission_Feedback_Review_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Submission_Feedback_Input_Builder                    $inputs Inputs.
	 * @param Submission_Feedback_Output_Normalizer                $outputs Outputs.
	 * @param Public_Id_Generator                                  $ids Ids.
	 * @param Canonical_Json                                       $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                        $clock Clock.
	 */
	public function __construct(
		private Submission_Feedback_Repository_Port_Interface $sources,
		private Submission_Feedback_Review_Repository_Port_Interface $repository,
		private Submission_Feedback_Input_Builder $inputs,
		private Submission_Feedback_Output_Normalizer $outputs,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute review submission feedback.
	 *
	 * @param Review_Submission_Feedback_Command $command Command.
	 * @return Submission_Feedback_Review_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Review_Submission_Feedback_Command $command ): Submission_Feedback_Review_Result {
		$receipt = $this->repository->find_command_receipt(
			$command->idempotency_storage_key(),
			$command->request_digest()
		);
		if ( $receipt instanceof Submission_Feedback_Review_Result ) {
			return $receipt;
		}

		$snapshot = $this->repository->find_owned_snapshot( $command->actor_user_id(), $command->evaluation_id() );
		$source   = $this->sources->find_owned_source( $command->actor_user_id(), $command->evaluation_id() );
		if ( null === $snapshot || null === $source ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}
		$this->assert_expected( $command, $snapshot, $source );

		if ( 'edit' === $command->source_mode() ) {
			if (
				! in_array( $snapshot['latest_feedback_state'], array( 'ai_draft', 'teacher_draft' ), true )
				|| ! hash_equals( $command->expected_official_review_id(), (string) $snapshot['latest_feedback_review_id'] )
			) {
				throw Submission_Evaluation_Failure::because(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE,
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					$this->safe_state( $snapshot )
				);
			}
		}

		$profile       = 'teacher_draft' === $command->decision()
			? $this->teacher_profile( $command, $source )
			: $this->rejection_profile( $command );
		$revision_no   = $command->expected_feedback_revision_no() + 1;
		$revision_id   = $this->ids->create()->value();
		$occurred_at   = $this->now();
		$source_run_id = 'edit' === $command->source_mode()
			? $snapshot['latest_feedback_source_run_id']
			: null;
		$content_hash  = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'audience'             => 'student',
					'evaluation_id'        => $command->evaluation_id(),
					'feedback_hash'        => $profile['hash'],
					'previous_revision_id' => $command->expected_feedback_revision_id(),
					'review_id'            => $command->expected_official_review_id(),
					'revision_no'          => $revision_no,
					'source_run_id'        => $source_run_id,
					'state'                => $command->decision(),
				)
			)
		);

		return $this->repository->append_revision(
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
				'expected_evaluation_lock_version' => $snapshot['evaluation_lock_version'],
				'expected_evaluation_state'        => $snapshot['evaluation_state'],
				'expected_feedback_revision_hash'  => $command->expected_feedback_revision_hash(),
				'expected_feedback_revision_id'    => $command->expected_feedback_revision_id(),
				'expected_feedback_revision_no'    => $command->expected_feedback_revision_no(),
				'expected_feedback_review_id'      => $snapshot['latest_feedback_review_id'],
				'expected_feedback_source_run_id'  => $snapshot['latest_feedback_source_run_id'],
				'expected_feedback_state'          => $snapshot['latest_feedback_state'],
				'expected_official_review_hash'    => $command->expected_official_review_hash(),
				'expected_official_review_id'      => $command->expected_official_review_id(),
				'feedback_json'                    => $profile['json'],
				'feedback_hash'                    => $profile['hash'],
				'feedback_schema'                  => $profile['schema'],
				'feedback_version'                 => self::PROFILE_VERSION,
				'outbox_public_id'                 => $this->ids->create()->value(),
				'occurred_at_utc'                  => $occurred_at,
				'previous_feedback_revision_id'    => $command->expected_feedback_revision_id(),
				'reason_digest'                    => null === $command->reason() ? null : hash( 'sha256', $command->reason() ),
				'request_digest'                   => $command->request_digest(),
				'revision_no'                      => $revision_no,
				'revision_public_id'               => $revision_id,
				'source_evaluation_run_public_id'  => $source_run_id,
				'source_mode'                      => $command->source_mode(),
				'state'                            => $command->decision(),
			)
		);
	}

	/**
	 * Teacher profile.
	 *
	 * @param Review_Submission_Feedback_Command $command Command.
	 * @param array<string,mixed>                $source Source.
	 * @return array{schema:string,json:string,hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function teacher_profile( Review_Submission_Feedback_Command $command, array $source ): array {
		try {
			$input = $this->inputs->build( $source );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$gateway_input = $input->gateway_input();
		$payload       = $gateway_input['operation_payload'] ?? null;
		$subject       = is_array( $payload ) && ! array_is_list( $payload ) ? ( $payload['subject'] ?? null ) : null;
		$feedback      = $command->feedback();
		if ( ! is_array( $subject ) || array_is_list( $subject ) || ! is_array( $feedback ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		try {
			$result = $this->outputs->normalize(
				array(
					'criterion_feedback' => $feedback['criterion_feedback'],
					'feedback_ref'       => $subject['feedback_ref'] ?? null,
					'next_steps'         => $feedback['next_steps'],
					'overall_feedback'   => $feedback['overall_feedback'],
					'safety_flags'       => array(),
					'schema_id'          => Submission_Feedback_Input_Builder::OUTPUT_SCHEMA,
					'schema_version'     => Submission_Feedback_Input_Builder::OUTPUT_VERSION,
					'source_hash'        => $subject['source_hash'] ?? null,
				),
				$gateway_input
			);
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::FEEDBACK_SCHEMA_INVALID );
		}
		return array(
			'schema' => Submission_Feedback_Input_Builder::RESULT_SCHEMA,
			'json'   => $result->profile_json(),
			'hash'   => $result->profile_hash(),
		);
	}

	/**
	 * Rejection profile.
	 *
	 * @param Review_Submission_Feedback_Command $command Command.
	 * @return array{schema:string,json:string,hash:string}
	 */
	private function rejection_profile( Review_Submission_Feedback_Command $command ): array {
		$profile = array(
			'reason'               => $command->reason(),
			'schema_id'            => self::REJECTION_SCHEMA,
			'schema_version'       => '1.0.0',
			'source_feedback_hash' => $command->expected_feedback_revision_hash(),
			'source_feedback_ref'  => $command->expected_feedback_revision_id(),
			'source_hash'          => $command->expected_official_review_hash(),
		);
		$json    = $this->canonical->encode( $profile );
		return array(
			'schema' => self::REJECTION_SCHEMA,
			'json'   => $json,
			'hash'   => hash( 'sha256', $json ),
		);
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param Review_Submission_Feedback_Command $command Command.
	 * @param array<string,mixed>                $snapshot Snapshot.
	 * @param array<string,mixed>                $source Source.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( Review_Submission_Feedback_Command $command, array $snapshot, array $source ): void {
		$required = array(
			'evaluation_lock_version',
			'evaluation_state',
			'context_type',
			'context_id',
			'context_revision',
			'official_review_id',
			'official_review_hash',
			'latest_feedback_revision_id',
			'latest_feedback_revision_hash',
			'latest_feedback_revision_no',
			'latest_feedback_state',
			'latest_feedback_review_id',
			'latest_feedback_source_run_id',
		);
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $snapshot ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
		}
		foreach ( array( 'evaluation_id', 'evaluation_lock_version', 'evaluation_state', 'context_type', 'context_id', 'context_revision', 'official_review_id', 'official_review_hash' ) as $key ) {
			if ( ! array_key_exists( $key, $source ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
		}
		if (
			'context_staff' !== $command->actor_relation()
			|| 'reviewed' !== $snapshot['evaluation_state']
			|| ! hash_equals( $command->authorized_revision(), (string) $snapshot['evaluation_lock_version'] )
			|| ! hash_equals( $command->authorized_state(), (string) $snapshot['evaluation_state'] )
			|| ! hash_equals( $command->context_type(), (string) $snapshot['context_type'] )
			|| ! hash_equals( $command->context_id(), (string) $snapshot['context_id'] )
			|| ! hash_equals( $command->context_revision(), (string) $snapshot['context_revision'] )
			|| ! hash_equals( $command->expected_official_review_id(), (string) $snapshot['official_review_id'] )
			|| ! hash_equals( $command->expected_official_review_hash(), (string) $snapshot['official_review_hash'] )
			|| $command->expected_feedback_revision_id() !== $snapshot['latest_feedback_revision_id']
			|| $command->expected_feedback_revision_hash() !== $snapshot['latest_feedback_revision_hash']
			|| $command->expected_feedback_revision_no() !== $snapshot['latest_feedback_revision_no']
			|| ! hash_equals( $command->evaluation_id(), (string) $source['evaluation_id'] )
			|| (int) $snapshot['evaluation_lock_version'] !== (int) $source['evaluation_lock_version']
			|| ! hash_equals( (string) $snapshot['official_review_id'], (string) $source['official_review_id'] )
			|| ! hash_equals( (string) $snapshot['official_review_hash'], (string) $source['official_review_hash'] )
			|| ! hash_equals( (string) $snapshot['context_type'], (string) $source['context_type'] )
			|| ! hash_equals( (string) $snapshot['context_id'], (string) $source['context_id'] )
			|| ! hash_equals( (string) $snapshot['context_revision'], (string) $source['context_revision'] )
		) {
			throw Submission_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$this->safe_state( $snapshot )
			);
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
			'current_feedback_revision_no'   => (int) ( $snapshot['latest_feedback_revision_no'] ?? 0 ),
			'current_feedback_state'         => (string) ( $snapshot['latest_feedback_state'] ?? '' ),
			'current_official_review_hash'   => (string) ( $snapshot['official_review_hash'] ?? '' ),
			'current_official_review_id'     => (string) ( $snapshot['official_review_id'] ?? '' ),
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
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback clock returned an invalid UTC value.' );
		}
		return $value;
	}
}
