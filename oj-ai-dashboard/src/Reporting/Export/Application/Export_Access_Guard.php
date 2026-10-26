<?php
/**
 * Shared export job and artifact authorization.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Export\Application;

use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reauthorizes the frozen source before returning job state or private bytes.
 */
final class Export_Access_Guard {
	/**
	 * Initialize export access guard dependencies and state.
	 *
	 * @param Access_Policy_Port_Interface $policy Policy.
	 */
	public function __construct( private Access_Policy_Port_Interface $policy ) {
	}

	/**
	 * Allows.
	 *
	 * @param int                 $actor Actor.
	 * @param array<string,mixed> $row Row.
	 * @param string              $surface Surface.
	 * @param string              $action Action.
	 * @param string              $correlation Correlation.
	 * @return bool
	 */
	public function allows( int $actor, array $row, string $surface, string $action, string $correlation ): bool {
		try {
			$authorization = $this->authorization( $row );
			$object        = $authorization['object'];
			$context       = $authorization['context'];
			$this->exact( $object, array( 'action', 'id', 'relation', 'revision', 'state', 'type' ) );
			$relation = (string) $object['relation'];
			if ( ! in_array( $relation, array( 'owner', 'context_staff', 'context_reporter', 'self_target', 'self_submission', 'self_release' ), true ) ) {
				return false;
			}
			$has_context = is_array( $context );
			if ( ! $this->matches_profile( $object, $relation, $has_context ) ) {
				return false;
			}
			$is_self           = in_array( $relation, array( 'self_target', 'self_submission', 'self_release' ), true );
			$context_operation = $is_self ? Context_Scope::OPERATION_PARTICIPATE : Context_Scope::OPERATION_REPORT;
			$context_subject   = $is_self ? $actor : null;
			$shell             = $is_self ? Access_Policy_Request::SHELL_PUBLIC : Access_Policy_Request::SHELL_CONSOLE;
			$capabilities      = array( $is_self ? Addon_Capabilities::PARTICIPATE : Addon_Capabilities::EXPORT_REPORTS );

			$context_scope = null;
			if ( is_array( $context ) ) {
				$this->exact( $context, array( 'id', 'revision', 'type' ) );
				$context_scope = new Context_Scope(
					(string) $context['type'],
					(string) $context['id'],
					$context_operation,
					$context_subject,
					(string) $context['revision']
				);
			}
			$object_scope     = new Object_Scope(
				(string) $object['type'],
				(string) $object['id'],
				(string) $object['action'],
				is_string( $object['revision'] ) ? $object['revision'] : null,
				is_string( $object['state'] ) ? $object['state'] : null
			);
			$decision         = $this->policy->authorize(
				new Access_Policy_Request(
					$actor,
					$shell,
					$surface,
					$action,
					$capabilities,
					$context_scope,
					$object_scope,
					true,
					$correlation
				)
			);
			$object_evidence  = $decision->object();
			$context_evidence = $decision->context();
			$relation_matches = 'context_reporter' === $relation
				? $context_evidence instanceof Context_Authorization_Result
					&& hash_equals( (string) $context_evidence->actor_relation(), (string) $object_evidence?->actor_relation() )
				: $object_evidence instanceof Object_Authorization_Result
					&& hash_equals( $relation, (string) $object_evidence->actor_relation() );
			if (
				! $decision->is_allowed()
				|| ! $object_evidence instanceof Object_Authorization_Result
				|| ! $object_evidence->is_allowed()
				|| ! $relation_matches
				|| ( is_string( $object['revision'] ) && ! hash_equals( $object['revision'], (string) $object_evidence->current_revision() ) )
				|| ( is_string( $object['state'] ) && ! hash_equals( $object['state'], (string) $object_evidence->current_state() ) )
			) {
				return false;
			}
			if ( null === $context_scope ) {
				return null === $decision->context();
			}
			return $context_evidence instanceof Context_Authorization_Result
				&& $context_evidence->is_allowed()
				&& hash_equals( (string) $context['type'], (string) $context_evidence->type() )
				&& hash_equals( (string) $context['id'], (string) $context_evidence->id() )
				&& hash_equals( (string) $context['revision'], (string) $context_evidence->revision() );
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Matches profile.
	 *
	 * @param array<string,mixed> $object Object.
	 * @param string              $relation Relation.
	 * @param bool                $has_context Has context.
	 * @return bool
	 */
	private function matches_profile( array $object, string $relation, bool $has_context ): bool { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		if ( 'context_staff' === $relation ) {
			return $has_context && (
				( 'teacher_view' === ( $object['action'] ?? null ) && Object_Scope::TYPE_SUBMISSION === ( $object['type'] ?? null ) )
				|| ( 'export_submissions' === ( $object['action'] ?? null ) && Object_Scope::TYPE_ASSIGNMENT === ( $object['type'] ?? null ) )
			);
		}
		$profiles = array(
			'owner'            => array(
				'action'  => 'view',
				'context' => false,
				'type'    => Object_Scope::TYPE_WORKSHEET_REVISION,
			),
			'context_reporter' => array(
				'action'  => 'view',
				'context' => true,
				'type'    => Object_Scope::TYPE_REPORT,
			),
			'self_target'      => array(
				'action'  => 'export_pdf',
				'context' => true,
				'type'    => Object_Scope::TYPE_ASSIGNMENT,
			),
			'self_submission'  => array(
				'action'  => 'export_pdf',
				'context' => true,
				'type'    => Object_Scope::TYPE_SUBMISSION,
			),
			'self_release'     => array(
				'action'  => 'export_pdf',
				'context' => true,
				'type'    => Object_Scope::TYPE_RELEASE,
			),
		);
		$profile  = $profiles[ $relation ] ?? null;
		return is_array( $profile )
			&& ( $object['action'] ?? null ) === $profile['action']
			&& ( $object['type'] ?? null ) === $profile['type']
			&& $has_context === $profile['context'];
	}

	/**
	 * Authorization.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array{context:?array<string,string>,object:array<string,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function authorization( array $row ): array {
		$value = $row['authorization'] ?? null;
		if ( is_array( $value ) && ! array_is_list( $value ) ) {
			$this->exact( $value, array( 'context', 'object' ) );
			if ( null !== $value['context'] && ( ! is_array( $value['context'] ) || array_is_list( $value['context'] ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			if ( ! is_array( $value['object'] ) || array_is_list( $value['object'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			return $value;
		}

		// Retains the frozen G2 worksheet query contract while submission exports require a descriptor.
		$kind        = is_string( $row['artifact_kind'] ?? null ) ? $row['artifact_kind'] : '';
		$source_type = is_string( $row['source_type'] ?? null ) ? $row['source_type'] : '';
		$source      = is_string( $row['source_id'] ?? null ) ? $row['source_id'] : '';
		if (
			'worksheet_revision' !== $source_type
			|| ! in_array( $kind, array( 'worksheet_pdf', 'worksheet_package' ), true )
			|| '' === $source
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		return array(
			'context' => null,
			'object'  => array(
				'action'   => 'view',
				'id'       => $source,
				'relation' => 'owner',
				'revision' => $source,
				'state'    => null,
				'type'     => Object_Scope::TYPE_WORKSHEET_REVISION,
			),
		);
	}

	/**
	 * Exact.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
	}
}
