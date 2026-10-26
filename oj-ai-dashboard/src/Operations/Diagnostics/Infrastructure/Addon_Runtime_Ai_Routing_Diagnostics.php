<?php
/**
 * Read-only AI routing diagnostics through the public OJ Platform Addon API.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Infrastructure;

use Closure;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Ai_Routing_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Ai_Routing_Task_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Port\Ai_Routing_Diagnostics_Port_Interface;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\Addon_Runtime_Bridge;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only AI routing diagnostics through the public OJ Platform Addon API.
 */
final class Addon_Runtime_Ai_Routing_Diagnostics implements Ai_Routing_Diagnostics_Port_Interface {
	/**
	 * Gateway provider.
	 *
	 * @var Closure
	 */
	private Closure $gateway_provider;

	/**
	 * Initialize addon runtime ai routing diagnostics dependencies and state.
	 *
	 * @param Addon_Runtime_Bridge $runtime Runtime.
	 * @param Closure|null         $gateway_provider Gateway provider.
	 */
	public function __construct( Addon_Runtime_Bridge $runtime, ?Closure $gateway_provider = null ) {
		$this->gateway_provider = $gateway_provider
			?? static fn () => $runtime->ai_tasks();
	}

	/**
	 * Inspect.
	 *
	 * @return Ai_Routing_Diagnostics_Result
	 */
	public function inspect(): Ai_Routing_Diagnostics_Result {
		try {
			$gateway = ( $this->gateway_provider )();
		} catch ( Throwable ) {
			return Ai_Routing_Diagnostics_Result::unavailable();
		}

		if ( ! $gateway instanceof AddonAiTaskGatewayV1 ) {
			return Ai_Routing_Diagnostics_Result::unavailable();
		}

		return new Ai_Routing_Diagnostics_Result(
			$this->inspect_task(
				$gateway,
				Addon_Manifest::TASK_WORKSHEET_QUALITY,
				Ai_Routing_Task_Diagnostics::SUBJECT_WORKSHEET_QUALITY
			),
			$this->inspect_task(
				$gateway,
				Addon_Manifest::TASK_STUDENT_ASSESSMENT,
				Ai_Routing_Task_Diagnostics::SUBJECT_STUDENT_SUBMISSION
			)
		);
	}

	/**
	 * Inspect task.
	 *
	 * @param AddonAiTaskGatewayV1 $gateway Gateway.
	 * @param string               $task_key Task key.
	 * @param string               $subject Subject.
	 * @return Ai_Routing_Task_Diagnostics
	 */
	private function inspect_task(
		AddonAiTaskGatewayV1 $gateway,
		string $task_key,
		string $subject
	): Ai_Routing_Task_Diagnostics {
		try {
			$decision = $gateway->canRoute( $task_key );
			$snapshot = $gateway->snapshot( $task_key );

			if ( $task_key !== $snapshot->taskKey() ) {
				return $this->task( $subject, Ai_Routing_Task_Diagnostics::ROUTING_STALE, 'ai_task_routing_stale' );
			}

			if ( $decision->canRoute() ) {
				if ( ! $snapshot->isAvailable() ) {
					return $this->unavailable_snapshot( $subject, $snapshot->problem(), true );
				}
				if ( ! hash_equals( $decision->routingRevision(), $snapshot->routingRevision() ) ) {
					return $this->task( $subject, Ai_Routing_Task_Diagnostics::ROUTING_STALE, 'ai_task_routing_stale' );
				}

				return new Ai_Routing_Task_Diagnostics(
					$subject,
					Ai_Routing_Task_Diagnostics::READY,
					'ai_task_routing_ready',
					$snapshot->routingRevision(),
					$snapshot->profileFingerprint()
				);
			}

			if ( $snapshot->isAvailable() ) {
				return $this->task( $subject, Ai_Routing_Task_Diagnostics::ROUTING_STALE, 'ai_task_routing_stale' );
			}

			return $this->unavailable_snapshot( $subject, $snapshot->problem(), false );
		} catch ( Throwable ) {
			return $this->task( $subject, Ai_Routing_Task_Diagnostics::RUNTIME_UNAVAILABLE, 'ai_task_routing_unavailable' );
		}
	}

	/**
	 * Unavailable snapshot.
	 *
	 * @param string                 $subject Subject.
	 * @param AddonApiProblemV1|null $problem Problem.
	 * @param bool                   $decision_was_routable Decision was routable.
	 * @return Ai_Routing_Task_Diagnostics
	 */
	private function unavailable_snapshot(
		string $subject,
		?AddonApiProblemV1 $problem,
		bool $decision_was_routable
	): Ai_Routing_Task_Diagnostics {
		if ( $problem instanceof AddonApiProblemV1 && $problem->retryable() ) {
			return $this->task(
				$subject,
				Ai_Routing_Task_Diagnostics::UNROUTABLE_TRANSIENT,
				'ai_task_routing_temporarily_unavailable'
			);
		}

		return $decision_was_routable
			? $this->task( $subject, Ai_Routing_Task_Diagnostics::ROUTING_STALE, 'ai_task_routing_stale' )
			: $this->task( $subject, Ai_Routing_Task_Diagnostics::UNROUTABLE, 'ai_task_assignment_required' );
	}

	/**
	 * Task.
	 *
	 * @param string $subject Subject.
	 * @param string $status Status.
	 * @param string $code Code.
	 * @return Ai_Routing_Task_Diagnostics
	 */
	private function task( string $subject, string $status, string $code ): Ai_Routing_Task_Diagnostics {
		return new Ai_Routing_Task_Diagnostics( $subject, $status, $code );
	}
}
