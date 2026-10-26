<?php
/**
 * Enqueue one exact official submission for asynchronous AI scoring.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Source;
use OJAI\Dashboard\Submission\Assessment\Domain\Manual_Submission_Score;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Failure;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Enqueuer_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Routing_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Source_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue one exact official submission for asynchronous AI scoring.
 */
final class Enqueue_Submission_Evaluation implements Submission_Evaluation_Enqueuer_Port_Interface {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize enqueue submission evaluation dependencies and state.
	 *
	 * @param Submission_Evaluation_Source_Port_Interface       $sources Sources.
	 * @param Submission_Evaluation_Routing_Port_Interface      $routing Routing.
	 * @param Submission_Evaluation_Repository_Port_Interface   $repository Persistence boundary used by this operation.
	 * @param Submission_Evaluation_Queue_Wakeup_Port_Interface $queue Queue.
	 * @param Submission_Score_Input_Builder                    $inputs Inputs.
	 * @param Public_Id_Generator                               $ids Ids.
	 * @param Canonical_Json                                    $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                     $clock Clock.
	 */
	public function __construct(
		private Submission_Evaluation_Source_Port_Interface $sources,
		private Submission_Evaluation_Routing_Port_Interface $routing,
		private Submission_Evaluation_Repository_Port_Interface $repository,
		private Submission_Evaluation_Queue_Wakeup_Port_Interface $queue,
		private Submission_Score_Input_Builder $inputs,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute enqueue submission evaluation.
	 *
	 * @param Enqueue_Submission_Evaluation_Command $command Command.
	 * @return Enqueue_Submission_Evaluation_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Enqueue_Submission_Evaluation_Command $command ): Enqueue_Submission_Evaluation_Result {
		$receipt = $this->repository->find_command_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Enqueue_Submission_Evaluation_Result ) {
			if ( ! $command->manual() ) {
				$this->queue->wake();
			}
			return $receipt;
		}

		$source = $this->sources->find_official( $command->submission_id(), $command->expected_official_revision_id(), $command->manual() );
		if ( null === $source ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}
		$this->assert_current_source( $source, $command );
		$evaluation_ref = $this->evaluation_reference( $source );
		try {
			$input = $this->inputs->build(
				$evaluation_ref,
				$source->submission_id(),
				$source->official_revision_id(),
				$source->official_revision(),
				$source->publication(),
				$source->configuration(),
				$source->artifact_representations()
			);
		} catch ( Submission_Score_Input_Failure $failure ) {
			throw Submission_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$failure->reason(),
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				array_merge( $failure->safe_context(), array( 'path' => $failure->diagnostic_path() ) )
			);
		}

		$route     = $command->manual() ? null : $this->routing->plan();
		$queued_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at ) ) {
			throw new InvalidArgumentException( 'EnqueueSubmissionEvaluation clock returned an invalid UTC value.' );
		}

		$authorization         = array(
			'schema_id'             => 'oj_ai.authorization_envelope',
			'schema_version'        => '1.0.0',
			'actor_user_id'         => $command->actor_user_id(),
			'shell'                 => 'console',
			'surface'               => 'rest',
			'action_key'            => $command->manual() ? 'submission.evaluation.manual' : 'submission.evaluation.enqueue',
			'required_capabilities' => array( $command->manual() ? Addon_Capabilities::REVIEW_ASSESSMENTS : Addon_Capabilities::ASSESS_SUBMISSIONS ),
			'context'               => array(
				'type'      => $source->context_type(),
				'id'        => $source->context_id(),
				'revision'  => $source->context_revision(),
				'operation' => 'evaluate',
			),
			'object'                => array(
				'type'                   => 'submission',
				'id'                     => $source->submission_id(),
				'action'                 => 'evaluate',
				'authorized_revision'    => $command->authorized_revision(),
				'authorized_state'       => $command->authorized_state(),
				'actor_relation'         => $command->actor_relation(),
				'official_revision_id'   => $source->official_revision_id(),
				'official_revision_hash' => $source->source_hash(),
			),
			'checked_at_utc'        => $queued_at,
		);
		$authorization_json    = $this->canonical->encode( $authorization );
		$identity              = array(
			'schema_id'                => 'oj_ai.submission_evaluation_identity',
			'schema_version'           => '1.0.0',
			'subject'                  => array(
				'type'                     => 'submission_revision',
				'revision_id'              => $source->official_revision_id(),
				'source_hash'              => $source->source_hash(),
				'worksheet_publication_id' => $source->publication_id(),
				'publication_hash'         => $source->publication_hash(),
			),
			'operation'                => $command->manual() ? Manual_Submission_Score::OPERATION : Submission_Score_Input_Builder::OPERATION,
			'configuration_hash'       => $input->configuration_snapshot_hash(),
			'source_snapshot_hash'     => $input->source_snapshot_hash(),
			'input_digest'             => $input->gateway_input_hash(),
			'artifact_references_hash' => $input->artifact_references_hash(),
		);
		$identity_digest       = hash( 'sha256', $this->canonical->encode( $identity ) );
		$evaluation_id         = $this->ids->create()->value();
		$run_identity          = array(
			'evaluation_identity_digest' => $identity_digest,
			'run_no'                     => 1,
			'task_key'                   => $command->manual() ? Manual_Submission_Score::TASK : Addon_Manifest::TASK_STUDENT_ASSESSMENT,
			'contract_version'           => Submission_Score_Input_Builder::OUTER_INPUT_CONTRACT,
			'operation'                  => $command->manual() ? Manual_Submission_Score::OPERATION : Submission_Score_Input_Builder::OPERATION,
			'input_digest'               => $input->gateway_input_hash(),
			'profile_fingerprint'        => $route?->profile_fingerprint(),
			'routing_revision'           => $route?->routing_revision(),
		);
		$binding_manifest_json = $this->canonical->encode( $input->binding_manifest() );

		$record = array(
			'actor_user_id'                   => $command->actor_user_id(),
			'command_id'                      => $command->command_id(),
			'command_storage_key'             => $command->idempotency_storage_key(),
			'request_digest'                  => $command->request_digest(),
			'correlation_id'                  => $command->correlation_id(),
			'queued_at_utc'                   => $queued_at,
			'evaluation_public_id'            => $evaluation_id,
			'evaluation_reference'            => $evaluation_ref,
			'input_public_id'                 => $this->ids->create()->value(),
			'run_public_id'                   => $this->ids->create()->value(),
			'audit_public_id'                 => $this->ids->create()->value(),
			'outbox_public_id'                => $this->ids->create()->value(),
			'evaluation_idempotency_key'      => 'eval:' . $identity_digest,
			'run_idempotency_key'             => 'run:' . hash( 'sha256', $this->canonical->encode( $run_identity ) ),
			'submission_public_id'            => $source->submission_id(),
			'submission_revision_public_id'   => $source->official_revision_id(),
			'submission_revision_hash'        => $source->source_hash(),
			'worksheet_publication_public_id' => $source->publication_id(),
			'publication_hash'                => $source->publication_hash(),
			'configuration'                   => $input->configuration_snapshot(),
			'configuration_hash'              => $input->configuration_snapshot_hash(),
			'source_snapshot_hash'            => $input->source_snapshot_hash(),
			'input_json'                      => $input->gateway_input_json(),
			'input_hash'                      => $input->gateway_input_hash(),
			'input_byte_size'                 => $input->byte_size(),
			'artifact_references_json'        => $input->artifact_references_json(),
			'artifact_references_hash'        => $input->artifact_references_hash(),
			'representation_set_hash'         => $input->representation_set_hash(),
			'binding_manifest_hash'           => hash( 'sha256', $binding_manifest_json ),
			'redaction_version'               => $input->redaction_version(),
			'authorization_envelope_json'     => $authorization_json,
			'authorization_envelope_hash'     => hash( 'sha256', $authorization_json ),
			'authorized_context_type'         => $source->context_type(),
			'authorized_context_id'           => $source->context_id(),
			'authorized_context_revision'     => $source->context_revision(),
			'task_key'                        => $route?->task_key() ?? Manual_Submission_Score::TASK,
			'provider_key'                    => $route?->provider_key(),
			'model_key'                       => $route?->model_key(),
			'profile_fingerprint'             => $route?->profile_fingerprint(),
			'routing_revision'                => $route?->routing_revision(),
		);
		if ( $command->manual() ) {
			$record['manual_result']           = Manual_Submission_Score::prepare( $input );
			$record['manual_result_public_id'] = $this->ids->create()->value();
		}

		$result = $this->repository->enqueue( $record );
		if ( ! $command->manual() ) {
			$this->queue->wake();
		}
		return $result;
	}

	/**
	 * Reject invalid current source.
	 *
	 * @param Submission_Evaluation_Source          $source Source.
	 * @param Enqueue_Submission_Evaluation_Command $command Command.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_current_source( Submission_Evaluation_Source $source, Enqueue_Submission_Evaluation_Command $command ): void {
		if (
			! hash_equals( $source->submission_id(), $command->submission_id() )
			|| ! hash_equals( $source->official_revision_id(), $command->expected_official_revision_id() )
			|| ! hash_equals( $source->source_hash(), $command->expected_official_revision_hash() )
			|| (string) $source->lock_version() !== $command->authorized_revision()
			|| $source->state() !== $command->authorized_state()
			|| $source->context_type() !== $command->authorized_context_type()
			|| $source->context_id() !== $command->authorized_context_id()
			|| $source->context_revision() !== $command->authorized_context_revision()
			|| 'context_staff' !== $command->actor_relation()
		) {
			throw Submission_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Submission_Evaluation_Failure::SOURCE_STALE,
				array(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_official_revision_id'   => $source->official_revision_id(),
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_official_revision_hash' => $source->source_hash(),
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_state'                  => $source->state(),
				)
			);
		}
	}

	/**
	 * Evaluation reference.
	 *
	 * @param Submission_Evaluation_Source $source Source.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evaluation_reference( Submission_Evaluation_Source $source ): string {
		$artifacts = array();
		foreach ( $source->artifact_representations() as $snapshot ) {
			if ( ! is_array( $snapshot ) || ! is_string( $snapshot['attachment_id'] ?? null ) ) {
				throw Submission_Evaluation_Failure::because(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					Submission_Evaluation_Failure::SOURCE_INVALID,
					array( 'path' => '$.artifact_representations' )
				);
			}
			$artifacts[] = array(
				'attachment_id'       => $snapshot['attachment_id'],
				'representation_hash' => $snapshot['representation_hash'] ?? null,
				'source_hash'         => $snapshot['source_hash'] ?? null,
			);
		}
		usort( $artifacts, static fn ( array $left, array $right ): int => strcmp( (string) $left['attachment_id'], (string) $right['attachment_id'] ) );
		try {
			$seed = $this->canonical->encode(
				array(
					'artifacts'                     => $artifacts,
					'configuration_hash'            => hash( 'sha256', $this->canonical->encode( $source->configuration() ) ),
					'publication_hash'              => $source->publication_hash(),
					'submission_revision_hash'      => $source->source_hash(),
					'submission_revision_public_id' => $source->official_revision_id(),
				)
			);
		} catch ( InvalidArgumentException ) {
			throw Submission_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Submission_Evaluation_Failure::SOURCE_INVALID,
				array(
					'path' => '$.evaluation_reference',
				)
			);
		}
		return 'EV_' . $this->base32( substr( hash( 'sha256', $seed, true ), 0, 20 ) );
	}

	/**
	 * Base32.
	 *
	 * @param string $binary Binary.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function base32( string $binary ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$buffer   = 0;
		$bits     = 0;
		$output   = '';
		foreach ( (array) unpack( 'C*', $binary ) as $byte ) {
			$buffer = ( $buffer << 8 ) | $byte;
			$bits  += 8;
			while ( $bits >= 5 ) {
				$bits   -= 5;
				$output .= $alphabet[ ( $buffer >> $bits ) & 31 ];
			}
			$buffer = 0 === $bits ? 0 : $buffer & ( ( 1 << $bits ) - 1 );
		}
		if ( 32 !== strlen( $output ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::SOURCE_INVALID );
		}
		return $output;
	}
}
