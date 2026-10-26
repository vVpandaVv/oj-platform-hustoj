<?php
/**
 * C34 student submission evaluation teacher review REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Presentation;

use InvalidArgumentException;
use LogicException;
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
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C34 student submission evaluation teacher review REST controller.
 */
final class Submission_Evaluation_Review_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_REVIEW   = '/submission-evaluations/(?P<evaluation_id>[0-9A-HJKMNP-TV-Z]{26})/commands/review';
	public const ACTION_REVIEW  = 'submission.evaluation.review';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize submission evaluation review rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff      $permissions Permissions.
	 * @param Review_Submission_Evaluation $review Review.
	 * @param Canonical_Json               $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory        $envelopes Envelopes.
	 * @param Correlation_Id_Factory       $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Review_Submission_Evaluation $review,
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
			throw new LogicException( 'Submission evaluation review REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_REVIEW,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'review' ),
				'permission_callback' => array( $this, 'permissions_review' ),
			)
		);
	}

	/**
	 * Permissions review.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_review( object $request ) {
		try {
			$evaluation_id = $this->path_id( $request );
			$context       = $this->context_query( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_REVIEW,
			array( Addon_Capabilities::REVIEW_ASSESSMENTS ),
			new Context_Scope(
				$context['context_type'],
				$context['context_id'],
				Context_Scope::OPERATION_EVALUATE,
				null,
				$context['context_revision']
			),
			new Object_Scope( Object_Scope::TYPE_EVALUATION, $evaluation_id, 'review' )
		);
	}

	/**
	 * Review.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function review( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$authorization  = $grant->authorization();
		$correlation_id = $authorization->correlation_id();
		$idempotency    = $authorization->idempotency_key();
		$decision       = $authorization->policy_decision();
		$context        = $decision->context();
		$object         = $decision->object();
		if (
			null === $idempotency
			|| ! $context instanceof Context_Authorization_Result || ! $context->is_allowed()
			|| ! $object instanceof Object_Authorization_Result || ! $object->is_allowed()
		) {
			return $this->problem( 503, $correlation_id );
		}

		try {
			$evaluation_id = $this->path_id( $request );
			$query         = $this->context_query( $request );
			$input         = $this->input( $request );
			if (
				$query['context_type'] !== $context->type()
				|| $query['context_id'] !== $context->id()
				|| $query['context_revision'] !== $context->revision()
			) {
				throw new InvalidArgumentException( 'Submission evaluation review Context evidence changed.' );
			}
			$result = $this->review->execute(
				new Review_Submission_Evaluation_Command(
					$grant->metadata()->actor_user_id(),
					$evaluation_id,
					$input['command_id'],
					$input['expected_result_id'],
					$input['expected_result_hash'],
					$input['expected_official_review_id'],
					$input['expected_official_review_hash'],
					$input['expected_review_no'],
					$input['decision'],
					$input['item_decisions'],
					$input['reason'],
					$idempotency->fingerprint(),
					$correlation_id,
					(string) $object->current_revision(),
					(string) $object->current_state(),
					(string) $object->actor_relation(),
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Submission_Evaluation_Failure $failure ) {
			return $this->evaluation_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'evaluation'      => array(
					'id'                       => $result->evaluation_id(),
					'submission_id'            => $result->submission_id(),
					'submission_revision_id'   => $result->submission_revision_id(),
					'submission_revision_hash' => $result->submission_revision_hash(),
				),
				'result'          => array(
					'id'   => $result->result_id(),
					'hash' => $result->result_hash(),
				),
				'review'          => array(
					'id'             => $result->review_id(),
					'hash'           => $result->review_hash(),
					'review_no'      => $result->review_no(),
					'decision'       => $result->decision(),
					'official_score' => $result->official_score(),
					'item_decisions' => $result->score_decisions(),
					'reason'         => $result->reason(),
				),
				'official_review' => array(
					'id'       => $result->official_review_id(),
					'hash'     => $result->official_review_hash(),
					'advanced' => $result->official_pointer_advanced(),
				),
			),
			$correlation_id,
			$result->replayed() ? 200 : 201,
			array(
				'idempotent_replay' => $result->replayed(),
				'operation'         => 'review',
				'occurred_at_utc'   => $result->occurred_at_utc(),
			)
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_result_id:string,expected_result_hash:string,expected_official_review_id:?string,expected_official_review_hash:?string,expected_review_no:int,decision:string,item_decisions:array<int,array<string,mixed>>,reason:?string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review JSON body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review sections are invalid.' );
		}
		$this->exact_keys(
			$expected,
			array(
				'expected_official_review_hash',
				'expected_official_review_id',
				'expected_result_hash',
				'expected_result_id',
				'expected_review_no',
			)
		);
		$this->exact_keys( $payload, array( 'decision', 'item_decisions', 'reason' ) );

		$command_id      = $input['command_id'] ?? null;
		$result_id       = $expected['expected_result_id'] ?? null;
		$result_hash     = $expected['expected_result_hash'] ?? null;
		$official_id     = $expected['expected_official_review_id'] ?? null;
		$official_hash   = $expected['expected_official_review_hash'] ?? null;
		$review_no       = $expected['expected_review_no'] ?? null;
		$review_decision = $payload['decision'] ?? null;
		$items           = $payload['item_decisions'] ?? null;
		$reason          = $payload['reason'] ?? null;
		if (
			! is_string( $command_id ) || ! is_string( $result_id ) || ! is_string( $result_hash )
			|| ! is_int( $review_no ) || $review_no < 0
			|| ! is_string( $review_decision ) || ! in_array( $review_decision, array( 'accepted', 'adjusted', 'rejected', 'withdrawn' ), true )
			|| ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > Submission_Score_Input_Builder::MAXIMUM_BINDINGS
			|| ( null !== $reason && ! is_string( $reason ) )
			|| ( null === $official_id ) !== ( null === $official_hash )
		) {
			throw new InvalidArgumentException( 'Submission evaluation review values are invalid.' );
		}
		new Public_Id( $result_id );
		$this->hash( $result_hash );
		if ( null !== $official_id ) {
			if ( ! is_string( $official_id ) || ! is_string( $official_hash ) ) {
				throw new InvalidArgumentException( 'Submission evaluation official review pointer is invalid.' );
			}
			new Public_Id( $official_id );
			$this->hash( $official_hash );
		}
		$item_decisions = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Submission evaluation review item is invalid.' );
			}
			$this->exact_keys( $item, array( 'binding_key', 'source', 'official_points', 'reason_code', 'reason_note' ) );
			$item_decisions[] = $item;
		}
		return array(
			'command_id'                    => $command_id,
			'expected_result_id'            => $result_id,
			'expected_result_hash'          => $result_hash,
			'expected_official_review_id'   => $official_id,
			'expected_official_review_hash' => $official_hash,
			'expected_review_no'            => $review_no,
			'decision'                      => $review_decision,
			'item_decisions'                => $item_decisions,
			'reason'                        => $reason,
		);
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
			throw new InvalidArgumentException( 'Submission evaluation review Context query is unavailable.' );
		}
		$query = $request->get_query_params();
		if ( ! is_array( $query ) || array_is_list( $query ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review Context query is invalid.' );
		}
		$this->exact_keys( $query, array( 'context_id', 'context_revision', 'context_type' ) );
		foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $key ) {
			if ( ! is_string( $query[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Submission evaluation review Context value is invalid.' );
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
			throw new InvalidArgumentException( 'Submission evaluation review path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['evaluation_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review path ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Hash.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review hash is invalid.' );
		}
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
			throw new InvalidArgumentException( 'Submission evaluation review contains unknown or missing fields.' );
		}
	}

	/**
	 * Evaluation problem.
	 *
	 * @param Submission_Evaluation_Failure $failure Failure.
	 * @param string                        $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function evaluation_problem( Submission_Evaluation_Failure $failure, string $correlation_id ) {
		$definition = match ( $failure->reason() ) {
			Submission_Evaluation_Failure::NOT_FOUND => array( 404, '평가를 찾을 수 없습니다.' ),
			Submission_Evaluation_Failure::REVIEW_SOURCE_STALE,
			Submission_Evaluation_Failure::SOURCE_STALE,
			Submission_Evaluation_Failure::STATE_CONFLICT,
			Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT => array( 409, '평가 결과가 변경되었습니다.' ),
			Submission_Evaluation_Failure::REVIEW_ITEMS_INCOMPLETE => array( 422, '확인할 점수 항목이 남아 있습니다.' ),
			Submission_Evaluation_Failure::REVIEW_SCORE_INVALID => array( 422, '점수를 확인해 주세요.' ),
			Submission_Evaluation_Failure::SAFETY_REVIEW_REQUIRED => array( 422, '확인이 필요한 내용이 있습니다.' ),
			default => array( 503, '지금은 검토를 저장할 수 없습니다.' ),
		};
		$problem = Rest_Problem::operation( $definition[0], $failure->reason(), $definition[1], $correlation_id );
		if ( array() !== $failure->safe_context() ) {
			return $this->envelopes->problem_with_safe_data( $problem, $failure->safe_context() );
		}
		return $this->envelopes->problem( $problem );
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
