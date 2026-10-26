<?php
/**
 * Frozen A-DS08-C43 exact first and corrected student result release command.
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
use OJAI\Dashboard\Submission\Assessment\Application\Release_Submission_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Release_Submission_Result_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen A-DS08-C43 exact first and corrected student result release command.
 */
final class Submission_Result_Release_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_RELEASE  = '/submission-evaluations/(?P<evaluation_id>[0-9A-HJKMNP-TV-Z]{26})/commands/release';
	public const ACTION_RELEASE = 'submission.result.release';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize submission result release rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff   $permissions Permissions.
	 * @param Release_Submission_Result $release Release.
	 * @param Canonical_Json            $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory     $envelopes Envelopes.
	 * @param Correlation_Id_Factory    $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Release_Submission_Result $release,
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
			throw new LogicException( 'Submission result release REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_RELEASE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'release' ),
				'permission_callback' => array( $this, 'permissions_release' ),
			)
		);
	}

	/**
	 * Permissions release.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_release( object $request ) {
		try {
			$id      = $this->path_id( $request );
			$context = $this->context_query( $request ); } catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
			}
			return $this->permissions->check(
				$request,
				Access_Policy_Request::SHELL_CONSOLE,
				self::ACTION_RELEASE,
				array( Addon_Capabilities::PUBLISH_FEEDBACK ),
				new Context_Scope( $context['context_type'], $context['context_id'], Context_Scope::OPERATION_EVALUATE, null, $context['context_revision'] ),
				new Object_Scope( Object_Scope::TYPE_EVALUATION, $id, 'release' )
			);
	}

	/**
	 * Release.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function release( object $request ) {
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
			$evaluation_id = $this->path_id( $request );
			$query         = $this->context_query( $request );
			$input         = $this->input( $request );
			if ( $query['context_type'] !== $context->type() || $query['context_id'] !== $context->id() || $query['context_revision'] !== $context->revision() ) {
				throw new InvalidArgumentException( 'Submission result release Context changed.' );
			}
			$result = $this->release->execute(
				new Release_Submission_Result_Command(
					$grant->metadata()->actor_user_id(),
					$evaluation_id,
					$input['command_id'],
					$input['mode'],
					$input['score_visibility'],
					$input['feedback_visibility'],
					$input['expected_official_review_id'],
					$input['expected_official_review_hash'],
					$input['expected_feedback_revision_id'],
					$input['expected_feedback_revision_hash'],
					$input['expected_current_release_id'],
					$input['expected_current_release_hash'],
					$idempotency->fingerprint(),
					$correlation,
					(string) $context->type(),
					(string) $context->id(),
					(string) $context->revision(),
					(string) $object->current_revision(),
					(string) $object->current_state(),
					(string) $object->actor_relation(),
					$this->canonical,
					$input['reason_code'],
					$input['reason_note']
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Submission_Evaluation_Failure $failure ) {
			return $this->release_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}

		return $this->envelopes->success(
			array(
				'evaluation_id'   => $result->evaluation_id(),
				'official_review' => array(
					'hash' => $result->official_review_hash(),
					'id'   => $result->official_review_id(),
				),
				'release'         => array(
					'feedback_revision_id' => $result->feedback_revision_id(),
					'hash'                 => $result->release_hash(),
					'id'                   => $result->release_id(),
					'path'                 => $result->release_path(),
					'projection_hash'      => $result->projection_hash(),
					'release_no'           => $result->release_no(),
					'state'                => $result->state(),
				),
			),
			$correlation,
			201,
			array(
				'idempotent_replay' => $result->replayed(),
				'occurred_at_utc'   => $result->released_at_utc(),
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
			throw new InvalidArgumentException( 'Submission result release body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Submission result release body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Submission result release sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_current_release_hash', 'expected_current_release_id', 'expected_feedback_revision_hash', 'expected_feedback_revision_id', 'expected_official_review_hash', 'expected_official_review_id' ) );
		$mode = $payload['mode'] ?? null;
		if ( ! is_string( $mode ) ) {
			throw new InvalidArgumentException( 'Submission result release mode is invalid.' );
		}
		$this->exact_keys( $payload, 'correction' === $mode ? array( 'feedback_visibility', 'mode', 'reason_code', 'reason_note', 'score_visibility' ) : array( 'feedback_visibility', 'mode', 'score_visibility' ) );

		$command_id    = $input['command_id'] ?? null;
		$official_id   = $expected['expected_official_review_id'] ?? null;
		$official_hash = $expected['expected_official_review_hash'] ?? null;
		$feedback_id   = $expected['expected_feedback_revision_id'] ?? null;
		$feedback_hash = $expected['expected_feedback_revision_hash'] ?? null;
		$current_id    = $expected['expected_current_release_id'] ?? null;
		$current_hash  = $expected['expected_current_release_hash'] ?? null;
		$score         = $payload['score_visibility'] ?? null;
		$feedback      = $payload['feedback_visibility'] ?? null;
		$reason_code   = 'correction' === $mode ? ( $payload['reason_code'] ?? null ) : null;
		$reason_note   = 'correction' === $mode ? ( $payload['reason_note'] ?? null ) : null;
		if ( ! is_string( $command_id ) || ! is_string( $official_id ) || ! is_string( $official_hash ) || ! is_string( $score ) || ! is_string( $feedback ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $official_hash ) ) {
			throw new InvalidArgumentException( 'Submission result release values are invalid.' );
		}
		if ( 'correction' === $mode && ( ! is_string( $reason_code ) || ( null !== $reason_note && ! is_string( $reason_note ) ) ) ) {
			throw new InvalidArgumentException( 'Submission result correction reason is invalid.' );
		}
		new Public_Id( $official_id );
		$this->nullable_pointer( $feedback_id, $feedback_hash );
		$this->nullable_pointer( $current_id, $current_hash );
		return array(
			'command_id'                      => $command_id,
			'expected_current_release_hash'   => $current_hash,
			'expected_current_release_id'     => $current_id,
			'expected_feedback_revision_hash' => $feedback_hash,
			'expected_feedback_revision_id'   => $feedback_id,
			'expected_official_review_hash'   => $official_hash,
			'expected_official_review_id'     => $official_id,
			'feedback_visibility'             => $feedback,
			'mode'                            => $mode,
			'reason_code'                     => $reason_code,
			'reason_note'                     => $reason_note,
			'score_visibility'                => $score,
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
			throw new InvalidArgumentException( 'Submission result release Context is unavailable.' );
		}
		$query = $request->get_query_params();
		if ( ! is_array( $query ) || array_is_list( $query ) ) {
			throw new InvalidArgumentException( 'Submission result release Context is invalid.' );
		}
		$this->exact_keys( $query, array( 'context_id', 'context_revision', 'context_type' ) );
		foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $key ) {
			if ( ! is_string( $query[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Submission result release Context value is invalid.' );
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
			throw new InvalidArgumentException( 'Submission result release path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['evaluation_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Submission result release path ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Nullable pointer.
	 *
	 * @param mixed $id Id.
	 * @param mixed $hash Hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function nullable_pointer( $id, $hash ): void {
		if ( ( null === $id ) !== ( null === $hash ) ) {
			throw new InvalidArgumentException( 'Submission result release pointer is invalid.' );
		}
		if ( null !== $id ) {
			if ( ! is_string( $id ) || ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Submission result release pointer is invalid.' );
			} new Public_Id( $id );
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
			throw new InvalidArgumentException( 'Submission result release shape is invalid.' );
		}
	}

	/**
	 * Release problem.
	 *
	 * @param Submission_Evaluation_Failure $failure Failure.
	 * @param string                        $correlation Correlation.
	 * @return \WP_Error
	 */
	private function release_problem( Submission_Evaluation_Failure $failure, string $correlation ) {
		$definition = match ( $failure->reason() ) {
			Submission_Evaluation_Failure::NOT_FOUND => array( 404, '평가를 찾을 수 없습니다.' ),
			Submission_Evaluation_Failure::RELEASE_SOURCE_STALE,
			Submission_Evaluation_Failure::RELEASE_ALREADY_CURRENT,
			Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
			Submission_Evaluation_Failure::STATE_CONFLICT => array( 409, '공개 상태가 변경되었습니다. 다시 확인해 주세요.' ),
			Submission_Evaluation_Failure::FEEDBACK_NOT_APPROVED => array( 422, '피드백을 먼저 승인해 주세요.' ),
			Submission_Evaluation_Failure::RELEASE_PROJECTION_INVALID,
			Submission_Evaluation_Failure::INPUT_INVALID,
			Submission_Evaluation_Failure::STORED_DATA_INVALID => array( 422, '공개할 내용을 확인해 주세요.' ),
			default => array( 503, '지금은 결과를 공개할 수 없습니다.' ),
		};
		$problem = Rest_Problem::operation( $definition[0], $failure->reason(), $definition[1], $correlation );
		return array() !== $failure->safe_context() ? $this->envelopes->problem_with_safe_data( $problem, $failure->safe_context() ) : $this->envelopes->problem( $problem );
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
