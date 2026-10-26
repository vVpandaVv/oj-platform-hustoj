<?php
/**
 * Platform submission feedback execution.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRequestV1;
use OJAI\Dashboard\Platform\Addon_Runtime_Bridge;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Gateway_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Work_Item;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Execution_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Platform submission feedback execution.
 */
final class Platform_Submission_Feedback_Execution implements Submission_Feedback_Execution_Port_Interface {
	/**
	 * Initialize platform submission feedback execution dependencies and state.
	 *
	 * @param Addon_Runtime_Bridge $runtime Runtime.
	 */
	public function __construct( private Addon_Runtime_Bridge $runtime ) {
	}
	/**
	 * Snapshot.
	 *
	 * @param string $task_key Task key.
	 * @return Submission_Evaluation_Routing_Snapshot|null
	 */
	public function snapshot( string $task_key ): ?Submission_Evaluation_Routing_Snapshot {
		$gateway = $this->runtime->ai_tasks();
		if ( null === $gateway ) {
			return null;
		}
		try {
			$snapshot = $gateway->snapshot( $task_key );
			if ( ! $snapshot->isAvailable() || ! hash_equals( $task_key, $snapshot->taskKey() ) ) {
				return null;
			} return new Submission_Evaluation_Routing_Snapshot( $snapshot->taskKey(), $snapshot->providerKey(), $snapshot->modelKey(), $snapshot->profileFingerprint(), $snapshot->routingRevision() ); } catch ( Throwable ) {
			return null;
			}
	}
	/**
	 * Execute platform submission feedback execution.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @return Submission_Evaluation_Gateway_Result
	 */
	public function execute( Submission_Feedback_Work_Item $item ): Submission_Evaluation_Gateway_Result {
		$gateway = $this->runtime->ai_tasks();
		if ( null === $gateway ) {
			return Submission_Evaluation_Gateway_Result::failure( 'invalid_gateway_response', false, $this->fallback( $item, 'unavailable' ), 0 );
		}
		$started = hrtime( true );
		try {
			$result   = $gateway->execute( new AddonAiTaskRequestV1( $item->task_key(), $item->contract_version(), $item->actor_user_id(), $item->run_idempotency_key(), $item->input() ) );
			$duration = $this->duration( $started );
			if ( $result->isSuccessful() ) {
				$snapshot = $result->routingSnapshot();
				return Submission_Evaluation_Gateway_Result::success( $result->output(), new Submission_Evaluation_Routing_Snapshot( $snapshot->taskKey(), $snapshot->providerKey(), $snapshot->modelKey(), $snapshot->profileFingerprint(), $snapshot->routingRevision() ), $result->correlationId(), $duration );
			}
			$problem = $result->problem();
			if ( null === $problem || ! in_array( $problem->code(), array( 'addon_ai_task_failed', 'addon_ai_task_unroutable' ), true ) || ! hash_equals( $problem->correlationId(), $result->correlationId() ) ) {
				return Submission_Evaluation_Gateway_Result::failure( 'invalid_gateway_response', false, $this->fallback( $item, 'invalid' ), $duration );
			}
			return Submission_Evaluation_Gateway_Result::failure( $problem->code(), 'addon_ai_task_failed' === $problem->code() && $problem->retryable(), $result->correlationId(), $duration );
		} catch ( Throwable ) {
			return Submission_Evaluation_Gateway_Result::failure( 'invalid_gateway_response', false, $this->fallback( $item, 'exception' ), $this->duration( $started ) );
		}
	}
	/**
	 * Duration.
	 *
	 * @param int $started Started.
	 * @return int
	 */
	private function duration( int $started ): int {
		return min( 300000, max( 0, (int) round( ( hrtime( true ) - $started ) / 1000000 ) ) );
	}
	/**
	 * Fallback.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @param string                        $suffix Suffix.
	 * @return string
	 */
	private function fallback( Submission_Feedback_Work_Item $item, string $suffix ): string {
		return 'worker:' . substr( hash( 'sha256', $item->run_id() . ':' . $item->attempt_no() . ':' . $suffix ), 0, 32 );
	}
}
