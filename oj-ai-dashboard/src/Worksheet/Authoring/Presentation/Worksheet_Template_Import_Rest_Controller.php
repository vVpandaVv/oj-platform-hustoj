<?php
/**
 * Worksheet template import REST boundary.
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
use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Application\Import_Worksheet_Template;
use OJAI\Dashboard\Worksheet\Authoring\Application\Import_Worksheet_Template_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Template_Import_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet template import REST boundary.
 */
final class Worksheet_Template_Import_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_IMPORT   = '/worksheet-revisions/(?P<revision_id>[0-9A-HJKMNP-TV-Z]{26})/commands/import-template';
	public const ACTION_IMPORT  = 'worksheet.template.import';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize worksheet template import rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff   $permissions Permissions.
	 * @param Import_Worksheet_Template $import_template Import template.
	 * @param Rest_Envelope_Factory     $envelopes Envelopes.
	 * @param Correlation_Id_Factory    $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Import_Worksheet_Template $import_template,
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
			self::ROUTE_IMPORT,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import' ),
				'permission_callback' => array( $this, 'permissions_import' ),
			)
		);
	}

	/**
	 * Permissions import.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_import( object $request ) {
		try {
			$revision_id = $this->path_id( $request );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_IMPORT,
			array( Addon_Capabilities::MANAGE_WORKSHEETS ),
			null,
			new Object_Scope( Object_Scope::TYPE_WORKSHEET_REVISION, $revision_id, 'edit' )
		);
	}

	/**
	 * Import.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( object $request ) {
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
			$input  = $this->input( $request );
			$result = $this->import_template->execute(
				new Import_Worksheet_Template_Command(
					$grant->metadata()->actor_user_id(),
					$this->path_id( $request ),
					$input['command_id'],
					$input['expected_state'],
					$input['expected_revision_id'],
					$input['package'],
					$idempotency->fingerprint(),
					$correlation_id
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Template_Import_Failure $failure ) {
			return $this->import_problem( $failure, $correlation_id );
		} catch ( Worksheet_Asset_Failure $failure ) {
			return $this->asset_problem( $failure, $correlation_id );
		} catch ( Worksheet_Failure $failure ) {
			return $this->worksheet_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		$saved = $result->saved();
		return $this->envelopes->success(
			array(
				'worksheet' => array(
					'id'                  => $saved->worksheet_id(),
					'title'               => $saved->title(),
					'state'               => $saved->worksheet_state(),
					'current_revision_id' => $saved->current_revision_id(),
				),
				'revision'  => array(
					'id'                 => $saved->revision_id(),
					'revision_no'        => $saved->revision_no(),
					'state'              => $saved->revision_state(),
					'source_revision_id' => $saved->source_revision_id(),
					'document_id'        => $saved->document_id(),
					'document_hash'      => $saved->document_hash(),
					'validation_hash'    => $saved->validation_hash(),
				),
				'import'    => array(
					'package_source_revision_id' => $result->package_source_revision_id(),
					'imported_asset_count'       => $result->imported_asset_count(),
					'reissued_id_count'          => $result->reissued_id_count(),
				),
			),
			$correlation_id,
			$saved->http_status(),
			array(
				'idempotent_replay' => $saved->replayed(),
				'package_hash'      => $result->package_hash(),
				'saved_at_utc'      => $saved->saved_at_utc(),
			),
			$this->links_for( $saved->worksheet_id(), $saved->revision_id() ),
			array( Worksheet_Rest_Controller::ACTION_VIEW, Worksheet_Rest_Controller::ACTION_VIEW_REVISION )
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_revision_id:string,package:array<string,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_body_params' ) || ! method_exists( $request, 'get_file_params' ) ) {
			throw new InvalidArgumentException( 'Worksheet template multipart input is unavailable.' );
		}
		$body  = $request->get_body_params();
		$files = $request->get_file_params();
		if ( ! is_array( $body ) || ! is_array( $files ) ) {
			throw new InvalidArgumentException( 'Worksheet template multipart input is invalid.' );
		}
		$this->assert_exact_keys( $body, array( 'command_id', 'expected_revision_id', 'expected_state' ) );
		$this->assert_exact_keys( $files, array( 'package' ) );
		foreach ( array( 'command_id', 'expected_state', 'expected_revision_id' ) as $key ) {
			if ( ! is_string( $body[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Worksheet template input value is invalid.' );
			}
		}
		if ( ! is_array( $files['package'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet template package is invalid.' );
		}
		return array(
			'command_id'           => $body['command_id'],
			'expected_state'       => $body['expected_state'],
			'expected_revision_id' => $body['expected_revision_id'],
			'package'              => $files['package'],
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
			throw new InvalidArgumentException( 'Worksheet template input shape is invalid.' );
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
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new InvalidArgumentException( 'Worksheet template path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['revision_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet template revision ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Links for.
	 *
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @return array<string,string>
	 */
	private function links_for( string $worksheet_id, string $revision_id ): array {
		if ( ! function_exists( 'rest_url' ) ) {
			return array();
		}
		$base = rest_url( self::REST_NAMESPACE );
		if ( ! is_string( $base ) || '' === $base ) {
			return array();
		}
		$base = rtrim( $base, '/' );
		return array(
			'worksheet' => $base . '/worksheets/' . $worksheet_id,
			'revision'  => $base . '/worksheet-revisions/' . $revision_id,
		);
	}

	/**
	 * Import problem.
	 *
	 * @param Worksheet_Template_Import_Failure $failure Failure.
	 * @param string                            $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function import_problem( Worksheet_Template_Import_Failure $failure, string $correlation_id ) {
		$status = match ( $failure->reason() ) {
			Worksheet_Template_Import_Failure::LIMIT_EXCEEDED => 413,
			Worksheet_Template_Import_Failure::VERSION_UNSUPPORTED => 415,
			Worksheet_Template_Import_Failure::ENVIRONMENT_UNSUPPORTED,
			Worksheet_Template_Import_Failure::STORAGE_UNAVAILABLE => 503,
			default => 422,
		};
		return $this->problem( $status, $correlation_id );
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
	 * Worksheet problem.
	 *
	 * @param Worksheet_Failure $failure Failure.
	 * @param string            $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function worksheet_problem( Worksheet_Failure $failure, string $correlation_id ) {
		$status = match ( $failure->reason() ) {
			Worksheet_Failure::IDEMPOTENCY_CONFLICT,
			Worksheet_Failure::REVISION_CONFLICT => 409,
			Worksheet_Failure::NOT_FOUND => 404,
			Worksheet_Failure::ASSET_NOT_READY => 422,
			default => 503,
		};
		if ( Worksheet_Failure::REVISION_CONFLICT === $failure->reason() ) {
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
