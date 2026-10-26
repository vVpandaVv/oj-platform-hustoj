<?php
/**
 * Report export rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Reporting\Report\Application\Read_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Query_Failure;
use OJAI\Dashboard\Reporting\ReportExport\Application\Enqueue_Report_Export;
use OJAI\Dashboard\Reporting\ReportExport\Application\Enqueue_Report_Export_Command;
use OJAI\Dashboard\Reporting\ReportExport\Application\Report_Export_Failure;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A-DS08-C46/C47/C69 exact report snapshot export enqueue boundary.
 */
final class Report_Export_Rest_Controller {
	public const REST_NAMESPACE      = 'oj-ai/v1';
	public const ROUTE_CSV           = '/report-snapshots/(?P<snapshot_id>[0-9A-HJKMNP-TV-Z]{26})/commands/export-csv';
	public const ROUTE_PDF           = '/report-snapshots/(?P<snapshot_id>[0-9A-HJKMNP-TV-Z]{26})/commands/export-pdf';
	public const ROUTE_JSON_PACKAGE  = '/report-snapshots/(?P<snapshot_id>[0-9A-HJKMNP-TV-Z]{26})/commands/export-json-package';
	public const ACTION_CSV          = 'report.export.csv.enqueue';
	public const ACTION_PDF          = 'report.export.pdf.enqueue';
	public const ACTION_JSON_PACKAGE = 'report.export.json_package.enqueue';
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize report export rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff $permissions Permissions.
	 * @param Read_Report_Snapshot    $reports Reports.
	 * @param Enqueue_Report_Export   $enqueue Enqueue.
	 * @param Canonical_Json          $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory   $envelopes Envelopes.
	 * @param Correlation_Id_Factory  $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_Report_Snapshot $reports,
		private Enqueue_Report_Export $enqueue,
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
			throw new LogicException( 'Report export REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_CSV,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'enqueue_csv' ),
				'permission_callback' => array( $this, 'permissions_csv' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_PDF,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'enqueue_pdf' ),
				'permission_callback' => array( $this, 'permissions_pdf' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_JSON_PACKAGE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'enqueue_json_package' ),
				'permission_callback' => array( $this, 'permissions_json_package' ),
			)
		);
	}

	/**
	 * Permissions csv.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_csv( object $request ) {
		return $this->permissions_enqueue( $request, self::ACTION_CSV );
	}
	/**
	 * Permissions pdf.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_pdf( object $request ) {
		return $this->permissions_enqueue( $request, self::ACTION_PDF );
	}
	/**
	 * Permissions json package.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_json_package( object $request ) {
		return $this->permissions_enqueue( $request, self::ACTION_JSON_PACKAGE );
	}
	/**
	 * Enqueue csv.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function enqueue_csv( object $request ) {
		return $this->enqueue_kind( $request, 'report_csv' );
	}
	/**
	 * Enqueue pdf.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function enqueue_pdf( object $request ) {
		return $this->enqueue_kind( $request, 'report_pdf' );
	}
	/**
	 * Enqueue json package.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function enqueue_json_package( object $request ) {
		return $this->enqueue_kind( $request, 'report_json_package' );
	}

	/**
	 * Permissions enqueue.
	 *
	 * @param object $request Request.
	 * @param string $action Action.
	 * @return true|\WP_Error
	 */
	private function permissions_enqueue( object $request, string $action ) {
		$correlation = $this->correlations->create();
		try {
			$this->empty_query( $request );
			$snapshot_id = $this->path_id( $request );
			$scope       = $this->reports->scope( $snapshot_id );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Report_Snapshot_Query_Failure $failure ) {
			return $this->snapshot_problem( $failure, $correlation );
		} catch ( Throwable ) {
				return $this->problem( 503, $correlation );
		}

			return $this->permissions->check(
				$request,
				Access_Policy_Request::SHELL_CONSOLE,
				$action,
				array( Addon_Capabilities::EXPORT_REPORTS ),
				new Context_Scope( $scope->context_type(), $scope->context_id(), Context_Scope::OPERATION_REPORT ),
				new Object_Scope( Object_Scope::TYPE_REPORT, $snapshot_id, 'view' )
			);
	}

	/**
	 * Enqueue kind.
	 *
	 * @param object $request Request.
	 * @param string $artifact_kind Artifact kind.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function enqueue_kind( object $request, string $artifact_kind ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$authorization = $grant->authorization();
		$correlation   = $authorization->correlation_id();
		$idempotency   = $authorization->idempotency_key();
		$decision      = $authorization->policy_decision();
		$context       = $decision->context();
		$object        = $decision->object();
		if (
			null === $idempotency || ! $context instanceof Context_Authorization_Result || ! $context->is_allowed()
			|| ! $object instanceof Object_Authorization_Result || ! $object->is_allowed()
		) {
			return $this->problem( 503, $correlation );
		}

		try {
			$snapshot_id = $this->path_id( $request );
			$input       = $this->input( $request, $artifact_kind );
			$result      = $this->enqueue->execute(
				new Enqueue_Report_Export_Command(
					$grant->metadata()->actor_user_id(),
					$artifact_kind,
					$snapshot_id,
					$input['command_id'],
					$input['expected_snapshot_id'],
					$input['expected_snapshot_hash'],
					$input['expected_snapshot_state'],
					$input['locale'],
					$input['profile'],
					$idempotency->fingerprint(),
					$correlation,
					(string) $object->current_revision(),
					(string) $object->current_state(),
					(string) $object->actor_relation(),
					(string) $context->type(),
					(string) $context->id(),
					(string) $context->revision(),
					(string) $context->actor_relation(),
					$this->canonical
				),
				$context
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Report_Export_Failure $failure ) {
			return $this->export_problem( $failure, $correlation );
		} catch ( Throwable ) {
				return $this->problem( 503, $correlation );
		}

			return $this->envelopes->success(
				array(
					'export_job' => array(
						'id'            => $result->job_id(),
						'state'         => $result->state(),
						'artifact_kind' => $result->artifact_kind(),
						'source_type'   => 'report_snapshot',
						'source_id'     => $result->snapshot_id(),
						'source_hash'   => $result->snapshot_hash(),
					),
				),
				$correlation,
				202,
				array(
					'idempotent_replay' => $result->replayed(),
					'queued_at_utc'     => $result->queued_at_utc(),
				)
			);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @param string $artifact_kind Artifact kind.
	 * @return array{command_id:string,expected_snapshot_id:string,expected_snapshot_hash:string,expected_snapshot_state:string,locale:string,profile:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request, string $artifact_kind ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Report export body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Report export body is invalid.' );
		}
		$this->exact( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Report export sections are invalid.' );
		}
		$this->exact( $expected, array( 'expected_snapshot_hash', 'expected_snapshot_id', 'expected_snapshot_state' ) );
		$this->exact( $payload, array( 'locale', 'profile' ) );
		$result = array(
			'command_id'              => $input['command_id'] ?? null,
			'expected_snapshot_id'    => $expected['expected_snapshot_id'] ?? null,
			'expected_snapshot_hash'  => $expected['expected_snapshot_hash'] ?? null,
			'expected_snapshot_state' => $expected['expected_snapshot_state'] ?? null,
			'locale'                  => $payload['locale'] ?? null,
			'profile'                 => $payload['profile'] ?? null,
		);
		foreach ( $result as $value ) {
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Report export value is invalid.' );
			}
		}
		if (
			! Public_Id::is_valid( $result['expected_snapshot_id'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $result['expected_snapshot_hash'] )
			|| 'ready' !== $result['expected_snapshot_state'] || ! Report_Export_Request_Snapshot::supports( $artifact_kind, $result['profile'] )
		) {
			throw new InvalidArgumentException( 'Report export expected values are invalid.' );
		}
		return $result;
	}

	/**
	 * Path id.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function path_id( object $request ): string {
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new InvalidArgumentException( 'Report export path is unavailable.' );
		} $params = $request->get_url_params();
		$value    = is_array( $params ) ? ( $params['snapshot_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Report export path ID is invalid.' );
		} return $value;
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
			throw new InvalidArgumentException( 'Report export query is unavailable.' );
		} $query = $request->get_query_params();
		if ( ! is_array( $query ) || array() !== $query ) {
			throw new InvalidArgumentException( 'Report export query must be empty.' );
		}
	}
	/**
	 * Exact.
	 *
	 * @param array $value Value.
	 * @param array $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Report export input shape is invalid.' );
		}
	}

	/**
	 * Snapshot problem.
	 *
	 * @param Report_Snapshot_Query_Failure $failure Failure.
	 * @param string                        $correlation Correlation.
	 * @return mixed
	 */
	private function snapshot_problem( Report_Snapshot_Query_Failure $failure, string $correlation ) {
		$status = match ( $failure->reason() ) {
			Report_Snapshot_Query_Failure::NOT_FOUND, Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED => 404, Report_Snapshot_Query_Failure::PROFILE_UNSUPPORTED => 409, default => 503 };
		$message = match ( $status ) {
			404 => '보고서 스냅샷을 찾을 수 없습니다.', 409 => '이 보고서 스냅샷 형식은 현재 지원되지 않습니다.', default => '지금은 보고서 스냅샷을 확인할 수 없습니다.' };
		return $this->envelopes->problem( Rest_Problem::operation( $status, $failure->reason(), $message, $correlation ) );
	}

	/**
	 * Export problem.
	 *
	 * @param Report_Export_Failure $failure Failure.
	 * @param string                $correlation Correlation.
	 * @return mixed
	 */
	private function export_problem( Report_Export_Failure $failure, string $correlation ) {
		$status = match ( $failure->reason() ) {
			Report_Export_Failure::NOT_FOUND, Report_Export_Failure::SOURCE_ACCESS_DENIED => 404,
			Report_Export_Failure::SOURCE_STALE, Report_Export_Failure::PROFILE_UNSUPPORTED, Report_Export_Failure::IDEMPOTENCY_CONFLICT => 409,
			Report_Export_Failure::SOURCE_INVALID, Report_Export_Failure::TOO_LARGE => 422,
			default => 503,
		};
		$message = match ( $status ) {
			404 => '보고서 스냅샷을 찾을 수 없습니다.', 409 => '보고서를 새로 확인한 뒤 다시 시도해 주세요.', 422 => '내보내기 정보를 다시 확인해 주세요.', default => '지금은 보고서를 내보낼 수 없습니다.' };
		return $this->envelopes->problem( Rest_Problem::operation( $status, $failure->reason(), $message, $correlation ) );
	}

	/**
	 * Problem.
	 *
	 * @param int    $status Status.
	 * @param string $correlation Correlation.
	 * @return mixed
	 */
	private function problem( int $status, string $correlation ) {
		return $this->envelopes->problem( Rest_Problem::for_status( $status, $correlation ) );
	}
}
