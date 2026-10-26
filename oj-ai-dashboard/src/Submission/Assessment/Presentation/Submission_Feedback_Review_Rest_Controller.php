<?php
/**
 * Frozen A-DS08-C36 teacher feedback review command.
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
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Feedback;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Feedback_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen A-DS08-C36 teacher feedback review command.
 */
final class Submission_Feedback_Review_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_REVIEW   = '/submission-evaluations/(?P<evaluation_id>[0-9A-HJKMNP-TV-Z]{26})/commands/review-feedback';
	public const ACTION_REVIEW  = 'submission.feedback.review';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize submission feedback review rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff    $permissions Permissions.
	 * @param Review_Submission_Feedback $review Review.
	 * @param Canonical_Json             $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory      $envelopes Envelopes.
	 * @param Correlation_Id_Factory     $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Review_Submission_Feedback $review,
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
			throw new LogicException( 'Submission feedback review REST support is unavailable.' );
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
			$id      = $this->path_id( $request );
			$context = $this->context_query( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_REVIEW,
			array( Addon_Capabilities::REVIEW_ASSESSMENTS ),
			new Context_Scope( $context['context_type'], $context['context_id'], Context_Scope::OPERATION_EVALUATE, null, $context['context_revision'] ),
			new Object_Scope( Object_Scope::TYPE_EVALUATION, $id, 'review' )
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
			$evaluation_id = $this->path_id( $request );
			$query         = $this->context_query( $request );
			$input         = $this->input( $request );
			if ( $query['context_type'] !== $context->type() || $query['context_id'] !== $context->id() || $query['context_revision'] !== $context->revision() ) {
				throw new InvalidArgumentException( 'Submission feedback review Context changed.' );
			}
			$result = $this->review->execute(
				new Review_Submission_Feedback_Command(
					$grant->metadata()->actor_user_id(),
					$evaluation_id,
					$input['command_id'],
					$input['expected_official_review_id'],
					$input['expected_official_review_hash'],
					$input['expected_feedback_revision_id'],
					$input['expected_feedback_revision_hash'],
					$input['expected_feedback_revision_no'],
					$input['decision'],
					$input['source_mode'],
					$input['feedback'],
					$input['reason'],
					$idempotency->fingerprint(),
					$correlation,
					(string) $context->type(),
					(string) $context->id(),
					(string) $context->revision(),
					(string) $object->current_revision(),
					(string) $object->current_state(),
					(string) $object->actor_relation(),
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Submission_Evaluation_Failure $failure ) {
			return $this->feedback_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}

		return $this->envelopes->success(
			array(
				'evaluation_id'     => $result->evaluation_id(),
				'official_review'   => array(
					'id'   => $result->official_review_id(),
					'hash' => $result->official_review_hash(),
				),
				'feedback_revision' => array(
					'id'                       => $result->feedback_revision_id(),
					'hash'                     => $result->feedback_revision_hash(),
					'revision_no'              => $result->feedback_revision_no(),
					'state'                    => $result->state(),
					'previous_revision_id'     => $result->previous_revision_id(),
					'source_evaluation_run_id' => $result->source_evaluation_run_id(),
				),
			),
			$correlation,
			201,
			array(
				'idempotent_replay' => $result->replayed(),
				'occurred_at_utc'   => $result->occurred_at_utc(),
			)
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Submission feedback review body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Submission feedback review body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Submission feedback review sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_feedback_revision_hash', 'expected_feedback_revision_id', 'expected_feedback_revision_no', 'expected_official_review_hash', 'expected_official_review_id' ) );
		$this->exact_keys( $payload, array( 'decision', 'feedback', 'reason', 'source_mode' ) );

		$command_id    = $input['command_id'] ?? null;
		$official_id   = $expected['expected_official_review_id'] ?? null;
		$official_hash = $expected['expected_official_review_hash'] ?? null;
		$feedback_id   = $expected['expected_feedback_revision_id'] ?? null;
		$feedback_hash = $expected['expected_feedback_revision_hash'] ?? null;
		$feedback_no   = $expected['expected_feedback_revision_no'] ?? null;
		$decision      = $payload['decision'] ?? null;
		$source_mode   = $payload['source_mode'] ?? null;
		$feedback      = $payload['feedback'] ?? null;
		$reason        = $payload['reason'] ?? null;
		if (
			! is_string( $command_id ) || ! is_string( $official_id )
			|| ! is_string( $official_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $official_hash )
			|| ! is_int( $feedback_no ) || $feedback_no < 0
			|| ( null !== $feedback_id && ! is_string( $feedback_id ) )
			|| ( null !== $feedback_hash && ( ! is_string( $feedback_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $feedback_hash ) ) )
			|| ( null === $feedback_id ) !== ( null === $feedback_hash )
			|| ( null === $feedback_id ) !== ( 0 === $feedback_no )
			|| ! is_string( $decision ) || ! is_string( $source_mode )
			|| ( null !== $feedback && ( ! is_array( $feedback ) || array_is_list( $feedback ) ) )
			|| ( null !== $reason && ! is_string( $reason ) )
		) {
			throw new InvalidArgumentException( 'Submission feedback review values are invalid.' );
		}
		new Public_Id( $official_id );
		if ( null !== $feedback_id ) {
			new Public_Id( $feedback_id );
		}
		return array(
			'command_id'                      => $command_id,
			'expected_official_review_id'     => $official_id,
			'expected_official_review_hash'   => $official_hash,
			'expected_feedback_revision_id'   => $feedback_id,
			'expected_feedback_revision_hash' => $feedback_hash,
			'expected_feedback_revision_no'   => $feedback_no,
			'decision'                        => $decision,
			'source_mode'                     => $source_mode,
			'feedback'                        => $feedback,
			'reason'                          => $reason,
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
			throw new InvalidArgumentException( 'Submission feedback review Context is unavailable.' );
		}
		$query = $request->get_query_params();
		if ( ! is_array( $query ) || array_is_list( $query ) ) {
			throw new InvalidArgumentException( 'Submission feedback review Context is invalid.' );
		}
		$this->exact_keys( $query, array( 'context_id', 'context_revision', 'context_type' ) );
		foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $key ) {
			if ( ! is_string( $query[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Submission feedback review Context value is invalid.' );
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
			throw new InvalidArgumentException( 'Submission feedback review path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['evaluation_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Submission feedback review path ID is invalid.' );
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
			throw new InvalidArgumentException( 'Submission feedback review shape is invalid.' );
		}
	}

	/**
	 * Feedback problem.
	 *
	 * @param Submission_Evaluation_Failure $failure Failure.
	 * @param string                        $correlation Correlation.
	 * @return \WP_Error
	 */
	private function feedback_problem( Submission_Evaluation_Failure $failure, string $correlation ) {
		$definition = match ( $failure->reason() ) {
			Submission_Evaluation_Failure::NOT_FOUND => array( 404, '평가를 찾을 수 없습니다.' ),
			Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE,
			Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
			Submission_Evaluation_Failure::STATE_CONFLICT => array( 409, '피드백이 변경되었습니다. 다시 확인해 주세요.' ),
			Submission_Evaluation_Failure::FEEDBACK_SCHEMA_INVALID,
			Submission_Evaluation_Failure::INPUT_INVALID,
			Submission_Evaluation_Failure::STORED_DATA_INVALID => array( 422, '피드백 내용을 확인해 주세요.' ),
			default => array( 503, '지금은 피드백을 저장할 수 없습니다.' ),
		};
		$problem = Rest_Problem::operation( $definition[0], $failure->reason(), $definition[1], $correlation );
		return array() !== $failure->safe_context()
			? $this->envelopes->problem_with_safe_data( $problem, $failure->safe_context() )
			: $this->envelopes->problem( $problem );
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
