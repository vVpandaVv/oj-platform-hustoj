<?php
/**
 * Export rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Export\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Reporting\Export\Application\Export_Download;
use OJAI\Dashboard\Reporting\Export\Application\Export_Failure;
use OJAI\Dashboard\Reporting\Export\Application\Read_Export_Artifact;
use OJAI\Dashboard\Reporting\Export\Application\Read_Export_Job;
use OJAI\Dashboard\Reporting\Export\Port\Export_Query_Port_Interface;
use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;
use WeakMap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export rest controller.
 */
final class Export_Rest_Controller {
	public const REST_NAMESPACE  = 'oj-ai/v1';
	public const ROUTE_JOB       = '/export-jobs/(?P<job_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ROUTE_DOWNLOAD  = '/artifacts/(?P<artifact_id>[0-9A-HJKMNP-TV-Z]{26})/download';
	public const ACTION_JOB      = 'export.job.read';
	public const ACTION_DOWNLOAD = 'export.artifact.download';
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;
	/**
	 * Streams.
	 *
	 * @var WeakMap<object,array{download:Export_Download,actor:int,artifact_id:string,correlation:string}>
	 */
	private WeakMap $streams;

	/**
	 * Initialize export rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff      $permissions Permissions.
	 * @param Current_Actor_Port_Interface $current_actor Current actor.
	 * @param Export_Query_Port_Interface  $query Query.
	 * @param Read_Export_Job              $read_job Read job.
	 * @param Read_Export_Artifact         $read_artifact Read artifact.
	 * @param Rest_Envelope_Factory        $envelopes Envelopes.
	 * @param Correlation_Id_Factory       $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Current_Actor_Port_Interface $current_actor,
		private Export_Query_Port_Interface $query,
		private Read_Export_Job $read_job,
		private Read_Export_Artifact $read_artifact,
		private Rest_Envelope_Factory $envelopes,
		private Correlation_Id_Factory $correlations
	) {$this->streams = new WeakMap();
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
		}if ( ! function_exists( 'register_rest_route' ) || ! function_exists( 'add_filter' ) ) {
			throw new LogicException( 'Export REST support is unavailable.' );
		}$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_JOB,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'job' ),
				'permission_callback' => array( $this, 'permissions_job' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_DOWNLOAD,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download' ),
				'permission_callback' => array( $this, 'permissions_download' ),
			)
		);
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_stream' ), 10, 4 );
	}

	/**
	 * Permissions job.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_job( object $request ) {
		return $this->permissions_for_row( $request, 'job_id', self::ACTION_JOB, false );
	}
	/**
	 * Permissions download.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_download( object $request ) {
		return $this->permissions_for_row( $request, 'artifact_id', self::ACTION_DOWNLOAD, true );
	}

	/**
	 * Permissions for row.
	 *
	 * @param object $request Request.
	 * @param string $path_name Path name.
	 * @param string $action Action.
	 * @param bool   $artifact Artifact.
	 * @return true|\WP_Error
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function permissions_for_row( object $request, string $path_name, string $action, bool $artifact ) {
		try {
			$id    = $this->path_id( $request, $path_name );
			$actor = $this->current_actor->current_actor_user_id();
			if ( $actor < 1 ) {
				return $this->permissions->check( $request, Access_Policy_Request::SHELL_PUBLIC, $action, array( Addon_Capabilities::PARTICIPATE ) );
			}
			$row = $artifact ? $this->query->find_artifact_for_actor( $actor, $id ) : $this->query->find_job_for_actor( $actor, $id );
			if ( null === $row ) {
				return $this->problem( 404, $this->correlations->create() );
			}
			$authorization = $row['authorization'] ?? null;
			if ( ! is_array( $authorization ) || array_is_list( $authorization ) ) {
				throw new InvalidArgumentException( 'Export authorization is unavailable.' );
			}
			$this->exact( $authorization, array( 'context', 'object' ) );
			$object  = $authorization['object'] ?? null;
			$context = $authorization['context'] ?? null;
			if ( ! is_array( $object ) || array_is_list( $object ) ) {
				throw new InvalidArgumentException( 'Export object authorization is invalid.' );
			}
			$this->exact( $object, array( 'action', 'id', 'relation', 'revision', 'state', 'type' ) );
			$relation = (string) $object['relation'];
			if ( ! in_array( $relation, array( 'owner', 'context_staff', 'context_reporter', 'self_target', 'self_submission', 'self_release' ), true ) ) {
				throw new InvalidArgumentException( 'Export actor relation is invalid.' );
			}
			$is_self       = in_array( $relation, array( 'self_target', 'self_submission', 'self_release' ), true );
			$context_scope = null;
			if ( null !== $context ) {
				if ( ! is_array( $context ) || array_is_list( $context ) ) {
					throw new InvalidArgumentException( 'Export Context authorization is invalid.' );
				}
				$this->exact( $context, array( 'id', 'revision', 'type' ) );
				$context_scope = new Context_Scope(
					(string) $context['type'],
					(string) $context['id'],
					$is_self ? Context_Scope::OPERATION_PARTICIPATE : Context_Scope::OPERATION_REPORT,
					$is_self ? $actor : null,
					(string) $context['revision']
				);
			}
			if ( $is_self && null === $context_scope ) {
				throw new InvalidArgumentException( 'Self export Context is required.' );
			}
			return $this->permissions->check(
				$request,
				$is_self ? Access_Policy_Request::SHELL_PUBLIC : Access_Policy_Request::SHELL_CONSOLE,
				$action,
				array( $is_self ? Addon_Capabilities::PARTICIPATE : Addon_Capabilities::EXPORT_REPORTS ),
				$context_scope,
				new Object_Scope(
					(string) $object['type'],
					(string) $object['id'],
					(string) $object['action'],
					is_string( $object['revision'] ) ? $object['revision'] : null,
					is_string( $object['state'] ) ? $object['state'] : null
				)
			);
		} catch ( Export_Failure ) {
			return $this->problem( 503, $this->correlations->create() );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}
	}

	/**
	 * Job.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function job( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}$correlation = $grant->authorization()->correlation_id();
		try {
			$row = $this->read_job->execute( $grant->metadata()->actor_user_id(), $this->path_id( $request, 'job_id' ), $correlation );
		} catch ( Export_Failure $failure ) {
			return $this->export_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}return $this->envelopes->success( array( 'export_job' => $row ), $correlation );
	}

	/**
	 * Download.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function download( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation = $grant->authorization()->correlation_id();
		$actor       = $grant->metadata()->actor_user_id();
		try {
			$artifact_id = $this->path_id( $request, 'artifact_id' );
			$result      = $this->read_artifact->execute( $actor, $artifact_id, $correlation );
			return $this->stream_response( $result, $correlation, $actor, $artifact_id );
		} catch ( Export_Failure $failure ) {
			return $this->export_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}
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
	public function serve_stream( $served, object $response, object $request, object $server ) {
		unset( $request, $server );
		if ( true === $served || ! isset( $this->streams[ $response ] ) ) {
			return $served;
		}
		$stream = $this->streams[ $response ];
		unset( $this->streams[ $response ] );

		try {
			$current = $this->read_artifact->execute(
				$stream['actor'],
				$stream['artifact_id'],
				$stream['correlation']
			);
			if ( ! $this->same_download( $stream['download'], $current ) ) {
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
	 * Stream response.
	 *
	 * @param Export_Download $result Result.
	 * @param string          $correlation Correlation.
	 * @param int             $actor Actor.
	 * @param string          $artifact_id Artifact id.
	 * @return \WP_REST_Response
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stream_response( Export_Download $result, string $correlation, int $actor, string $artifact_id ) {
		if ( ! class_exists( '\\WP_REST_Response' ) ) {
			throw new LogicException( 'WordPress REST response support is unavailable.' );
		}
		$response = new \WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', $result->mime_type() );
		$response->header( 'Content-Length', (string) $result->bytes() );
		$response->header( 'Content-Disposition', "attachment; filename*=UTF-8''" . rawurlencode( $result->name() ) );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'X-OJ-Artifact-Kind', $result->artifact_kind() );
		$response->header( 'X-OJ-Content-SHA256', $result->sha256() );
		$response->header( 'X-OJ-Correlation-Id', $correlation );
		$this->streams[ $response ] = array(
			'download'    => $result,
			'actor'       => $actor,
			'artifact_id' => $artifact_id,
			'correlation' => $correlation,
		);
		return $response;
	}

	/**
	 * Same download.
	 *
	 * @param Export_Download $expected Expected.
	 * @param Export_Download $current Current.
	 * @return bool
	 */
	private function same_download( Export_Download $expected, Export_Download $current ): bool {
		return hash_equals( $expected->sha256(), $current->sha256() )
		&& $expected->bytes() === $current->bytes()
		&& $expected->name() === $current->name()
		&& $expected->mime_type() === $current->mime_type()
		&& $expected->artifact_kind() === $current->artifact_kind();
	}

	/**
	 * Verified handle.
	 *
	 * @param Export_Download $download Download.
	 * @return resource|null
	 */
	private function verified_handle( Export_Download $download ) {
		$handle = fopen( $download->path(), 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
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
			if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) || $stat['size'] !== $download->bytes() ) {
				return null;
			}
			$hash  = hash_init( 'sha256' );
			$bytes = 0;
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, 64 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				if ( false === $chunk ) {
					return null;
				}
				$bytes += strlen( $chunk );
				hash_update( $hash, $chunk );
			}
			if ( $bytes !== $download->bytes() || ! hash_equals( $download->sha256(), hash_final( $hash ) ) || 0 !== fseek( $handle, 0 ) ) {
				return null;
			}
			$valid = true;
			return $handle;
		} finally {
			if ( ! $valid ) {
				flock( $handle, LOCK_UN );
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			}
		}
	}

	/**
	 * Exact.
	 *
	 * @param array $value Value.
	 * @param array $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Export authorization keys are invalid.' );
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
			throw new InvalidArgumentException( 'Export path is unavailable.' );
		}$params = $request->get_url_params();
		$value   = is_array( $params ) ? ( $params[ $name ] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Export path ID is invalid.' );
		}return $value;
	}
	/**
	 * Export problem.
	 *
	 * @param Export_Failure $failure Failure.
	 * @param string         $correlation Correlation.
	 * @return mixed
	 */
	private function export_problem( Export_Failure $failure, string $correlation ) {
		$status = match ( $failure->reason() ) {
			Export_Failure::NOT_FOUND=>404, Export_Failure::ACCESS_DENIED=>403, Export_Failure::EXPIRED=>410, Export_Failure::NOT_READY=>409, default=>503};
		return $this->envelopes->problem(
			Rest_Problem::operation(
				$status,
				$failure->reason(),
				match ( $status ) {
					403=>'권한을 확인해 주세요.',
					404=>'대상을 찾을 수 없습니다.',
					409=>'파일이 아직 준비되지 않았습니다.',
					410=>'파일 유효기간이 끝났습니다.',
					default=>'지금은 파일을 내려받을 수 없습니다.'},
				$correlation
			)
		);
	}
	/**
	 * Problem.
	 *
	 * @param int    $status Status.
	 * @param string $correlation Correlation.
	 * @return mixed
	 */
	private function problem( int $status, string $correlation ) {
		return $this->envelopes->problem( Rest_Problem::for_status( $status, $correlation ) );
	}
}
