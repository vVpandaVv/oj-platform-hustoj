<?php
/**
 * C49 teacher submission PDF REST boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Build_Submission_Print_Projection;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Enqueue_Submission_Pdf;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Enqueue_Submission_Pdf_Command;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Submission_Pdf_Failure;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer;
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
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Teacher\Port\Submission_Print_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C49 teacher submission PDF REST boundary.
 */
final class Submission_Pdf_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_ENQUEUE  = '/submission-revisions/(?P<revision_id>[0-9A-HJKMNP-TV-Z]{26})/commands/export-pdf';
	public const ACTION_ENQUEUE = 'submission.pdf.enqueue';
	public const ROUTE_VIEW     = '/submission-revisions/(?P<revision_id>[0-9A-HJKMNP-TV-Z]{26})/view';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize submission pdf rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff                      $permissions Permissions.
	 * @param Enqueue_Submission_Pdf                       $enqueue Enqueue.
	 * @param Submission_Print_Source_Query_Port_Interface $sources Sources.
	 * @param Canonical_Json                               $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory                        $envelopes Envelopes.
	 * @param Correlation_Id_Factory                       $correlations Correlations.
	 * @param Build_Submission_Print_Projection|null       $projections Screen projection builder.
	 * @param Submission_Print_Html_Serializer|null        $html Safe document renderer.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Enqueue_Submission_Pdf $enqueue,
		private Submission_Print_Source_Query_Port_Interface $sources,
		private Canonical_Json $canonical,
		private Rest_Envelope_Factory $envelopes,
		private Correlation_Id_Factory $correlations,
		private ?Build_Submission_Print_Projection $projections = null,
		private ?Submission_Print_Html_Serializer $html = null
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
			throw new LogicException( 'Submission PDF REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_VIEW,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'view' ),
				'permission_callback' => array( $this, 'permissions_view' ),
			)
		);
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
		return $this->permission( $request, false );
	}

	/**
	 * Authorize a read without requiring export or AI permissions.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_view( object $request ) {
		return $this->permission( $request, true );
	}

	/**
	 * Resolve the official revision to its submission authorization boundary.
	 *
	 * @param object $request Request.
	 * @param bool   $view Read-only view.
	 * @return true|\WP_Error
	 */
	private function permission( object $request, bool $view ) {
		try {
			$revision_id = $this->path_id( $request, 'revision_id' );
			$context     = $this->context_query( $request );
			$source      = $this->sources->find_official_revision( $revision_id );
			if ( null === $source ) {
				return $this->problem( 404, $this->correlations->create() );
			}
			$scope = $this->source_scope( $source, $revision_id );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $this->correlations->create() );
		} catch ( Assignment_Failure ) {
			return $this->problem( 503, $this->correlations->create() );
		} catch ( Throwable ) {
			return $this->problem( 503, $this->correlations->create() );
		}

		$context_scope = new Context_Scope(
			$context['context_type'],
			$context['context_id'],
			$view ? Context_Scope::OPERATION_MANAGE : Context_Scope::OPERATION_REPORT,
			null,
			$context['context_revision']
		);
		$object_scope  = new Object_Scope( Object_Scope::TYPE_SUBMISSION, $scope['submission_id'], 'teacher_view' );
		if ( $view ) {
			return $this->permissions->check_any(
				$request,
				Access_Policy_Request::SHELL_CONSOLE,
				'teacher_submission.view',
				array(
					array( Addon_Capabilities::MANAGE_SUBMISSIONS ),
					array( Addon_Capabilities::REVIEW_ASSESSMENTS ),
					array( Addon_Capabilities::ASSESS_SUBMISSIONS ),
				),
				$context_scope,
				$object_scope
			);
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_ENQUEUE,
			array( Addon_Capabilities::EXPORT_REPORTS ),
			$context_scope,
			$object_scope
		);
	}

	/**
	 * Read the same frozen questions and official answers used by PDF export.
	 *
	 * @param object $request Request.
	 * @return mixed
	 */
	public function view( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant || null === $this->projections || null === $this->html ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$authorization = $grant->authorization();
		$correlation   = $authorization->correlation_id();
		$context       = $authorization->policy_decision()->context();
		$object        = $authorization->policy_decision()->object();
		try {
			$revision_id = $this->path_id( $request, 'revision_id' );
			$query       = $this->context_query( $request );
			$source      = $this->sources->find_official_revision( $revision_id );
			if ( null === $source ) {
				return $this->problem( 404, $correlation );
			}
			$projection = $this->projections->build(
				$revision_id,
				(string) $source['official_revision']['content_hash'],
				(string) $source['source']['publication_hash'],
				(string) $source['official_revision']['attachment_set_hash']
			);
			if (
				! $context instanceof Context_Authorization_Result || ! $context->is_allowed()
				|| ! $object instanceof Object_Authorization_Result || ! $object->is_allowed()
				|| $source['id'] !== $projection['submission']['id']
				|| $object->current_state() !== $projection['submission']['state']
				|| $query['context_type'] !== $context->type() || $query['context_id'] !== $context->id()
				|| $query['context_revision'] !== $context->revision()
				|| $projection['context'] !== array(
					'type'     => $context->type(),
					'id'       => $context->id(),
					'revision' => $context->revision(),
				)
			) {
				return $this->problem( 403, $correlation );
			}
			$html     = $this->html->serialize( $projection['print']['projection'], $revision_id, true );
			$response = $this->envelopes->success(
				array(
					'submission_view' => array(
						'revision_id'   => $revision_id,
						'submission_id' => $projection['submission']['id'],
						'html'          => $html,
					),
				),
				$correlation
			);
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( Submission_Pdf_Failure $failure ) {
			return $this->pdf_problem( $failure, $correlation );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}
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
		$authorization    = $grant->authorization();
		$correlation      = $authorization->correlation_id();
		$idempotency      = $authorization->idempotency_key();
		$decision         = $authorization->policy_decision();
		$context_evidence = $decision->context();
		$object_evidence  = $decision->object();
		if (
			null === $idempotency
			|| ! $context_evidence instanceof Context_Authorization_Result || ! $context_evidence->is_allowed()
			|| ! $object_evidence instanceof Object_Authorization_Result || ! $object_evidence->is_allowed()
		) {
			return $this->problem( 503, $correlation );
		}

		try {
			$revision_id = $this->path_id( $request, 'revision_id' );
			$context     = $this->context_query( $request );
			$input       = $this->input( $request );
			$result      = $this->enqueue->execute(
				new Enqueue_Submission_Pdf_Command(
					$grant->metadata()->actor_user_id(),
					$revision_id,
					$input['command_id'],
					$input['expected_revision_id'],
					$input['expected_revision_hash'],
					$input['expected_publication_hash'],
					$input['expected_attachment_set_hash'],
					$input['expected_submission_id'],
					$input['expected_submission_state'],
					$input['expected_submission_lock_version'],
					$context['context_type'],
					$context['context_id'],
					$input['expected_context_revision'],
					$input['profile'],
					$idempotency->fingerprint(),
					$correlation,
					(string) $object_evidence->current_revision(),
					(string) $object_evidence->current_state(),
					(string) $object_evidence->actor_relation(),
					(string) $context_evidence->type(),
					(string) $context_evidence->id(),
					(string) $context_evidence->revision(),
					(string) $context_evidence->actor_relation(),
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Submission_Pdf_Failure $failure ) {
			return $this->pdf_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}

		return $this->envelopes->success(
			array(
				'export_job' => array(
					'id'            => $result->job_id(),
					'state'         => $result->state(),
					'artifact_kind' => 'submission_pdf',
					'source_type'   => 'submission_revision',
					'source_id'     => $result->source_id(),
					'source_hash'   => $result->source_hash(),
					'submission_id' => $result->submission_id(),
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
	 * @return array{command_id:string,expected_revision_id:string,expected_revision_hash:string,expected_publication_hash:string,expected_attachment_set_hash:string,expected_submission_id:string,expected_submission_state:string,expected_submission_lock_version:int,expected_context_revision:string,profile:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Submission PDF body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Submission PDF body is invalid.' );
		}
		$this->exact( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Submission PDF sections are invalid.' );
		}
		$this->exact(
			$expected,
			array(
				'expected_attachment_set_hash',
				'expected_context_revision',
				'expected_publication_hash',
				'expected_revision_hash',
				'expected_revision_id',
				'expected_submission_id',
				'expected_submission_lock_version',
				'expected_submission_state',
			)
		);
		$this->exact( $payload, array( 'profile' ) );
		$result = array(
			'command_id'                       => $input['command_id'] ?? null,
			'expected_revision_id'             => $expected['expected_revision_id'] ?? null,
			'expected_revision_hash'           => $expected['expected_revision_hash'] ?? null,
			'expected_publication_hash'        => $expected['expected_publication_hash'] ?? null,
			'expected_attachment_set_hash'     => $expected['expected_attachment_set_hash'] ?? null,
			'expected_submission_id'           => $expected['expected_submission_id'] ?? null,
			'expected_submission_state'        => $expected['expected_submission_state'] ?? null,
			'expected_submission_lock_version' => $expected['expected_submission_lock_version'] ?? null,
			'expected_context_revision'        => $expected['expected_context_revision'] ?? null,
			'profile'                          => $payload['profile'] ?? null,
		);
		foreach ( array_keys( $result ) as $key ) {
			if ( 'expected_submission_lock_version' === $key ) {
				continue;
			}
			if ( ! is_string( $result[ $key ] ) ) {
				throw new InvalidArgumentException( 'Submission PDF value is invalid.' );
			}
		}
		if ( ! is_int( $result['expected_submission_lock_version'] ) ) {
			throw new InvalidArgumentException( 'Submission PDF lock version is invalid.' );
		}
		new Public_Id( $result['expected_revision_id'] );
		new Public_Id( $result['expected_submission_id'] );
		foreach ( array( 'expected_revision_hash', 'expected_publication_hash', 'expected_attachment_set_hash' ) as $key ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $result[ $key ] ) ) {
				throw new InvalidArgumentException( 'Submission PDF hash is invalid.' );
			}
		}
		if ( Build_Submission_Print_Projection::PROFILE !== $result['profile'] ) {
			throw new InvalidArgumentException( 'Submission PDF profile is invalid.' );
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
			throw new InvalidArgumentException( 'Submission PDF Context query is unavailable.' );
		}
		$params = $request->get_query_params();
		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new InvalidArgumentException( 'Submission PDF Context query is invalid.' );
		}
		$this->exact( $params, array( 'context_id', 'context_revision', 'context_type' ) );
		foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $key ) {
			if ( ! is_string( $params[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Submission PDF Context value is invalid.' );
			}
		}
		return array(
			'context_type'     => $params['context_type'],
			'context_id'       => $params['context_id'],
			'context_revision' => $params['context_revision'],
		);
	}

	/**
	 * Source scope.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param string              $revision_id Revision id.
	 * @return array{submission_id:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_scope( array $source, string $revision_id ): array {
		$official      = $source['official_revision'] ?? null;
		$submission_id = $source['id'] ?? null;
		if (
			! is_array( $official ) || array_is_list( $official )
			|| ! is_string( $submission_id )
			|| ! is_string( $official['id'] ?? null )
			|| ! hash_equals( $revision_id, $official['id'] )
			|| ! hash_equals( $revision_id, (string) ( $source['official_revision_id'] ?? '' ) )
		) {
			throw new InvalidArgumentException( 'Submission PDF source scope is invalid.' );
		}
		new Public_Id( $submission_id );
		return array( 'submission_id' => $submission_id );
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
			throw new InvalidArgumentException( 'Submission PDF input shape is invalid.' );
		}
	}

	/**
	 * Path id.
	 *
	 * @param object $request Request.
	 * @param string $name Name.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function path_id( object $request, string $name ): string {
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new InvalidArgumentException( 'Submission PDF path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params[ $name ] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Submission PDF path ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Pdf problem.
	 *
	 * @param Submission_Pdf_Failure $failure Failure.
	 * @param string                 $correlation Correlation.
	 * @return mixed
	 */
	private function pdf_problem( Submission_Pdf_Failure $failure, string $correlation ) {
		$status = match ( $failure->reason() ) {
			Submission_Pdf_Failure::NOT_FOUND => 404,
			Submission_Pdf_Failure::SOURCE_STALE, Submission_Pdf_Failure::IDEMPOTENCY_CONFLICT => 409,
			Submission_Pdf_Failure::SOURCE_INVALID, Submission_Pdf_Failure::TOO_LARGE => 422,
			Submission_Pdf_Failure::ACCESS_DENIED => 403,
			Submission_Pdf_Failure::EXPIRED => 410,
			Submission_Pdf_Failure::NOT_READY => 409,
			default => 503,
		};
		$message = match ( $status ) {
			403 => '권한을 확인해 주세요.',
			404 => '제출본을 찾을 수 없습니다.',
			409 => '최신 제출 상태를 다시 확인해 주세요.',
			410 => '파일 유효기간이 끝났습니다.',
			422 => '제출 내용과 첨부 파일을 확인해 주세요.',
			default => '지금은 PDF를 만들 수 없습니다.',
		};
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
