<?php
/**
 * C33 worksheet evaluation teacher review REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Review_Worksheet_Evaluation;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Review_Worksheet_Evaluation_Command;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C33 worksheet evaluation teacher review REST controller.
 */
final class Worksheet_Evaluation_Review_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_REVIEW   = '/worksheet-evaluations/(?P<evaluation_id>[0-9A-HJKMNP-TV-Z]{26})/commands/review';
	public const ACTION_REVIEW  = 'worksheet.evaluation.review';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize worksheet evaluation review rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff     $permissions Permissions.
	 * @param Review_Worksheet_Evaluation $review Review.
	 * @param Canonical_Json              $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory       $envelopes Envelopes.
	 * @param Correlation_Id_Factory      $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Review_Worksheet_Evaluation $review,
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
			throw new LogicException( 'WordPress REST route registration is unavailable.' );
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
			$evaluation_id = $this->path_id( $request ); } catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
			}
			return $this->permissions->check(
				$request,
				Access_Policy_Request::SHELL_CONSOLE,
				self::ACTION_REVIEW,
				array( Addon_Capabilities::REVIEW_ASSESSMENTS ),
				null,
				new Object_Scope( Object_Scope::TYPE_EVALUATION, $evaluation_id, 'edit' )
			);
	}

	/**
	 * Review.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function review( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		$object         = $grant->authorization()->policy_decision()->object();
		if ( null === $idempotency || ! $object instanceof Object_Authorization_Result || ! $object->is_allowed() ) {
			return $this->problem( 503, $correlation_id );
		}

		try {
			$input  = $this->input( $request );
			$result = $this->review->execute(
				new Review_Worksheet_Evaluation_Command(
					$grant->metadata()->actor_user_id(),
					$this->path_id( $request ),
					$input['command_id'],
					$input['expected_state'],
					$input['expected_result_id'],
					$input['expected_review_no'],
					$input['item_decisions'],
					$input['complete_review'],
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
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			return $this->evaluation_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'evaluation' => array(
					'id'                    => $result->evaluation_id(),
					'state'                 => $result->evaluation_state(),
					'worksheet_revision_id' => $result->worksheet_revision_id(),
					'source_hash'           => $result->source_hash(),
				),
				'result_id'  => $result->result_id(),
				'review'     => array(
					'id'             => $result->review_id(),
					'review_no'      => $result->review_no(),
					'decision'       => $result->decision(),
					'item_decisions' => $result->item_decisions(),
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
	 * @return array{command_id:string,expected_state:string,expected_result_id:string,expected_review_no:int,item_decisions:array<int,array<string,mixed>>,complete_review:bool}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review JSON body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_state', 'expected_result_id', 'expected_review_no' ) );
		$this->exact_keys( $payload, array( 'complete_review', 'item_decisions' ) );
		$command_id         = $input['command_id'] ?? null;
		$expected_state     = $expected['expected_state'] ?? null;
		$expected_result_id = $expected['expected_result_id'] ?? null;
		$expected_review_no = $expected['expected_review_no'] ?? null;
		$complete_review    = $payload['complete_review'] ?? null;
		$items              = $payload['item_decisions'] ?? null;
		if (
			! is_string( $command_id ) || ! is_string( $expected_state ) || ! is_string( $expected_result_id )
			|| ! is_int( $expected_review_no ) || $expected_review_no < 0 || ! is_bool( $complete_review )
			|| ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > 100
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation review values are invalid.' );
		}
		new Public_Id( $expected_result_id );
		$item_decisions = array();
		foreach ( $items as $item ) {
			$item_decisions[] = $this->normalize_item( $item );
		}
		return compact(
			'command_id',
			'expected_state',
			'expected_result_id',
			'expected_review_no',
			'item_decisions',
			'complete_review'
		);
	}

	/**
	 * Normalize item.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_item( $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review item is invalid.' );
		}
		$allowed = array( 'finding_key', 'decision', 'reason_code', 'note', 'resolution_revision_id', 'resolution_source_hash' );
		foreach ( array_keys( $value ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Worksheet evaluation review item contains an unknown field.' );
			}
		}
		if ( ! array_key_exists( 'finding_key', $value ) || ! array_key_exists( 'decision', $value ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation review item identity is missing.' );
		}
		return array(
			'finding_key'            => $value['finding_key'],
			'decision'               => $value['decision'],
			'reason_code'            => $value['reason_code'] ?? null,
			'note'                   => $value['note'] ?? null,
			'resolution_revision_id' => $value['resolution_revision_id'] ?? null,
			'resolution_source_hash' => $value['resolution_source_hash'] ?? null,
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
			throw new InvalidArgumentException( 'Worksheet evaluation path parameters are unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['evaluation_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation ID is invalid.' );
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
			throw new InvalidArgumentException( 'Worksheet evaluation review contains unknown or missing fields.' );
		}
	}

	/**
	 * Evaluation problem.
	 *
	 * @param Worksheet_Evaluation_Failure $failure Failure.
	 * @param string                       $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function evaluation_problem( Worksheet_Evaluation_Failure $failure, string $correlation_id ) {
		$definition = match ( $failure->reason() ) {
			Worksheet_Evaluation_Failure::NOT_FOUND => array( 404, '평가를 찾을 수 없습니다.' ),
			Worksheet_Evaluation_Failure::REVIEW_CONFLICT => array( 409, '검토 내용이 바뀌었습니다.' ),
			Worksheet_Evaluation_Failure::REVIEW_INCOMPLETE => array( 422, '확인할 항목이 남아 있습니다.' ),
			Worksheet_Evaluation_Failure::IDEMPOTENCY_CONFLICT => array( 409, '같은 요청 키의 내용이 다릅니다.' ),
			default => array( 503, '지금은 검토를 저장할 수 없습니다.' ),
		};
		$problem = Rest_Problem::operation( $definition[0], $failure->reason(), $definition[1], $correlation_id );
		if ( in_array( $definition[0], array( 409, 422 ), true ) && array() !== $failure->safe_context() ) {
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
