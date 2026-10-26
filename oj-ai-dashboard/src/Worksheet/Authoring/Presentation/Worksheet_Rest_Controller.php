<?php
/**
 * Worksheet authoring REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Application\Approve_Worksheet_Revision;
use OJAI\Dashboard\Worksheet\Authoring\Application\Approve_Worksheet_Revision_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Change_Worksheet_Lifecycle;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Lifecycle_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Duplicate_Worksheet;
use OJAI\Dashboard\Worksheet\Authoring\Application\Duplicate_Worksheet_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Publish_Worksheet_Revision;
use OJAI\Dashboard\Worksheet\Authoring\Application\Publish_Worksheet_Revision_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision;
use OJAI\Dashboard\Worksheet\Authoring\Application\Read_Worksheet_Preview;
use OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Read_Worksheets;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opens the frozen Worksheet authoring command and query routes.
 */
final class Worksheet_Rest_Controller {
	public const REST_NAMESPACE   = 'oj-ai/v1';
	public const ROUTE_WORKSHEETS = '/worksheets';
	public const ROUTE_WORKSHEET  = '/worksheets/(?P<worksheet_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ROUTE_REVISIONS  = '/worksheets/(?P<worksheet_id>[0-9A-HJKMNP-TV-Z]{26})/revisions';
	public const ROUTE_REVISION   = '/worksheet-revisions/(?P<revision_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ROUTE_PREVIEW    = '/worksheet-revisions/(?P<revision_id>[0-9A-HJKMNP-TV-Z]{26})/preview';
	public const ROUTE_SAVE_DRAFT = '/worksheets/(?P<worksheet_id>[0-9A-HJKMNP-TV-Z]{26})/commands/save-draft';
	public const ROUTE_DUPLICATE  = '/worksheets/(?P<worksheet_id>[0-9A-HJKMNP-TV-Z]{26})/commands/duplicate';
	public const ROUTE_VALIDATE   = '/worksheet-revisions/(?P<revision_id>[0-9A-HJKMNP-TV-Z]{26})/commands/validate';
	public const ROUTE_APPROVE    = '/worksheet-revisions/(?P<revision_id>[0-9A-HJKMNP-TV-Z]{26})/commands/approve';
	public const ROUTE_PUBLISH    = '/worksheet-revisions/(?P<revision_id>[0-9A-HJKMNP-TV-Z]{26})/commands/publish';
	public const ROUTE_ARCHIVE    = '/worksheets/(?P<worksheet_id>[0-9A-HJKMNP-TV-Z]{26})/commands/archive';
	public const ROUTE_RESTORE    = '/worksheets/(?P<worksheet_id>[0-9A-HJKMNP-TV-Z]{26})/commands/restore';

	public const ACTION_LIST           = 'worksheet.list';
	public const ACTION_CREATE         = 'worksheet.create';
	public const ACTION_VIEW           = 'worksheet.view';
	public const ACTION_LIST_REVISIONS = 'worksheet.revisions.list';
	public const ACTION_VIEW_REVISION  = 'worksheet.revision.view';
	public const ACTION_PREVIEW        = 'worksheet.revision.preview';
	public const ACTION_SAVE_DRAFT     = 'worksheet.save-draft';
	public const ACTION_DUPLICATE      = 'worksheet.duplicate';
	public const ACTION_VALIDATE       = 'worksheet.revision.validate';
	public const ACTION_APPROVE        = 'worksheet.revision.approve';
	public const ACTION_PUBLISH        = 'worksheet.revision.publish';
	public const ACTION_ARCHIVE        = 'worksheet.archive';
	public const ACTION_RESTORE        = 'worksheet.restore';

	/**
	 * Permissions.
	 *
	 * @var Rest_Permission_Handoff
	 */
	private Rest_Permission_Handoff $permissions;
	/**
	 * Create worksheet.
	 *
	 * @var Create_Worksheet
	 */
	private Create_Worksheet $create_worksheet;
	/**
	 * Read worksheets.
	 *
	 * @var Read_Worksheets
	 */
	private Read_Worksheets $read_worksheets;
	/**
	 * Save worksheet draft.
	 *
	 * @var Save_Worksheet_Draft|null
	 */
	private ?Save_Worksheet_Draft $save_worksheet_draft;
	/**
	 * Duplicate worksheet.
	 *
	 * @var Duplicate_Worksheet|null
	 */
	private ?Duplicate_Worksheet $duplicate_worksheet;
	/**
	 * Validate worksheet revision.
	 *
	 * @var Validate_Worksheet_Revision|null
	 */
	private ?Validate_Worksheet_Revision $validate_worksheet_revision;
	/**
	 * Read worksheet preview.
	 *
	 * @var Read_Worksheet_Preview|null
	 */
	private ?Read_Worksheet_Preview $read_worksheet_preview;
	/**
	 * Approve worksheet revision.
	 *
	 * @var Approve_Worksheet_Revision|null
	 */
	private ?Approve_Worksheet_Revision $approve_worksheet_revision;
	/**
	 * Publish worksheet revision.
	 *
	 * @var Publish_Worksheet_Revision|null
	 */
	private ?Publish_Worksheet_Revision $publish_worksheet_revision;
	/**
	 * Change worksheet lifecycle.
	 *
	 * @var Change_Worksheet_Lifecycle|null
	 */
	private ?Change_Worksheet_Lifecycle $change_worksheet_lifecycle;
	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;
	/**
	 * Envelopes.
	 *
	 * @var Rest_Envelope_Factory
	 */
	private Rest_Envelope_Factory $envelopes;
	/**
	 * Correlations.
	 *
	 * @var Correlation_Id_Factory
	 */
	private Correlation_Id_Factory $correlations;
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize worksheet rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff          $permissions Permissions.
	 * @param Create_Worksheet                 $create_worksheet Create worksheet.
	 * @param Read_Worksheets                  $read_worksheets Read worksheets.
	 * @param Canonical_Json                   $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory            $envelopes Envelopes.
	 * @param Correlation_Id_Factory           $correlations Correlations.
	 * @param Save_Worksheet_Draft|null        $save_worksheet_draft Save worksheet draft.
	 * @param Duplicate_Worksheet|null         $duplicate_worksheet Duplicate worksheet.
	 * @param Validate_Worksheet_Revision|null $validate_worksheet_revision Validate worksheet revision.
	 * @param Read_Worksheet_Preview|null      $read_worksheet_preview Read worksheet preview.
	 * @param Approve_Worksheet_Revision|null  $approve_worksheet_revision Approve worksheet revision.
	 * @param Publish_Worksheet_Revision|null  $publish_worksheet_revision Publish worksheet revision.
	 * @param Change_Worksheet_Lifecycle|null  $change_worksheet_lifecycle Change worksheet lifecycle.
	 */
	public function __construct(
		Rest_Permission_Handoff $permissions,
		Create_Worksheet $create_worksheet,
		Read_Worksheets $read_worksheets,
		Canonical_Json $canonical,
		Rest_Envelope_Factory $envelopes,
		Correlation_Id_Factory $correlations,
		?Save_Worksheet_Draft $save_worksheet_draft = null,
		?Duplicate_Worksheet $duplicate_worksheet = null,
		?Validate_Worksheet_Revision $validate_worksheet_revision = null,
		?Read_Worksheet_Preview $read_worksheet_preview = null,
		?Approve_Worksheet_Revision $approve_worksheet_revision = null,
		?Publish_Worksheet_Revision $publish_worksheet_revision = null,
		?Change_Worksheet_Lifecycle $change_worksheet_lifecycle = null
	) {
		$this->permissions                 = $permissions;
		$this->create_worksheet            = $create_worksheet;
		$this->read_worksheets             = $read_worksheets;
		$this->save_worksheet_draft        = $save_worksheet_draft;
		$this->canonical                   = $canonical;
		$this->envelopes                   = $envelopes;
		$this->correlations                = $correlations;
		$this->duplicate_worksheet         = $duplicate_worksheet;
		$this->validate_worksheet_revision = $validate_worksheet_revision;
		$this->read_worksheet_preview      = $read_worksheet_preview;
		$this->approve_worksheet_revision  = $approve_worksheet_revision;
		$this->publish_worksheet_revision  = $publish_worksheet_revision;
		$this->change_worksheet_lifecycle  = $change_worksheet_lifecycle;
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
			self::ROUTE_WORKSHEETS,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'permissions_list' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'permissions_create' ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_WORKSHEET,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'permissions_show' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_REVISIONS,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'revisions' ),
				'permission_callback' => array( $this, 'permissions_revisions' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_REVISION,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'revision' ),
				'permission_callback' => array( $this, 'permissions_revision' ),
			)
		);
		if ( $this->read_worksheet_preview instanceof Read_Worksheet_Preview ) {
			register_rest_route(
				self::REST_NAMESPACE,
				self::ROUTE_PREVIEW,
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'preview' ),
					'permission_callback' => array( $this, 'permissions_preview' ),
				)
			);
		}
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_SAVE_DRAFT,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_draft' ),
				'permission_callback' => array( $this, 'permissions_save_draft' ),
			)
		);
		if ( $this->duplicate_worksheet instanceof Duplicate_Worksheet ) {
			register_rest_route(
				self::REST_NAMESPACE,
				self::ROUTE_DUPLICATE,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'duplicate' ),
					'permission_callback' => array( $this, 'permissions_duplicate' ),
				)
			);
		}
		if ( $this->validate_worksheet_revision instanceof Validate_Worksheet_Revision ) {
			register_rest_route(
				self::REST_NAMESPACE,
				self::ROUTE_VALIDATE,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'validate_revision' ),
					'permission_callback' => array( $this, 'permissions_validate_revision' ),
				)
			);
		}
		if ( $this->approve_worksheet_revision instanceof Approve_Worksheet_Revision ) {
			register_rest_route(
				self::REST_NAMESPACE,
				self::ROUTE_APPROVE,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'approve_revision' ),
					'permission_callback' => array( $this, 'permissions_approve_revision' ),
				)
			);
		}
		if ( $this->publish_worksheet_revision instanceof Publish_Worksheet_Revision ) {
			register_rest_route(
				self::REST_NAMESPACE,
				self::ROUTE_PUBLISH,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'publish_revision' ),
					'permission_callback' => array( $this, 'permissions_publish_revision' ),
				)
			);
		}
		if ( $this->change_worksheet_lifecycle instanceof Change_Worksheet_Lifecycle ) {
			register_rest_route(
				self::REST_NAMESPACE,
				self::ROUTE_ARCHIVE,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'archive_worksheet' ),
					'permission_callback' => array( $this, 'permissions_archive_worksheet' ),
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				self::ROUTE_RESTORE,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'restore_worksheet' ),
					'permission_callback' => array( $this, 'permissions_restore_worksheet' ),
				)
			);
		}
	}

	/**
	 * Permissions list.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_list( object $request ) {
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_LIST,
			array( Addon_Capabilities::VIEW_WORKSHEETS )
		);
	}

	/**
	 * Permissions create.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_create( object $request ) {
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_CREATE,
			array( Addon_Capabilities::MANAGE_WORKSHEETS )
		);
	}

	/**
	 * Permissions show.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_show( object $request ) {
		return $this->check_object_permission(
			$request,
			self::ACTION_VIEW,
			Object_Scope::TYPE_WORKSHEET,
			'worksheet_id'
		);
	}

	/**
	 * Permissions revisions.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_revisions( object $request ) {
		return $this->check_object_permission(
			$request,
			self::ACTION_LIST_REVISIONS,
			Object_Scope::TYPE_WORKSHEET,
			'worksheet_id'
		);
	}

	/**
	 * Permissions revision.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_revision( object $request ) {
		return $this->check_object_permission(
			$request,
			self::ACTION_VIEW_REVISION,
			Object_Scope::TYPE_WORKSHEET_REVISION,
			'revision_id'
		);
	}

	/**
	 * Permissions preview.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_preview( object $request ) {
		return $this->check_object_permission(
			$request,
			self::ACTION_PREVIEW,
			Object_Scope::TYPE_WORKSHEET_REVISION,
			'revision_id'
		);
	}

	/**
	 * Permissions save draft.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_save_draft( object $request ) {
		try {
			$worksheet_id = $this->path_id( $request, 'worksheet_id' );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}

		$object = new Object_Scope(
			Object_Scope::TYPE_WORKSHEET,
			$worksheet_id,
			'edit'
		);

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_SAVE_DRAFT,
			array( Addon_Capabilities::MANAGE_WORKSHEETS ),
			null,
			$object
		);
	}

	/**
	 * Permissions duplicate.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_duplicate( object $request ) {
		try {
			$worksheet_id = $this->path_id( $request, 'worksheet_id' );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}
		$object = new Object_Scope(
			Object_Scope::TYPE_WORKSHEET,
			$worksheet_id,
			'edit'
		);
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_DUPLICATE,
			array( Addon_Capabilities::MANAGE_WORKSHEETS ),
			null,
			$object
		);
	}

	/**
	 * Permissions validate revision.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_validate_revision( object $request ) {
		try {
			$revision_id = $this->path_id( $request, 'revision_id' );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}

		$object = new Object_Scope(
			Object_Scope::TYPE_WORKSHEET_REVISION,
			$revision_id,
			'edit'
		);

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_VALIDATE,
			array( Addon_Capabilities::MANAGE_WORKSHEETS ),
			null,
			$object
		);
	}

	/**
	 * Permissions approve revision.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_approve_revision( object $request ) {
		try {
			$revision_id = $this->path_id( $request, 'revision_id' );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}
		$object = new Object_Scope(
			Object_Scope::TYPE_WORKSHEET_REVISION,
			$revision_id,
			'edit'
		);
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_APPROVE,
			array( Addon_Capabilities::MANAGE_WORKSHEETS ),
			null,
			$object
		);
	}

	/**
	 * Permissions publish revision.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_publish_revision( object $request ) {
		try {
			$revision_id = $this->path_id( $request, 'revision_id' );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}
		$object = new Object_Scope(
			Object_Scope::TYPE_WORKSHEET_REVISION,
			$revision_id,
			'edit'
		);
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_PUBLISH,
			array( Addon_Capabilities::MANAGE_WORKSHEETS ),
			null,
			$object
		);
	}

	/**
	 * Permissions archive worksheet.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_archive_worksheet( object $request ) {
		return $this->permissions_lifecycle( $request, self::ACTION_ARCHIVE );
	}

	/**
	 * Permissions restore worksheet.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_restore_worksheet( object $request ) {
		return $this->permissions_lifecycle( $request, self::ACTION_RESTORE );
	}

	/**
	 * Permissions lifecycle.
	 *
	 * @param object $request Request.
	 * @param string $action Action.
	 * @return true|\WP_Error
	 */
	private function permissions_lifecycle( object $request, string $action ) {
		try {
			$worksheet_id = $this->path_id( $request, 'worksheet_id' );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			$action,
			array( Addon_Capabilities::MANAGE_WORKSHEETS ),
			null,
			new Object_Scope( Object_Scope::TYPE_WORKSHEET, $worksheet_id, 'edit' )
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
			$page  = $this->read_worksheets->list(
				$grant->metadata()->actor_user_id(),
				$query['state'],
				$query['cursor'],
				$query['limit']
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Failure $failure ) {
			return $this->worksheet_problem( $failure, $correlation_id );
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
				'relation_scope' => 'owner',
			)
		);
	}

	/**
	 * Create worksheet rest controller.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( object $request ) {
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
			$input   = $this->create_input( $request );
			$command = new Create_Worksheet_Command(
				$grant->metadata()->actor_user_id(),
				$input['command_id'],
				$input['expected_state'],
				$input['title'],
				$this->site_locale(),
				$idempotency->fingerprint(),
				$correlation_id,
				$this->canonical
			);
			$result  = $this->create_worksheet->execute( $command );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Failure $failure ) {
			return $this->worksheet_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'worksheet' => array(
					'id'                  => $result->worksheet_id(),
					'title'               => $result->title(),
					'state'               => 'draft',
					'current_revision_id' => $result->revision_id(),
				),
				'revision'  => array(
					'id'            => $result->revision_id(),
					'revision_no'   => 1,
					'state'         => 'draft',
					'document_id'   => $result->document_id(),
					'document_hash' => $result->document_hash(),
				),
			),
			$correlation_id,
			201,
			array(
				'idempotent_replay' => $result->replayed(),
				'created_at_utc'    => $result->created_at_utc(),
			),
			$this->links_for( $result->worksheet_id(), $result->revision_id() ),
			array( self::ACTION_VIEW, self::ACTION_LIST_REVISIONS, self::ACTION_VIEW_REVISION )
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
			$worksheet_id = $this->path_id( $request, 'worksheet_id' );
			$worksheet    = $this->read_worksheets->worksheet(
				$grant->metadata()->actor_user_id(),
				$worksheet_id
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 404, $correlation_id );
		} catch ( Worksheet_Failure $failure ) {
			return $this->worksheet_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array( 'worksheet' => $worksheet ),
			$correlation_id,
			200,
			array(),
			$this->links_for( $worksheet_id, $worksheet['current_revision_id'] ),
			array( self::ACTION_LIST_REVISIONS )
		);
	}

	/**
	 * Revisions.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revisions( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();

		try {
			$worksheet_id = $this->path_id( $request, 'worksheet_id' );
			$query        = $this->page_query( $request );
			$page         = $this->read_worksheets->revisions(
				$grant->metadata()->actor_user_id(),
				$worksheet_id,
				$query['cursor'],
				$query['limit']
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Failure $failure ) {
			return $this->worksheet_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'worksheet_id' => $worksheet_id,
				'items'        => $page['items'],
			),
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
	 * Revision.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revision( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();

		try {
			$revision = $this->read_worksheets->revision(
				$grant->metadata()->actor_user_id(),
				$this->path_id( $request, 'revision_id' )
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 404, $correlation_id );
		} catch ( Worksheet_Failure $failure ) {
			return $this->worksheet_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success( $revision, $correlation_id );
	}

	/**
	 * Preview.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant || ! $this->read_worksheet_preview instanceof Read_Worksheet_Preview ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();

		try {
			$query  = $this->preview_query( $request );
			$result = $this->read_worksheet_preview->read(
				$grant->metadata()->actor_user_id(),
				$this->path_id( $request, 'revision_id' ),
				$query['projection'],
				$query['profile']
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Failure $failure ) {
			return $this->worksheet_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			$result,
			$correlation_id,
			200,
			array(
				'mutation_count'     => 0,
				'private_asset_urls' => 0,
			)
		);
	}

	/**
	 * Save draft.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_draft( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant || ! $this->save_worksheet_draft instanceof Save_Worksheet_Draft ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 422, $correlation_id );
		}

		try {
			$worksheet_id = $this->path_id( $request, 'worksheet_id' );
			$input        = $this->save_input( $request );
			$command      = new Save_Worksheet_Draft_Command(
				$grant->metadata()->actor_user_id(),
				$worksheet_id,
				$input['command_id'],
				$input['expected_state'],
				$input['expected_revision_id'],
				$input['teacher_document'],
				$input['achievement_links'],
				$input['staged_asset_receipt_ids'],
				$idempotency->fingerprint(),
				$correlation_id,
				$this->canonical
			);
			$result       = $this->save_worksheet_draft->execute( $command );
		} catch ( \OJAI\Dashboard\Assessment\Project\Domain\Project_Failure $failure ) {
			return $this->problem( $failure->status, $correlation_id );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Failure $failure ) {
			return $this->worksheet_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'worksheet'        => array(
					'id'                  => $result->worksheet_id(),
					'title'               => $result->title(),
					'state'               => $result->worksheet_state(),
					'current_revision_id' => $result->current_revision_id(),
				),
				'revision'         => array(
					'id'                 => $result->revision_id(),
					'revision_no'        => $result->revision_no(),
					'state'              => $result->revision_state(),
					'source_revision_id' => $result->source_revision_id(),
					'document_id'        => $result->document_id(),
					'document_hash'      => $result->document_hash(),
					'validation_hash'    => $result->validation_hash(),
				),
				'temporary_id_map' => $result->temporary_id_map(),
			),
			$correlation_id,
			$result->http_status(),
			array(
				'idempotent_replay' => $result->replayed(),
				'unchanged'         => $result->unchanged(),
				'saved_at_utc'      => $result->saved_at_utc(),
			),
			$this->links_for( $result->worksheet_id(), $result->revision_id() ),
			array( self::ACTION_VIEW, self::ACTION_LIST_REVISIONS, self::ACTION_VIEW_REVISION, self::ACTION_SAVE_DRAFT )
		);
	}

	/**
	 * Duplicate.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function duplicate( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant || ! $this->duplicate_worksheet instanceof Duplicate_Worksheet ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 422, $correlation_id );
		}

		try {
			$source_worksheet_id = $this->path_id( $request, 'worksheet_id' );
			$input               = $this->duplicate_input( $request );
			$result              = $this->duplicate_worksheet->execute(
				new Duplicate_Worksheet_Command(
					$grant->metadata()->actor_user_id(),
					$source_worksheet_id,
					$input['command_id'],
					$input['expected_state'],
					$input['expected_revision_id'],
					$idempotency->fingerprint(),
					$correlation_id,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Failure $failure ) {
			return $this->worksheet_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'worksheet' => array(
					'id'                  => $result->worksheet_id(),
					'title'               => $result->title(),
					'state'               => 'draft',
					'current_revision_id' => $result->revision_id(),
				),
				'revision'  => array(
					'id'                 => $result->revision_id(),
					'revision_no'        => 1,
					'state'              => 'draft',
					'source_revision_id' => $result->source_revision_id(),
					'document_id'        => $result->document_id(),
					'document_hash'      => $result->document_hash(),
					'validation_hash'    => $result->validation_hash(),
				),
				'source'    => array(
					'worksheet_id' => $result->source_worksheet_id(),
					'revision_id'  => $result->source_revision_id(),
				),
			),
			$correlation_id,
			201,
			array(
				'asset_count'       => $result->asset_count(),
				'created_at_utc'    => $result->created_at_utc(),
				'idempotent_replay' => $result->replayed(),
				'reissued_id_count' => $result->reissued_id_count(),
			),
			$this->links_for( $result->worksheet_id(), $result->revision_id() ),
			array( self::ACTION_VIEW, self::ACTION_LIST_REVISIONS, self::ACTION_VIEW_REVISION, self::ACTION_SAVE_DRAFT )
		);
	}

	/**
	 * Validate revision.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function validate_revision( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant || ! $this->validate_worksheet_revision instanceof Validate_Worksheet_Revision ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 422, $correlation_id );
		}

		try {
			$revision_id = $this->path_id( $request, 'revision_id' );
			$input       = $this->validate_input( $request );
			$result      = $this->validate_worksheet_revision->execute(
				new Validate_Worksheet_Revision_Command(
					$grant->metadata()->actor_user_id(),
					$revision_id,
					$input['command_id'],
					$input['expected_state'],
					$input['expected_revision_id'],
					$idempotency->fingerprint(),
					$correlation_id,
					$this->canonical
				)
			);
			$approval    = $this->approval_readiness_projection(
				$grant->metadata()->actor_user_id(),
				$result
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Failure $failure ) {
			return $this->worksheet_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'worksheet_id' => $result->worksheet_id(),
				'revision'     => array(
					'id'              => $result->revision_id(),
					'state'           => $result->revision_state(),
					'document_hash'   => $result->document_hash(),
					'validation_hash' => $result->source_validation_hash(),
				),
				'validation'   => array(
					'schema_id'       => Validate_Worksheet_Revision::PROJECTION_SCHEMA,
					'schema_version'  => Validate_Worksheet_Revision::PROJECTION_VERSION,
					'status'          => $result->status(),
					'release_ready'   => $result->release_ready(),
					'projection_hash' => $result->projection_hash(),
					'checks'          => $result->checks(),
					'blockers'        => $result->blockers(),
					'warnings'        => $result->warnings(),
					'statistics'      => $result->statistics(),
				),
				'approval'     => $approval,
			),
			$correlation_id,
			200,
			array(
				'idempotency_key_bound' => true,
				'mutation_count'        => 0,
				'validated_at_utc'      => $result->validated_at_utc(),
			),
			$this->links_for( $result->worksheet_id(), $result->revision_id() ),
			array( self::ACTION_VIEW_REVISION )
		);
	}

	/**
	 * Approve revision.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function approve_revision( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant || ! $this->approve_worksheet_revision instanceof Approve_Worksheet_Revision ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 422, $correlation_id );
		}

		try {
			$revision_id = $this->path_id( $request, 'revision_id' );
			$input       = $this->approve_input( $request );
			$result      = $this->approve_worksheet_revision->execute(
				new Approve_Worksheet_Revision_Command(
					$grant->metadata()->actor_user_id(),
					$revision_id,
					$input['command_id'],
					$input['expected_state'],
					$input['expected_revision_id'],
					$input['expected_document_hash'],
					$input['expected_validation_projection_hash'],
					$input['expected_approval_decision_hash'],
					$idempotency->fingerprint(),
					$correlation_id,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Failure $failure ) {
			return $this->worksheet_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'worksheet_id' => $result->worksheet_id(),
				'revision'     => array(
					'id'                  => $result->revision_id(),
					'revision_no'         => $result->revision_no(),
					'state'               => $result->revision_state(),
					'document_hash'       => $result->document_hash(),
					'approved_by_user_id' => $result->approved_by_user_id(),
					'approved_at_utc'     => $result->approved_at_utc(),
				),
				'approval'     => array(
					'status'                     => 'ready',
					'decision_hash'              => $result->approval_decision_hash(),
					'evidence_hash'              => $result->approval_evidence_hash(),
					'validation_projection_hash' => $result->validation_projection_hash(),
					'evaluation_projection_hash' => $result->evaluation_projection_hash(),
					'policy'                     => array(
						'revision_id'  => $result->policy_revision_id(),
						'content_hash' => $result->policy_hash(),
					),
				),
			),
			$correlation_id,
			200,
			array(
				'idempotent_replay' => $result->replayed(),
				'mutation_count'    => $result->replayed() ? 0 : 1,
			),
			$this->links_for( $result->worksheet_id(), $result->revision_id() ),
			array( self::ACTION_VIEW_REVISION, self::ACTION_PREVIEW )
		);
	}

	/**
	 * Publish revision.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function publish_revision( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant || ! $this->publish_worksheet_revision instanceof Publish_Worksheet_Revision ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 422, $correlation_id );
		}

		try {
			$revision_id = $this->path_id( $request, 'revision_id' );
			$input       = $this->publish_input( $request );
			$result      = $this->publish_worksheet_revision->execute(
				new Publish_Worksheet_Revision_Command(
					$grant->metadata()->actor_user_id(),
					$revision_id,
					$input['command_id'],
					$input['expected_state'],
					$input['expected_revision_id'],
					$input['expected_document_hash'],
					$input['expected_approval_decision_hash'],
					$idempotency->fingerprint(),
					$correlation_id,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Failure $failure ) {
			return $this->worksheet_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'worksheet_id'           => $result->worksheet_id(),
				'revision'               => array(
					'id'            => $result->revision_id(),
					'state'         => 'approved',
					'document_hash' => $result->document_hash(),
				),
				'publication'            => array(
					'id'                          => $result->publication_id(),
					'publication_no'              => $result->publication_no(),
					'state'                       => $result->publication_state(),
					'publication_hash'            => $result->publication_hash(),
					'projection_manifest_version' => $result->projection_manifest_version(),
					'profile_hashes'              => $result->profile_hashes(),
					'published_by_user_id'        => $result->published_by_user_id(),
					'published_at_utc'            => $result->published_at_utc(),
				),
				'approval_decision_hash' => $result->approval_decision_hash(),
			),
			$correlation_id,
			201,
			array(
				'idempotent_replay' => $result->replayed(),
				'mutation_count'    => $result->replayed() ? 0 : 1,
			),
			$this->links_for( $result->worksheet_id(), $result->revision_id() ),
			array( self::ACTION_VIEW_REVISION, self::ACTION_PREVIEW )
		);
	}

	/**
	 * Archive worksheet.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function archive_worksheet( object $request ) {
		return $this->change_lifecycle( $request, Worksheet_Lifecycle_Command::ARCHIVE );
	}

	/**
	 * Restore worksheet.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore_worksheet( object $request ) {
		return $this->change_lifecycle( $request, Worksheet_Lifecycle_Command::RESTORE );
	}

	/**
	 * Change lifecycle.
	 *
	 * @param object $request Request.
	 * @param string $operation Operation.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function change_lifecycle( object $request, string $operation ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant || ! $this->change_worksheet_lifecycle instanceof Change_Worksheet_Lifecycle ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 422, $correlation_id );
		}

		try {
			$worksheet_id = $this->path_id( $request, 'worksheet_id' );
			$input        = $this->lifecycle_input( $request, $operation );
			$result       = $this->change_worksheet_lifecycle->execute(
				new Worksheet_Lifecycle_Command(
					$grant->metadata()->actor_user_id(),
					$operation,
					$worksheet_id,
					$input['command_id'],
					$input['expected_state'],
					$input['expected_revision_id'],
					$input['expected_lock_version'],
					$idempotency->fingerprint(),
					$correlation_id,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Failure $failure ) {
			return $this->worksheet_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'worksheet'  => array(
					'id'                    => $result->worksheet_id(),
					'state'                 => $result->state(),
					'current_revision_id'   => $result->current_revision_id(),
					'latest_publication_id' => $result->latest_publication_id(),
					'lock_version'          => $result->lock_version(),
					'archived_at_utc'       => $result->archived_at_utc(),
				),
				'transition' => array(
					'operation'          => $result->operation(),
					'previous_state'     => $result->previous_state(),
					'changed_by_user_id' => $result->changed_by_user_id(),
					'changed_at_utc'     => $result->changed_at_utc(),
				),
			),
			$correlation_id,
			200,
			array(
				'idempotent_replay' => $result->replayed(),
				'mutation_count'    => $result->replayed() ? 0 : 1,
			),
			$this->links_for( $result->worksheet_id(), $result->current_revision_id() ),
			array( self::ACTION_VIEW, Worksheet_Lifecycle_Command::ARCHIVE === $operation ? self::ACTION_RESTORE : self::ACTION_ARCHIVE )
		);
	}

	/**
	 * Check object permission.
	 *
	 * @param object $request Request.
	 * @param string $action_key Action key.
	 * @param string $object_type Object type.
	 * @param string $parameter Parameter.
	 * @return true|\WP_Error
	 */
	private function check_object_permission(
		object $request,
		string $action_key,
		string $object_type,
		string $parameter
	) {
		try {
			$object = new Object_Scope(
				$object_type,
				$this->path_id( $request, $parameter ),
				'view'
			);
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			$action_key,
			array( Addon_Capabilities::VIEW_WORKSHEETS ),
			null,
			$object
		);
	}

	/**
	 * List query.
	 *
	 * @param object $request Request.
	 * @return array{state:string,cursor:string,limit:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function list_query( object $request ): array {
		$params = $this->query_params( $request );
		$this->assert_allowed_keys( $params, array( 'state', 'cursor', 'limit' ) );
		$page  = $this->normalize_page_query( $params );
		$state = $params['state'] ?? '';
		if ( ! is_string( $state ) ) {
			throw new InvalidArgumentException( 'Worksheet state must be a string.' );
		}

		return array(
			'state'  => $state,
			'cursor' => $page['cursor'],
			'limit'  => $page['limit'],
		);
	}

	/**
	 * Page query.
	 *
	 * @param object $request Request.
	 * @return array{cursor:string,limit:int}
	 */
	private function page_query( object $request ): array {
		$params = $this->query_params( $request );
		$this->assert_allowed_keys( $params, array( 'cursor', 'limit' ) );

		return $this->normalize_page_query( $params );
	}

	/**
	 * Normalize page query.
	 *
	 * @param array<string,mixed> $params Params.
	 * @return array{cursor:string,limit:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_page_query( array $params ): array {
		$cursor = $params['cursor'] ?? '';
		$limit  = $params['limit'] ?? Read_Worksheets::DEFAULT_LIMIT;
		if ( ! is_string( $cursor ) ) {
			throw new InvalidArgumentException( 'Worksheet cursor must be a string.' );
		}
		if ( is_string( $limit ) ) {
			if ( 1 !== preg_match( '/^[1-9][0-9]{0,2}$/D', $limit ) ) {
				throw new InvalidArgumentException( 'Worksheet limit must be canonical.' );
			}
			$limit = (int) $limit;
		}
		if ( ! is_int( $limit ) ) {
			throw new InvalidArgumentException( 'Worksheet limit must be an integer.' );
		}

		return array(
			'cursor' => $cursor,
			'limit'  => $limit,
		);
	}

	/**
	 * Preview query.
	 *
	 * @param object $request Request.
	 * @return array{projection:string,profile:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function preview_query( object $request ): array {
		$params = $this->query_params( $request );
		$this->assert_allowed_keys( $params, array( 'projection', 'profile' ) );
		$projection = $params['projection'] ?? null;
		$profile    = $params['profile'] ?? null;
		if ( ! is_string( $projection ) || ! in_array( $projection, array( 'student', 'print' ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet preview projection is invalid.' );
		}
		if ( 'student' === $projection ) {
			if ( null !== $profile ) {
				throw new InvalidArgumentException( 'Student preview does not accept a print profile.' );
			}

			return array(
				'projection' => 'student',
				'profile'    => 'student',
			);
		}
		if ( null === $profile || '' === $profile ) {
			$profile = 'student_blank';
		}
		if (
			! is_string( $profile )
			|| ! in_array( $profile, array( 'student_blank', 'teacher_blank' ), true )
		) {
			throw new InvalidArgumentException( 'Worksheet print preview profile is invalid.' );
		}

		return array(
			'projection' => 'print',
			'profile'    => $profile,
		);
	}

	/**
	 * Create input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,title:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function create_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'CreateWorksheet JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			throw new InvalidArgumentException( 'CreateWorksheet JSON body is invalid.' );
		}
		$this->assert_allowed_keys( $input, array( 'command_id', 'expected', 'payload' ), true );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || ! is_array( $payload ) ) {
			throw new InvalidArgumentException( 'CreateWorksheet command sections are invalid.' );
		}
		$this->assert_allowed_keys( $expected, array( 'expected_state' ), true );
		$this->assert_allowed_keys( $payload, array( 'title' ), true );
		if (
			! is_string( $input['command_id'] ?? null )
			|| ! is_string( $expected['expected_state'] ?? null )
			|| ! is_string( $payload['title'] ?? null )
		) {
			throw new InvalidArgumentException( 'CreateWorksheet command values are invalid.' );
		}

		return array(
			'command_id'     => $input['command_id'],
			'expected_state' => $expected['expected_state'],
			'title'          => $payload['title'],
		);
	}

	/**
	 * Duplicate input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_revision_id:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function duplicate_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'DuplicateWorksheet JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'DuplicateWorksheet JSON body is invalid.' );
		}
		$this->assert_allowed_keys( $input, array( 'command_id', 'expected', 'payload' ), true );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) ) {
			throw new InvalidArgumentException( 'DuplicateWorksheet command sections are invalid.' );
		}
		$this->assert_allowed_keys(
			$expected,
			array( 'expected_state', 'expected_revision_id' ),
			true
		);
		if ( array() !== $payload ) {
			throw new InvalidArgumentException( 'DuplicateWorksheet payload must be empty.' );
		}
		$command_id           = $input['command_id'] ?? null;
		$expected_state       = $expected['expected_state'] ?? null;
		$expected_revision_id = $expected['expected_revision_id'] ?? null;
		if (
			! is_string( $command_id )
			|| ! is_string( $expected_state )
			|| ! in_array( $expected_state, array( 'draft', 'active', 'archived' ), true )
			|| ! is_string( $expected_revision_id )
		) {
			throw new InvalidArgumentException( 'DuplicateWorksheet command values are invalid.' );
		}
		new Public_Id( $expected_revision_id );

		return array(
			'command_id'           => $command_id,
			'expected_state'       => $expected_state,
			'expected_revision_id' => $expected_revision_id,
		);
	}

	/**
	 * Validate input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_revision_id:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function validate_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'ValidateWorksheetRevision JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'ValidateWorksheetRevision JSON body is invalid.' );
		}
		$this->assert_allowed_keys( $input, array( 'command_id', 'expected', 'payload' ), true );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if (
			! is_array( $expected ) || array_is_list( $expected )
			|| ! is_array( $payload ) || ( array() !== $payload && array_is_list( $payload ) )
		) {
			throw new InvalidArgumentException( 'ValidateWorksheetRevision command sections are invalid.' );
		}
		$this->assert_allowed_keys(
			$expected,
			array( 'expected_state', 'expected_revision_id' ),
			true
		);
		if ( array() !== $payload ) {
			throw new InvalidArgumentException( 'ValidateWorksheetRevision payload must be empty.' );
		}
		$command_id           = $input['command_id'] ?? null;
		$expected_state       = $expected['expected_state'] ?? null;
		$expected_revision_id = $expected['expected_revision_id'] ?? null;
		if (
			! is_string( $command_id )
			|| ! is_string( $expected_state )
			|| ! in_array( $expected_state, array( 'draft', 'approved' ), true )
			|| ! is_string( $expected_revision_id )
		) {
			throw new InvalidArgumentException( 'ValidateWorksheetRevision command values are invalid.' );
		}
		new Public_Id( $expected_revision_id );

		return array(
			'command_id'           => $command_id,
			'expected_state'       => $expected_state,
			'expected_revision_id' => $expected_revision_id,
		);
	}

	/**
	 * Approve input.
	 *
	 * @param object $request Request.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function approve_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'ApproveWorksheetRevision JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'ApproveWorksheetRevision JSON body is invalid.' );
		}
		$this->assert_allowed_keys( $input, array( 'command_id', 'expected', 'payload' ), true );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'ApproveWorksheetRevision command sections are invalid.' );
		}
		$this->assert_allowed_keys(
			$expected,
			array(
				'expected_state',
				'expected_revision_id',
				'expected_document_hash',
				'expected_validation_projection_hash',
				'expected_approval_decision_hash',
			),
			true
		);
		$result = array(
			'command_id'                          => $input['command_id'] ?? null,
			'expected_state'                      => $expected['expected_state'] ?? null,
			'expected_revision_id'                => $expected['expected_revision_id'] ?? null,
			'expected_document_hash'              => $expected['expected_document_hash'] ?? null,
			'expected_validation_projection_hash' => $expected['expected_validation_projection_hash'] ?? null,
			'expected_approval_decision_hash'     => $expected['expected_approval_decision_hash'] ?? null,
		);
		foreach ( $result as $value ) {
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'ApproveWorksheetRevision command value is invalid.' );
			}
		}
		if ( 'draft' !== $result['expected_state'] ) {
			throw new InvalidArgumentException( 'ApproveWorksheetRevision expected state is invalid.' );
		}
		new Public_Id( $result['expected_revision_id'] );
		foreach ( array( 'expected_document_hash', 'expected_validation_projection_hash', 'expected_approval_decision_hash' ) as $field ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'ApproveWorksheetRevision expected hash is invalid.' );
			}
		}
		return $result;
	}

	/**
	 * Publish input.
	 *
	 * @param object $request Request.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function publish_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'PublishWorksheetRevision JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'PublishWorksheetRevision JSON body is invalid.' );
		}
		$this->assert_allowed_keys( $input, array( 'command_id', 'expected', 'payload' ), true );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'PublishWorksheetRevision command sections are invalid.' );
		}
		$this->assert_allowed_keys(
			$expected,
			array( 'expected_state', 'expected_revision_id', 'expected_document_hash', 'expected_approval_decision_hash' ),
			true
		);
		$result = array(
			'command_id'                      => $input['command_id'] ?? null,
			'expected_state'                  => $expected['expected_state'] ?? null,
			'expected_revision_id'            => $expected['expected_revision_id'] ?? null,
			'expected_document_hash'          => $expected['expected_document_hash'] ?? null,
			'expected_approval_decision_hash' => $expected['expected_approval_decision_hash'] ?? null,
		);
		foreach ( $result as $value ) {
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'PublishWorksheetRevision command value is invalid.' );
			}
		}
		if ( 'approved' !== $result['expected_state'] ) {
			throw new InvalidArgumentException( 'PublishWorksheetRevision expected state is invalid.' );
		}
		new Public_Id( $result['expected_revision_id'] );
		foreach ( array( 'expected_document_hash', 'expected_approval_decision_hash' ) as $field ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'PublishWorksheetRevision expected hash is invalid.' );
			}
		}
		return $result;
	}

	/**
	 * Lifecycle input.
	 *
	 * @param object $request Request.
	 * @param string $operation Operation.
	 * @return array{command_id:string,expected_state:string,expected_revision_id:string,expected_lock_version:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lifecycle_input( object $request, string $operation ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle JSON body is invalid.' );
		}
		$this->assert_allowed_keys( $input, array( 'command_id', 'expected', 'payload' ), true );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle command sections are invalid.' );
		}
		$this->assert_allowed_keys(
			$expected,
			array( 'expected_state', 'expected_revision_id', 'expected_lock_version' ),
			true
		);
		$command_id            = $input['command_id'] ?? null;
		$expected_state        = $expected['expected_state'] ?? null;
		$expected_revision_id  = $expected['expected_revision_id'] ?? null;
		$expected_lock_version = $expected['expected_lock_version'] ?? null;
		if (
			! is_string( $command_id ) || ! is_string( $expected_state ) || ! is_string( $expected_revision_id )
			|| ! is_int( $expected_lock_version ) || $expected_lock_version < 0
			|| ( Worksheet_Lifecycle_Command::ARCHIVE === $operation && ! in_array( $expected_state, array( 'draft', 'active' ), true ) )
			|| ( Worksheet_Lifecycle_Command::RESTORE === $operation && 'archived' !== $expected_state )
		) {
			throw new InvalidArgumentException( 'Worksheet lifecycle command values are invalid.' );
		}
		new Public_Id( $expected_revision_id );

		return array(
			'command_id'            => $command_id,
			'expected_state'        => $expected_state,
			'expected_revision_id'  => $expected_revision_id,
			'expected_lock_version' => $expected_lock_version,
		);
	}

	/**
	 * Save expected input.
	 *
	 * @param object $request Request.
	 * @return array{expected_state:string,expected_revision_id:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function save_expected_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft JSON body is unavailable.' );
		}
		$input    = $request->get_json_params();
		$expected = is_array( $input ) ? ( $input['expected'] ?? null ) : null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft expected input is invalid.' );
		}
		$this->assert_allowed_keys( $expected, array( 'expected_state', 'expected_revision_id' ), true );
		$expected_state       = $expected['expected_state'] ?? null;
		$expected_revision_id = $expected['expected_revision_id'] ?? null;
		if (
			! is_string( $expected_state )
			|| ! in_array( $expected_state, array( 'draft', 'active' ), true )
			|| ! is_string( $expected_revision_id )
		) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft expected values are invalid.' );
		}
		new Public_Id( $expected_revision_id );

		return array(
			'expected_state'       => $expected_state,
			'expected_revision_id' => $expected_revision_id,
		);
	}

	/**
	 * Save input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_revision_id:string,teacher_document:array<string,mixed>,achievement_links:array<int,array<string,mixed>>,staged_asset_receipt_ids:array<int,string>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function save_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft JSON body is invalid.' );
		}
		$this->assert_allowed_keys( $input, array( 'command_id', 'expected', 'payload' ), true );
		$expected = $this->save_expected_input( $request );
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft payload is invalid.' );
		}
		$this->assert_allowed_keys(
			$payload,
			array( 'teacher_document', 'achievement_links', 'staged_asset_receipt_ids' ),
			true
		);
		if (
			! is_string( $input['command_id'] ?? null )
			|| ! is_array( $payload['teacher_document'] ?? null )
			|| ! is_array( $payload['achievement_links'] ?? null )
			|| ! is_array( $payload['staged_asset_receipt_ids'] ?? null )
		) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft command values are invalid.' );
		}

		return array(
			'command_id'               => $input['command_id'],
			'expected_state'           => $expected['expected_state'],
			'expected_revision_id'     => $expected['expected_revision_id'],
			'teacher_document'         => $payload['teacher_document'],
			'achievement_links'        => $payload['achievement_links'],
			'staged_asset_receipt_ids' => $payload['staged_asset_receipt_ids'],
		);
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
			throw new InvalidArgumentException( 'Worksheet query parameters are unavailable.' );
		}
		$params = $request->get_query_params();
		if ( ! is_array( $params ) ) {
			throw new InvalidArgumentException( 'Worksheet query parameters are invalid.' );
		}

		return $params;
	}

	/**
	 * Reject invalid allowed keys.
	 *
	 * @param array<string,mixed> $values Values.
	 * @param array<int,string>   $allowed Allowed.
	 * @param bool                $require_all Require all.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_allowed_keys( array $values, array $allowed, bool $require_all = false ): void {
		if ( array_diff( array_keys( $values ), $allowed ) ) {
			throw new InvalidArgumentException( 'Worksheet input contains unknown fields.' );
		}
		if ( $require_all && array_diff( $allowed, array_keys( $values ) ) ) {
			throw new InvalidArgumentException( 'Worksheet input is incomplete.' );
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
			throw new InvalidArgumentException( 'Worksheet path parameters are unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params[ $name ] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet path ID is invalid.' );
		}

		return $value;
	}

	/**
	 * Site locale.
	 *
	 * @return string
	 */
	private function site_locale(): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : '';
		if ( ! is_string( $locale ) || '' === $locale ) {
			$locale = function_exists( 'get_locale' ) ? get_locale() : 'ko_KR';
		}

		return is_string( $locale ) && '' !== $locale ? $locale : 'ko_KR';
	}

	/**
	 * Links for.
	 *
	 * @param string      $worksheet_id Worksheet public identifier.
	 * @param string|null $revision_id Revision id.
	 * @return array<string,string>
	 */
	private function links_for( string $worksheet_id, ?string $revision_id ): array {
		if ( ! function_exists( 'rest_url' ) ) {
			return array();
		}
		$base = rest_url( self::REST_NAMESPACE );
		if ( ! is_string( $base ) || '' === $base ) {
			return array();
		}
		$base  = rtrim( $base, '/' );
		$links = array(
			'worksheet' => $base . '/worksheets/' . $worksheet_id,
			'revisions' => $base . '/worksheets/' . $worksheet_id . '/revisions',
		);
		if ( null !== $revision_id ) {
			$links['revision'] = $base . '/worksheet-revisions/' . $revision_id;
			$links['preview']  = $base . '/worksheet-revisions/' . $revision_id . '/preview';
		}

		return $links;
	}

	/**
	 * Approval readiness projection.
	 *
	 * @param int                                                                                $actor_user_id Authenticated actor from the access context.
	 * @param \OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision_Result $validation Validation.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function approval_readiness_projection(
		int $actor_user_id,
		\OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision_Result $validation
	): array {
		try {
			if ( 'approved' === $validation->revision_state() ) {
				if ( ! $this->publish_worksheet_revision instanceof Publish_Worksheet_Revision ) {
					return $this->unavailable_approval_projection();
				}
				return $this->publish_worksheet_revision->readiness( $actor_user_id, $validation );
			}
			if ( ! $this->approve_worksheet_revision instanceof Approve_Worksheet_Revision ) {
				return $this->unavailable_approval_projection();
			}
			$decision = $this->approve_worksheet_revision->readiness( $actor_user_id, $validation );
			return array(
				'status'         => $decision->status(),
				'approval_ready' => $decision->approval_ready(),
				'decision_hash'  => $decision->decision_hash(),
				'evidence_hash'  => $decision->evidence_hash(),
				'blockers'       => $decision->blockers(),
				'warnings'       => $decision->warnings(),
			);
		} catch ( Worksheet_Failure $failure ) {
			if (
				in_array(
					$failure->reason(),
					array( Worksheet_Failure::APPROVAL_EVIDENCE_UNAVAILABLE, Worksheet_Failure::PUBLICATION_APPROVAL_UNAVAILABLE ),
					true
				)
			) {
				return $this->unavailable_approval_projection();
			}
			throw $failure;
		}
	}

	/**
	 * Unavailable approval projection.
	 *
	 * @return array<string,mixed>
	 */
	private function unavailable_approval_projection(): array {
		return array(
			'status'         => 'unavailable',
			'approval_ready' => false,
			'decision_hash'  => null,
			'evidence_hash'  => null,
			'blockers'       => array( array( 'code' => 'worksheet_approval_evidence_unavailable' ) ),
			'warnings'       => array(),
		);
	}

	/**
	 * Worksheet problem.
	 *
	 * @param Worksheet_Failure $failure Failure.
	 * @param string            $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function worksheet_problem( Worksheet_Failure $failure, string $correlation_id ) {
		$status = match ( $failure->reason() ) {
			Worksheet_Failure::IDEMPOTENCY_CONFLICT,
			Worksheet_Failure::REVISION_CONFLICT,
			Worksheet_Failure::APPROVAL_CONFLICT,
			Worksheet_Failure::PUBLICATION_CONFLICT,
			Worksheet_Failure::LIFECYCLE_CONFLICT => 409,
			Worksheet_Failure::NOT_FOUND => 404,
			Worksheet_Failure::ASSET_NOT_READY,
			Worksheet_Failure::APPROVAL_BLOCKED,
			Worksheet_Failure::PUBLICATION_BLOCKED,
			Worksheet_Failure::PUBLICATION_CONFIGURATION_UNAVAILABLE => 422,
			default => 503,
		};

		if (
			in_array(
				$failure->reason(),
				array(
					Worksheet_Failure::REVISION_CONFLICT,
					Worksheet_Failure::APPROVAL_CONFLICT,
					Worksheet_Failure::APPROVAL_BLOCKED,
					Worksheet_Failure::PUBLICATION_CONFLICT,
					Worksheet_Failure::PUBLICATION_BLOCKED,
					Worksheet_Failure::LIFECYCLE_CONFLICT,
				),
				true
			)
		) {
			return $this->envelopes->problem_with_safe_data(
				Rest_Problem::for_status( $status, $correlation_id ),
				$failure->safe_context()
			);
		}

		return $this->problem( $status, $correlation_id );
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
