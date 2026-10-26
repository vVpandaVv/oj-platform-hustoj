<?php
/**
 * Worksheet asset content and discard REST boundary.
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
use OJAI\Dashboard\Storage\Application\Discard_Staged_Worksheet_Asset;
use OJAI\Dashboard\Storage\Application\Discard_Staged_Worksheet_Asset_Command;
use OJAI\Dashboard\Storage\Application\Read_Worksheet_Asset_Content;
use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Content_Result;
use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;
use WeakMap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams authorized bytes without serializing private paths into REST data.
 */
final class Worksheet_Asset_Access_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_CONTENT  = '/worksheet-assets/(?P<asset_id>[0-9A-HJKMNP-TV-Z]{26})/content';
	public const ROUTE_DISCARD  = '/worksheets/(?P<worksheet_id>[0-9A-HJKMNP-TV-Z]{26})/assets/(?P<asset_id>[0-9A-HJKMNP-TV-Z]{26})/commands/discard';
	public const ACTION_CONTENT = 'worksheet.asset.content';
	public const ACTION_DISCARD = 'worksheet.asset.discard';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Streams.
	 *
	 * @var WeakMap<object,array{result:Worksheet_Asset_Content_Result,request:object,actor:int,asset_id:string,source_type:string,source_id:string}>
	 */
	private WeakMap $streams;

	/**
	 * Initialize worksheet asset access rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff        $permissions Permissions.
	 * @param Read_Worksheet_Asset_Content   $read_content Read content.
	 * @param Discard_Staged_Worksheet_Asset $discard_asset Discard asset.
	 * @param Rest_Envelope_Factory          $envelopes Envelopes.
	 * @param Correlation_Id_Factory         $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_Worksheet_Asset_Content $read_content,
		private Discard_Staged_Worksheet_Asset $discard_asset,
		private Rest_Envelope_Factory $envelopes,
		private Correlation_Id_Factory $correlations
	) {
		$this->streams = new WeakMap();
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
		if ( ! function_exists( 'register_rest_route' ) || ! function_exists( 'add_filter' ) ) {
			throw new LogicException( 'WordPress REST binary streaming is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_CONTENT,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'content' ),
				'permission_callback' => array( $this, 'permissions_content' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_DISCARD,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'discard' ),
				'permission_callback' => array( $this, 'permissions_discard' ),
			)
		);
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_stream' ), 10, 4 );
	}

	/**
	 * Permissions content.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_content( object $request ) {
		try {
			$this->path_id( $request, 'asset_id' );
			$this->source_query( $request );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_CONTENT,
			array( Addon_Capabilities::VIEW_WORKSHEETS )
		);
	}

	/**
	 * Permissions discard.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_discard( object $request ) {
		try {
			$worksheet_id = $this->path_id( $request, 'worksheet_id' );
			$this->path_id( $request, 'asset_id' );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_DISCARD,
			array( Addon_Capabilities::MANAGE_WORKSHEETS ),
			null,
			new Object_Scope( Object_Scope::TYPE_WORKSHEET, $worksheet_id, 'edit' )
		);
	}

	/**
	 * Content.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function content( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$source   = $this->source_query( $request );
			$actor    = $grant->metadata()->actor_user_id();
			$asset_id = $this->path_id( $request, 'asset_id' );
			$result   = $this->read_content->execute(
				$actor,
				$asset_id,
				$source['source_type'],
				$source['source_id']
			);
			return $this->stream_response( $result, $correlation_id, $request, $actor, $asset_id, $source['source_type'], $source['source_id'] );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 404, $correlation_id );
		} catch ( Worksheet_Asset_Failure $failure ) {
			return $this->asset_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}
	}

	/**
	 * Discard.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function discard( object $request ) {
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
			$input  = $this->discard_input( $request );
			$result = $this->discard_asset->execute(
				new Discard_Staged_Worksheet_Asset_Command(
					$grant->metadata()->actor_user_id(),
					$this->path_id( $request, 'worksheet_id' ),
					$this->path_id( $request, 'asset_id' ),
					$input['command_id'],
					$input['expected_state'],
					$input['expected_revision_id'],
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

		return $this->envelopes->success(
			array(
				'asset' => array(
					'id'    => $result->asset_id(),
					'state' => 'purged',
				),
			),
			$correlation_id,
			200,
			array(
				'idempotent_replay' => $result->replayed(),
				'discarded_at_utc'  => $result->discarded_at_utc(),
			)
		);
	}

	/**
	 * Serve stream.
	 *
	 * @param mixed  $served Served.
	 * @param object $response Response.
	 * @param object $request Request.
	 * @param object $server Server.
	 * @return mixed
	 */
	public function serve_stream( $served, object $response, object $request, object $server ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the four-argument WordPress rest_pre_serve_request callback signature.
		if ( true === $served || ! isset( $this->streams[ $response ] ) ) {
			return $served;
		}
		$stream = $this->streams[ $response ];
		unset( $this->streams[ $response ] );
		try {
			if ( $request !== $stream['request'] || true !== $this->permissions_content( $request ) ) {
				return true;
			}
			$grant  = $this->permissions->take( $request );
			$source = $this->source_query( $request );
			if ( null === $grant || $stream['actor'] !== $grant->metadata()->actor_user_id()
				|| $stream['asset_id'] !== $this->path_id( $request, 'asset_id' )
				|| $stream['source_type'] !== $source['source_type'] || $stream['source_id'] !== $source['source_id'] ) {
				return true;
			}
			$current  = $this->read_content->execute( $stream['actor'], $stream['asset_id'], $stream['source_type'], $stream['source_id'] );
			$expected = $stream['result'];
			if ( ! hash_equals( $expected->sha256(), $current->sha256() ) || $expected->byte_size() !== $current->byte_size()
				|| $expected->mime_type() !== $current->mime_type() || $expected->original_name() !== $current->original_name() ) {
				return true;
			}
			$handle = $this->verified_handle( $current );
			if ( ! is_resource( $handle ) ) {
				return true;
			}
			try {
				fpassthru( $handle );
			} finally {
				flock( $handle, LOCK_UN );
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			}
		} catch ( Throwable ) {
			return true;
		}
		return true;
	}

	/**
	 * Verify the opened descriptor so a path replacement cannot change the bytes.
	 *
	 * @param Worksheet_Asset_Content_Result $result Authorized content metadata.
	 * @return resource|null
	 */
	private function verified_handle( Worksheet_Asset_Content_Result $result ) {
		$handle = fopen( $result->path(), 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( false === $handle ) {
			return null;
		}
		if ( ! flock( $handle, LOCK_SH ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			return null;
		}
		$valid = false;
		try {
			$stat = fstat( $handle );
			if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) || $result->byte_size() !== $stat['size'] ) {
				return null;
			}
			$hash = hash_init( 'sha256' );
			if ( $result->byte_size() !== hash_update_stream( $hash, $handle ) || ! hash_equals( $result->sha256(), hash_final( $hash ) )
				|| 0 !== fseek( $handle, 0 ) ) {
				return null;
			}
			$valid = true;
			return $handle;
		} finally {
			if ( ! $valid ) {
				flock( $handle, LOCK_UN );
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
	}

	/**
	 * Stream response.
	 *
	 * @param Worksheet_Asset_Content_Result $result Result.
	 * @param string                         $correlation_id Request correlation identifier for diagnostics.
	 * @param object                         $request Request.
	 * @param int                            $actor Actor.
	 * @param string                         $asset_id Asset id.
	 * @param string                         $source_type Source type.
	 * @param string                         $source_id Source id.
	 * @return \WP_REST_Response
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stream_response( Worksheet_Asset_Content_Result $result, string $correlation_id, object $request, int $actor, string $asset_id, string $source_type, string $source_id ) {
		if ( ! class_exists( '\\WP_REST_Response' ) ) {
			throw new LogicException( 'WordPress REST response support is unavailable.' );
		}
		$response = new \WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', $result->mime_type() );
		$response->header( 'Content-Length', (string) $result->byte_size() );
		$response->header( 'Content-Disposition', "inline; filename*=UTF-8''" . rawurlencode( $result->original_name() ) );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'X-OJ-Correlation-Id', $correlation_id );
		$this->streams[ $response ] = array(
			'result'      => $result,
			'request'     => $request,
			'actor'       => $actor,
			'asset_id'    => $asset_id,
			'source_type' => $source_type,
			'source_id'   => $source_id,
		);
		return $response;
	}

	/**
	 * Source query.
	 *
	 * @param object $request Request.
	 * @return array{source_type:string,source_id:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_query( object $request ): array {
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'Worksheet asset source query is unavailable.' );
		}
		$query = $request->get_query_params();
		if ( ! is_array( $query ) ) {
			throw new InvalidArgumentException( 'Worksheet asset source query is invalid.' );
		}
		$this->assert_exact_keys( $query, array( 'source_id', 'source_type' ) );
		$source_type = $query['source_type'] ?? null;
		$source_id   = $query['source_id'] ?? null;
		if (
			! is_string( $source_type )
			|| ! in_array( $source_type, array( 'revision', 'publication', 'staged' ), true )
			|| ! is_string( $source_id )
			|| ! Public_Id::is_valid( $source_id )
		) {
			throw new InvalidArgumentException( 'Worksheet asset source query values are invalid.' );
		}
		return array(
			'source_type' => $source_type,
			'source_id'   => $source_id,
		);
	}

	/**
	 * Discard input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_revision_id:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function discard_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Worksheet asset discard input is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			throw new InvalidArgumentException( 'Worksheet asset discard input is invalid.' );
		}
		$this->assert_exact_keys( $input, array( 'command_id', 'expected_revision_id', 'expected_state' ) );
		foreach ( array( 'command_id', 'expected_state', 'expected_revision_id' ) as $key ) {
			if ( ! is_string( $input[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Worksheet asset discard value is invalid.' );
			}
		}
		return $input;
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
	 * Asset problem.
	 *
	 * @param Worksheet_Asset_Failure $failure Failure.
	 * @param string                  $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function asset_problem( Worksheet_Asset_Failure $failure, string $correlation_id ) {
		$status = match ( $failure->reason() ) {
			Worksheet_Asset_Failure::TARGET_CONFLICT,
			Worksheet_Asset_Failure::IDEMPOTENCY_CONFLICT => 409,
			Worksheet_Asset_Failure::EXPIRED => 410,
			Worksheet_Asset_Failure::NOT_FOUND => 404,
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
