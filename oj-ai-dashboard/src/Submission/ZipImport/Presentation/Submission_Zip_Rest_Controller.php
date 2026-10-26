<?php
/**
 * Submission ZIP REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Presentation;

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
use OJAI\Dashboard\Submission\ZipImport\Application\Cancel_Submission_Zip;
use OJAI\Dashboard\Submission\ZipImport\Application\Cancel_Submission_Zip_Command;
use OJAI\Dashboard\Submission\ZipImport\Application\Confirm_Submission_Zip;
use OJAI\Dashboard\Submission\ZipImport\Application\Confirm_Submission_Zip_Command;
use OJAI\Dashboard\Submission\ZipImport\Application\Map_Submission_Zip_Items;
use OJAI\Dashboard\Submission\ZipImport\Application\Map_Submission_Zip_Items_Command;
use OJAI\Dashboard\Submission\ZipImport\Application\Read_Submission_Zip_Preview;
use OJAI\Dashboard\Submission\ZipImport\Application\Stage_Submission_Zip;
use OJAI\Dashboard\Submission\ZipImport\Application\Stage_Submission_Zip_Command;
use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activates frozen A-DS08 C19-C21 in the OJ Console authorization shell.
 */
final class Submission_Zip_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_STAGE    = '/assignments/(?P<assignment_id>[0-9A-HJKMNP-TV-Z]{26})/commands/import-zip';
	public const ROUTE_PREVIEW  = '/zip-uploads/(?P<upload_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ROUTE_MAP      = '/zip-uploads/(?P<upload_id>[0-9A-HJKMNP-TV-Z]{26})/commands/map';
	public const ROUTE_CONFIRM  = '/zip-uploads/(?P<upload_id>[0-9A-HJKMNP-TV-Z]{26})/commands/confirm';
	public const ROUTE_CANCEL   = '/zip-uploads/(?P<upload_id>[0-9A-HJKMNP-TV-Z]{26})/commands/cancel';
	public const ACTION_CANCEL  = 'submission_zip.cancel';
	public const ACTION_CONFIRM = 'submission_zip.confirm';
	public const ACTION_MAP     = 'submission_zip.map';
	public const ACTION_PREVIEW = 'submission_zip.preview';
	public const ACTION_STAGE   = 'submission_zip.stage';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize submission zip rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff     $permissions Permissions.
	 * @param Read_Submission_Zip_Preview $preview_zip Preview zip.
	 * @param Map_Submission_Zip_Items    $map_zip Map zip.
	 * @param Confirm_Submission_Zip      $confirm_zip Confirm zip.
	 * @param Cancel_Submission_Zip       $cancel_zip Cancel zip.
	 * @param Stage_Submission_Zip        $stage_zip Stage zip.
	 * @param Rest_Envelope_Factory       $envelopes Envelopes.
	 * @param Correlation_Id_Factory      $correlations Correlations.
	 * @param Canonical_Json              $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_Submission_Zip_Preview $preview_zip,
		private Map_Submission_Zip_Items $map_zip,
		private Confirm_Submission_Zip $confirm_zip,
		private Cancel_Submission_Zip $cancel_zip,
		private Stage_Submission_Zip $stage_zip,
		private Rest_Envelope_Factory $envelopes,
		private Correlation_Id_Factory $correlations,
		private Canonical_Json $canonical
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
			self::ROUTE_STAGE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'stage' ),
				'permission_callback' => array( $this, 'permissions_stage' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_PREVIEW,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'permissions_preview' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_MAP,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'map' ),
				'permission_callback' => array( $this, 'permissions_map' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_CONFIRM,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'confirm' ),
				'permission_callback' => array( $this, 'permissions_confirm' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_CANCEL,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => array( $this, 'permissions_cancel' ),
			)
		);
	}

	/**
	 * Permissions stage.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_stage( object $request ) {
		try {
			$context       = $this->context_query( $request );
			$assignment_id = $this->assignment_path_id( $request );
			$input         = $this->zip_input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_STAGE,
			array( Addon_Capabilities::MANAGE_SUBMISSIONS ),
			new Context_Scope( $context['context_type'], $context['context_id'], Context_Scope::OPERATION_MANAGE, null, $context['context_revision'] ),
			new Object_Scope(
				Object_Scope::TYPE_ASSIGNMENT,
				$assignment_id,
				'import_zip',
				(string) $input['expected_schedule_version'],
				$input['expected_state']
			)
		);
	}

	/**
	 * Stage.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function stage( object $request ) {
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
			$context = $this->context_query( $request );
			$input   = $this->zip_input( $request );
			$result  = $this->stage_zip->execute(
				new Stage_Submission_Zip_Command(
					$grant->metadata()->actor_user_id(),
					$this->assignment_path_id( $request ),
					$input['command_id'],
					$input['expected_state'],
					$input['expected_schedule_version'],
					$context['context_type'],
					$context['context_id'],
					$context['context_revision'],
					$input['file'],
					$idempotency->fingerprint(),
					$correlation_id
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Zip_Import_Failure $failure ) {
			return $this->zip_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array( 'upload' => $result->upload() ),
			$correlation_id,
			$result->replayed() ? 200 : 202,
			array(
				'expires_at_utc'    => $result->expires_at_utc(),
				'idempotent_replay' => $result->replayed(),
				'mutation_count'    => $result->replayed() ? 0 : 1,
			)
		);
	}

	/**
	 * Permissions preview.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_preview( object $request ) {
		try {
			$context  = $this->context_query( $request );
			$snapshot = $this->preview_zip->authorization_snapshot( $this->upload_path_id( $request ) );
			if (
				null === $snapshot
				|| $context['context_type'] !== ( $snapshot['context_type'] ?? null )
				|| $context['context_id'] !== ( $snapshot['context_id'] ?? null )
				|| $context['context_revision'] !== ( $snapshot['context_revision'] ?? null )
			) {
				return $this->problem( 404, $this->correlations->create() );
			}
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_PREVIEW,
			array( Addon_Capabilities::MANAGE_SUBMISSIONS ),
			new Context_Scope( $context['context_type'], $context['context_id'], Context_Scope::OPERATION_MANAGE, null, $context['context_revision'] ),
			new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, (string) $snapshot['assignment_id'], 'view_submissions' )
		);
	}

	/**
	 * Preview.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$context = $this->context_query( $request );
			$result  = $this->preview_zip->execute(
				$this->upload_path_id( $request ),
				$context['context_type'],
				$context['context_id'],
				$context['context_revision']
			);
		} catch ( Zip_Import_Failure $failure ) {
			return $this->problem( Zip_Import_Failure::NOT_FOUND === $failure->reason() ? 404 : 503, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}
		return $this->envelopes->success(
			array( 'upload' => $result ),
			$correlation_id,
			200,
			array( 'mutation_count' => 0 )
		);
	}

	/**
	 * Permissions map.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_map( object $request ) {
		try {
			$context = $this->context_query( $request );
			$this->map_input( $request );
			$snapshot = $this->preview_zip->authorization_snapshot( $this->upload_path_id( $request ) );
			if (
				null === $snapshot
				|| $context['context_type'] !== ( $snapshot['context_type'] ?? null )
				|| $context['context_id'] !== ( $snapshot['context_id'] ?? null )
				|| $context['context_revision'] !== ( $snapshot['context_revision'] ?? null )
			) {
				return $this->problem( 404, $this->correlations->create() );
			}
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_MAP,
			array( Addon_Capabilities::MANAGE_SUBMISSIONS ),
			new Context_Scope( $context['context_type'], $context['context_id'], Context_Scope::OPERATION_MANAGE, null, $context['context_revision'] ),
			new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, (string) $snapshot['assignment_id'], 'import_zip' )
		);
	}

	/**
	 * Map submission zip rest controller.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function map( object $request ) {
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
			$context = $this->context_query( $request );
			$input   = $this->map_input( $request );
			$result  = $this->map_zip->execute(
				new Map_Submission_Zip_Items_Command(
					$grant->metadata()->actor_user_id(),
					$this->upload_path_id( $request ),
					$input['command_id'],
					$input['expected_mapping_version'],
					$input['items'],
					$context['context_type'],
					$context['context_id'],
					$context['context_revision'],
					$idempotency->fingerprint(),
					$correlation_id,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Zip_Import_Failure $failure ) {
			return $this->zip_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'items'  => $result->items(),
				'upload' => $result->upload(),
			),
			$correlation_id,
			200,
			array(
				'idempotent_replay' => $result->replayed(),
				'mapped_at_utc'     => $result->mapped_at_utc(),
				'mapped_by_user_id' => $result->mapped_by_user_id(),
				'mutation_count'    => $result->replayed() ? 0 : 1,
			)
		);
	}

	/**
	 * Permissions confirm.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_confirm( object $request ) {
		try {
			$context = $this->context_query( $request );
			$this->confirm_input( $request );
			$snapshot = $this->authorization_snapshot( $request, $context );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_CONFIRM,
			array( Addon_Capabilities::MANAGE_SUBMISSIONS ),
			new Context_Scope( $context['context_type'], $context['context_id'], Context_Scope::OPERATION_MANAGE, null, $context['context_revision'] ),
			new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, (string) $snapshot['assignment_id'], 'import_zip' )
		);
	}

	/**
	 * Confirm.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function confirm( object $request ) {
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
			$context = $this->context_query( $request );
			$input   = $this->confirm_input( $request );
			$result  = $this->confirm_zip->execute(
				new Confirm_Submission_Zip_Command(
					$grant->metadata()->actor_user_id(),
					$this->upload_path_id( $request ),
					$input['command_id'],
					$input['expected_mapping_version'],
					$input['reason'],
					$context['context_type'],
					$context['context_id'],
					$context['context_revision'],
					$idempotency->fingerprint(),
					$correlation_id,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Zip_Import_Failure $failure ) {
			return $this->zip_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'items'  => $result->items(),
				'upload' => $result->upload(),
			),
			$correlation_id,
			200,
			array(
				'confirmed_at_utc'     => $result->confirmed_at_utc(),
				'confirmed_by_user_id' => $result->confirmed_by_user_id(),
				'idempotent_replay'    => $result->replayed(),
				'mutation_count'       => $result->replayed() ? 0 : 1,
			)
		);
	}

	/**
	 * Permissions cancel.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_cancel( object $request ) {
		try {
			$context = $this->context_query( $request );
			$this->cancel_input( $request );
			$snapshot = $this->authorization_snapshot( $request, $context );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_CANCEL,
			array( Addon_Capabilities::MANAGE_SUBMISSIONS ),
			new Context_Scope( $context['context_type'], $context['context_id'], Context_Scope::OPERATION_MANAGE, null, $context['context_revision'] ),
			new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, (string) $snapshot['assignment_id'], 'import_zip' )
		);
	}

	/**
	 * Cancel.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel( object $request ) {
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
			$context = $this->context_query( $request );
			$input   = $this->cancel_input( $request );
			$result  = $this->cancel_zip->execute(
				new Cancel_Submission_Zip_Command(
					$grant->metadata()->actor_user_id(),
					$this->upload_path_id( $request ),
					$input['command_id'],
					$input['expected_mapping_version'],
					$context['context_type'],
					$context['context_id'],
					$context['context_revision'],
					$idempotency->fingerprint(),
					$correlation_id,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Zip_Import_Failure $failure ) {
			return $this->zip_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array( 'upload' => $result->upload() ),
			$correlation_id,
			200,
			array(
				'canceled_at_utc'     => $result->canceled_at_utc(),
				'canceled_by_user_id' => $result->canceled_by_user_id(),
				'idempotent_replay'   => $result->replayed(),
				'mutation_count'      => $result->replayed() ? 0 : 1,
			)
		);
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param object                                                               $request Request.
	 * @param array{context_type:string,context_id:string,context_revision:string} $context Access or execution context for this operation.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function authorization_snapshot( object $request, array $context ): array {
		$snapshot = $this->preview_zip->authorization_snapshot( $this->upload_path_id( $request ) );
		if (
			null === $snapshot
			|| $context['context_type'] !== ( $snapshot['context_type'] ?? null )
			|| $context['context_id'] !== ( $snapshot['context_id'] ?? null )
			|| $context['context_revision'] !== ( $snapshot['context_revision'] ?? null )
		) {
			throw new InvalidArgumentException( 'Submission ZIP authorization snapshot is unavailable.' );
		}
		return $snapshot;
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
			throw new InvalidArgumentException( 'StageSubmissionZip Context query is unavailable.' );
		}
		$params = $request->get_query_params();
		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new InvalidArgumentException( 'StageSubmissionZip Context query is invalid.' );
		}
		$this->exact_keys( $params, array( 'context_id', 'context_revision', 'context_type' ) );
		foreach ( array(
			'context_type'     => 16,
			'context_id'       => 191,
			'context_revision' => 191,
		) as $field => $maximum ) {
			$value = $params[ $field ] ?? null;
			if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				throw new InvalidArgumentException( 'StageSubmissionZip Context value is invalid.' );
			}
		}
		return array(
			'context_type'     => $params['context_type'],
			'context_id'       => $params['context_id'],
			'context_revision' => $params['context_revision'],
		);
	}

	/**
	 * Zip input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_schedule_version:int,file:array<string,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function zip_input( object $request ): array {
		if ( ! method_exists( $request, 'get_body_params' ) || ! method_exists( $request, 'get_file_params' ) ) {
			throw new InvalidArgumentException( 'StageSubmissionZip multipart body is unavailable.' );
		}
		$body  = $request->get_body_params();
		$files = $request->get_file_params();
		if ( ! is_array( $body ) || array_is_list( $body ) || ! is_array( $files ) || array_is_list( $files ) ) {
			throw new InvalidArgumentException( 'StageSubmissionZip multipart body is invalid.' );
		}
		$this->exact_keys( $body, array( 'command_id', 'expected_schedule_version', 'expected_state' ) );
		$this->exact_keys( $files, array( 'file' ) );
		$version = $body['expected_schedule_version'] ?? null;
		if ( is_string( $version ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $version ) ) {
			$version = (int) $version;
		}
		if (
			! is_string( $body['command_id'] ?? null )
			|| ! is_string( $body['expected_state'] ?? null )
			|| ! is_int( $version )
		) {
			throw new InvalidArgumentException( 'StageSubmissionZip multipart value is invalid.' );
		}
		$file = $files['file'] ?? null;
		if ( ! is_array( $file ) || array_is_list( $file ) ) {
			throw new InvalidArgumentException( 'StageSubmissionZip file is invalid.' );
		}
		$this->exact_keys( $file, array( 'error', 'name', 'size', 'tmp_name', 'type' ) );
		if ( ! is_int( $file['error'] ?? null ) || ! is_int( $file['size'] ?? null ) ) {
			throw new InvalidArgumentException( 'StageSubmissionZip file metadata is invalid.' );
		}
		foreach ( array( 'name', 'tmp_name', 'type' ) as $field ) {
			if ( ! is_string( $file[ $field ] ?? null ) ) {
				throw new InvalidArgumentException( 'StageSubmissionZip file value is invalid.' );
			}
		}
		return array(
			'command_id'                => $body['command_id'],
			'expected_state'            => $body['expected_state'],
			'expected_schedule_version' => $version,
			'file'                      => $file,
		);
	}

	/**
	 * Map input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_mapping_version:int,items:array<int,array<string,mixed>>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function map_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'MapSubmissionZipItems body is unavailable.' );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array_is_list( $body ) ) {
			throw new InvalidArgumentException( 'MapSubmissionZipItems body is invalid.' );
		}
		$this->exact_keys( $body, array( 'command_id', 'expected_mapping_version', 'items' ) );
		if (
			! is_string( $body['command_id'] ?? null ) || ! is_int( $body['expected_mapping_version'] ?? null )
			|| ! is_array( $body['items'] ?? null ) || ! array_is_list( $body['items'] )
		) {
			throw new InvalidArgumentException( 'MapSubmissionZipItems value is invalid.' );
		}
		foreach ( $body['items'] as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'MapSubmissionZipItems item is invalid.' );
			}
			$this->exact_keys( $item, array( 'item_no', 'learner_user_id', 'response_id', 'target_id' ) );
			if (
				! is_int( $item['item_no'] ?? null ) || ! is_int( $item['learner_user_id'] ?? null )
				|| ! is_string( $item['target_id'] ?? null ) || ! is_string( $item['response_id'] ?? null )
			) {
				throw new InvalidArgumentException( 'MapSubmissionZipItems item value is invalid.' );
			}
			new Public_Id( $item['target_id'] );
			new Public_Id( $item['response_id'] );
		}
		return array(
			'command_id'               => $body['command_id'],
			'expected_mapping_version' => $body['expected_mapping_version'],
			'items'                    => $body['items'],
		);
	}

	/**
	 * Confirm input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_mapping_version:int,reason:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function confirm_input( object $request ): array {
		$body = $this->json_object( $request, 'ConfirmSubmissionZip' );
		$this->exact_keys( $body, array( 'command_id', 'expected_mapping_version', 'reason' ) );
		if (
			! is_string( $body['command_id'] ?? null )
			|| ! is_int( $body['expected_mapping_version'] ?? null )
			|| ! is_string( $body['reason'] ?? null )
		) {
			throw new InvalidArgumentException( 'ConfirmSubmissionZip value is invalid.' );
		}
		return array(
			'command_id'               => $body['command_id'],
			'expected_mapping_version' => $body['expected_mapping_version'],
			'reason'                   => $body['reason'],
		);
	}

	/**
	 * Cancel input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_mapping_version:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function cancel_input( object $request ): array {
		$body = $this->json_object( $request, 'CancelSubmissionZip' );
		$this->exact_keys( $body, array( 'command_id', 'expected_mapping_version' ) );
		if ( ! is_string( $body['command_id'] ?? null ) || ! is_int( $body['expected_mapping_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'CancelSubmissionZip value is invalid.' );
		}
		return array(
			'command_id'               => $body['command_id'],
			'expected_mapping_version' => $body['expected_mapping_version'],
		);
	}

	/**
	 * Json object.
	 *
	 * @param object $request Request.
	 * @param string $operation Operation.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function json_object( object $request, string $operation ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( $operation . ' body is unavailable.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This internal exception is mapped to stable errors at presentation boundaries, not rendered directly.
		} // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array_is_list( $body ) ) {
			throw new InvalidArgumentException( $operation . ' body is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This internal exception is mapped to stable errors at presentation boundaries, not rendered directly.
		} // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		return $body;
	}

	/**
	 * Assignment path id.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assignment_path_id( object $request ): string {
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new InvalidArgumentException( 'StageSubmissionZip path is unavailable.' );
		}
		$params = $request->get_url_params();
		$id     = is_array( $params ) ? ( $params['assignment_id'] ?? null ) : null;
		if ( ! is_string( $id ) ) {
			throw new InvalidArgumentException( 'StageSubmissionZip path is invalid.' );
		}
		new Public_Id( $id );
		return $id;
	}

	/**
	 * Upload path id.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function upload_path_id( object $request ): string {
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new InvalidArgumentException( 'Submission ZIP preview path is unavailable.' );
		}
		$params = $request->get_url_params();
		$id     = is_array( $params ) ? ( $params['upload_id'] ?? null ) : null;
		if ( ! is_string( $id ) ) {
			throw new InvalidArgumentException( 'Submission ZIP preview path is invalid.' );
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
			throw new InvalidArgumentException( 'StageSubmissionZip object keys are invalid.' );
		}
	}

	/**
	 * Zip problem.
	 *
	 * @param Zip_Import_Failure $failure Failure.
	 * @param string             $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function zip_problem( Zip_Import_Failure $failure, string $correlation_id ) {
		$definition = match ( $failure->reason() ) {
			Zip_Import_Failure::NOT_FOUND => array( 404, '과제를 찾을 수 없습니다.' ),
			Zip_Import_Failure::LIMIT_EXCEEDED => array( 413, 'ZIP 파일의 개수나 크기를 확인해 주세요.' ),
			Zip_Import_Failure::UNSUPPORTED_ARCHIVE => array( 415, '지원하지 않는 ZIP 형식입니다.' ),
			Zip_Import_Failure::INVALID_UPLOAD, Zip_Import_Failure::UNSAFE_ARCHIVE => array( 422, '안전한 ZIP 파일인지 확인해 주세요.' ),
			Zip_Import_Failure::MAPPING_INVALID => array( 422, '연결할 학생과 응답칸을 확인해 주세요.' ),
			Zip_Import_Failure::PREVIEW_NOT_READY => array( 409, '파일을 확인하고 있습니다.' ),
			Zip_Import_Failure::OFFICIAL_EXISTS => array( 409, '이미 제출된 학생입니다.' ),
			Zip_Import_Failure::IDEMPOTENCY_CONFLICT, Zip_Import_Failure::STATE_CONFLICT => array( 409, '내용이 바뀌었습니다. 새로고침해 주세요.' ),
			default => array( 503, 'ZIP 파일을 저장하지 못했습니다.' ),
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
