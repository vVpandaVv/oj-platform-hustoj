<?php
/**
 * Public OJ Platform AI routing adapter for worksheet quality evaluation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Infrastructure;

use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\Addon_Runtime_Bridge;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Routing_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public OJ Platform AI routing adapter for worksheet quality evaluation.
 */
final class Platform_Worksheet_Evaluation_Routing implements Worksheet_Evaluation_Routing_Port_Interface {
	/**
	 * Initialize platform worksheet evaluation routing dependencies and state.
	 *
	 * @param Addon_Runtime_Bridge $runtime Runtime.
	 */
	public function __construct( private Addon_Runtime_Bridge $runtime ) {
	}

	/**
	 * Plan.
	 *
	 * @return Worksheet_Evaluation_Routing_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function plan(): Worksheet_Evaluation_Routing_Snapshot {
		$gateway = $this->runtime->ai_tasks();
		if ( null === $gateway ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::NOT_ROUTABLE );
		}
		try {
			$decision = $gateway->canRoute( Addon_Manifest::TASK_WORKSHEET_QUALITY );
			if ( ! $decision->canRoute() ) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::NOT_ROUTABLE );
			}
			$snapshot = $gateway->snapshot( Addon_Manifest::TASK_WORKSHEET_QUALITY );
			if (
				! $snapshot->isAvailable()
				|| Addon_Manifest::TASK_WORKSHEET_QUALITY !== $snapshot->taskKey()
				|| ! hash_equals( $decision->routingRevision(), $snapshot->routingRevision() )
			) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::NOT_ROUTABLE );
			}
			return new Worksheet_Evaluation_Routing_Snapshot(
				$snapshot->taskKey(),
				$snapshot->providerKey(),
				$snapshot->modelKey(),
				$snapshot->profileFingerprint(),
				$snapshot->routingRevision()
			);
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::ROUTING_SNAPSHOT_INVALID );
		}
	}
}
