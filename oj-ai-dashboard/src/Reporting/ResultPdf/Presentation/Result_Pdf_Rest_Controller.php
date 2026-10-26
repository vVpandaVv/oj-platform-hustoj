<?php
/**
 * Result pdf rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Build_Result_Print_Projection;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Enqueue_Own_Result_Pdf;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Enqueue_Result_Pdf_Command;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Result_Pdf_Failure;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Result_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A-DS08-C52 learner current-result PDF enqueue boundary.
 */
final class Result_Pdf_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_ENQUEUE  = '/me/results/(?P<release_id>[0-9A-HJKMNP-TV-Z]{26})/commands/export-pdf';
	public const ACTION_ENQUEUE = 'result.pdf.enqueue';
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize result pdf rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff                $permissions Permissions.
	 * @param Current_Actor_Port_Interface           $current_actor Current actor.
	 * @param Submission_Result_Query_Port_Interface $access Access.
	 * @param Enqueue_Own_Result_Pdf                 $enqueue Enqueue.
	 * @param Canonical_Json                         $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory                  $envelopes Envelopes.
	 * @param Correlation_Id_Factory                 $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Current_Actor_Port_Interface $current_actor,
		private Submission_Result_Query_Port_Interface $access,
		private Enqueue_Own_Result_Pdf $enqueue,
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
			throw new LogicException( 'Result PDF REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_ENQUEUE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'enqueue' ),
				'permission_callback' => array( $this, 'permissions_enqueue' ),
			)
		);
	}

	/**
	 * Permissions enqueue.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_enqueue( object $request ) {
		try {
			$this->empty_query( $request );
			$release_id = $this->path_id( $request ); } catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
			}
			$actor_user_id = $this->current_actor->current_actor_user_id();
			if ( $actor_user_id < 1 ) {
				return $this->permissions->check( $request, Access_Policy_Request::SHELL_PUBLIC, self::ACTION_ENQUEUE, array( Addon_Capabilities::PARTICIPATE ) );
			}
			try {
				$snapshot = $this->access->authorization_snapshot( $release_id, $actor_user_id ); } catch ( Throwable ) {
						return $this->problem( 503, $this->correlations->create() );
				}
				if ( null === $snapshot || 'active' !== $snapshot['target_state'] || 'withdrawn' === $snapshot['assignment_state'] ) {
					return $this->problem( 404, $this->correlations->create() );
				}
				return $this->permissions->check(
					$request,
					Access_Policy_Request::SHELL_PUBLIC,
					self::ACTION_ENQUEUE,
					array( Addon_Capabilities::PARTICIPATE ),
					new Context_Scope( $snapshot['context_type'], $snapshot['context_id'], Context_Scope::OPERATION_PARTICIPATE, $actor_user_id, $snapshot['context_revision'] ),
					new Object_Scope( Object_Scope::TYPE_RELEASE, $release_id, 'export_pdf' )
				);
	}

	/**
	 * Enqueue.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function enqueue( object $request ) {
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
		if ( null === $idempotency || ! $context instanceof Context_Authorization_Result || ! $context->is_allowed() || ! $object instanceof Object_Authorization_Result || ! $object->is_allowed() ) {
			return $this->problem( 503, $correlation );
		}
		try {
			$release_id = $this->path_id( $request );
			$input      = $this->input( $request );
			$result     = $this->enqueue->execute(
				new Enqueue_Result_Pdf_Command(
					$grant->metadata()->actor_user_id(),
					$release_id,
					$input['command_id'],
					$input['expected_release_hash'],
					$input['expected_release_state'],
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
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Result_Pdf_Failure $failure ) {
			return $this->pdf_problem( $failure, $correlation );
		} catch ( Throwable ) {
				return $this->problem( 503, $correlation );
		}

			return $this->envelopes->success(
				array(
					'export_job' => array(
						'id'            => $result->job_id(),
						'state'         => $result->state(),
						'artifact_kind' => 'result_pdf',
						'source_type'   => 'submission_release',
						'source_id'     => $result->release_id(),
						'source_hash'   => $result->release_hash(),
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
	 * @return array{command_id:string,expected_release_hash:string,expected_release_state:string,locale:string,profile:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Result PDF body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Result PDF body is invalid.' );
		}
		$this->exact( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Result PDF sections are invalid.' );
		}
		$this->exact( $expected, array( 'expected_release_hash', 'expected_release_state' ) );
		$this->exact( $payload, array( 'locale', 'profile' ) );
		$result = array(
			'command_id'             => $input['command_id'] ?? null,
			'expected_release_hash'  => $expected['expected_release_hash'] ?? null,
			'expected_release_state' => $expected['expected_release_state'] ?? null,
			'locale'                 => $payload['locale'] ?? null,
			'profile'                => $payload['profile'] ?? null,
		);
		foreach ( $result as $value ) {
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Result PDF value is invalid.' );
			}
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $result['expected_release_hash'] ) || ! in_array( $result['expected_release_state'], array( 'released', 'corrected' ), true ) || Build_Result_Print_Projection::PROFILE !== $result['profile'] ) {
			throw new InvalidArgumentException( 'Result PDF expected values are invalid.' );
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
			throw new InvalidArgumentException( 'Result PDF path is unavailable.' );
		} $params = $request->get_url_params();
		$value    = is_array( $params ) ? ( $params['release_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Result PDF path ID is invalid.' );
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
			throw new InvalidArgumentException( 'Result PDF query is unavailable.' );
		} $query = $request->get_query_params();
		if ( ! is_array( $query ) || array() !== $query ) {
			throw new InvalidArgumentException( 'Result PDF query must be empty.' );
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
			throw new InvalidArgumentException( 'Result PDF input shape is invalid.' );
		}
	}
	/**
	 * Pdf problem.
	 *
	 * @param Result_Pdf_Failure $failure Failure.
	 * @param string             $correlation Correlation.
	 * @return mixed
	 */
	private function pdf_problem( Result_Pdf_Failure $failure, string $correlation ) {
		$status = match ( $failure->reason() ) {
			Result_Pdf_Failure::NOT_FOUND => 404, Result_Pdf_Failure::SOURCE_STALE, Result_Pdf_Failure::IDEMPOTENCY_CONFLICT => 409, Result_Pdf_Failure::SOURCE_INVALID, Result_Pdf_Failure::TOO_LARGE => 422, Result_Pdf_Failure::ACCESS_DENIED => 403, default => 503 };
		$message = match ( $status ) {
			403 => '권한을 확인해 주세요.', 404 => '결과를 찾을 수 없습니다.', 409 => '최신 결과를 다시 확인해 주세요.', 422 => '결과 정보를 다시 확인해 주세요.', default => '지금은 PDF를 만들 수 없습니다.' };
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
