<?php
/**
 * Frozen A-DS08-C45 report snapshot REST boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Reporting\Report\Application\Create_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Application\Create_Report_Snapshot_Command;
use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen A-DS08-C45 report snapshot REST boundary.
 */
final class Report_Snapshot_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_CREATE   = '/reports/commands/create-snapshot';
	public const ACTION_CREATE  = 'report.snapshot.create';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize report snapshot rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff $permissions Permissions.
	 * @param Create_Report_Snapshot  $snapshots Snapshots.
	 * @param Canonical_Json          $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory   $envelopes Envelopes.
	 * @param Correlation_Id_Factory  $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Create_Report_Snapshot $snapshots,
		private Canonical_Json $canonical,
		private Rest_Envelope_Factory $envelopes,
		private Correlation_Id_Factory $correlations
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function register_routes(): void {
		if ( $this->registered ) {
			return;
		}
		if ( ! function_exists( 'register_rest_route' ) ) {
			throw new LogicException( 'Report snapshot REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_CREATE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'permissions_create' ),
			)
		);
	}

	/**
	 * Permissions create.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_create( object $request ) {
		try {
			$this->empty_query( $request );
			$input = $this->input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_CREATE,
			array( Addon_Capabilities::VIEW_REPORTS ),
			$this->context_scope( $input )
		);
	}

	/**
	 * Create report snapshot rest controller.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$authorization = $grant->authorization();
		$correlation   = $authorization->correlation_id();
		$idempotency   = $authorization->idempotency_key();
		$context       = $authorization->policy_decision()->context();
		if ( null === $idempotency || ! $context instanceof Context_Authorization_Result || ! $context->is_allowed() ) {
			return $this->problem( 503, $correlation );
		}

		try {
			$input = $this->input( $request );
			$this->assert_granted_context( $context, $input );
			$result = $this->snapshots->execute(
				new Create_Report_Snapshot_Command(
					$grant->metadata()->actor_user_id(),
					$input['command_id'],
					$input['expected_source_type'],
					$input['expected_source_id'],
					$input['expected_source_revision'],
					$input['expected_live_source_manifest_hash'],
					$input['expected_filter_hash'],
					$input['expected_request_hash'],
					$input['context_type'],
					$input['context_id'],
					$input['context_revision'],
					$input['filter'],
					$input['source_page_size'],
					$idempotency->fingerprint(),
					$correlation,
					(string) $context->actor_relation(),
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Report_Snapshot_Failure $failure ) {
			return $this->snapshot_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}

		return $this->envelopes->success(
			array(
				'report_snapshot' => array(
					'id'                   => $result->snapshot_id(),
					'state'                => $result->state(),
					'snapshot_hash'        => $result->snapshot_hash(),
					'filter_hash'          => $result->filter_hash(),
					'source_manifest_hash' => $result->source_manifest_hash(),
					'projection_hash'      => $result->projection_hash(),
					'row_count'            => $result->row_count(),
					'generated_at_utc'     => $result->generated_at_utc(),
				),
			),
			$correlation,
			201,
			array(
				'idempotent_replay' => $result->replayed(),
				'mutation_count'    => $result->replayed() ? 0 : 1,
				'relation_scope'    => 'context_staff',
				'student_visible'   => false,
			)
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_source_type:string,expected_source_id:string,expected_source_revision:string,expected_live_source_manifest_hash:string,expected_filter_hash:string,expected_request_hash:string,context_type:string,context_id:string,context_revision:string,filter:Report_Filter,source_page_size:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Report snapshot body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Report snapshot body is invalid.' );
		}
		$this->exact( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Report snapshot sections are invalid.' );
		}
		$this->exact(
			$expected,
			array(
				'expected_source_type',
				'expected_source_id',
				'expected_source_revision',
				'expected_live_source_manifest_hash',
				'expected_filter_hash',
				'expected_request_schema',
				'expected_request_version',
				'expected_request_hash',
			)
		);
		$this->exact( $payload, array( 'lens', 'context', 'filter', 'source_page_size' ) );
		$context      = $payload['context'] ?? null;
		$filter_input = $payload['filter'] ?? null;
		if ( ! is_array( $context ) || array_is_list( $context ) || ! is_array( $filter_input ) || array_is_list( $filter_input ) ) {
			throw new InvalidArgumentException( 'Report snapshot payload is invalid.' );
		}
		$this->exact( $context, array( 'type', 'id', 'revision' ) );
		$this->exact( $filter_input, array( 'assignment_states', 'submission_states', 'timezone', 'period' ) );

		$strings = array(
			'command_id'                         => $input['command_id'] ?? null,
			'expected_source_type'               => $expected['expected_source_type'] ?? null,
			'expected_source_id'                 => $expected['expected_source_id'] ?? null,
			'expected_source_revision'           => $expected['expected_source_revision'] ?? null,
			'expected_live_source_manifest_hash' => $expected['expected_live_source_manifest_hash'] ?? null,
			'expected_filter_hash'               => $expected['expected_filter_hash'] ?? null,
			'expected_request_hash'              => $expected['expected_request_hash'] ?? null,
			'context_type'                       => $context['type'] ?? null,
			'context_id'                         => $context['id'] ?? null,
			'context_revision'                   => $context['revision'] ?? null,
		);
		foreach ( $strings as $value ) {
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Report snapshot string value is invalid.' );
			}
		}
		if (
			'worksheet' !== ( $payload['lens'] ?? null )
			|| Create_Report_Snapshot_Command::REQUEST_SCHEMA !== ( $expected['expected_request_schema'] ?? null )
			|| Create_Report_Snapshot_Command::REQUEST_VERSION !== ( $expected['expected_request_version'] ?? null )
			|| ! is_int( $payload['source_page_size'] ?? null )
			|| ! is_array( $filter_input['assignment_states'] ?? null )
			|| ! is_array( $filter_input['submission_states'] ?? null )
			|| ! is_string( $filter_input['timezone'] ?? null )
		) {
			throw new InvalidArgumentException( 'Report snapshot contract value is invalid.' );
		}

		$period = $filter_input['period'];
		$from   = null;
		$to     = null;
		if ( null !== $period ) {
			if ( ! is_array( $period ) || array_is_list( $period ) ) {
				throw new InvalidArgumentException( 'Report snapshot period is invalid.' );
			}
			$this->exact( $period, array( 'from', 'to', 'boundary', 'from_utc', 'to_utc' ) );
			foreach ( $period as $value ) {
				if ( ! is_string( $value ) ) {
					throw new InvalidArgumentException( 'Report snapshot period value is invalid.' );
				}
			}
			$from = $period['from'];
			$to   = $period['to'];
		}
		$filter = Report_Filter::from_query(
			$from,
			$to,
			$filter_input['timezone'],
			$filter_input['assignment_states'],
			$filter_input['submission_states']
		);
		if ( ! hash_equals( $this->canonical->encode( $filter->payload() ), $this->canonical->encode( $filter_input ) ) ) {
			throw new InvalidArgumentException( 'Report snapshot filter is not canonical.' );
		}

		return array_merge(
			$strings,
			array(
				'filter'           => $filter,
				'source_page_size' => $payload['source_page_size'],
			)
		);
	}

	/**
	 * Context scope.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return Context_Scope
	 */
	private function context_scope( array $input ): Context_Scope {
		return new Context_Scope(
			(string) $input['context_type'],
			(string) $input['context_id'],
			Context_Scope::OPERATION_REPORT,
			null,
			(string) $input['context_revision']
		);
	}

	/**
	 * Reject invalid granted context.
	 *
	 * @param Context_Authorization_Result $context Access or execution context for this operation.
	 * @param array<string,mixed>          $input Input.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_granted_context( Context_Authorization_Result $context, array $input ): void {
		if (
			! $context->is_allowed()
			|| $context->type() !== $input['context_type']
			|| $context->id() !== $input['context_id']
			|| $context->revision() !== $input['context_revision']
		) {
			throw new InvalidArgumentException( 'Granted report snapshot Context drifted.' );
		}
	}

	/**
	 * Empty query.
	 *
	 * @param object $request Request.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function empty_query( object $request ): void {
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'Report snapshot query is unavailable.' );
		}
		$query = $request->get_query_params();
		if ( ! is_array( $query ) || array() !== $query ) {
			throw new InvalidArgumentException( 'Report snapshot query must be empty.' );
		}
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
			throw new InvalidArgumentException( 'Report snapshot input shape is invalid.' );
		}
	}

	/**
	 * Snapshot problem.
	 *
	 * @param Report_Snapshot_Failure $failure Failure.
	 * @param string                  $correlation Correlation.
	 * @return \WP_Error
	 */
	private function snapshot_problem( Report_Snapshot_Failure $failure, string $correlation ) {
		$status = match ( $failure->reason() ) {
			Report_Snapshot_Failure::SOURCE_STALE,
			Report_Snapshot_Failure::SOURCE_SCHEMA_UNSUPPORTED,
			Report_Snapshot_Failure::IDEMPOTENCY_CONFLICT => 409,
			Report_Snapshot_Failure::TOO_LARGE => 422,
			default => 503,
		};
		$message = match ( $status ) {
			409 => '보고서를 새로 불러온 뒤 다시 만들어 주세요.',
			422 => '보고서 범위를 줄여 주세요.',
			default => '지금은 보고서를 만들 수 없습니다.',
		};
		return $this->envelopes->problem( Rest_Problem::operation( $status, $failure->reason(), $message, $correlation ) );
	}

	/**
	 * Problem.
	 *
	 * @param int    $status Status.
	 * @param string $correlation Correlation.
	 * @return \WP_Error
	 */
	private function problem( int $status, string $correlation ) {
		return $this->envelopes->problem( Rest_Problem::for_status( $status, $correlation ) );
	}
}
