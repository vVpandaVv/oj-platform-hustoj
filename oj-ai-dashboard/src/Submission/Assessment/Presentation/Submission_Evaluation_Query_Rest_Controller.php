<?php
/**
 * Student submission evaluation Q16 and Q17 REST controller.
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
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Read_Submission_Evaluations;
use OJAI\Dashboard\Submission\Assessment\Application\Read_Submission_Evaluation_Roster;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Teacher\Application\Read_Teacher_Submissions;
use OJAI\Dashboard\Submission\Teacher\Infrastructure\WordPress_Teacher_Learner_Labels;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student submission evaluation Q16 and Q17 REST controller.
 */
final class Submission_Evaluation_Query_Rest_Controller {
	public const REST_NAMESPACE    = 'oj-ai/v1';
	public const ROUTE_EVALUATIONS = '/submission-evaluations';
	public const ROUTE_EVALUATION  = '/submission-evaluations/(?P<evaluation_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ROUTE_CANDIDATES  = '/submission-evaluation-candidates';
	public const ROUTE_CANDIDATE   = '/submission-evaluation-candidates/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ROUTE_ROSTER      = '/submission-evaluation-roster';

	public const ACTION_LIST           = 'submission.evaluation.list';
	public const ACTION_VIEW           = 'submission.evaluation.view';
	public const ACTION_CANDIDATE_LIST = 'submission.evaluation.candidate.list';
	public const ACTION_CANDIDATE_VIEW = 'submission.evaluation.candidate.view';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize submission evaluation query rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff                $permissions Permissions.
	 * @param Read_Submission_Evaluations            $queries Queries.
	 * @param Read_Teacher_Submissions               $submissions Submissions.
	 * @param Rest_Envelope_Factory                  $envelopes Envelopes.
	 * @param Correlation_Id_Factory                 $correlations Correlations.
	 * @param Read_Submission_Evaluation_Roster|null $roster Complete student status reader.
	 * @param WordPress_Teacher_Learner_Labels|null  $learner_labels Teacher-only account labels.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_Submission_Evaluations $queries,
		private Read_Teacher_Submissions $submissions,
		private Rest_Envelope_Factory $envelopes,
		private Correlation_Id_Factory $correlations,
		private ?Read_Submission_Evaluation_Roster $roster = null,
		private ?WordPress_Teacher_Learner_Labels $learner_labels = null
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
			self::ROUTE_ROSTER,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'roster' ),
				'permission_callback' => array( $this, 'permissions_roster' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_EVALUATIONS,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permissions_list' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_EVALUATION,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'permissions_show' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_CANDIDATES,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'candidates' ),
				'permission_callback' => array( $this, 'permissions_candidates' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_CANDIDATE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'candidate' ),
				'permission_callback' => array( $this, 'permissions_candidate' ),
			)
		);
	}

	/**
	 * Authorize a narrowly scoped teacher-only roster status read.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_roster( object $request ) {
		try {
			$query   = $this->roster_query( $request );
			$context = $this->context_scope( $query );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check_any(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			'submission.evaluation.roster.list',
			array( array( Addon_Capabilities::ASSESS_SUBMISSIONS ), array( Addon_Capabilities::REVIEW_ASSESSMENTS ) ),
			$context,
			new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, $query['assignment_id'], 'view_submissions' )
		);
	}

	/**
	 * Read complete roster status without answer content.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function roster( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant || null === $this->roster ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$query = $this->roster_query( $request );
			$this->assert_granted_context( $grant->authorization()->policy_decision()->context(), $query );
			$data = $this->roster->read( $grant->metadata()->actor_user_id(), $query['assignment_id'], $query['context_type'], $query['context_id'], $query['context_revision'] );
			if ( null !== $this->learner_labels ) {
				$data['items'] = array_map( $this->learner_labels->present( ... ), $data['items'] );
			}
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}
		return $this->envelopes->success(
			$data,
			$correlation_id,
			200,
			array(
				'mutation_count'  => 0,
				'relation_scope'  => 'context_staff',
				'student_visible' => false,
				'server_time_utc' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Parse an exact assignment roster scope.
	 *
	 * @param object $request Request.
	 * @return array{context_type:string,context_id:string,context_revision:string,assignment_id:string}
	 * @throws InvalidArgumentException Rejects missing scope and injected query fields.
	 */
	private function roster_query( object $request ): array {
		$params = $this->query_params( $request );
		$keys   = array( 'context_type', 'context_id', 'context_revision', 'assignment_id' );
		$this->allowed_keys( $params, $keys, $keys );
		foreach ( $keys as $key ) {
			if ( ! is_string( $params[ $key ] ) || '' === $params[ $key ] ) {
				throw new InvalidArgumentException( 'Roster scope is invalid.' );
			}
		}
		new Public_Id( $params['assignment_id'] );
		return array(
			'context_type'     => $params['context_type'],
			'context_id'       => $params['context_id'],
			'context_revision' => $params['context_revision'],
			'assignment_id'    => $params['assignment_id'],
		);
	}

	/**
	 * Permissions candidates.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_candidates( object $request ) {
		try {
			$query   = $this->candidate_query( $request );
			$context = $this->context_scope( $query );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}

		return $this->permissions->check_any(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_CANDIDATE_LIST,
			array( array( Addon_Capabilities::ASSESS_SUBMISSIONS ), array( Addon_Capabilities::REVIEW_ASSESSMENTS ) ),
			$context
		);
	}

	/**
	 * Permissions candidate.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_candidate( object $request ) {
		try {
			$query         = $this->candidate_detail_query( $request );
			$submission_id = $this->path_id( $request, 'submission_id' );
			$context       = $this->context_scope( $query );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $this->correlations->create() );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}

		return $this->permissions->check_any(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_CANDIDATE_VIEW,
			array( array( Addon_Capabilities::ASSESS_SUBMISSIONS ), array( Addon_Capabilities::REVIEW_ASSESSMENTS ) ),
			$context,
			new Object_Scope( Object_Scope::TYPE_SUBMISSION, $submission_id, 'evaluate' )
		);
	}

	/**
	 * Permissions list.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_list( object $request ) {
		try {
			$query   = $this->list_query( $request );
			$context = $this->context_scope( $query );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}

		return $this->permissions->check_any(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_LIST,
			array(
				array( Addon_Capabilities::ASSESS_SUBMISSIONS ),
				array( Addon_Capabilities::REVIEW_ASSESSMENTS ),
			),
			$context
		);
	}

	/**
	 * Permissions show.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_show( object $request ) {
		try {
			$query         = $this->detail_query( $request );
			$evaluation_id = $this->path_id( $request );
			$context       = $this->context_scope( $query );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $this->correlations->create() );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}

		return $this->permissions->check_any(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_VIEW,
			array(
				array( Addon_Capabilities::ASSESS_SUBMISSIONS ),
				array( Addon_Capabilities::REVIEW_ASSESSMENTS ),
			),
			$context,
			new Object_Scope( Object_Scope::TYPE_EVALUATION, $evaluation_id, 'view' )
		);
	}

	/**
	 * Index.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function index( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$query = $this->list_query( $request );
			$this->assert_granted_context( $grant->authorization()->policy_decision()->context(), $query );
			$page = $this->queries->list(
				$grant->metadata()->actor_user_id(),
				$query['context_type'],
				$query['context_id'],
				$query['context_revision'],
				$query['assignment_id'],
				$query['state'],
				$query['cursor'],
				$query['limit']
			);
			if ( null !== $this->learner_labels ) {
				$page['items'] = array_map( $this->learner_labels->present( ... ), $page['items'] );
			}
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Submission_Evaluation_Failure $failure ) {
			return $this->evaluation_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array( 'items' => $page['items'] ),
			$correlation_id,
			200,
			array(
				'next_cursor'    => $page['next_cursor'],
				'has_more'       => '' !== $page['next_cursor'],
				'limit'          => $query['limit'],
				'mutation_count' => 0,
				'relation_scope' => 'context_staff',
			)
		);
	}

	/**
	 * Show.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$query = $this->detail_query( $request );
			$this->assert_granted_context( $grant->authorization()->policy_decision()->context(), $query );
			$result = $this->queries->detail(
				$this->path_id( $request ),
				$query['context_type'],
				$query['context_id'],
				$query['context_revision'],
				$query['result_id']
			);
			if ( null !== $this->learner_labels ) {
				$result['evaluation'] = $this->learner_labels->present( $result['evaluation'] );
			}
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Submission_Evaluation_Failure $failure ) {
			return $this->evaluation_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			$result,
			$correlation_id,
			200,
			array(
				'mutation_count'      => 0,
				'relation_scope'      => 'context_staff',
				'raw_provider_output' => false,
				'raw_prompt'          => false,
			)
		);
	}

	/**
	 * Candidates.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function candidates( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$query = $this->candidate_query( $request );
			$this->assert_granted_context( $grant->authorization()->policy_decision()->context(), $query );
			$page = $this->queries->unevaluated(
				$grant->metadata()->actor_user_id(),
				$query['context_type'],
				$query['context_id'],
				$query['context_revision'],
				$query['assignment_id'],
				$query['cursor'],
				$query['limit']
			);
			if ( null !== $this->learner_labels ) {
				$page['items'] = array_map( $this->learner_labels->present( ... ), $page['items'] );
			}
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Submission_Evaluation_Failure $failure ) {
			return $this->evaluation_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array( 'items' => $page['items'] ),
			$correlation_id,
			200,
			array(
				'next_cursor'    => $page['next_cursor'],
				'has_more'       => '' !== $page['next_cursor'],
				'limit'          => $query['limit'],
				'mutation_count' => 0,
				'relation_scope' => 'context_staff',
			)
		);
	}

	/**
	 * Candidate.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function candidate( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$query = $this->candidate_detail_query( $request );
			$this->assert_granted_context( $grant->authorization()->policy_decision()->context(), $query );
			$result = $this->submissions->detail(
				$this->path_id( $request, 'submission_id' ),
				$query['context_type'],
				$query['context_id'],
				$query['context_revision']
			);
			if ( null !== $this->learner_labels ) {
				$result['submission'] = $this->learner_labels->present( $result['submission'] );
			}
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array( 'submission' => $result['submission'] ),
			$correlation_id,
			200,
			array(
				'mutation_count'      => 0,
				'relation_scope'      => 'context_staff',
				'raw_provider_output' => false,
				'raw_prompt'          => false,
			)
		);
	}

	/**
	 * List query.
	 *
	 * @param object $request Request.
	 * @return array{context_type:string,context_id:string,context_revision:string,assignment_id:string,state:string,cursor:string,limit:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function list_query( object $request ): array {
		$params = $this->query_params( $request );
		$this->allowed_keys(
			$params,
			array( 'context_type', 'context_id', 'context_revision', 'assignment_id', 'state', 'cursor', 'limit' ),
			array( 'context_type', 'context_id', 'context_revision' )
		);
		$type       = $params['context_type'];
		$id         = $params['context_id'];
		$revision   = $params['context_revision'];
		$assignment = $params['assignment_id'] ?? '';
		$state      = $params['state'] ?? '';
		$cursor     = $params['cursor'] ?? '';
		$limit      = $params['limit'] ?? Read_Submission_Evaluations::DEFAULT_LIMIT;
		if ( is_string( $limit ) && 1 === preg_match( '/^[1-9][0-9]{0,2}$/D', $limit ) ) {
			$limit = (int) $limit;
		}
		if ( ! is_string( $type ) || ! is_string( $id ) || ! is_string( $revision ) || ! is_string( $assignment ) || ! is_string( $state ) || ! is_string( $cursor ) || ! is_int( $limit ) ) {
			throw new InvalidArgumentException( 'Submission evaluation list query values are invalid.' );
		}
		return array(
			'context_type'     => $type,
			'context_id'       => $id,
			'context_revision' => $revision,
			'assignment_id'    => $assignment,
			'state'            => $state,
			'cursor'           => $cursor,
			'limit'            => $limit,
		);
	}

	/**
	 * Candidate query.
	 *
	 * @param object $request Request.
	 * @return array{context_type:string,context_id:string,context_revision:string,assignment_id:string,cursor:string,limit:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function candidate_query( object $request ): array {
		$params = $this->query_params( $request );
		$this->allowed_keys(
			$params,
			array( 'context_type', 'context_id', 'context_revision', 'assignment_id', 'cursor', 'limit' ),
			array( 'context_type', 'context_id', 'context_revision' )
		);
		$type       = $params['context_type'];
		$id         = $params['context_id'];
		$revision   = $params['context_revision'];
		$assignment = $params['assignment_id'] ?? '';
		$cursor     = $params['cursor'] ?? '';
		$limit      = $params['limit'] ?? Read_Submission_Evaluations::DEFAULT_LIMIT;
		if ( is_string( $limit ) && 1 === preg_match( '/^[1-9][0-9]{0,2}$/D', $limit ) ) {
			$limit = (int) $limit;
		}
		if ( ! is_string( $type ) || ! is_string( $id ) || ! is_string( $revision ) || ! is_string( $assignment ) || ! is_string( $cursor ) || ! is_int( $limit ) ) {
			throw new InvalidArgumentException( 'Submission evaluation candidate query values are invalid.' );
		}
		return array(
			'context_type'     => $type,
			'context_id'       => $id,
			'context_revision' => $revision,
			'assignment_id'    => $assignment,
			'cursor'           => $cursor,
			'limit'            => $limit,
		);
	}

	/**
	 * Candidate detail query.
	 *
	 * @param object $request Request.
	 * @return array{context_type:string,context_id:string,context_revision:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function candidate_detail_query( object $request ): array {
		$params = $this->query_params( $request );
		$this->allowed_keys(
			$params,
			array( 'context_type', 'context_id', 'context_revision' ),
			array( 'context_type', 'context_id', 'context_revision' )
		);
		foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $key ) {
			if ( ! is_string( $params[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Submission evaluation candidate detail query values are invalid.' );
			}
		}
		return array(
			'context_type'     => $params['context_type'],
			'context_id'       => $params['context_id'],
			'context_revision' => $params['context_revision'],
		);
	}

	/**
	 * Detail query.
	 *
	 * @param object $request Request.
	 * @return array{context_type:string,context_id:string,context_revision:string,result_id:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function detail_query( object $request ): array {
		$params = $this->query_params( $request );
		$this->allowed_keys(
			$params,
			array( 'context_type', 'context_id', 'context_revision', 'result_id' ),
			array( 'context_type', 'context_id', 'context_revision' )
		);
		$type      = $params['context_type'];
		$id        = $params['context_id'];
		$revision  = $params['context_revision'];
		$result_id = $params['result_id'] ?? '';
		if ( ! is_string( $type ) || ! is_string( $id ) || ! is_string( $revision ) || ! is_string( $result_id ) ) {
			throw new InvalidArgumentException( 'Submission evaluation detail query values are invalid.' );
		}
		if ( '' !== $result_id ) {
			new Public_Id( $result_id );
		}
		return array(
			'context_type'     => $type,
			'context_id'       => $id,
			'context_revision' => $revision,
			'result_id'        => $result_id,
		);
	}

	/**
	 * Context scope.
	 *
	 * @param array<string,mixed> $query Query.
	 * @return Context_Scope
	 */
	private function context_scope( array $query ): Context_Scope {
		return new Context_Scope(
			(string) $query['context_type'],
			(string) $query['context_id'],
			Context_Scope::OPERATION_EVALUATE,
			null,
			(string) $query['context_revision']
		);
	}

	/**
	 * Reject invalid granted context.
	 *
	 * @param Context_Authorization_Result|null $context Access or execution context for this operation.
	 * @param array<string,mixed>               $query Query.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_granted_context( ?Context_Authorization_Result $context, array $query ): void {
		if (
			null === $context || ! $context->is_allowed()
			|| $context->type() !== $query['context_type']
			|| $context->id() !== $query['context_id']
			|| $context->revision() !== $query['context_revision']
		) {
			throw new InvalidArgumentException( 'Granted submission evaluation Context drifted.' );
		}
	}

	/**
	 * Query params.
	 *
	 * @param object $request Request.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_params( object $request ): array {
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'Submission evaluation query is unavailable.' );
		}
		$params = $request->get_query_params();
		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new InvalidArgumentException( 'Submission evaluation query is invalid.' );
		}
		return $params;
	}

	/**
	 * Path id.
	 *
	 * @param object $request Request.
	 * @param string $key Key.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function path_id( object $request, string $key = 'evaluation_id' ): string {
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new LogicException( 'Submission evaluation path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params[ $key ] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new LogicException( 'Submission evaluation path ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Allowed keys.
	 *
	 * @param array<string,mixed> $params Params.
	 * @param array<int,string>   $allowed Allowed.
	 * @param array<int,string>   $required Required.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function allowed_keys( array $params, array $allowed, array $required ): void {
		if ( array_diff( array_keys( $params ), $allowed ) || array_diff( $required, array_keys( $params ) ) ) {
			throw new InvalidArgumentException( 'Submission evaluation query contains unknown or missing fields.' );
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
		if ( Submission_Evaluation_Failure::NOT_FOUND === $failure->reason() ) {
			return $this->problem( 404, $correlation_id );
		}
		$problem = Rest_Problem::operation( 503, $failure->reason(), '평가 결과를 불러오지 못했습니다.', $correlation_id );
		if ( array() !== $failure->safe_context() ) {
			return $this->envelopes->problem_with_safe_data( $problem, $failure->safe_context() );
		}
		return $this->envelopes->problem( $problem );
	}

	/**
	 * Assignment problem.
	 *
	 * @param Assignment_Failure $failure Failure.
	 * @param string             $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function assignment_problem( Assignment_Failure $failure, string $correlation_id ) {
		if ( Assignment_Failure::NOT_FOUND === $failure->reason() ) {
			return $this->envelopes->problem(
				Rest_Problem::operation( 404, $failure->reason(), '제출을 찾을 수 없습니다.', $correlation_id )
			);
		}
		return $this->envelopes->problem(
			Rest_Problem::operation( 503, $failure->reason(), '제출을 불러오지 못했습니다.', $correlation_id )
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
		return $this->envelopes->problem( Rest_Problem::for_status( $status, $correlation_id ) );
	}
}
