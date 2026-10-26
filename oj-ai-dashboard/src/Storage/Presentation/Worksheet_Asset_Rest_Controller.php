<?php
/**
 * Worksheet private asset REST boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Storage\Application\Stage_Worksheet_Asset;
use OJAI\Dashboard\Storage\Application\Stage_Worksheet_Asset_Command;
use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers only the implemented C64 private staging command.
 */
final class Worksheet_Asset_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_STAGE    = '/worksheets/(?P<worksheet_id>[0-9A-HJKMNP-TV-Z]{26})/commands/stage-asset';
	public const ACTION_STAGE   = 'worksheet.asset.stage';
	public const ACTION_DISCARD = 'worksheet.asset.discard';

	/**
	 * Permissions.
	 *
	 * @var Rest_Permission_Handoff
	 */
	private Rest_Permission_Handoff $permissions;
	/**
	 * Stage asset.
	 *
	 * @var Stage_Worksheet_Asset
	 */
	private Stage_Worksheet_Asset $stage_asset;
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
	 * Initialize worksheet asset rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff $permissions Permissions.
	 * @param Stage_Worksheet_Asset   $stage_asset Stage asset.
	 * @param Rest_Envelope_Factory   $envelopes Envelopes.
	 * @param Correlation_Id_Factory  $correlations Correlations.
	 */
	public function __construct(
		Rest_Permission_Handoff $permissions,
		Stage_Worksheet_Asset $stage_asset,
		Rest_Envelope_Factory $envelopes,
		Correlation_Id_Factory $correlations
	) {
		$this->permissions  = $permissions;
		$this->stage_asset  = $stage_asset;
		$this->envelopes    = $envelopes;
		$this->correlations = $correlations;
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
	}

	/**
	 * Permissions stage.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_stage( object $request ) {
		try {
			$worksheet_id = $this->path_id( $request, 'worksheet_id' );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_STAGE,
			array( Addon_Capabilities::MANAGE_WORKSHEETS ),
			null,
			new Object_Scope( Object_Scope::TYPE_WORKSHEET, $worksheet_id, 'edit' )
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
			$input  = $this->stage_input( $request );
			$result = $this->stage_asset->execute(
				new Stage_Worksheet_Asset_Command(
					$grant->metadata()->actor_user_id(),
					$this->path_id( $request, 'worksheet_id' ),
					$input['command_id'],
					$input['expected_state'],
					$input['expected_revision_id'],
					$input['file'],
					$idempotency->fingerprint(),
					$correlation_id
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Asset_Failure $failure ) {
			return $this->asset_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		$worksheet_id = $this->path_id( $request, 'worksheet_id' );
		$asset_id     = $result->asset_id();
		return $this->envelopes->success(
			array(
				'asset' => array(
					'id'            => $asset_id,
					'original_name' => $result->original_name(),
					'mime_type'     => $result->mime_type(),
					'byte_size'     => $result->byte_size(),
					'sha256'        => $result->sha256(),
					'state'         => $result->state(),
					'scan_state'    => $result->scan_state(),
				),
			),
			$correlation_id,
			201,
			array(
				'idempotent_replay' => $result->replayed(),
				'expires_at_utc'    => $result->expires_at_utc(),
			),
			$this->links_for( $worksheet_id, $asset_id ),
			array( self::ACTION_DISCARD )
		);
	}

	/**
	 * Stage input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_revision_id:string,file:array<string,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stage_input( object $request ): array {
		if ( ! method_exists( $request, 'get_body_params' ) || ! method_exists( $request, 'get_file_params' ) ) {
			throw new InvalidArgumentException( 'Worksheet asset multipart input is unavailable.' );
		}
		$body  = $request->get_body_params();
		$files = $request->get_file_params();
		if ( ! is_array( $body ) || ! is_array( $files ) ) {
			throw new InvalidArgumentException( 'Worksheet asset multipart input is invalid.' );
		}
		$this->assert_exact_keys( $body, array( 'command_id', 'expected_revision_id', 'expected_state' ) );
		$this->assert_exact_keys( $files, array( 'file' ) );
		foreach ( array( 'command_id', 'expected_state', 'expected_revision_id' ) as $key ) {
			if ( ! is_string( $body[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Worksheet asset input value is invalid.' );
			}
		}
		if ( ! is_array( $files['file'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet asset file is invalid.' );
		}

		return array(
			'command_id'           => $body['command_id'],
			'expected_state'       => $body['expected_state'],
			'expected_revision_id' => $body['expected_revision_id'],
			'file'                 => $files['file'],
		);
	}

	/**
	 * Reject invalid exact keys.
	 *
	 * @param array<string,mixed> $values Values.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_exact_keys( array $values, array $expected ): void {
		$keys = array_keys( $values );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $keys !== $expected ) {
			throw new InvalidArgumentException( 'Worksheet asset input shape is invalid.' );
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
			throw new InvalidArgumentException( 'Worksheet asset path parameters are unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params[ $name ] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet asset path ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Links for.
	 *
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $asset_id Asset id.
	 * @return array<string,string>
	 */
	private function links_for( string $worksheet_id, string $asset_id ): array {
		if ( ! function_exists( 'rest_url' ) ) {
			return array();
		}
		$base = rest_url( self::REST_NAMESPACE );
		if ( ! is_string( $base ) || '' === $base ) {
			return array();
		}
		$base = rtrim( $base, '/' );
		return array(
			'content' => $base . '/worksheet-assets/' . $asset_id . '/content?source_type=staged&source_id=' . $asset_id,
			'discard' => $base . '/worksheets/' . $worksheet_id . '/assets/' . $asset_id . '/commands/discard',
		);
	}

	/**
	 * Asset problem.
	 *
	 * @param Worksheet_Asset_Failure $failure Failure.
	 * @param string                  $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function asset_problem( Worksheet_Asset_Failure $failure, string $correlation_id ) {
		$status = match ( $failure->reason() ) {
			Worksheet_Asset_Failure::IDEMPOTENCY_CONFLICT,
			Worksheet_Asset_Failure::TARGET_CONFLICT => 409,
			Worksheet_Asset_Failure::NOT_FOUND => 404,
			Worksheet_Asset_Failure::LIMIT_EXCEEDED => 413,
			Worksheet_Asset_Failure::UNSUPPORTED_MEDIA => 415,
			Worksheet_Asset_Failure::INVALID_UPLOAD => 422,
			default => 503,
		};
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
