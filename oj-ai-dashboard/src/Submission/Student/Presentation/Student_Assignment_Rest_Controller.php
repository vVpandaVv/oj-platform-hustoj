<?php
/**
 * Student assignment self REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Presentation;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Build_Submission_Print_Projection;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Enqueue_Own_Submission_Pdf;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Enqueue_Own_Submission_Pdf_Command;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Submission_Pdf_Failure;
use LogicException;
use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Enqueue_Own_Worksheet_Pdf;
use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Enqueue_Own_Worksheet_Pdf_Command;
use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Worksheet_Pdf_Failure;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Application\Autosave_Own_Submission;
use OJAI\Dashboard\Submission\Student\Application\Autosave_Own_Submission_Command;
use OJAI\Dashboard\Submission\Student\Application\Read_Own_Submission;
use OJAI\Dashboard\Submission\Student\Application\Read_Student_File_Content;
use OJAI\Dashboard\Submission\Student\Application\Remove_Own_Staged_Attachment;
use OJAI\Dashboard\Submission\Student\Application\Remove_Own_Staged_Attachment_Command;
use OJAI\Dashboard\Submission\Student\Application\Stage_Own_Attachment;
use OJAI\Dashboard\Submission\Student\Application\Stage_Own_Attachment_Command;
use OJAI\Dashboard\Submission\Student\Application\Read_Student_Assignments;
use OJAI\Dashboard\Submission\Student\Application\Start_Own_Submission;
use OJAI\Dashboard\Submission\Student\Application\Start_Own_Submission_Command;
use OJAI\Dashboard\Submission\Student\Application\Submit_Own_Submission;
use OJAI\Dashboard\Submission\Student\Application\Submit_Own_Submission_Command;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Access_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opens Q30, Q31, Q32, and C22 through C26 on a self-only route surface.
 */
final class Student_Assignment_Rest_Controller {
	public const REST_NAMESPACE               = 'oj-ai/v1';
	public const ROUTE_LIST                   = '/me/assignments';
	public const ROUTE_DETAIL                 = '/me/assignments/(?P<assignment_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ROUTE_SUBMISSION             = '/me/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ROUTE_START                  = '/me/assignments/(?P<assignment_id>[0-9A-HJKMNP-TV-Z]{26})/commands/start';
	public const ROUTE_AUTOSAVE               = '/me/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})/commands/autosave';
	public const ROUTE_SUBMIT                 = '/me/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})/commands/submit';
	public const ROUTE_EXPORT_PDF             = '/me/assignments/(?P<assignment_id>[0-9A-HJKMNP-TV-Z]{26})/commands/export-pdf';
	public const ROUTE_SUBMISSION_EXPORT_PDF  = '/me/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})/commands/export-pdf';
	public const ROUTE_ATTACHMENT             = '/me/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})/attachments';
	public const ROUTE_ATTACHMENT_REMOVE      = '/me/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})/attachments/(?P<attachment_id>[0-9A-HJKMNP-TV-Z]{26})/commands/remove';
	public const ROUTE_ASSET_CONTENT          = '/me/assignments/(?P<assignment_id>[0-9A-HJKMNP-TV-Z]{26})/assets/(?P<asset_id>[0-9A-HJKMNP-TV-Z]{26})/content';
	public const ROUTE_ATTACHMENT_CONTENT     = '/me/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})/attachments/(?P<attachment_id>[0-9A-HJKMNP-TV-Z]{26})/content';
	public const ACTION_LIST                  = 'student_assignment.list';
	public const ACTION_VIEW                  = 'student_assignment.view';
	public const ACTION_SUBMISSION_VIEW       = 'student_submission.view';
	public const ACTION_START                 = 'student_assignment.start';
	public const ACTION_AUTOSAVE              = 'student_submission.autosave';
	public const ACTION_SUBMIT                = 'student_submission.submit';
	public const ACTION_EXPORT_PDF            = 'student_assignment.pdf.enqueue';
	public const ACTION_SUBMISSION_EXPORT_PDF = 'student_submission.pdf.enqueue';
	public const ACTION_ATTACHMENT            = 'student_submission.attachment.stage';
	public const ACTION_ATTACHMENT_REMOVE     = 'student_submission.attachment.remove';

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
	 * Initialize student assignment rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff                                           $permissions Permissions.
	 * @param Current_Actor_Port_Interface                                      $current_actor Current actor.
	 * @param Student_Assignment_Access_Port_Interface                          $access Access.
	 * @param Read_Student_Assignments                                          $read_assignments Read assignments.
	 * @param Read_Own_Submission                                               $read_submission Read submission.
	 * @param Start_Own_Submission                                              $start_submission Start submission.
	 * @param Autosave_Own_Submission                                           $autosave_submission Autosave submission.
	 * @param Submit_Own_Submission                                             $submit_submission Submit submission.
	 * @param Stage_Own_Attachment                                              $stage_attachment Stage attachment.
	 * @param Remove_Own_Staged_Attachment                                      $remove_attachment Remove attachment.
	 * @param Canonical_Json                                                    $canonical Canonical JSON encoder used for stable digests.
	 * @param Enqueue_Own_Worksheet_Pdf                                         $enqueue_worksheet_pdf Enqueue worksheet pdf.
	 * @param Enqueue_Own_Submission_Pdf                                        $enqueue_submission_pdf Enqueue submission pdf.
	 * @param Rest_Envelope_Factory                                             $envelopes Envelopes.
	 * @param Correlation_Id_Factory                                            $correlations Correlations.
	 * @param Read_Student_File_Content|null                                    $read_files Read files.
	 * @param \OJAI\Dashboard\Rest\Port\Rest_Nonce_Verifier_Port_Interface|null $file_nonces File nonces.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Current_Actor_Port_Interface $current_actor,
		private Student_Assignment_Access_Port_Interface $access,
		private Read_Student_Assignments $read_assignments,
		private Read_Own_Submission $read_submission,
		private Start_Own_Submission $start_submission,
		private Autosave_Own_Submission $autosave_submission,
		private Submit_Own_Submission $submit_submission,
		private Stage_Own_Attachment $stage_attachment,
		private Remove_Own_Staged_Attachment $remove_attachment,
		private Canonical_Json $canonical,
		private Enqueue_Own_Worksheet_Pdf $enqueue_worksheet_pdf,
		private Enqueue_Own_Submission_Pdf $enqueue_submission_pdf,
		private Rest_Envelope_Factory $envelopes,
		private Correlation_Id_Factory $correlations,
		private ?Read_Student_File_Content $read_files = null,
		private ?\OJAI\Dashboard\Rest\Port\Rest_Nonce_Verifier_Port_Interface $file_nonces = null
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
			self::ROUTE_LIST,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permissions_list' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_DETAIL,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'permissions_show' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_SUBMISSION,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show_submission' ),
				'permission_callback' => array( $this, 'permissions_show_submission' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_START,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'permissions_start' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_EXPORT_PDF,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'export_pdf' ),
				'permission_callback' => array( $this, 'permissions_export_pdf' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_SUBMISSION_EXPORT_PDF,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'export_submission_pdf' ),
				'permission_callback' => array( $this, 'permissions_export_submission_pdf' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_AUTOSAVE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'autosave' ),
				'permission_callback' => array( $this, 'permissions_autosave' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_SUBMIT,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => array( $this, 'permissions_submit' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_ATTACHMENT,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'stage_attachment' ),
				'permission_callback' => array( $this, 'permissions_stage_attachment' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_ATTACHMENT_REMOVE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'remove_attachment' ),
				'permission_callback' => array( $this, 'permissions_remove_attachment' ),
			)
		);
		if ( null !== $this->read_files ) {
			$this->file_streams = new \WeakMap();
			register_rest_route(
				self::REST_NAMESPACE,
				self::ROUTE_ASSET_CONTENT,
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'asset_content' ),
					'permission_callback' => array( $this, 'permissions_asset_content' ),
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				self::ROUTE_ATTACHMENT_CONTENT,
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'attachment_content' ),
					'permission_callback' => array( $this, 'permissions_attachment_content' ),
				)
			);
			add_filter( 'rest_pre_serve_request', array( $this, 'serve_file' ), 10, 4 );
		}
	}

	/**
	 * Permissions asset content.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_asset_content( object $request ) {
		$this->permissions->take( $request );
		try {
			$this->file_path( $request, 'asset' ); } catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
			}
			if ( ! $this->file_nonce_valid( $request ) ) {
				return $this->problem( 403, $this->correlations->create() );
			}
			return $this->permissions_show( $request );
	}

	/**
	 * Permissions attachment content.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_attachment_content( object $request ) {
		$this->permissions->take( $request );
		try {
			$this->file_path( $request, 'attachment' ); } catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
			}
			if ( ! $this->file_nonce_valid( $request ) ) {
				return $this->problem( 403, $this->correlations->create() );
			}
			return $this->permissions_show_submission( $request );
	}

	/**
	 * File nonce valid.
	 *
	 * @param object $request Request.
	 * @return bool
	 */
	private function file_nonce_valid( object $request ): bool {
		try {
			if ( 'cross-site' === $request->get_header( 'Sec-Fetch-Site' ) ) {
				return false;
			}
			$origin = $request->get_header( 'Origin' );
			if ( null !== $origin && '' !== $origin ) {
				if ( ! is_string( $origin ) || ! function_exists( 'rest_url' ) ) {
					return false;
				}
				$parts = parse_url( rest_url( '/' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Native URL parsing preserves the exact absolute-URL validation contract.
				if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
					return false;
				}
				$expected = strtolower( $parts['scheme'] . '://' . $parts['host'] );
				$port     = $parts['port'] ?? ( 'https' === $parts['scheme'] ? 443 : 80 );
				if ( ( 'https' === $parts['scheme'] && 443 !== $port ) || ( 'http' === $parts['scheme'] && 80 !== $port ) ) {
					$expected .= ':' . $port;
				}
				if ( ! hash_equals( $expected, $origin ) ) {
					return false;
				}
			}
			$nonce = $request->get_header( 'X-WP-Nonce' );
			return null !== $this->file_nonces && is_string( $nonce ) && $this->file_nonces->verify( $nonce );
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Asset content.
	 *
	 * @param object $request Request.
	 * @return mixed
	 */
	public function asset_content( object $request ) {
		return $this->file_content( $request, 'asset' );
	}
	/**
	 * Attachment content.
	 *
	 * @param object $request Request.
	 * @return mixed
	 */
	public function attachment_content( object $request ) {
		return $this->file_content( $request, 'attachment' );
	}

	/**
	 * File content.
	 *
	 * @param object $request Request.
	 * @param string $kind Kind.
	 * @return mixed
	 */
	private function file_content( object $request, string $kind ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant || null === $this->read_files || null === $this->file_streams ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation = $grant->authorization()->correlation_id();
		try {
			[ $source_id, $file_id ] = $this->file_path( $request, $kind );
			$actor                   = $grant->metadata()->actor_user_id();
			if ( $actor !== $this->current_actor->current_actor_user_id() ) {
				return $this->problem( 404, $correlation );
			}
			$allowed = 'asset' === $kind ? $this->permissions_asset_content( $request ) : $this->permissions_attachment_content( $request );
			$fresh   = $this->permissions->take( $request );
			if ( true !== $allowed || null === $fresh || $actor !== $fresh->metadata()->actor_user_id() ) {
				return $this->problem( 403, $correlation );
			}
			$content                         = $this->read_files->open( $actor, $kind, $source_id, $file_id );
			$response                        = new \WP_REST_Response( null, 200, $content->headers() );
			$this->file_streams[ $response ] = array( $request, $actor, $kind, $source_id, $file_id, $content );
			return $response;
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}
	}

	/**
	 * Re-check the complete policy and current file binding immediately before the first byte.
	 *
	 * @param mixed  $served Served.
	 * @param object $response Response.
	 * @param object $request Request.
	 * @param object $server Server.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function serve_file( $served, object $response, object $request, object $server ) {
		if ( null === $this->file_streams || ! isset( $this->file_streams[ $response ] ) ) {
			return $served;
		}
		[ $original_request, $actor, $kind, $source_id, $file_id, $content ] = $this->file_streams[ $response ];
		unset( $this->file_streams[ $response ] );
		if ( $served ) {
			$content->close();
			return $served;
		}
		try {
			if ( $request !== $original_request || $actor !== $this->current_actor->current_actor_user_id()
				|| array( $source_id, $file_id ) !== $this->file_path( $request, $kind ) ) {
				throw new LogicException( 'File request changed.' );
			}
			$allowed = 'asset' === $kind ? $this->permissions_asset_content( $request ) : $this->permissions_attachment_content( $request );
			$grant   = $this->permissions->take( $request );
			if ( true !== $allowed || null === $grant || $actor !== $grant->metadata()->actor_user_id()
				|| ! $content->matches( $this->read_files->authorize( $actor, $kind, $source_id, $file_id ) ) ) {
				throw new LogicException( 'File access changed.' );
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
	 * File path.
	 *
	 * @param object $request Request.
	 * @param string $kind Kind.
	 * @return array{string,string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function file_path( object $request, string $kind ): array {
		if ( 'GET' !== $request->get_method() ) {
			throw new InvalidArgumentException( 'File method is invalid.' );
		}
		$this->empty_query( $request );
		$params    = $request->get_url_params();
		$source_id = $params[ 'asset' === $kind ? 'assignment_id' : 'submission_id' ] ?? null;
		$file_id   = $params[ 'asset' === $kind ? 'asset_id' : 'attachment_id' ] ?? null;
		if ( ! is_string( $source_id ) || ! is_string( $file_id ) ) {
			throw new InvalidArgumentException( 'File path is invalid.' );
		}
		new Public_Id( $source_id );
		new Public_Id( $file_id );
		return array( $source_id, $file_id );
	}

	/**
	 * Permissions list.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_list( object $request ) {
		try {
			$this->list_query( $request ); } catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
			}

			return $this->permissions->check(
				$request,
				Access_Policy_Request::SHELL_PUBLIC,
				self::ACTION_LIST,
				array( Addon_Capabilities::PARTICIPATE )
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
			$this->empty_query( $request );
			$assignment_id = $this->path_id( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}

		return $this->permissions_for_assignment( $request, $assignment_id, self::ACTION_VIEW, 'self_view' );
	}

	/**
	 * Permissions show submission.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_show_submission( object $request ) {
		try {
			$this->empty_query( $request );
			$submission_id = $this->submission_path_id( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}

		return $this->permissions_for_submission( $request, $submission_id, self::ACTION_SUBMISSION_VIEW, 'self_view' );
	}

	/**
	 * Permissions start.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_start( object $request ) {
		try {
			$this->empty_query( $request );
			$assignment_id = $this->path_id( $request );
			$input         = $this->start_input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}

		return $this->permissions_for_assignment(
			$request,
			$assignment_id,
			self::ACTION_START,
			'start',
			(string) $input['expected_lock_version'],
			$input['expected_state']
		);
	}

	/**
	 * Permissions export pdf.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_export_pdf( object $request ) {
		try {
			$this->empty_query( $request );
			$assignment_id = $this->path_id( $request );
			$input         = $this->worksheet_pdf_input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}

		return $this->permissions_for_assignment(
			$request,
			$assignment_id,
			self::ACTION_EXPORT_PDF,
			'export_pdf',
			(string) $input['expected_assignment_lock_version'],
			$input['expected_assignment_state'],
			$input['expected_context_revision']
		);
	}

	/**
	 * Permissions export submission pdf.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_export_submission_pdf( object $request ) {
		try {
			$this->empty_query( $request );
			$submission_id = $this->submission_path_id( $request );
			$input         = $this->submission_pdf_input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}

		return $this->permissions_for_submission(
			$request,
			$submission_id,
			self::ACTION_SUBMISSION_EXPORT_PDF,
			'export_pdf',
			(string) $input['expected_submission_lock_version'],
			$input['expected_submission_state'],
			$input['expected_context_revision']
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
			$page  = $this->read_assignments->list( $grant->metadata()->actor_user_id(), $query['cursor'], $query['limit'] );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
				return $this->problem( 503, $correlation_id );
		}

			return $this->envelopes->success(
				array( 'items' => $page['items'] ),
				$correlation_id,
				200,
				array(
					'next_cursor' => $page['next_cursor'],
					'has_more'    => '' !== $page['next_cursor'],
					'limit'       => $query['limit'],
				)
			);
	}

	/**
	 * Permissions autosave.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_autosave( object $request ) {
		try {
			$this->empty_query( $request );
			$submission_id = $this->submission_path_id( $request );
			$input         = $this->autosave_input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}

		return $this->permissions_for_submission(
			$request,
			$submission_id,
			self::ACTION_AUTOSAVE,
			'autosave',
			(string) $input['expected_lock_version'],
			$input['expected_state']
		);
	}

	/**
	 * Permissions submit.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_submit( object $request ) {
		try {
			$this->empty_query( $request );
			$submission_id = $this->submission_path_id( $request );
			$input         = $this->submit_input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}

		return $this->permissions_for_submission(
			$request,
			$submission_id,
			self::ACTION_SUBMIT,
			'submit',
			(string) $input['expected_lock_version'],
			$input['expected_state']
		);
	}

	/**
	 * Permissions stage attachment.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_stage_attachment( object $request ) {
		try {
			$this->empty_query( $request );
			$submission_id = $this->submission_path_id( $request );
			$input         = $this->attachment_input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}

		return $this->permissions_for_submission(
			$request,
			$submission_id,
			self::ACTION_ATTACHMENT,
			'stage_attachment',
			(string) $input['expected_lock_version'],
			$input['expected_state']
		);
	}

	/**
	 * Permissions remove attachment.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_remove_attachment( object $request ) {
		try {
			$this->empty_query( $request );
			$submission_id = $this->submission_path_id( $request );
			$this->attachment_path_id( $request );
			$input = $this->remove_attachment_input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}

		return $this->permissions_for_submission(
			$request,
			$submission_id,
			self::ACTION_ATTACHMENT_REMOVE,
			'remove_attachment',
			(string) $input['expected_lock_version'],
			$input['expected_state']
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
			$result = $this->read_assignments->detail( $grant->metadata()->actor_user_id(), $this->path_id( $request ) );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
				return $this->problem( 503, $correlation_id );
		}

			$assignment = $result['assignment'];
			return $this->envelopes->success(
				array( 'assignment' => $assignment ),
				$correlation_id,
				200,
				array(),
				array( 'self' => $this->detail_url( $assignment['id'] ) ),
				$assignment['actions']
			);
	}

	/**
	 * Show submission.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show_submission( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$result = $this->read_submission->read( $grant->metadata()->actor_user_id(), $this->submission_path_id( $request ) );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
				return $this->problem( 503, $correlation_id );
		}

			$submission = $result['submission'];
			return $this->envelopes->success(
				array( 'submission' => $submission ),
				$correlation_id,
				200,
				array(),
				array(
					'self'       => $this->submission_url( $submission['id'] ),
					'assignment' => $this->detail_url( $submission['assignment_id'] ),
				),
				$submission['actions']
			);
	}

	/**
	 * Start.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start( object $request ) {
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
			$input  = $this->start_input( $request );
			$result = $this->start_submission->execute(
				new Start_Own_Submission_Command(
					$grant->metadata()->actor_user_id(),
					$this->path_id( $request ),
					$input['command_id'],
					$input['expected_state'],
					$input['expected_lock_version'],
					$input['expected_schedule_version'],
					$input['expected_schedule_hash'],
					$input['expected_publication_hash'],
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

			$submission = $result->submission();
			return $this->envelopes->success(
				array( 'submission' => $submission ),
				$correlation_id,
				$result->created() ? 201 : 200,
				array(
					'idempotent_replay' => $result->replayed(),
					'mutation_count'    => $result->created() && ! $result->replayed() ? 1 : 0,
				),
				array( 'assignment' => $this->detail_url( $submission['assignment_id'] ) ),
				$submission['actions']
			);
	}

	/**
	 * Export pdf.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export_pdf( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$authorization    = $grant->authorization();
		$correlation_id   = $authorization->correlation_id();
		$idempotency      = $authorization->idempotency_key();
		$decision         = $authorization->policy_decision();
		$context_evidence = $decision->context();
		$object_evidence  = $decision->object();
		if (
			null === $idempotency
			|| ! $context_evidence instanceof Context_Authorization_Result || ! $context_evidence->is_allowed()
			|| ! $object_evidence instanceof Object_Authorization_Result || ! $object_evidence->is_allowed()
		) {
			return $this->problem( 503, $correlation_id );
		}

		try {
			$assignment_id = $this->path_id( $request );
			$input         = $this->worksheet_pdf_input( $request );
			$result        = $this->enqueue_worksheet_pdf->execute(
				new Enqueue_Own_Worksheet_Pdf_Command(
					$grant->metadata()->actor_user_id(),
					$assignment_id,
					$input['command_id'],
					$input['expected_assignment_lock_version'],
					$input['expected_assignment_state'],
					$input['expected_context_revision'],
					$input['expected_publication_id'],
					$input['expected_publication_hash'],
					$input['expected_target_state'],
					$input['profile'],
					$idempotency->fingerprint(),
					$correlation_id,
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
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Pdf_Failure $failure ) {
			return $this->worksheet_pdf_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
				return $this->problem( 503, $correlation_id );
		}

			return $this->envelopes->success(
				array(
					'export_job' => array(
						'id'            => $result->job_id(),
						'state'         => $result->state(),
						'artifact_kind' => 'worksheet_pdf',
						'source_type'   => 'worksheet_publication',
						'source_id'     => $result->source_id(),
						'source_hash'   => $result->source_hash(),
						'assignment_id' => $assignment_id,
					),
				),
				$correlation_id,
				202,
				array(
					'idempotent_replay' => $result->replayed(),
					'queued_at_utc'     => $result->queued_at_utc(),
				)
			);
	}

	/**
	 * Export submission pdf.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export_submission_pdf( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$authorization    = $grant->authorization();
		$correlation_id   = $authorization->correlation_id();
		$idempotency      = $authorization->idempotency_key();
		$decision         = $authorization->policy_decision();
		$context_evidence = $decision->context();
		$object_evidence  = $decision->object();
		if (
			null === $idempotency
			|| ! $context_evidence instanceof Context_Authorization_Result || ! $context_evidence->is_allowed()
			|| ! $object_evidence instanceof Object_Authorization_Result || ! $object_evidence->is_allowed()
		) {
			return $this->problem( 503, $correlation_id );
		}

		try {
			$submission_id = $this->submission_path_id( $request );
			$input         = $this->submission_pdf_input( $request );
			$result        = $this->enqueue_submission_pdf->execute(
				new Enqueue_Own_Submission_Pdf_Command(
					$grant->metadata()->actor_user_id(),
					$submission_id,
					$input['command_id'],
					$input['expected_assignment_id'],
					$input['expected_assignment_state'],
					$input['expected_official_revision_id'],
					$input['expected_official_revision_hash'],
					$input['expected_attachment_set_hash'],
					$input['expected_publication_id'],
					$input['expected_publication_hash'],
					$input['expected_submission_lock_version'],
					$input['expected_submission_state'],
					$input['expected_context_revision'],
					$input['expected_target_state'],
					$input['profile'],
					$idempotency->fingerprint(),
					$correlation_id,
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
			return $this->problem( 422, $correlation_id );
		} catch ( Submission_Pdf_Failure $failure ) {
			return $this->submission_pdf_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
				return $this->problem( 503, $correlation_id );
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
				$correlation_id,
				202,
				array(
					'idempotent_replay' => $result->replayed(),
					'queued_at_utc'     => $result->queued_at_utc(),
				)
			);
	}

	/**
	 * Autosave.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function autosave( object $request ) {
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
			$input  = $this->autosave_input( $request );
			$result = $this->autosave_submission->execute(
				new Autosave_Own_Submission_Command(
					$grant->metadata()->actor_user_id(),
					$this->submission_path_id( $request ),
					$input['command_id'],
					$input['expected_state'],
					$input['expected_lock_version'],
					$input['expected_current_revision_id'],
					$input['expected_schedule_version'],
					$input['expected_schedule_hash'],
					$input['expected_publication_hash'],
					$input['expected_save_token'],
					$input['answers'],
					$input['staged_attachment_ids'],
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

			$submission = $result->submission();
			return $this->envelopes->success(
				array(
					'revision'   => $result->revision(),
					'submission' => $submission,
				),
				$correlation_id,
				$result->replayed() ? 200 : 201,
				array(
					'idempotent_replay' => $result->replayed(),
					'mutation_count'    => $result->replayed() ? 0 : 1,
				),
				array(
					'self'       => $this->submission_url( $submission['id'] ),
					'assignment' => $this->detail_url( $submission['assignment_id'] ),
				),
				$submission['actions']
			);
	}

	/**
	 * Submit.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit( object $request ) {
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
			$input  = $this->submit_input( $request );
			$result = $this->submit_submission->execute(
				new Submit_Own_Submission_Command(
					$grant->metadata()->actor_user_id(),
					$this->submission_path_id( $request ),
					$input['command_id'],
					$input['expected_state'],
					$input['expected_lock_version'],
					$input['expected_current_revision_id'],
					$input['expected_schedule_version'],
					$input['expected_schedule_hash'],
					$input['expected_publication_hash'],
					$input['expected_save_token'],
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

			$submission = $result->submission();
			return $this->envelopes->success(
				array(
					'receipt'    => $result->receipt(),
					'submission' => $submission,
				),
				$correlation_id,
				$result->replayed() ? 200 : 201,
				array(
					'idempotent_replay' => $result->replayed(),
					'mutation_count'    => $result->replayed() ? 0 : 1,
				),
				array(
					'self'       => $this->submission_url( $submission['id'] ),
					'assignment' => $this->detail_url( $submission['assignment_id'] ),
				),
				$submission['actions']
			);
	}

	/**
	 * Stage attachment.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function stage_attachment( object $request ) {
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
			$input  = $this->attachment_input( $request );
			$result = $this->stage_attachment->execute(
				new Stage_Own_Attachment_Command(
					$grant->metadata()->actor_user_id(),
					$this->submission_path_id( $request ),
					$input['command_id'],
					$input['response_id'],
					$input['role'],
					$input['expected_state'],
					$input['expected_lock_version'],
					$input['expected_current_revision_id'],
					$input['expected_schedule_version'],
					$input['expected_schedule_hash'],
					$input['expected_publication_hash'],
					$input['expected_save_token'],
					$input['file'],
					$idempotency->fingerprint(),
					$correlation_id
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
				array( 'attachment' => $result->attachment() ),
				$correlation_id,
				$result->replayed() ? 200 : 202,
				array(
					'expires_at_utc'    => $result->expires_at_utc(),
					'idempotent_replay' => $result->replayed(),
					'mutation_count'    => $result->replayed() ? 0 : 1,
				),
				array( 'submission' => $this->submission_url( $this->submission_path_id( $request ) ) ),
				array( 'submission.attachment.remove' )
			);
	}

	/**
	 * Remove attachment.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_attachment( object $request ) {
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
			$input  = $this->remove_attachment_input( $request );
			$result = $this->remove_attachment->execute(
				new Remove_Own_Staged_Attachment_Command(
					$grant->metadata()->actor_user_id(),
					$this->submission_path_id( $request ),
					$this->attachment_path_id( $request ),
					$input['command_id'],
					$input['expected_state'],
					$input['expected_lock_version'],
					$input['expected_current_revision_id'],
					$input['expected_schedule_version'],
					$input['expected_schedule_hash'],
					$input['expected_publication_hash'],
					$input['expected_save_token'],
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
				array( 'attachment' => $result->attachment() ),
				$correlation_id,
				200,
				array(
					'idempotent_replay' => $result->replayed(),
					'mutation_count'    => $result->replayed() ? 0 : 1,
					'removed_at_utc'    => $result->removed_at_utc(),
				),
				array( 'submission' => $this->submission_url( $this->submission_path_id( $request ) ) )
			);
	}

	/**
	 * Permissions for assignment.
	 *
	 * @param object      $request Request.
	 * @param string      $assignment_id Distribution public identifier.
	 * @param string      $action_key Action key.
	 * @param string      $object_action Object action.
	 * @param string|null $expected_revision Expected revision.
	 * @param string|null $expected_state Expected state.
	 * @param string|null $expected_context_revision Expected context revision.
	 * @return true|\WP_Error
	 */
	private function permissions_for_assignment(
		object $request,
		string $assignment_id,
		string $action_key,
		string $object_action,
		?string $expected_revision = null,
		?string $expected_state = null,
		?string $expected_context_revision = null
	) {
		$actor_user_id = $this->current_actor->current_actor_user_id();
		if ( $actor_user_id < 1 ) {
			return $this->permissions->check(
				$request,
				Access_Policy_Request::SHELL_PUBLIC,
				$action_key,
				array( Addon_Capabilities::PARTICIPATE )
			);
		}
		try {
			$snapshot = $this->access->authorization_snapshot( $assignment_id, $actor_user_id ); } catch ( Throwable ) {
			return $this->problem( 503, $this->correlations->create() );
			}
			if ( null === $snapshot || 'active' !== $snapshot['target_state'] ) {
				return $this->problem( 404, $this->correlations->create() );
			}

			return $this->permissions->check(
				$request,
				Access_Policy_Request::SHELL_PUBLIC,
				$action_key,
				array( Addon_Capabilities::PARTICIPATE ),
				new Context_Scope(
					$snapshot['context_type'],
					$snapshot['context_id'],
					Context_Scope::OPERATION_PARTICIPATE,
					$actor_user_id,
					$expected_context_revision
				),
				new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, $assignment_id, $object_action, $expected_revision, $expected_state )
			);
	}

	/**
	 * Permissions for submission.
	 *
	 * @param object      $request Request.
	 * @param string      $submission_id Submission public identifier.
	 * @param string      $action_key Action key.
	 * @param string      $object_action Object action.
	 * @param string|null $expected_revision Expected revision.
	 * @param string|null $expected_state Expected state.
	 * @param string|null $expected_context_revision Expected context revision.
	 * @return true|\WP_Error
	 */
	private function permissions_for_submission(
		object $request,
		string $submission_id,
		string $action_key,
		string $object_action,
		?string $expected_revision = null,
		?string $expected_state = null,
		?string $expected_context_revision = null
	) {
		$actor_user_id = $this->current_actor->current_actor_user_id();
		if ( $actor_user_id < 1 ) {
			return $this->permissions->check(
				$request,
				Access_Policy_Request::SHELL_PUBLIC,
				$action_key,
				array( Addon_Capabilities::PARTICIPATE )
			);
		}
		try {
			$snapshot = $this->access->submission_authorization_snapshot( $submission_id, $actor_user_id ); } catch ( Throwable ) {
			return $this->problem( 503, $this->correlations->create() );
			}
			if ( null === $snapshot || 'active' !== $snapshot['target_state'] || 'withdrawn' === $snapshot['assignment_state'] ) {
				return $this->problem( 404, $this->correlations->create() );
			}

			return $this->permissions->check(
				$request,
				Access_Policy_Request::SHELL_PUBLIC,
				$action_key,
				array( Addon_Capabilities::PARTICIPATE ),
				new Context_Scope(
					$snapshot['context_type'],
					$snapshot['context_id'],
					Context_Scope::OPERATION_PARTICIPATE,
					$actor_user_id,
					$expected_context_revision
				),
				new Object_Scope(
					Object_Scope::TYPE_SUBMISSION,
					$submission_id,
					$object_action,
					$expected_revision,
					$expected_state
				)
			);
	}

	/**
	 * List query.
	 *
	 * @param object $request Request.
	 * @return array{cursor:string,limit:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function list_query( object $request ): array {
		$params = method_exists( $request, 'get_query_params' ) ? $request->get_query_params() : null;
		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new InvalidArgumentException( 'Student assignment query is invalid.' );
		}
		foreach ( array_keys( $params ) as $key ) {
			if ( ! in_array( $key, array( 'cursor', 'limit' ), true ) ) {
				throw new InvalidArgumentException( 'Student assignment query key is invalid.' );
			}
		}
		$cursor = $params['cursor'] ?? '';
		$limit  = $params['limit'] ?? Read_Student_Assignments::DEFAULT_LIMIT;
		if ( is_string( $limit ) && 1 === preg_match( '/^[1-9][0-9]{0,2}$/D', $limit ) ) {
			$limit = (int) $limit;
		}
		if ( ! is_string( $cursor ) || ! is_int( $limit ) ) {
			throw new InvalidArgumentException( 'Student assignment query value is invalid.' );
		}

		return array(
			'cursor' => $cursor,
			'limit'  => $limit,
		);
	}

	/**
	 * Start input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_lock_version:int,expected_schedule_version:int,expected_schedule_hash:string,expected_publication_hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function start_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'StartOwnSubmission body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'StartOwnSubmission body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || ( array() !== $payload && array_is_list( $payload ) ) ) {
			throw new InvalidArgumentException( 'StartOwnSubmission sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_lock_version', 'expected_publication_hash', 'expected_schedule_hash', 'expected_schedule_version', 'expected_state' ) );
		$this->exact_keys( $payload, array() );
		$result = array(
			'command_id'                => $input['command_id'] ?? null,
			'expected_state'            => $expected['expected_state'] ?? null,
			'expected_lock_version'     => $expected['expected_lock_version'] ?? null,
			'expected_schedule_version' => $expected['expected_schedule_version'] ?? null,
			'expected_schedule_hash'    => $expected['expected_schedule_hash'] ?? null,
			'expected_publication_hash' => $expected['expected_publication_hash'] ?? null,
		);
		foreach ( array( 'command_id', 'expected_state', 'expected_schedule_hash', 'expected_publication_hash' ) as $field ) {
			if ( ! is_string( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'StartOwnSubmission value is invalid.' );
			}
		}
		foreach ( array( 'expected_lock_version', 'expected_schedule_version' ) as $field ) {
			if ( ! is_int( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'StartOwnSubmission version is invalid.' );
			}
		}

		return $result;
	}

	/**
	 * Autosave input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_lock_version:int,expected_current_revision_id:?string,expected_schedule_version:int,expected_schedule_hash:string,expected_publication_hash:string,expected_save_token:string,answers:array<string,mixed>,staged_attachment_ids:array<int,string>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function autosave_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'AutosaveOwnSubmission body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'AutosaveOwnSubmission body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'AutosaveOwnSubmission sections are invalid.' );
		}
		$this->exact_keys(
			$expected,
			array(
				'expected_current_revision_id',
				'expected_lock_version',
				'expected_publication_hash',
				'expected_save_token',
				'expected_schedule_hash',
				'expected_schedule_version',
				'expected_state',
			)
		);
		$this->exact_keys( $payload, array( 'answers', 'staged_attachment_ids' ) );
		$result = array(
			'command_id'                   => $input['command_id'] ?? null,
			'expected_state'               => $expected['expected_state'] ?? null,
			'expected_lock_version'        => $expected['expected_lock_version'] ?? null,
			'expected_current_revision_id' => $expected['expected_current_revision_id'] ?? null,
			'expected_schedule_version'    => $expected['expected_schedule_version'] ?? null,
			'expected_schedule_hash'       => $expected['expected_schedule_hash'] ?? null,
			'expected_publication_hash'    => $expected['expected_publication_hash'] ?? null,
			'expected_save_token'          => $expected['expected_save_token'] ?? null,
			'answers'                      => $payload['answers'] ?? null,
			'staged_attachment_ids'        => $payload['staged_attachment_ids'] ?? null,
		);
		foreach ( array( 'command_id', 'expected_state', 'expected_schedule_hash', 'expected_publication_hash', 'expected_save_token' ) as $field ) {
			if ( ! is_string( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'AutosaveOwnSubmission value is invalid.' );
			}
		}
		foreach ( array( 'expected_lock_version', 'expected_schedule_version' ) as $field ) {
			if ( ! is_int( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'AutosaveOwnSubmission version is invalid.' );
			}
		}
		if (
			( null !== $result['expected_current_revision_id'] && ! is_string( $result['expected_current_revision_id'] ) )
			|| ! is_array( $result['answers'] ) || ( array() !== $result['answers'] && array_is_list( $result['answers'] ) )
			|| ! is_array( $result['staged_attachment_ids'] ) || ! array_is_list( $result['staged_attachment_ids'] )
		) {
			throw new InvalidArgumentException( 'AutosaveOwnSubmission payload is invalid.' );
		}
		return $result;
	}

	/**
	 * Submit input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_lock_version:int,expected_current_revision_id:string,expected_schedule_version:int,expected_schedule_hash:string,expected_publication_hash:string,expected_save_token:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function submit_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'SubmitOwnSubmission body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'SubmitOwnSubmission body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'SubmitOwnSubmission sections are invalid.' );
		}
		$this->exact_keys(
			$expected,
			array(
				'expected_current_revision_id',
				'expected_lock_version',
				'expected_publication_hash',
				'expected_save_token',
				'expected_schedule_hash',
				'expected_schedule_version',
				'expected_state',
			)
		);
		$result = array(
			'command_id'                   => $input['command_id'] ?? null,
			'expected_state'               => $expected['expected_state'] ?? null,
			'expected_lock_version'        => $expected['expected_lock_version'] ?? null,
			'expected_current_revision_id' => $expected['expected_current_revision_id'] ?? null,
			'expected_schedule_version'    => $expected['expected_schedule_version'] ?? null,
			'expected_schedule_hash'       => $expected['expected_schedule_hash'] ?? null,
			'expected_publication_hash'    => $expected['expected_publication_hash'] ?? null,
			'expected_save_token'          => $expected['expected_save_token'] ?? null,
		);
		foreach ( array(
			'command_id',
			'expected_state',
			'expected_current_revision_id',
			'expected_schedule_hash',
			'expected_publication_hash',
			'expected_save_token',
		) as $field ) {
			if ( ! is_string( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'SubmitOwnSubmission value is invalid.' );
			}
		}
		foreach ( array( 'expected_lock_version', 'expected_schedule_version' ) as $field ) {
			if ( ! is_int( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'SubmitOwnSubmission version is invalid.' );
			}
		}
		return $result;
	}

	/**
	 * Attachment input.
	 *
	 * @param object $request Request.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachment_input( object $request ): array {
		if ( ! method_exists( $request, 'get_body_params' ) || ! method_exists( $request, 'get_file_params' ) ) {
			throw new InvalidArgumentException( 'StageOwnAttachment multipart body is unavailable.' );
		}
		$body  = $request->get_body_params();
		$files = $request->get_file_params();
		if ( ! is_array( $body ) || array_is_list( $body ) || ! is_array( $files ) || array_is_list( $files ) ) {
			throw new InvalidArgumentException( 'StageOwnAttachment multipart body is invalid.' );
		}
		$this->exact_keys(
			$body,
			array(
				'command_id',
				'expected_current_revision_id',
				'expected_lock_version',
				'expected_publication_hash',
				'expected_save_token',
				'expected_schedule_hash',
				'expected_schedule_version',
				'expected_state',
				'response_id',
				'role',
			)
		);
		$this->exact_keys( $files, array( 'file' ) );
		foreach ( array( 'expected_lock_version', 'expected_schedule_version' ) as $field ) {
			$value = $body[ $field ] ?? null;
			if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
				throw new InvalidArgumentException( 'StageOwnAttachment version is invalid.' );
			}
			$body[ $field ] = (int) $value;
		}
		foreach ( array(
			'command_id',
			'expected_current_revision_id',
			'expected_publication_hash',
			'expected_save_token',
			'expected_schedule_hash',
			'expected_state',
			'response_id',
			'role',
		) as $field ) {
			if ( ! is_string( $body[ $field ] ?? null ) ) {
				throw new InvalidArgumentException( 'StageOwnAttachment value is invalid.' );
			}
		}
		$body['expected_current_revision_id'] = '' === $body['expected_current_revision_id'] ? null : $body['expected_current_revision_id'];
		$file                                 = $files['file'] ?? null;
		if ( ! is_array( $file ) || array_is_list( $file ) ) {
			throw new InvalidArgumentException( 'StageOwnAttachment file is invalid.' );
		}
		// PHP 8.1 includes the untrusted client path even for single-file uploads.
		if ( array_key_exists( 'full_path', $file ) ) {
			if ( ! is_string( $file['full_path'] ) ) {
				throw new InvalidArgumentException( 'StageOwnAttachment client path metadata is invalid.' );
			}
			unset( $file['full_path'] );
		}
		$this->exact_keys( $file, array( 'error', 'name', 'size', 'tmp_name', 'type' ) );
		if ( ! is_int( $file['error'] ?? null ) || ! is_int( $file['size'] ?? null ) ) {
			throw new InvalidArgumentException( 'StageOwnAttachment file metadata is invalid.' );
		}
		foreach ( array( 'name', 'tmp_name', 'type' ) as $field ) {
			if ( ! is_string( $file[ $field ] ?? null ) ) {
				throw new InvalidArgumentException( 'StageOwnAttachment file value is invalid.' );
			}
		}
		$body['file'] = $file;
		return $body;
	}

	/**
	 * Remove attachment input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_lock_version:int,expected_current_revision_id:?string,expected_schedule_version:int,expected_schedule_hash:string,expected_publication_hash:string,expected_save_token:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function remove_attachment_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'RemoveOwnStagedAttachment body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'RemoveOwnStagedAttachment body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'RemoveOwnStagedAttachment sections are invalid.' );
		}
		$this->exact_keys(
			$expected,
			array(
				'expected_current_revision_id',
				'expected_lock_version',
				'expected_publication_hash',
				'expected_save_token',
				'expected_schedule_hash',
				'expected_schedule_version',
				'expected_state',
			)
		);
		$result = array(
			'command_id'                   => $input['command_id'] ?? null,
			'expected_state'               => $expected['expected_state'] ?? null,
			'expected_lock_version'        => $expected['expected_lock_version'] ?? null,
			'expected_current_revision_id' => $expected['expected_current_revision_id'] ?? null,
			'expected_schedule_version'    => $expected['expected_schedule_version'] ?? null,
			'expected_schedule_hash'       => $expected['expected_schedule_hash'] ?? null,
			'expected_publication_hash'    => $expected['expected_publication_hash'] ?? null,
			'expected_save_token'          => $expected['expected_save_token'] ?? null,
		);
		foreach ( array( 'command_id', 'expected_state', 'expected_schedule_hash', 'expected_publication_hash', 'expected_save_token' ) as $field ) {
			if ( ! is_string( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'RemoveOwnStagedAttachment value is invalid.' );
			}
		}
		foreach ( array( 'expected_lock_version', 'expected_schedule_version' ) as $field ) {
			if ( ! is_int( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'RemoveOwnStagedAttachment version is invalid.' );
			}
		}
		if ( null !== $result['expected_current_revision_id'] && ! is_string( $result['expected_current_revision_id'] ) ) {
			throw new InvalidArgumentException( 'RemoveOwnStagedAttachment revision is invalid.' );
		}
		return $result;
	}

	/**
	 * Empty query.
	 *
	 * @param object $request Request.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function empty_query( object $request ): void {
		$params = method_exists( $request, 'get_query_params' ) ? $request->get_query_params() : null;
		if ( ! is_array( $params ) || array() !== $params ) {
			throw new InvalidArgumentException( 'Student assignment detail query must be empty.' );
		}
	}

	/**
	 * Path id.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function path_id( object $request ): string {
		$params = method_exists( $request, 'get_url_params' ) ? $request->get_url_params() : null;
		$id     = is_array( $params ) ? ( $params['assignment_id'] ?? null ) : null;
		if ( ! is_string( $id ) ) {
			throw new InvalidArgumentException( 'Student assignment path is invalid.' );
		}
		new Public_Id( $id );

		return $id;
	}

	/**
	 * Worksheet pdf input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_assignment_lock_version:int,expected_assignment_state:string,expected_context_revision:string,expected_publication_id:string,expected_publication_hash:string,expected_target_state:string,profile:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function worksheet_pdf_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Own worksheet PDF body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Own worksheet PDF body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Own worksheet PDF sections are invalid.' );
		}
		$this->exact_keys(
			$expected,
			array(
				'expected_assignment_lock_version',
				'expected_assignment_state',
				'expected_context_revision',
				'expected_publication_hash',
				'expected_publication_id',
				'expected_target_state',
			)
		);
		$this->exact_keys( $payload, array( 'profile' ) );
		$result = array(
			'command_id'                       => $input['command_id'] ?? null,
			'expected_assignment_lock_version' => $expected['expected_assignment_lock_version'] ?? null,
			'expected_assignment_state'        => $expected['expected_assignment_state'] ?? null,
			'expected_context_revision'        => $expected['expected_context_revision'] ?? null,
			'expected_publication_id'          => $expected['expected_publication_id'] ?? null,
			'expected_publication_hash'        => $expected['expected_publication_hash'] ?? null,
			'expected_target_state'            => $expected['expected_target_state'] ?? null,
			'profile'                          => $payload['profile'] ?? null,
		);
		if ( ! is_int( $result['expected_assignment_lock_version'] ) || $result['expected_assignment_lock_version'] < 0 ) {
			throw new InvalidArgumentException( 'Own worksheet PDF lock version is invalid.' );
		}
		foreach ( array( 'command_id', 'expected_assignment_state', 'expected_context_revision', 'expected_publication_id', 'expected_publication_hash', 'expected_target_state', 'profile' ) as $field ) {
			if ( ! is_string( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'Own worksheet PDF value is invalid.' );
			}
		}
		new Public_Id( $result['expected_publication_id'] );
		if (
			! in_array( $result['expected_assignment_state'], array( 'open', 'closed' ), true )
			|| 'active' !== $result['expected_target_state'] || 'student_blank' !== $result['profile']
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $result['expected_publication_hash'] )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $result['expected_context_revision'] )
		) {
			throw new InvalidArgumentException( 'Own worksheet PDF expected values are invalid.' );
		}
		return $result;
	}

	/**
	 * Submission pdf input.
	 *
	 * @param object $request Request.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function submission_pdf_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Own submission PDF body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Own submission PDF body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Own submission PDF sections are invalid.' );
		}
		$this->exact_keys(
			$expected,
			array(
				'expected_assignment_id',
				'expected_assignment_state',
				'expected_attachment_set_hash',
				'expected_context_revision',
				'expected_official_revision_hash',
				'expected_official_revision_id',
				'expected_publication_hash',
				'expected_publication_id',
				'expected_submission_lock_version',
				'expected_submission_state',
				'expected_target_state',
			)
		);
		$this->exact_keys( $payload, array( 'profile' ) );
		$result = array(
			'command_id'                       => $input['command_id'] ?? null,
			'expected_assignment_id'           => $expected['expected_assignment_id'] ?? null,
			'expected_assignment_state'        => $expected['expected_assignment_state'] ?? null,
			'expected_attachment_set_hash'     => $expected['expected_attachment_set_hash'] ?? null,
			'expected_context_revision'        => $expected['expected_context_revision'] ?? null,
			'expected_official_revision_hash'  => $expected['expected_official_revision_hash'] ?? null,
			'expected_official_revision_id'    => $expected['expected_official_revision_id'] ?? null,
			'expected_publication_hash'        => $expected['expected_publication_hash'] ?? null,
			'expected_publication_id'          => $expected['expected_publication_id'] ?? null,
			'expected_submission_lock_version' => $expected['expected_submission_lock_version'] ?? null,
			'expected_submission_state'        => $expected['expected_submission_state'] ?? null,
			'expected_target_state'            => $expected['expected_target_state'] ?? null,
			'profile'                          => $payload['profile'] ?? null,
		);
		if ( ! is_int( $result['expected_submission_lock_version'] ) || $result['expected_submission_lock_version'] < 0 ) {
			throw new InvalidArgumentException( 'Own submission PDF lock version is invalid.' );
		}
		foreach ( array_keys( $result ) as $field ) {
			if ( 'expected_submission_lock_version' === $field ) {
				continue;
			}
			if ( ! is_string( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'Own submission PDF value is invalid.' );
			}
		}
		foreach ( array( 'expected_assignment_id', 'expected_official_revision_id', 'expected_publication_id' ) as $field ) {
			new Public_Id( $result[ $field ] );
		}
		foreach ( array( 'expected_attachment_set_hash', 'expected_official_revision_hash', 'expected_publication_hash' ) as $field ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'Own submission PDF hash is invalid.' );
			}
		}
		if (
			! in_array( $result['expected_assignment_state'], array( 'open', 'closed' ), true )
			|| ! in_array( $result['expected_submission_state'], array( 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true )
			|| 'active' !== $result['expected_target_state']
			|| Build_Submission_Print_Projection::PROFILE !== $result['profile']
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $result['expected_context_revision'] )
		) {
			throw new InvalidArgumentException( 'Own submission PDF expected values are invalid.' );
		}
		return $result;
	}

	/**
	 * Submission path id.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function submission_path_id( object $request ): string {
		$params = method_exists( $request, 'get_url_params' ) ? $request->get_url_params() : null;
		$id     = is_array( $params ) ? ( $params['submission_id'] ?? null ) : null;
		if ( ! is_string( $id ) ) {
			throw new InvalidArgumentException( 'Student submission path is invalid.' );
		}
		new Public_Id( $id );

		return $id;
	}

	/**
	 * Attachment path id.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachment_path_id( object $request ): string {
		$params = method_exists( $request, 'get_url_params' ) ? $request->get_url_params() : null;
		$id     = is_array( $params ) ? ( $params['attachment_id'] ?? null ) : null;
		if ( ! is_string( $id ) ) {
			throw new InvalidArgumentException( 'Student attachment path is invalid.' );
		}
		new Public_Id( $id );

		return $id;
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
			throw new InvalidArgumentException( 'Student assignment object keys are invalid.' );
		}
	}

	/**
	 * Detail url.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @return string
	 */
	private function detail_url( string $assignment_id ): string {
		$path = 'oj-ai/v1/me/assignments/' . rawurlencode( $assignment_id );

		return function_exists( 'rest_url' ) ? rest_url( $path ) : '/wp-json/' . $path;
	}

	/**
	 * Submission url.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @return string
	 */
	private function submission_url( string $submission_id ): string {
		$path = 'oj-ai/v1/me/submissions/' . rawurlencode( $submission_id );

		return function_exists( 'rest_url' ) ? rest_url( $path ) : '/wp-json/' . $path;
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
			Assignment_Failure::NOT_FOUND => array( 404, '활동지를 찾을 수 없습니다.' ),
			Assignment_Failure::SUBMISSION_VALIDATION_FAILED => array( 422, '확인할 답변이 있습니다.' ),
			Assignment_Failure::ATTACHMENT_LIMIT_EXCEEDED => array( 413, '파일 수나 크기를 확인해 주세요.' ),
			Assignment_Failure::ATTACHMENT_UNSUPPORTED_MEDIA => array( 415, '지원하지 않는 파일 형식입니다.' ),
			Assignment_Failure::ATTACHMENT_INVALID_UPLOAD => array( 422, '파일을 다시 선택해 주세요.' ),
			Assignment_Failure::ATTACHMENT_NOT_READY => array( 409, '파일 확인이 끝나지 않았습니다.' ),
			Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL => array( 409, '이미 제출했습니다.' ),
			Assignment_Failure::STATE_CONFLICT, Assignment_Failure::IDEMPOTENCY_CONFLICT,
			Assignment_Failure::SUBMISSION_REVISION_CONFLICT => array( 409, '내용이 바뀌었습니다. 새로고침해 주세요.' ),
			default => array( 503, '활동지를 불러오지 못했습니다.' ),
		};

		return $this->envelopes->problem( Rest_Problem::operation( $definition[0], $failure->reason(), $definition[1], $correlation_id ) );
	}

	/**
	 * Worksheet pdf problem.
	 *
	 * @param Worksheet_Pdf_Failure $failure Failure.
	 * @param string                $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function worksheet_pdf_problem( Worksheet_Pdf_Failure $failure, string $correlation_id ) {
		$status = match ( $failure->reason() ) {
			Worksheet_Pdf_Failure::NOT_FOUND => 404,
			Worksheet_Pdf_Failure::SOURCE_STALE, Worksheet_Pdf_Failure::IDEMPOTENCY_CONFLICT, Worksheet_Pdf_Failure::NOT_READY => 409,
			Worksheet_Pdf_Failure::SOURCE_INVALID, Worksheet_Pdf_Failure::TOO_LARGE => 422,
			Worksheet_Pdf_Failure::ACCESS_DENIED => 403,
			Worksheet_Pdf_Failure::EXPIRED => 410,
			default => 503,
		};
		$message = match ( $status ) {
			403 => '권한을 확인해 주세요.', 404 => '활동지를 찾을 수 없습니다.', 409 => '새로고침한 뒤 다시 시도해 주세요.',
			410 => '파일 유효기간이 끝났습니다.', 422 => '활동지를 먼저 확인해 주세요.', default => '지금은 PDF를 만들 수 없습니다.',
		};
		return $this->envelopes->problem( Rest_Problem::operation( $status, $failure->reason(), $message, $correlation_id ) );
	}

	/**
	 * Submission pdf problem.
	 *
	 * @param Submission_Pdf_Failure $failure Failure.
	 * @param string                 $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function submission_pdf_problem( Submission_Pdf_Failure $failure, string $correlation_id ) {
		$status = match ( $failure->reason() ) {
			Submission_Pdf_Failure::NOT_FOUND => 404,
			Submission_Pdf_Failure::SOURCE_STALE, Submission_Pdf_Failure::IDEMPOTENCY_CONFLICT, Submission_Pdf_Failure::NOT_READY => 409,
			Submission_Pdf_Failure::SOURCE_INVALID, Submission_Pdf_Failure::STORED_DATA_INVALID, Submission_Pdf_Failure::TOO_LARGE => 422,
			Submission_Pdf_Failure::ACCESS_DENIED => 403,
			Submission_Pdf_Failure::EXPIRED => 410,
			default => 503,
		};
		$message = match ( $status ) {
			403 => '권한을 확인해 주세요.', 404 => '공식 제출본을 찾을 수 없습니다.', 409 => '새로고침한 뒤 다시 시도해 주세요.',
			410 => '파일 유효기간이 끝났습니다.', 422 => '제출 결과를 먼저 확인해 주세요.', default => '지금은 PDF를 만들 수 없습니다.',
		};
		return $this->envelopes->problem( Rest_Problem::operation( $status, $failure->reason(), $message, $correlation_id ) );
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
