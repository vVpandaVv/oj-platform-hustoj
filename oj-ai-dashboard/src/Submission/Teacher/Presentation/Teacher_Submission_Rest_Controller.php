<?php
/**
 * Teacher submission REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Teacher\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Teacher\Application\Control_Teacher_Submission;
use OJAI\Dashboard\Submission\Teacher\Application\Control_Teacher_Submission_Command;
use OJAI\Dashboard\Submission\Teacher\Application\Read_Teacher_Submissions;
use OJAI\Dashboard\Submission\Teacher\Application\Read_Teacher_Attachment;
use OJAI\Dashboard\Submission\Teacher\Infrastructure\WordPress_Teacher_Learner_Labels;
use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;
use OJAI\Dashboard\Rest\Port\Rest_Nonce_Verifier_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opens frozen Q13, Q14, and Q15 inside the OJ Console authorization shell.
 */
final class Teacher_Submission_Rest_Controller {
	public const REST_NAMESPACE               = 'oj-ai/v1';
	public const ROUTE_ASSIGNMENT_SUBMISSIONS = '/assignments/(?P<assignment_id>[0-9A-HJKMNP-TV-Z]{26})/submissions';
	public const ROUTE_SUBMISSION             = '/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ROUTE_SUBMISSION_REVISIONS   = '/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})/revisions';
	public const ROUTE_SUBMISSION_REOPEN      = '/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})/commands/reopen';
	public const ROUTE_SUBMISSION_LOCK        = '/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})/commands/lock';
	public const ROUTE_ATTACHMENT_CONTENT     = '/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})/attachments/(?P<attachment_id>[0-9A-HJKMNP-TV-Z]{26})/content';
	public const ACTION_LIST                  = 'teacher_submission.list';
	public const ACTION_VIEW                  = 'teacher_submission.view';
	public const ACTION_HISTORY               = 'teacher_submission.history';
	public const ACTION_REOPEN                = 'teacher_submission.reopen';
	public const ACTION_LOCK                  = 'teacher_submission.lock';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;
	/**
	 * File streams.
	 *
	 * @var \WeakMap|null
	 */
	private ?\WeakMap $file_streams = null;

	/**
	 * Initialize teacher submission rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff                 $permissions Permissions.
	 * @param Read_Teacher_Submissions                $submissions Submissions.
	 * @param Control_Teacher_Submission              $control Control.
	 * @param Canonical_Json                          $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory                   $envelopes Envelopes.
	 * @param Correlation_Id_Factory                  $correlations Correlations.
	 * @param Read_Teacher_Attachment|null            $read_files Read files.
	 * @param Current_Actor_Port_Interface|null       $current_actor Current actor.
	 * @param Rest_Nonce_Verifier_Port_Interface|null $file_nonces File nonces.
	 * @param WordPress_Teacher_Learner_Labels|null   $learner_labels Teacher-only account labels.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_Teacher_Submissions $submissions,
		private Control_Teacher_Submission $control,
		private Canonical_Json $canonical,
		private Rest_Envelope_Factory $envelopes,
		private Correlation_Id_Factory $correlations,
		private ?Read_Teacher_Attachment $read_files = null,
		private ?Current_Actor_Port_Interface $current_actor = null,
		private ?Rest_Nonce_Verifier_Port_Interface $file_nonces = null,
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
			self::ROUTE_ASSIGNMENT_SUBMISSIONS,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permissions_index' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_SUBMISSION,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'permissions_show' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_SUBMISSION_REVISIONS,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'history' ),
				'permission_callback' => array( $this, 'permissions_history' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_SUBMISSION_REOPEN,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reopen' ),
				'permission_callback' => array( $this, 'permissions_reopen' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_SUBMISSION_LOCK,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'lock' ),
				'permission_callback' => array( $this, 'permissions_lock' ),
			)
		);
		if ( null !== $this->read_files ) {
			$this->file_streams = new \WeakMap();
			register_rest_route(
				self::REST_NAMESPACE,
				self::ROUTE_ATTACHMENT_CONTENT,
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'attachment_content' ),
					'permission_callback' => array( $this, 'permissions_attachment_content' ),
				)
			);
			add_filter( 'rest_pre_serve_request', array( $this, 'serve_attachment' ), 10, 4 );
		}
	}

	/**
	 * Requires a fresh console/object grant as well as a header-only REST nonce.
	 *
	 * @param object $request Request.
	 * @return mixed
	 */
	public function permissions_attachment_content( object $request ) {
		$this->permissions->take( $request );
		try {
			$this->attachment_arguments( $request );
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( 'cross-site' === $request->get_header( 'Sec-Fetch-Site' ) || ! is_string( $nonce )
				|| null === $this->file_nonces || ! $this->file_nonces->verify( $nonce ) ) {
				return $this->problem( 403, $this->correlations->create() );
			}
			$origin = $request->get_header( 'Origin' );
			if ( null !== $origin && '' !== $origin ) {
				$parts = function_exists( 'rest_url' ) ? parse_url( rest_url( '/' ) ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Native URL parsing preserves the exact absolute-URL validation contract.
				if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || ! is_string( $origin ) ) {
					return $this->problem( 403, $this->correlations->create() );
				}
				$expected = strtolower( $parts['scheme'] . '://' . $parts['host'] );
				$port     = $parts['port'] ?? ( 'https' === $parts['scheme'] ? 443 : 80 );
				if ( ( 'https' === $parts['scheme'] && 443 !== $port ) || ( 'http' === $parts['scheme'] && 80 !== $port ) ) {
					$expected .= ':' . $port;
				}
				if ( ! hash_equals( $expected, $origin ) ) {
					return $this->problem( 403, $this->correlations->create() );
				}
			}
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}
		return $this->permissions_show( $request );
	}

	/**
	 * Attachment arguments.
	 *
	 * @param object $request Request.
	 * @return array{string,string,string,string,string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachment_arguments( object $request ): array {
		if ( 'GET' !== $request->get_method() ) {
			throw new InvalidArgumentException( 'Attachment method is invalid.' );
		}
		$query = $this->context_query( $request, false );
		return array(
			$this->path_id( $request, 'submission_id' ),
			$this->path_id( $request, 'attachment_id' ),
			$query['context_type'],
			$query['context_id'],
			$query['context_revision'],
		);
	}

	/**
	 * Attachment content.
	 *
	 * @param object $request Request.
	 * @return mixed
	 */
	public function attachment_content( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant || null === $this->read_files || null === $this->file_streams || null === $this->current_actor ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation = $grant->authorization()->correlation_id();
		try {
			$actor = $grant->metadata()->actor_user_id();
			if ( $actor !== $this->current_actor->current_actor_user_id() ) {
				return $this->problem( 404, $correlation );
			}
			$allowed = $this->permissions_attachment_content( $request );
			$fresh   = $this->permissions->take( $request );
			if ( true !== $allowed || null === $fresh || $actor !== $fresh->metadata()->actor_user_id() ) {
				return $this->problem( 403, $correlation );
			}
			$args                            = $this->attachment_arguments( $request );
			$content                         = $this->read_files->open( ...$args );
			$response                        = new \WP_REST_Response( null, 200, $content->headers() );
			$this->file_streams[ $response ] = array( $request, $actor, $args, $content );
			return $response;
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}
	}

	/**
	 * Revoke pending bytes if the actor, context, submission binding or file changed.
	 *
	 * @param mixed  $served Served.
	 * @param object $response Response.
	 * @param object $request Request.
	 * @param object $server Server.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function serve_attachment( $served, object $response, object $request, object $server ) {
		if ( null === $this->file_streams || ! isset( $this->file_streams[ $response ] ) ) {
			return $served;
		}
		[ $original, $actor, $args, $content ] = $this->file_streams[ $response ];
		unset( $this->file_streams[ $response ] );
		if ( $served ) {
			$content->close();
			return $served;
		}
		try {
			if ( $request !== $original || $actor !== $this->current_actor?->current_actor_user_id()
				|| $args !== $this->attachment_arguments( $request ) ) {
				throw new LogicException( 'Attachment request changed.' );
			}
			$allowed = $this->permissions_attachment_content( $request );
			$grant   = $this->permissions->take( $request );
			if ( true !== $allowed || null === $grant || $actor !== $grant->metadata()->actor_user_id()
				|| null === $this->read_files || ! $content->matches( $this->read_files->authorize( ...$args ) ) ) {
				throw new LogicException( 'Attachment access changed.' );
			}
		} catch ( Throwable ) {
			$content->close();
			$response->set_status( 404 );
			if ( function_exists( 'status_header' ) ) {
				status_header( 404 );
			}
			$server->send_header( 'Content-Length', '0' );
			$server->send_header( 'Cache-Control', 'private, no-store, max-age=0' );
			return true;
		}
		foreach ( $content->headers() as $name => $value ) {
			$server->send_header( $name, $value );
		}
		$content->emit();
		return true;
	}

	/**
	 * Permissions index.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_index( object $request ) {
		try {
			$query         = $this->context_query( $request, true );
			$assignment_id = $this->path_id( $request, 'assignment_id' );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_LIST,
			array( Addon_Capabilities::MANAGE_SUBMISSIONS ),
			new Context_Scope( $query['context_type'], $query['context_id'], Context_Scope::OPERATION_MANAGE, null, $query['context_revision'] ),
			new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, $assignment_id, 'view_submissions' )
		);
	}

	/**
	 * Permissions show.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_show( object $request ) {
		return $this->submission_permission( $request, self::ACTION_VIEW, 'teacher_view', false );
	}

	/**
	 * Permissions history.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_history( object $request ) {
		return $this->submission_permission( $request, self::ACTION_HISTORY, 'teacher_history', true );
	}

	/**
	 * Permissions reopen.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_reopen( object $request ) {
		return $this->control_permission( $request, Control_Teacher_Submission_Command::REOPEN, self::ACTION_REOPEN );
	}

	/**
	 * Permissions lock.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_lock( object $request ) {
		return $this->control_permission( $request, Control_Teacher_Submission_Command::LOCK, self::ACTION_LOCK );
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
			$query  = $this->context_query( $request, true );
			$result = $this->submissions->list_assignment(
				$this->path_id( $request, 'assignment_id' ),
				$query['context_type'],
				$query['context_id'],
				$query['context_revision'],
				$query['cursor'],
				$query['limit']
			);
			if ( null !== $this->learner_labels ) {
				$result['items'] = array_map( $this->learner_labels->present( ... ), $result['items'] );
			}
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'assignment' => $result['assignment'],
				'items'      => $result['items'],
			),
			$correlation_id,
			200,
			array(
				'next_cursor' => $result['next_cursor'],
				'has_more'    => '' !== $result['next_cursor'],
				'limit'       => $query['limit'],
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
			$query  = $this->context_query( $request, false );
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

		return $this->envelopes->success( array( 'submission' => $result['submission'] ), $correlation_id );
	}

	/**
	 * History.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function history( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$query  = $this->context_query( $request, true );
			$result = $this->submissions->history(
				$this->path_id( $request, 'submission_id' ),
				$query['context_type'],
				$query['context_id'],
				$query['context_revision'],
				$query['cursor'],
				$query['limit']
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'submission' => $result['submission'],
				'revisions'  => $result['revisions'],
			),
			$correlation_id,
			200,
			array(
				'next_cursor' => $result['next_cursor'],
				'has_more'    => '' !== $result['next_cursor'],
				'limit'       => $query['limit'],
			)
		);
	}

	/**
	 * Reopen.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reopen( object $request ) {
		return $this->control_submission( $request, Control_Teacher_Submission_Command::REOPEN );
	}

	/**
	 * Lock.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lock( object $request ) {
		return $this->control_submission( $request, Control_Teacher_Submission_Command::LOCK );
	}

	/**
	 * Submission permission.
	 *
	 * @param object $request Request.
	 * @param string $action_key Action key.
	 * @param string $object_action Object action.
	 * @param bool   $paged Paged.
	 * @return true|\WP_Error
	 */
	private function submission_permission( object $request, string $action_key, string $object_action, bool $paged ) {
		try {
			$query         = $this->context_query( $request, $paged );
			$submission_id = $this->path_id( $request, 'submission_id' );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			$action_key,
			array( Addon_Capabilities::MANAGE_SUBMISSIONS ),
			new Context_Scope( $query['context_type'], $query['context_id'], Context_Scope::OPERATION_MANAGE, null, $query['context_revision'] ),
			new Object_Scope( Object_Scope::TYPE_SUBMISSION, $submission_id, $object_action )
		);
	}

	/**
	 * Control permission.
	 *
	 * @param object $request Request.
	 * @param string $action Action.
	 * @param string $action_key Action key.
	 * @return true|\WP_Error
	 */
	private function control_permission( object $request, string $action, string $action_key ) {
		try {
			$query         = $this->context_query( $request, false );
			$submission_id = $this->path_id( $request, 'submission_id' );
			$input         = $this->control_input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			$action_key,
			array( Addon_Capabilities::MANAGE_SUBMISSIONS ),
			new Context_Scope( $query['context_type'], $query['context_id'], Context_Scope::OPERATION_MANAGE, null, $query['context_revision'] ),
			new Object_Scope(
				Object_Scope::TYPE_SUBMISSION,
				$submission_id,
				$action,
				(string) $input['expected_lock_version'],
				$input['expected_state']
			)
		);
	}

	/**
	 * Control submission.
	 *
	 * @param object $request Request.
	 * @param string $action Action.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function control_submission( object $request, string $action ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 422, $correlation_id );
		}
		try {
			$query  = $this->context_query( $request, false );
			$input  = $this->control_input( $request );
			$result = $this->control->execute(
				new Control_Teacher_Submission_Command(
					$grant->metadata()->actor_user_id(),
					$this->path_id( $request, 'submission_id' ),
					$action,
					$input['command_id'],
					$input['expected_state'],
					$input['expected_lock_version'],
					$input['expected_official_revision_id'],
					$input['expected_official_attempt_no'],
					$input['expected_schedule_version'],
					$input['expected_schedule_hash'],
					$input['expected_publication_hash'],
					$input['reason'],
					$query['context_type'],
					$query['context_id'],
					$query['context_revision'],
					$idempotency->fingerprint(),
					$correlation_id,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'editable_revision' => $result->editable_revision(),
				'receipt'           => $result->receipt(),
				'submission'        => $result->submission(),
			),
			$correlation_id,
			$result->replayed() ? 200 : 201,
			array(
				'idempotent_replay' => $result->replayed(),
				'mutation_count'    => $result->replayed() ? 0 : 1,
			)
		);
	}

	/**
	 * Context query.
	 *
	 * @param object $request Request.
	 * @param bool   $paged Paged.
	 * @return array{context_type:string,context_id:string,context_revision:string,cursor:string,limit:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_query( object $request, bool $paged ): array {
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'Teacher submission query is unavailable.' );
		}
		$params = $request->get_query_params();
		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new InvalidArgumentException( 'Teacher submission query is invalid.' );
		}
		$allowed = $paged
			? array( 'context_id', 'context_revision', 'context_type', 'cursor', 'limit' )
			: array( 'context_id', 'context_revision', 'context_type' );
		$this->allowed_keys( $params, $allowed, array( 'context_id', 'context_revision', 'context_type' ) );
		$type     = $params['context_type'] ?? null;
		$id       = $params['context_id'] ?? null;
		$revision = $params['context_revision'] ?? null;
		$cursor   = $paged ? ( $params['cursor'] ?? '' ) : '';
		$limit    = $paged ? ( $params['limit'] ?? Read_Teacher_Submissions::DEFAULT_LIMIT ) : Read_Teacher_Submissions::DEFAULT_LIMIT;
		if ( is_string( $limit ) && 1 === preg_match( '/^[1-9][0-9]{0,2}$/D', $limit ) ) {
			$limit = (int) $limit;
		}
		if ( ! is_string( $type ) || ! is_string( $id ) || ! is_string( $revision ) || ! is_string( $cursor ) || ! is_int( $limit ) ) {
			throw new InvalidArgumentException( 'Teacher submission query values are invalid.' );
		}
		return array(
			'context_type'     => $type,
			'context_id'       => $id,
			'context_revision' => $revision,
			'cursor'           => $cursor,
			'limit'            => $limit,
		);
	}

	/**
	 * Control input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_lock_version:int,expected_official_revision_id:string,expected_official_attempt_no:int,expected_schedule_version:int,expected_schedule_hash:string,expected_publication_hash:string,reason:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function control_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Teacher submission control body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Teacher submission control body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Teacher submission control sections are invalid.' );
		}
		$this->exact_keys(
			$expected,
			array(
				'expected_lock_version',
				'expected_official_attempt_no',
				'expected_official_revision_id',
				'expected_publication_hash',
				'expected_schedule_hash',
				'expected_schedule_version',
				'expected_state',
			)
		);
		$this->exact_keys( $payload, array( 'reason' ) );
		$result = array(
			'command_id'                    => $input['command_id'] ?? null,
			'expected_state'                => $expected['expected_state'] ?? null,
			'expected_lock_version'         => $expected['expected_lock_version'] ?? null,
			'expected_official_revision_id' => $expected['expected_official_revision_id'] ?? null,
			'expected_official_attempt_no'  => $expected['expected_official_attempt_no'] ?? null,
			'expected_schedule_version'     => $expected['expected_schedule_version'] ?? null,
			'expected_schedule_hash'        => $expected['expected_schedule_hash'] ?? null,
			'expected_publication_hash'     => $expected['expected_publication_hash'] ?? null,
			'reason'                        => $payload['reason'] ?? null,
		);
		foreach ( array( 'command_id', 'expected_state', 'expected_official_revision_id', 'expected_schedule_hash', 'expected_publication_hash', 'reason' ) as $field ) {
			if ( ! is_string( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'Teacher submission control value is invalid.' );
			}
		}
		foreach ( array( 'expected_lock_version', 'expected_official_attempt_no', 'expected_schedule_version' ) as $field ) {
			if ( ! is_int( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'Teacher submission control version is invalid.' );
			}
		}
		return $result;
	}

	/**
	 * Path id.
	 *
	 * @param object $request Request.
	 * @param string $key Key.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function path_id( object $request, string $key ): string {
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new InvalidArgumentException( 'Teacher submission path is unavailable.' );
		}
		$params = $request->get_url_params();
		$id     = is_array( $params ) ? ( $params[ $key ] ?? null ) : null;
		if ( ! is_string( $id ) ) {
			throw new InvalidArgumentException( 'Teacher submission path is invalid.' );
		}
		new Public_Id( $id );
		return $id;
	}

	/**
	 * Allowed keys.
	 *
	 * @param array<array-key,mixed> $value Raw query keys are not validated yet.
	 * @param array<int,string>      $allowed Allowed.
	 * @param array<int,string>      $required Required.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function allowed_keys( array $value, array $allowed, array $required ): void {
		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Teacher submission query key is invalid.' );
			}
		}
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				throw new InvalidArgumentException( 'Teacher submission query key is missing.' );
			}
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
			throw new InvalidArgumentException( 'Teacher submission control keys are invalid.' );
		}
	}

	/**
	 * Assignment problem.
	 *
	 * @param Assignment_Failure $failure Failure.
	 * @param string             $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function assignment_problem( Assignment_Failure $failure, string $correlation_id ) {
		$definition = match ( $failure->reason() ) {
			Assignment_Failure::NOT_FOUND => array( 404, '제출을 찾을 수 없습니다.' ),
			Assignment_Failure::STATE_CONFLICT, Assignment_Failure::IDEMPOTENCY_CONFLICT,
			Assignment_Failure::SUBMISSION_REVISION_CONFLICT, Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL => array( 409, '내용이 바뀌었습니다. 새로고침해 주세요.' ),
			default => array( 503, '제출을 불러오지 못했습니다.' ),
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
