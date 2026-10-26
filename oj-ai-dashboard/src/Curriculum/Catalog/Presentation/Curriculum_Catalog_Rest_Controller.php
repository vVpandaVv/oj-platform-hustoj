<?php
/**
 * Curriculum catalog REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Curriculum\Catalog\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Curriculum\Catalog\Application\Curriculum_Catalog_Failure;
use OJAI\Dashboard\Curriculum\Catalog\Application\Manage_Curriculum_Catalog;
use OJAI\Dashboard\Curriculum\Catalog\Domain\Curriculum_Catalog_Profile;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Curriculum catalog REST controller.
 */
final class Curriculum_Catalog_Rest_Controller {
	public const REST_NAMESPACE        = 'oj-ai/v1';
	public const ROUTE_CURRICULUMS     = '/curriculums';
	public const ROUTE_STANDARDS       = '/curriculums/(?P<curriculum_id>[0-9A-HJKMNP-TV-Z]{26})/standards';
	public const ROUTE_CURRICULUM_SAVE = '/curriculums/commands/save';
	public const ROUTE_STANDARD_SAVE   = '/curriculums/(?P<curriculum_id>[0-9A-HJKMNP-TV-Z]{26})/standards/commands/save';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize curriculum catalog rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff   $permissions Permissions.
	 * @param Manage_Curriculum_Catalog $catalog Catalog.
	 * @param Rest_Envelope_Factory     $envelopes Envelopes.
	 * @param Correlation_Id_Factory    $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Manage_Curriculum_Catalog $catalog,
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

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_CURRICULUMS,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'curriculums' ),
				'permission_callback' => array( $this, 'permissions_read' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_STANDARDS,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'standards' ),
				'permission_callback' => array( $this, 'permissions_read' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_CURRICULUM_SAVE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_curriculum' ),
				'permission_callback' => array( $this, 'permissions_write' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_STANDARD_SAVE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_standard' ),
				'permission_callback' => array( $this, 'permissions_write' ),
			)
		);
	}

	/**
	 * Permissions read.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_read( object $request ) {
		return $this->permissions->check_any(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			'curriculum_catalog.read',
			array(
				array( Addon_Capabilities::VIEW_WORKSHEETS ),
				array( Addon_Capabilities::MANAGE_WORKSHEETS ),
				array( Addon_Capabilities::MANAGE_POLICIES ),
			)
		);
	}

	/**
	 * Permissions write.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_write( object $request ) {
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			'curriculum_catalog.write',
			array( Addon_Capabilities::MANAGE_POLICIES )
		);
	}

	/**
	 * Curriculums.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function curriculums( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$query = $this->query_input( $request );
			$items = $this->catalog->list_curriculums( $query['search'], $query['limit'] );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Curriculum_Catalog_Failure $failure ) {
			return $this->catalog_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array( 'items' => $items ),
			$correlation_id,
			200,
			array(
				'has_more'    => false,
				'limit'       => $query['limit'],
				'next_cursor' => '',
			),
			array()
		);
	}

	/**
	 * Standards.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function standards( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$query = $this->query_input( $request );
			$items = $this->catalog->list_standards(
				$this->curriculum_id( $request ),
				$query['search'],
				$query['limit']
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Curriculum_Catalog_Failure $failure ) {
			return $this->catalog_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array( 'items' => $items ),
			$correlation_id,
			200,
			array(
				'has_more'    => false,
				'limit'       => $query['limit'],
				'next_cursor' => '',
			),
			array()
		);
	}

	/**
	 * Save curriculum.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_curriculum( object $request ) {
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
			$input  = $this->command_input( $request );
			$result = $this->catalog->save_curriculum(
				$grant->metadata()->actor_user_id(),
				$input['command_id'],
				$input['expected_lock_version'],
				$input['payload'],
				$idempotency->fingerprint(),
				$correlation_id
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Curriculum_Catalog_Failure $failure ) {
			return $this->catalog_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}
		return $this->command_success( $result, 'curriculum', $correlation_id );
	}

	/**
	 * Save standard.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_standard( object $request ) {
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
			$input  = $this->command_input( $request );
			$result = $this->catalog->save_standard(
				$this->curriculum_id( $request ),
				$grant->metadata()->actor_user_id(),
				$input['command_id'],
				$input['expected_lock_version'],
				$input['payload'],
				$idempotency->fingerprint(),
				$correlation_id
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Curriculum_Catalog_Failure $failure ) {
			return $this->catalog_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}
		return $this->command_success( $result, 'achievement_standard', $correlation_id );
	}

	/**
	 * Query input.
	 *
	 * @param object $request Request.
	 * @return array{search:string,limit:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_input( object $request ): array {
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'Catalog query parameters are unavailable.' );
		}
		$params = $request->get_query_params();
		if ( ! is_array( $params ) || array_diff( array_keys( $params ), array( 'limit', 'search' ) ) ) {
			throw new InvalidArgumentException( 'Catalog query parameters are invalid.' );
		}
		$search = $params['search'] ?? '';
		$limit  = $params['limit'] ?? Curriculum_Catalog_Profile::MAX_QUERY_ITEMS;
		if ( ! is_string( $search ) ) {
			throw new InvalidArgumentException( 'Catalog search is invalid.' );
		}
		if ( is_string( $limit ) ) {
			if ( 1 !== preg_match( '/^[1-9][0-9]{0,2}$/D', $limit ) ) {
				throw new InvalidArgumentException( 'Catalog limit is invalid.' );
			}
			$limit = (int) $limit;
		}
		if ( ! is_int( $limit ) || $limit < 1 || $limit > Curriculum_Catalog_Profile::MAX_QUERY_ITEMS ) {
			throw new InvalidArgumentException( 'Catalog limit is invalid.' );
		}
		return array(
			'search' => $search,
			'limit'  => $limit,
		);
	}

	/**
	 * Command input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_lock_version:?int,payload:array<string,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Catalog JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Catalog JSON body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if (
			! is_array( $expected )
			|| array_is_list( $expected )
			|| ! is_array( $payload )
			|| array_is_list( $payload )
		) {
			throw new InvalidArgumentException( 'Catalog command sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_lock_version' ) );
		$command_id   = $input['command_id'] ?? null;
		$lock_version = $expected['expected_lock_version'] ?? null;
		if (
			! is_string( $command_id )
			|| (
				null !== $lock_version
				&& ( ! is_int( $lock_version ) || $lock_version < 0 )
			)
		) {
			throw new InvalidArgumentException( 'Catalog command expectation is invalid.' );
		}
		return array(
			'command_id'            => $command_id,
			'expected_lock_version' => $lock_version,
			'payload'               => $payload,
		);
	}

	/**
	 * Curriculum id.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function curriculum_id( object $request ): string {
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new InvalidArgumentException( 'Catalog path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['curriculum_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Curriculum path is invalid.' );
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
			throw new InvalidArgumentException( 'Catalog contains unknown or missing fields.' );
		}
	}

	/**
	 * Command success.
	 *
	 * @param array  $result Result.
	 * @param string $data_key Data key.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_REST_Response
	 */
	private function command_success(
		array $result,
		string $data_key,
		string $correlation_id
	) {
		$status = 'create' === ( $result['operation'] ?? null ) ? 201 : 200;
		return $this->envelopes->success(
			array( $data_key => $result[ $data_key ] ),
			$correlation_id,
			$status,
			array(
				'idempotent_replay' => true === ( $result['replayed'] ?? false ),
				'operation'         => $result['operation'] ?? null,
				'unchanged'         => true === ( $result['unchanged'] ?? false ),
				'occurred_at_utc'   => $result['occurred_at_utc'] ?? null,
			),
			array()
		);
	}

	/**
	 * Catalog problem.
	 *
	 * @param Curriculum_Catalog_Failure $failure Failure.
	 * @param string                     $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function catalog_problem(
		Curriculum_Catalog_Failure $failure,
		string $correlation_id
	) {
		$definition = match ( $failure->reason() ) {
			Curriculum_Catalog_Failure::VALIDATION_FAILED => array( 422, '중복되거나 올바르지 않은 항목이 있습니다.' ),
			Curriculum_Catalog_Failure::NOT_FOUND => array( 404, '교육과정 또는 성취기준을 찾을 수 없습니다.' ),
			Curriculum_Catalog_Failure::STATE_CONFLICT => array( 409, '내용이 바뀌었습니다. 새로고침해 주세요.' ),
			Curriculum_Catalog_Failure::IDEMPOTENCY_CONFLICT => array( 409, '같은 요청 키의 내용이 다릅니다.' ),
			default => array( 503, '성취기준을 처리하지 못했습니다.' ),
		};
		return $this->envelopes->problem(
			Rest_Problem::operation(
				$definition[0],
				$failure->reason(),
				$definition[1],
				$correlation_id
			)
		);
	}

	/**
	 * Problem.
	 *
	 * @param int    $status Status.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function problem( int $status, string $correlation_id ) {
		return $this->envelopes->problem(
			Rest_Problem::for_status( $status, $correlation_id )
		);
	}
}
