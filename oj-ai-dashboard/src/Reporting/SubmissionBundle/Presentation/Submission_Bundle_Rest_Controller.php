<?php
/**
 * Submission bundle rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Enqueue_Submission_Bundle;
use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Enqueue_Submission_Bundle_Command;
use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Submission_Bundle_Failure;
use OJAI\Dashboard\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot;
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
 * A-DS08-C50 exact assignment submission bundle enqueue boundary.
 */
final class Submission_Bundle_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE          = '/assignments/(?P<assignment_id>[0-9A-HJKMNP-TV-Z]{26})/commands/export-submissions-zip';
	public const ACTION         = 'assignment.submission_bundle.enqueue';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize submission bundle rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff   $permissions Permissions.
	 * @param Enqueue_Submission_Bundle $enqueue Enqueue.
	 * @param Canonical_Json            $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory     $envelopes Envelopes.
	 * @param Correlation_Id_Factory    $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Enqueue_Submission_Bundle $enqueue,
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
			throw new LogicException( 'Submission bundle REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
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
			$assignment_id = $this->path_id( $request );
			$context       = $this->context_query( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION,
			array( Addon_Capabilities::EXPORT_REPORTS ),
			new Context_Scope(
				$context['context_type'],
				$context['context_id'],
				Context_Scope::OPERATION_REPORT,
				null,
				$context['context_revision']
			),
			new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, $assignment_id, 'export_submissions' )
		);
	}

	/**
	 * Enqueue.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws \Throwable Propagates validation or dependency failures.
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
		if (
			null === $idempotency
			|| ! $context instanceof Context_Authorization_Result || ! $context->is_allowed()
			|| ! $object instanceof Object_Authorization_Result || ! $object->is_allowed()
		) {
			return $this->problem( 503, $correlation );
		}

		try {
			$assignment_id = $this->path_id( $request );
			$query         = $this->context_query( $request );
			$input         = $this->input( $request );
			if (
				$query['context_type'] !== $context->type()
				|| $query['context_id'] !== $context->id()
				|| $query['context_revision'] !== $context->revision()
			) {
				throw new InvalidArgumentException( 'Submission bundle Context evidence changed.' );
			}

			$result = $this->enqueue->execute(
				new Enqueue_Submission_Bundle_Command(
					$grant->metadata()->actor_user_id(),
					$assignment_id,
					$input['command_id'],
					$input['expected_assignment_id'],
					$input['expected_assignment_hash'],
					$input['expected_schedule_version'],
					$input['expected_assignment_state'],
					$input['locale'],
					$input['profile'],
					$input['include_allowed_originals'],
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
		} catch ( Submission_Bundle_Failure $failure ) {
			return $this->bundle_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}

		return $this->envelopes->success(
			array(
				'export_job' => array(
					'id'            => $result->job_id(),
					'state'         => $result->state(),
					'artifact_kind' => Submission_Bundle_Request_Snapshot::ARTIFACT_KIND,
					'source_type'   => 'assignment',
					'source_id'     => $result->assignment_id(),
					'source_hash'   => $result->source_hash(),
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
	 * @return array{command_id:string,expected_assignment_id:string,expected_assignment_hash:string,expected_schedule_version:int,expected_assignment_state:string,locale:string,profile:string,include_allowed_originals:bool}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Submission bundle body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Submission bundle body is invalid.' );
		}
		$this->exact( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Submission bundle sections are invalid.' );
		}
		$this->exact(
			$expected,
			array(
				'expected_assignment_hash',
				'expected_assignment_id',
				'expected_assignment_state',
				'expected_schedule_version',
			)
		);
		$this->exact( $payload, array( 'include_allowed_originals', 'locale', 'profile' ) );
		$result = array(
			'command_id'                => $input['command_id'] ?? null,
			'expected_assignment_id'    => $expected['expected_assignment_id'] ?? null,
			'expected_assignment_hash'  => $expected['expected_assignment_hash'] ?? null,
			'expected_schedule_version' => $expected['expected_schedule_version'] ?? null,
			'expected_assignment_state' => $expected['expected_assignment_state'] ?? null,
			'locale'                    => $payload['locale'] ?? null,
			'profile'                   => $payload['profile'] ?? null,
			'include_allowed_originals' => $payload['include_allowed_originals'] ?? null,
		);
		foreach ( array( 'command_id', 'expected_assignment_id', 'expected_assignment_hash', 'expected_assignment_state', 'locale', 'profile' ) as $key ) {
			if ( ! is_string( $result[ $key ] ) ) {
				throw new InvalidArgumentException( 'Submission bundle value is invalid.' );
			}
		}
		if (
			! is_int( $result['expected_schedule_version'] ) || $result['expected_schedule_version'] < 1
			|| ! is_bool( $result['include_allowed_originals'] )
			|| ! Public_Id::is_valid( $result['expected_assignment_id'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $result['expected_assignment_hash'] )
			|| ! in_array( $result['expected_assignment_state'], array( 'open', 'closed' ), true )
			|| Submission_Bundle_Request_Snapshot::PROFILE !== $result['profile']
			|| 1 !== preg_match( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/D', $result['locale'] )
		) {
			throw new InvalidArgumentException( 'Submission bundle expected values are invalid.' );
		}
		return $result;
	}

	/**
	 * Context query.
	 *
	 * @param object $request Request.
	 * @return array{context_type:string,context_id:string,context_revision:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_query( object $request ): array {
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'Submission bundle Context query is unavailable.' );
		}
		$query = $request->get_query_params();
		if ( ! is_array( $query ) || array_is_list( $query ) ) {
			throw new InvalidArgumentException( 'Submission bundle Context query is invalid.' );
		}
		$this->exact( $query, array( 'context_id', 'context_revision', 'context_type' ) );
		foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $key ) {
			if ( ! is_string( $query[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Submission bundle Context value is invalid.' );
			}
		}
		return array(
			'context_type'     => $query['context_type'],
			'context_id'       => $query['context_id'],
			'context_revision' => $query['context_revision'],
		);
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
			throw new InvalidArgumentException( 'Submission bundle path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['assignment_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Submission bundle path ID is invalid.' );
		}
		return $value;
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
			throw new InvalidArgumentException( 'Submission bundle input shape is invalid.' );
		}
	}

	/**
	 * Bundle problem.
	 *
	 * @param Submission_Bundle_Failure $failure Failure.
	 * @param string                    $correlation Correlation.
	 * @return \WP_Error
	 */
	private function bundle_problem( Submission_Bundle_Failure $failure, string $correlation ) {
		$status = match ( $failure->reason() ) {
			Submission_Bundle_Failure::NOT_FOUND,
			Submission_Bundle_Failure::SOURCE_ACCESS_DENIED => 404,
			Submission_Bundle_Failure::SOURCE_STALE,
			Submission_Bundle_Failure::IDEMPOTENCY_CONFLICT => 409,
			Submission_Bundle_Failure::SOURCE_INVALID,
			Submission_Bundle_Failure::TOO_LARGE => 422,
			default => 503,
		};
		$message = match ( $status ) {
			404 => '배포 또는 제출 결과를 찾을 수 없습니다.',
			409 => '최신 배포 상태를 다시 확인해 주세요.',
			422 => Submission_Bundle_Failure::TOO_LARGE === $failure->reason()
				? '결과물이 너무 많습니다. 범위를 나누어 다시 시도해 주세요.'
				: '내보낼 제출 결과를 확인해 주세요.',
			default => '지금은 제출 결과 파일을 만들 수 없습니다.',
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
