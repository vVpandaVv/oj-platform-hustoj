<?php
/**
 * Q25-Q27 and C55-C60 assessment configuration REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Assessment\Configuration\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Assessment\Configuration\Application\Assessment_Configuration_Failure;
use OJAI\Dashboard\Assessment\Configuration\Application\Manage_Assessment_Configuration;
use OJAI\Dashboard\Assessment\Configuration\Domain\Assessment_Configuration_Profile;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Q25-Q27 and C55-C60 assessment configuration REST controller.
 */
final class Assessment_Configuration_Rest_Controller {
	public const REST_NAMESPACE              = 'oj-ai/v1';
	public const ROUTE_POLICIES              = '/settings/policies';
	public const ROUTE_POLICY_REVISIONS      = '/settings/policies/revisions';
	public const ROUTE_POLICY_ACTIVATE       = '/settings/policies/revisions/(?P<revision_id>[0-9A-HJKMNP-TV-Z]{26})/commands/activate';
	public const ROUTE_RUBRICS               = '/settings/rubrics';
	public const ROUTE_RUBRIC_REVISIONS      = '/settings/rubrics/revisions';
	public const ROUTE_RUBRIC_ACTIVATE       = '/settings/rubrics/revisions/(?P<revision_id>[0-9A-HJKMNP-TV-Z]{26})/commands/activate';
	public const ROUTE_REVIEW_RULES          = '/settings/review-rules';
	public const ROUTE_REVIEW_RULE_REVISIONS = '/settings/review-rules/revisions';
	public const ROUTE_REVIEW_RULE_ACTIVATE  = '/settings/review-rules/revisions/(?P<revision_id>[0-9A-HJKMNP-TV-Z]{26})/commands/activate';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize assessment configuration rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff         $permissions Permissions.
	 * @param Manage_Assessment_Configuration $configuration Configuration.
	 * @param Rest_Envelope_Factory           $envelopes Envelopes.
	 * @param Correlation_Id_Factory          $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Manage_Assessment_Configuration $configuration,
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
			throw new LogicException( 'WordPress REST route registration is unavailable.' );
		}
		$this->registered = true;
		$this->register_resource(
			self::ROUTE_POLICIES,
			self::ROUTE_POLICY_REVISIONS,
			self::ROUTE_POLICY_ACTIVATE,
			'policies',
			'policy'
		);
		$this->register_resource(
			self::ROUTE_RUBRICS,
			self::ROUTE_RUBRIC_REVISIONS,
			self::ROUTE_RUBRIC_ACTIVATE,
			'rubrics',
			'rubric'
		);
		$this->register_resource(
			self::ROUTE_REVIEW_RULES,
			self::ROUTE_REVIEW_RULE_REVISIONS,
			self::ROUTE_REVIEW_RULE_ACTIVATE,
			'review_rules',
			'review_rule'
		);
	}

	/**
	 * Permissions read policy.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_read_policy( object $request ) {
		return $this->permissions->check_any(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			'assessment_configuration.policy.read',
			array(
				array( Addon_Capabilities::MANAGE_POLICIES ),
				array( Addon_Capabilities::MANAGE_WORKSHEETS ),
			)
		);
	}
	/**
	 * Permissions create policy.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_create_policy( object $request ) {
		return $this->permission( $request, 'policy.create' );
	}
	/**
	 * Permissions activate policy.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_activate_policy( object $request ) {
		return $this->permission( $request, 'policy.activate' );
	}
	/**
	 * Permissions read rubric.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_read_rubric( object $request ) {
		return $this->permissions->check_any(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			'assessment_configuration.rubric.read',
			array(
				array( Addon_Capabilities::MANAGE_POLICIES ),
				array( Addon_Capabilities::MANAGE_WORKSHEETS ),
			)
		);
	}
	/**
	 * Permissions create rubric.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_create_rubric( object $request ) {
		return $this->permission( $request, 'rubric.create' );
	}
	/**
	 * Permissions activate rubric.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_activate_rubric( object $request ) {
		return $this->permission( $request, 'rubric.activate' );
	}
	/**
	 * Permissions read review rule.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_read_review_rule( object $request ) {
		return $this->permission( $request, 'review_rule.read' );
	}
	/**
	 * Permissions create review rule.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_create_review_rule( object $request ) {
		return $this->permission( $request, 'review_rule.create' );
	}
	/**
	 * Permissions activate review rule.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_activate_review_rule( object $request ) {
		return $this->permission( $request, 'review_rule.activate' );
	}

	/**
	 * Policies.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function policies( object $request ) {
		return $this->read( $request, Assessment_Configuration_Profile::POLICY, 'evaluation_policies' );
	}
	/**
	 * Rubrics.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rubrics( object $request ) {
		return $this->read( $request, Assessment_Configuration_Profile::RUBRIC, 'rubrics' );
	}
	/**
	 * Review rules.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function review_rules( object $request ) {
		return $this->read( $request, Assessment_Configuration_Profile::REVIEW_RULE, 'review_rules' );
	}

	/**
	 * Create policy.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_policy( object $request ) {
		return $this->create( $request, Assessment_Configuration_Profile::POLICY );
	}
	/**
	 * Create rubric.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_rubric( object $request ) {
		return $this->create( $request, Assessment_Configuration_Profile::RUBRIC );
	}
	/**
	 * Create review rule.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_review_rule( object $request ) {
		return $this->create( $request, Assessment_Configuration_Profile::REVIEW_RULE );
	}

	/**
	 * Activate policy.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function activate_policy( object $request ) {
		return $this->activate( $request, Assessment_Configuration_Profile::POLICY );
	}
	/**
	 * Activate rubric.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function activate_rubric( object $request ) {
		return $this->activate( $request, Assessment_Configuration_Profile::RUBRIC );
	}
	/**
	 * Activate review rule.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function activate_review_rule( object $request ) {
		return $this->activate( $request, Assessment_Configuration_Profile::REVIEW_RULE );
	}

	/**
	 * Register resource.
	 *
	 * @param string $read_route Read route.
	 * @param string $create_route Create route.
	 * @param string $activate_route Activate route.
	 * @param string $read_callback Read callback.
	 * @param string $suffix Suffix.
	 * @return void
	 */
	private function register_resource( string $read_route, string $create_route, string $activate_route, string $read_callback, string $suffix ): void {
		register_rest_route(
			self::REST_NAMESPACE,
			$read_route,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, $read_callback ),
				'permission_callback' => array( $this, 'permissions_read_' . $suffix ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			$create_route,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_' . $suffix ),
				'permission_callback' => array( $this, 'permissions_create_' . $suffix ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			$activate_route,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'activate_' . $suffix ),
				'permission_callback' => array( $this, 'permissions_activate_' . $suffix ),
			)
		);
	}

	/**
	 * Permission.
	 *
	 * @param object $request Request.
	 * @param string $action Action.
	 * @return true|\WP_Error
	 */
	private function permission( object $request, string $action ) {
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			'assessment_configuration.' . $action,
			array( Addon_Capabilities::MANAGE_POLICIES )
		);
	}

	/**
	 * Read assessment configuration rest controller.
	 *
	 * @param object $request Request.
	 * @param string $resource_type Resource type.
	 * @param string $response_key Response key.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function read( object $request, string $resource_type, string $response_key ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$data = $this->configuration->read( $resource_type );
		} catch ( Assessment_Configuration_Failure $failure ) {
			return $this->configuration_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}
		return $this->envelopes->success( array( $response_key => $data ), $correlation_id, 200, array(), array() );
	}

	/**
	 * Create assessment configuration rest controller.
	 *
	 * @param object $request Request.
	 * @param string $resource_type Resource type.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function create( object $request, string $resource_type ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 503, $correlation_id );
		}
		try {
			$input  = $this->create_input( $request );
			$result = $this->configuration->create(
				$resource_type,
				$grant->metadata()->actor_user_id(),
				$input['command_id'],
				$input['expected_latest_revision_no'],
				$input['payload'],
				$idempotency->fingerprint(),
				$correlation_id
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Assessment_Configuration_Failure $failure ) {
			return $this->configuration_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}
		return $this->command_success( $result, $correlation_id, 201 );
	}

	/**
	 * Activate.
	 *
	 * @param object $request Request.
	 * @param string $resource_type Resource type.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function activate( object $request, string $resource_type ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 503, $correlation_id );
		}
		try {
			$input  = $this->activate_input( $request );
			$result = $this->configuration->activate(
				$resource_type,
				$this->revision_id( $request ),
				$grant->metadata()->actor_user_id(),
				$input['command_id'],
				$input['expected_state'],
				$input['expected_lock_version'],
				$input['expected_active_revision_id'],
				$idempotency->fingerprint(),
				$correlation_id
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Assessment_Configuration_Failure $failure ) {
			return $this->configuration_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}
		return $this->command_success( $result, $correlation_id, 200 );
	}

	/**
	 * Create input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_latest_revision_no:int,payload:array<string,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function create_input( object $request ): array {
		$input = $this->json_body( $request );
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Assessment configuration create sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_latest_revision_no' ) );
		$command_id = $input['command_id'] ?? null;
		$latest     = $expected['expected_latest_revision_no'] ?? null;
		if ( ! is_string( $command_id ) || ! is_int( $latest ) || $latest < 0 ) {
			throw new InvalidArgumentException( 'Assessment configuration create expectation is invalid.' );
		}
		return array(
			'command_id'                  => $command_id,
			'expected_latest_revision_no' => $latest,
			'payload'                     => $payload,
		);
	}

	/**
	 * Activate input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_lock_version:int,expected_active_revision_id:?string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function activate_input( object $request ): array {
		$input = $this->json_body( $request );
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'Assessment configuration activation sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_active_revision_id', 'expected_lock_version', 'expected_state' ) );
		$command_id = $input['command_id'] ?? null;
		$state      = $expected['expected_state'] ?? null;
		$lock       = $expected['expected_lock_version'] ?? null;
		$active_id  = $expected['expected_active_revision_id'] ?? null;
		if ( ! is_string( $command_id ) || ! is_string( $state ) || ! is_int( $lock ) || ( null !== $active_id && ! is_string( $active_id ) ) ) {
			throw new InvalidArgumentException( 'Assessment configuration activation expectation is invalid.' );
		}
		return array(
			'command_id'                  => $command_id,
			'expected_state'              => $state,
			'expected_lock_version'       => $lock,
			'expected_active_revision_id' => $active_id,
		);
	}

	/**
	 * Json body.
	 *
	 * @param object $request Request.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function json_body( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Assessment configuration JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Assessment configuration JSON body is invalid.' );
		}
		return $input;
	}

	/**
	 * Revision id.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function revision_id( object $request ): string {
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new InvalidArgumentException( 'Assessment configuration revision path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['revision_id'] ?? null ) : null;
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Assessment configuration revision path is invalid.' );
		}
		return $value;
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Assessment configuration contains unknown or missing fields.' );
		}
	}

	/**
	 * Command success.
	 *
	 * @param array<string,mixed> $result Result.
	 * @param string              $correlation_id Request correlation identifier for diagnostics.
	 * @param int                 $status Status.
	 * @return \WP_REST_Response
	 */
	private function command_success( array $result, string $correlation_id, int $status ) {
		return $this->envelopes->success(
			array( 'configuration_revision' => $result['revision'] ),
			$correlation_id,
			$status,
			array(
				'idempotent_replay'   => true === ( $result['replayed'] ?? false ),
				'operation'           => $result['operation'] ?? null,
				'occurred_at_utc'     => $result['occurred_at_utc'] ?? null,
				'retired_revision_id' => $result['retired_revision_id'] ?? null,
			),
			array()
		);
	}

	/**
	 * Configuration problem.
	 *
	 * @param Assessment_Configuration_Failure $failure Failure.
	 * @param string                           $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function configuration_problem( Assessment_Configuration_Failure $failure, string $correlation_id ) {
		$definition = match ( $failure->reason() ) {
			Assessment_Configuration_Failure::VALIDATION_FAILED => array( 422, '설정 내용을 확인해 주세요.' ),
			Assessment_Configuration_Failure::NOT_FOUND => array( 404, '설정 revision을 찾을 수 없습니다.' ),
			Assessment_Configuration_Failure::STATE_CONFLICT => array( 409, '설정이 바뀌었습니다. 새로고침해 주세요.' ),
			Assessment_Configuration_Failure::IDEMPOTENCY_CONFLICT => array( 409, '같은 요청 키의 내용이 다릅니다.' ),
			default => array( 503, '평가 설정을 처리하지 못했습니다.' ),
		};
		return $this->envelopes->problem( Rest_Problem::operation( $definition[0], $failure->reason(), $definition[1], $correlation_id ) );
	}

	/**
	 * Problem.
	 *
	 * @param int    $status Status.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function problem( int $status, string $correlation_id ) {
		return $this->envelopes->problem( Rest_Problem::for_status( $status, $correlation_id ) );
	}
}
