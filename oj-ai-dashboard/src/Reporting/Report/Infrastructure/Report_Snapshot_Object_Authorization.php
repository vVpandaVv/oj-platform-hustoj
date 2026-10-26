<?php
/**
 * Q22 report snapshot object authorization adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Infrastructure;

use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Port\Report_Snapshot_Query_Port_Interface;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Q22 report snapshot object authorization adapter.
 */
final class Report_Snapshot_Object_Authorization implements Object_Authorization_Port_Interface {
	/**
	 * Initialize report snapshot object authorization dependencies and state.
	 *
	 * @param Report_Snapshot_Query_Port_Interface $snapshots Snapshots.
	 */
	public function __construct( private Report_Snapshot_Query_Port_Interface $snapshots ) {
	}

	/**
	 * Authorize.
	 *
	 * @param int                               $actor_user_id Authenticated actor from the access context.
	 * @param string                            $action_key Action key.
	 * @param Context_Authorization_Result|null $context Access or execution context for this operation.
	 * @param Object_Scope                      $object Object.
	 * @return Object_Authorization_Result
	 */
	public function authorize(
		int $actor_user_id,
		string $action_key,
		?Context_Authorization_Result $context,
		Object_Scope $object // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
	): Object_Authorization_Result {
		if ( Object_Scope::TYPE_REPORT !== $object->type() || 'view' !== $object->action() ) {
			return Object_Authorization_Result::unavailable();
		}
		if ( null === $context || ! $context->is_allowed() ) {
			return Object_Authorization_Result::denied();
		}

		try {
			$scope = $this->snapshots->scope( $object->id() );
		} catch ( Report_Snapshot_Query_Failure $failure ) {
			if ( Report_Snapshot_Query_Failure::NOT_FOUND === $failure->reason() ) {
				return Object_Authorization_Result::denied();
			}
			return Object_Authorization_Result::unavailable();
		} catch ( Throwable ) {
			return Object_Authorization_Result::unavailable();
		}

		if (
			null === $scope || 'ready' !== $scope->state()
			|| $scope->context_type() !== $context->type()
			|| $scope->context_id() !== $context->id()
		) {
			return Object_Authorization_Result::denied();
		}

		return Object_Authorization_Result::allowed(
			$scope->snapshot_hash(),
			$scope->state(),
			(string) $context->actor_relation()
		);
	}
}
