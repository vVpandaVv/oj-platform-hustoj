<?php
/**
 * Public OJ Platform AI routing adapter for student submission assessment.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\Addon_Runtime_Bridge;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Routing_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public OJ Platform AI routing adapter for student submission assessment.
 */
final class Platform_Submission_Evaluation_Routing implements Submission_Evaluation_Routing_Port_Interface {
	/**
	 * Initialize platform submission evaluation routing dependencies and state.
	 *
	 * @param Addon_Runtime_Bridge $runtime Runtime.
	 */
	public function __construct( private Addon_Runtime_Bridge $runtime ) {
	}

	/**
	 * Plan.
	 *
	 * @return Submission_Evaluation_Routing_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function plan(): Submission_Evaluation_Routing_Snapshot {
		$gateway = $this->runtime->ai_tasks();
		if ( null === $gateway ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_ROUTABLE );
		}
		try {
			$decision = $gateway->canRoute( Addon_Manifest::TASK_STUDENT_ASSESSMENT );
			if ( ! $decision->canRoute() ) {
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_ROUTABLE );
			}
			$snapshot = $gateway->snapshot( Addon_Manifest::TASK_STUDENT_ASSESSMENT );
			if (
				! $snapshot->isAvailable()
				|| Addon_Manifest::TASK_STUDENT_ASSESSMENT !== $snapshot->taskKey()
				|| ! hash_equals( $decision->routingRevision(), $snapshot->routingRevision() )
			) {
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_ROUTABLE );
			}
			return new Submission_Evaluation_Routing_Snapshot(
				$snapshot->taskKey(),
				$snapshot->providerKey(),
				$snapshot->modelKey(),
				$snapshot->profileFingerprint(),
				$snapshot->routingRevision()
			);
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::ROUTING_SNAPSHOT_INVALID );
		}
	}
}
