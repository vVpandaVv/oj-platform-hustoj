<?php
/**
 * Public OJ Platform AI execution adapter for worksheet evaluation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Infrastructure;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRequestV1;
use OJAI\Dashboard\Platform\Addon_Runtime_Bridge;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Gateway_Result;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Work_Item;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Execution_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public OJ Platform AI execution adapter for worksheet evaluation.
 */
final class Platform_Worksheet_Evaluation_Execution implements Worksheet_Evaluation_Execution_Port_Interface {
	/**
	 * Initialize platform worksheet evaluation execution dependencies and state.
	 *
	 * @param Addon_Runtime_Bridge $runtime Runtime.
	 */
	public function __construct( private Addon_Runtime_Bridge $runtime ) {
	}

	/**
	 * Snapshot.
	 *
	 * @param string $task_key Task key.
	 * @return Worksheet_Evaluation_Routing_Snapshot|null
	 */
	public function snapshot( string $task_key ): ?Worksheet_Evaluation_Routing_Snapshot {
		$gateway = $this->runtime->ai_tasks();
		if ( null === $gateway ) {
			return null;
		}
		try {
			$snapshot = $gateway->snapshot( $task_key );
			if ( ! $snapshot->isAvailable() || ! hash_equals( $task_key, $snapshot->taskKey() ) ) {
				return null;
			}
			return new Worksheet_Evaluation_Routing_Snapshot(
				$snapshot->taskKey(),
				$snapshot->providerKey(),
				$snapshot->modelKey(),
				$snapshot->profileFingerprint(),
				$snapshot->routingRevision()
			);
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Execute platform worksheet evaluation execution.
	 *
	 * @param Worksheet_Evaluation_Work_Item $item Item.
	 * @return Worksheet_Evaluation_Gateway_Result
	 */
	public function execute( Worksheet_Evaluation_Work_Item $item ): Worksheet_Evaluation_Gateway_Result {
		$gateway = $this->runtime->ai_tasks();
		if ( null === $gateway ) {
			return Worksheet_Evaluation_Gateway_Result::failure(
				'invalid_gateway_response',
				false,
				$this->fallback_correlation( $item, 'unavailable' ),
				0
			);
		}
		$started = hrtime( true );
		try {
			$result   = $gateway->execute(
				new AddonAiTaskRequestV1(
					$item->task_key(),
					$item->contract_version(),
					$item->actor_user_id(),
					$item->run_idempotency_key(),
					$item->input()
				)
			);
			$duration = $this->duration_ms( $started );
			if ( $result->isSuccessful() ) {
				$snapshot = $result->routingSnapshot();
				return Worksheet_Evaluation_Gateway_Result::success(
					$result->output(),
					new Worksheet_Evaluation_Routing_Snapshot(
						$snapshot->taskKey(),
						$snapshot->providerKey(),
						$snapshot->modelKey(),
						$snapshot->profileFingerprint(),
						$snapshot->routingRevision()
					),
					$result->correlationId(),
					$duration
				);
			}

			$problem = $result->problem();
			if (
				null === $problem
				|| ! in_array( $problem->code(), array( 'addon_ai_task_failed', 'addon_ai_task_unroutable' ), true )
				|| ! hash_equals( $problem->correlationId(), $result->correlationId() )
			) {
				return Worksheet_Evaluation_Gateway_Result::failure(
					'invalid_gateway_response',
					false,
					$this->fallback_correlation( $item, 'invalid' ),
					$duration
				);
			}
			return Worksheet_Evaluation_Gateway_Result::failure(
				$problem->code(),
				'addon_ai_task_failed' === $problem->code() && $problem->retryable(),
				$result->correlationId(),
				$duration,
				$this->safe_message( $problem->message() )
			);
		} catch ( Throwable ) {
			return Worksheet_Evaluation_Gateway_Result::failure(
				'invalid_gateway_response',
				false,
				$this->fallback_correlation( $item, 'exception' ),
				$this->duration_ms( $started )
			);
		}
	}

	/**
	 * Duration ms.
	 *
	 * @param int $started Started.
	 * @return int
	 */
	private function duration_ms( int $started ): int {
		return min( 300000, max( 0, (int) round( ( hrtime( true ) - $started ) / 1000000 ) ) );
	}

	/**
	 * Fallback correlation.
	 *
	 * @param Worksheet_Evaluation_Work_Item $item Item.
	 * @param string                         $suffix Suffix.
	 * @return string
	 */
	private function fallback_correlation( Worksheet_Evaluation_Work_Item $item, string $suffix ): string {
		return 'worker:' . substr( hash( 'sha256', $item->run_id() . ':' . $item->attempt_no() . ':' . $suffix ), 0, 32 );
	}

	/**
	 * Safe message.
	 *
	 * @param string $message Message.
	 * @return string|null
	 */
	private function safe_message( string $message ): ?string {
		$message = trim( $message );
		if ( '' === $message || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $message ) ) {
			return null;
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 500 ) : substr( $message, 0, 500 );
	}
}
