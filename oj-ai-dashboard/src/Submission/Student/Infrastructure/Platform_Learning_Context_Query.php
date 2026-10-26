<?php
/**
 * Public Addon API learner Context adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextQueryV1;
use OJAI\Dashboard\Contracts\Platform_Context_Runtime_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consumes CG02 exactly, with bounded pages and no local membership fallback.
 */
final class Platform_Learning_Context_Query implements Learning_Context_Query_Port_Interface {
	private const PAGE_LIMIT = 100;
	private const MAX_PAGES  = 50;
	private const MAX_ITEMS  = 5000;

	/**
	 * Initialize platform learning context query dependencies and state.
	 *
	 * @param Platform_Context_Runtime_Port_Interface $runtime Runtime.
	 */
	public function __construct( private Platform_Context_Runtime_Port_Interface $runtime ) {
	}

	/**
	 * For actor.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return array<string,array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>}>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function for_actor( int $actor_user_id ): array {
		if ( $actor_user_id < 1 ) {
			throw new \InvalidArgumentException( 'Learning Context actor is invalid.' );
		}
		if ( ! $this->runtime->is_ready() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ROSTER_UNAVAILABLE );
		}
		$gateway = $this->runtime->contexts();
		if ( ! $gateway instanceof AddonLearningContextGatewayV1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ROSTER_UNAVAILABLE );
		}

		$result       = array();
		$seen_items   = array();
		$seen_cursors = array( '' => true );
		$cursor       = '';
		try {
			for ( $page_number = 1; $page_number <= self::MAX_PAGES; ++$page_number ) {
				$page = $gateway->listLearningContexts(
					new AddonLearningContextQueryV1(
						$actor_user_id,
						array( 'class', 'group' ),
						'',
						$cursor,
						self::PAGE_LIMIT
					)
				);
				if ( ! $page->isSuccessful() || null !== $page->problem() ) {
					throw Assignment_Failure::because( Assignment_Failure::ROSTER_UNAVAILABLE );
				}
				foreach ( $page->items() as $item ) {
					$type = $item->type();
					$id   = $item->id();
					$key  = $type . ':' . $id;
					if ( ! in_array( $type, array( 'class', 'group' ), true ) || isset( $seen_items[ $key ] ) ) {
						throw Assignment_Failure::because( Assignment_Failure::ROSTER_INVALID );
					}
					$seen_items[ $key ] = true;
					if ( 'active' === $item->lifecycleState() ) {
						$result[ $key ] = array(
							'type'            => $type,
							'id'              => $id,
							'label'           => $item->label(),
							'lifecycle_state' => $item->lifecycleState(),
							'revision'        => $item->revision(),
							'attributes'      => $item->attributes(),
						);
					}
					if ( count( $seen_items ) > self::MAX_ITEMS ) {
						throw Assignment_Failure::because( Assignment_Failure::ROSTER_TOO_LARGE );
					}
				}
				$next = $page->nextCursor();
				if ( '' === $next ) {
					ksort( $result, SORT_STRING );
					return $result;
				}
				if ( isset( $seen_cursors[ $next ] ) ) {
					throw Assignment_Failure::because( Assignment_Failure::ROSTER_INVALID );
				}
				$seen_cursors[ $next ] = true;
				$cursor                = $next;
			}
		} catch ( Assignment_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ROSTER_UNAVAILABLE );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Failure::because( Assignment_Failure::ROSTER_TOO_LARGE );
	}
}
