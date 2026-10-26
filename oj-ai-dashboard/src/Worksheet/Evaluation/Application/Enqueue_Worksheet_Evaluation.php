<?php
/**
 * Enqueue one exact worksheet revision for asynchronous AI evaluation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Configuration_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Routing_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Source_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue one exact worksheet revision for asynchronous AI evaluation.
 */
final class Enqueue_Worksheet_Evaluation {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize enqueue worksheet evaluation dependencies and state.
	 *
	 * @param Worksheet_Evaluation_Source_Port_Interface                                     $sources Sources.
	 * @param Worksheet_Evaluation_Configuration_Port_Interface                              $configuration Configuration.
	 * @param Worksheet_Evaluation_Routing_Port_Interface                                    $routing Routing.
	 * @param Worksheet_Evaluation_Repository_Port_Interface                                 $repository Persistence boundary used by this operation.
	 * @param Worksheet_Evaluation_Queue_Wakeup_Port_Interface                               $queue Queue.
	 * @param Worksheet_Evaluation_Input_Builder                                             $inputs Inputs.
	 * @param Public_Id_Generator                                                            $ids Ids.
	 * @param Canonical_Json                                                                 $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                                                  $clock Clock.
	 * @param \OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface|null $projects Projects.
	 */
	public function __construct(
		private Worksheet_Evaluation_Source_Port_Interface $sources,
		private Worksheet_Evaluation_Configuration_Port_Interface $configuration,
		private Worksheet_Evaluation_Routing_Port_Interface $routing,
		private Worksheet_Evaluation_Repository_Port_Interface $repository,
		private Worksheet_Evaluation_Queue_Wakeup_Port_Interface $queue,
		private Worksheet_Evaluation_Input_Builder $inputs,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null,
		private ?\OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface $projects = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute enqueue worksheet evaluation.
	 *
	 * @param Enqueue_Worksheet_Evaluation_Command $command Command.
	 * @return Enqueue_Worksheet_Evaluation_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Enqueue_Worksheet_Evaluation_Command $command ): Enqueue_Worksheet_Evaluation_Result {
		$receipt = $this->repository->find_command_receipt(
			$command->idempotency_storage_key(),
			$command->request_digest()
		);
		if ( $receipt instanceof Enqueue_Worksheet_Evaluation_Result ) {
			$this->queue->wake();
			return $receipt;
		}

		$source = $this->sources->find_owned( $command->actor_user_id(), $command->revision_id() );
		if ( null === $source ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::NOT_FOUND );
		}
		if (
			! hash_equals( $source->revision_id(), $command->expected_revision_id() )
			|| ! hash_equals( $source->source_hash(), $command->expected_source_hash() )
			|| ! hash_equals( $source->revision_id(), $command->authorized_revision() )
			|| $source->state() !== $command->authorized_state()
			|| 'owner' !== $command->actor_relation()
		) {
			throw Worksheet_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Worksheet_Evaluation_Failure::SOURCE_STALE,
				array(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_revision_id' => $source->revision_id(),
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_source_hash' => $source->source_hash(),
				)
			);
		}

		$pin     = $source->document()->document()['settings']['assessment_project'] ?? null;
		$project = $this->projects?->for_worksheet( $source->worksheet_id(), $pin, $command->actor_user_id() );
		if ( ( null === $project ) !== ( null === $pin ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
		}
		$configuration = $this->configuration instanceof \OJAI\Dashboard\Worksheet\Evaluation\Port\Scoped_Evaluation_Configuration_Port_Interface
			? $this->configuration->for_worksheet( $source->worksheet_id() ) : $this->configuration->active();
		try {
			$input = $this->inputs->build(
				$source->worksheet_id(),
				$source->revision_id(),
				$source->document(),
				$source->projection(),
				$source->asset_snapshots(),
				$configuration,
				$project['configuration']['achievement_standards'] ?? array()
			);
		} catch ( Worksheet_Evaluation_Input_Failure $failure ) {
			$reason = match ( true ) {
				'$.document.settings.preparation' === $failure->diagnostic_path() => Worksheet_Evaluation_Failure::PREPARATION_REQUIRED,
				Worksheet_Evaluation_Input_Failure::INPUT_TOO_LARGE === $failure->reason() => Worksheet_Evaluation_Failure::INPUT_TOO_LARGE,
				str_starts_with( $failure->diagnostic_path(), '$.configuration.' ) => Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE,
				default => Worksheet_Evaluation_Failure::INPUT_INVALID,
			};
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( $reason );
		}
		$route     = $this->routing->plan();
		$queued_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at ) ) {
			throw new InvalidArgumentException( 'EnqueueWorksheetEvaluation clock returned an invalid UTC value.' );
		}

		$authorization      = array(
			'schema_id'             => 'oj_ai.authorization_envelope',
			'schema_version'        => '1.0.0',
			'actor_user_id'         => $command->actor_user_id(),
			'shell'                 => 'console',
			'surface'               => 'rest',
			'action_key'            => 'worksheet.evaluation.enqueue',
			'required_capabilities' => array( Addon_Capabilities::ASSESS_WORKSHEETS ),
			'context'               => null,
			'object'                => array(
				'type'                => 'worksheet_revision',
				'id'                  => $source->revision_id(),
				'action'              => 'edit',
				'authorized_revision' => $command->authorized_revision(),
				'authorized_state'    => $command->authorized_state(),
				'actor_relation'      => $command->actor_relation(),
			),
			'checked_at_utc'        => $queued_at,
		);
		$authorization_json = $this->canonical->encode( $authorization );

		$identity        = array(
			'schema_id'          => 'oj_ai.worksheet_evaluation_identity',
			'schema_version'     => '1.0.0',
			'subject'            => array(
				'type'        => 'worksheet_revision',
				'revision_id' => $source->revision_id(),
				'source_hash' => $source->source_hash(),
			),
			'operation'          => Worksheet_Evaluation_Input_Builder::OPERATION,
			'configuration_hash' => $input->configuration_hash(),
			'input_digest'       => $input->input_hash(),
		);
		$identity_digest = hash( 'sha256', $this->canonical->encode( $identity ) );
		$evaluation_key  = 'eval:' . $identity_digest;
		$evaluation_id   = $this->ids->create()->value();
		$run_identity    = array(
			'evaluation_identity_digest' => $identity_digest,
			'run_no'                     => 1,
			'task_key'                   => Addon_Manifest::TASK_WORKSHEET_QUALITY,
			'contract_version'           => Worksheet_Evaluation_Input_Builder::INPUT_CONTRACT,
			'operation'                  => Worksheet_Evaluation_Input_Builder::OPERATION,
			'input_digest'               => $input->input_hash(),
			'profile_fingerprint'        => $route->profile_fingerprint(),
			'routing_revision'           => $route->routing_revision(),
		);
		$run_key         = 'run:' . hash( 'sha256', $this->canonical->encode( $run_identity ) );

		$record = array(
			'actor_user_id'                => $command->actor_user_id(),
			'command_id'                   => $command->command_id(),
			'command_storage_key'          => $command->idempotency_storage_key(),
			'request_digest'               => $command->request_digest(),
			'correlation_id'               => $command->correlation_id(),
			'queued_at_utc'                => $queued_at,
			'evaluation_public_id'         => $evaluation_id,
			'input_public_id'              => $this->ids->create()->value(),
			'run_public_id'                => $this->ids->create()->value(),
			'audit_public_id'              => $this->ids->create()->value(),
			'outbox_public_id'             => $this->ids->create()->value(),
			'evaluation_idempotency_key'   => $evaluation_key,
			'run_idempotency_key'          => $run_key,
			'worksheet_public_id'          => $source->worksheet_id(),
			'worksheet_revision_public_id' => $source->revision_id(),
			'worksheet_revision_hash'      => $source->source_hash(),
			'configuration'                => $configuration,
			'configuration_hash'           => $input->configuration_hash(),
			'input_json'                   => $input->input_json(),
			'input_hash'                   => $input->input_hash(),
			'input_byte_size'              => $input->byte_size(),
			'projection_hash'              => $input->worksheet_projection_hash(),
			'authorization_envelope_json'  => $authorization_json,
			'authorization_envelope_hash'  => hash( 'sha256', $authorization_json ),
			'task_key'                     => $route->task_key(),
			'provider_key'                 => $route->provider_key(),
			'model_key'                    => $route->model_key(),
			'profile_fingerprint'          => $route->profile_fingerprint(),
			'routing_revision'             => $route->routing_revision(),
		);

		$result = $this->repository->enqueue( $record );
		$this->queue->wake();
		return $result;
	}
}
