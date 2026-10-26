<?php
/**
 * Project rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Assessment\Project\Presentation;

use OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration;
use OJAI\Dashboard\Assessment\Project\Domain\Project_Failure;
use OJAI\Dashboard\Assessment\Project\Domain\Project_Input_Failure;
use OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface;
use OJAI\Dashboard\Contracts\Module_Interface;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Command;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use InvalidArgumentException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Project rest controller.
 */
final class Project_Rest_Controller implements Module_Interface {
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;
	/**
	 * Initialize rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff                                             $permissions Permissions.
	 * @param Project_Repository_Port_Interface                                   $projects Projects.
	 * @param Create_Worksheet                                                    $worksheets Worksheets.
	 * @param Canonical_Json                                                      $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory                                               $envelopes Envelopes.
	 * @param Correlation_Id_Factory                                              $correlations Correlations.
	 * @param \OJAI\Dashboard\Worksheet\Authoring\Application\Duplicate_Worksheet $duplicate Duplicate.
	 */
	public function __construct( private Rest_Permission_Handoff $permissions, private Project_Repository_Port_Interface $projects, private Create_Worksheet $worksheets, private Canonical_Json $canonical, private Rest_Envelope_Factory $envelopes, private Correlation_Id_Factory $correlations, private \OJAI\Dashboard\Worksheet\Authoring\Application\Duplicate_Worksheet $duplicate ) {
	}
	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}
	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		if ( $this->registered ) {
			return;
		} $this->registered = true;
		$id                 = '(?P<project_id>[0-9A-HJKMNP-TV-Z]{26})';
		foreach ( array(
			array( '/assessment-projects', 'GET', 'list' ),
			array( '/assessment-projects', 'POST', 'create' ),
			array( '/assessment-projects/' . $id, 'GET', 'read' ),
			array( '/assessment-projects/' . $id . '/revisions', 'POST', 'save' ),
			array( '/assessment-projects/' . $id . '/archive', 'POST', 'archive' ),
			array( '/assessment-projects/' . $id . '/worksheets', 'POST', 'worksheet' ),
			array( '/assessment-projects/' . $id . '/worksheets/copy', 'POST', 'copy_worksheet' ),
			array( '/worksheets/(?P<worksheet_id>[0-9A-HJKMNP-TV-Z]{26})/assessment-project', 'GET', 'worksheet_project' ),
			array( '/worksheets/(?P<worksheet_id>[0-9A-HJKMNP-TV-Z]{26})/assessment-project', 'POST', 'adopt_worksheet' ),
		) as [ $route, $method, $operation ] ) {
			register_rest_route(
				'oj-ai/v1',
				$route,
				array(
					'methods'             => $method,
					'permission_callback' => fn ( object $request ) => $this->permissions->check( $request, Access_Policy_Request::SHELL_CONSOLE, 'assessment_project.' . $operation, array( Addon_Capabilities::MANAGE_WORKSHEETS ) ),
					'callback'            => fn ( object $request ) => $this->execute( $request, $operation ),
				)
			);
		}
	}
	/**
	 * Execute rest controller.
	 *
	 * @param object $request Request.
	 * @param string $operation Operation.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( object $request, string $operation ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->envelopes->problem( Rest_Problem::for_status( 503, $this->correlations->create() ) );
		}
		$correlation = $grant->authorization()->correlation_id();
		$actor       = $grant->metadata()->actor_user_id();
		$path        = $request->get_url_params();
		$project_id  = $path['project_id'] ?? '';
		try {
			if ( 'list' === $operation ) {
				$cursor = $request->get_param( 'cursor' ) ?? '0';
				if ( ! is_scalar( $cursor ) || ! preg_match( '/^[0-9]{1,15}$/D', (string) $cursor ) ) {
					throw new Project_Input_Failure( '목록 위치가 올바르지 않습니다.' );
				}
				$archived = $request->get_param( 'archived' ) ?? '0';
				if ( ! in_array( $archived, array( '0', '1' ), true ) ) {
					throw new Project_Input_Failure( '평가 목록 구분이 올바르지 않습니다.' );
				}
				$data = $this->projects->listing( $actor, (int) $cursor, '1' === $archived );
				return $this->envelopes->success( array( 'projects' => $data['projects'] ), $correlation, 200, array( 'next_cursor' => $data['next_cursor'] ) );
			}
			if ( 'read' === $operation ) {
				return $this->envelopes->success( array( 'project' => $this->projects->read( $actor, $project_id ) ), $correlation );
			}
			if ( 'worksheet_project' === $operation ) {
				return $this->envelopes->success( array( 'project' => $this->projects->for_worksheet( $path['worksheet_id'], null, $actor, true ) ), $correlation );
			}
			$input = $request->get_json_params();
			Project_Configuration::keys( $input, array( 'command_id', 'expected', 'payload' ) );
			if ( ! is_string( $input['command_id'] ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{7,127}$/D', $input['command_id'] ) || ! is_array( $input['expected'] ) || ! is_array( $input['payload'] ) ) {
				throw new Project_Input_Failure( '평가 요청 형식이 올바르지 않습니다.' );
			}
			$idempotency = $grant->authorization()->idempotency_key();
			if ( null === $idempotency ) {
				throw new Project_Failure( 422, '요청 식별값이 필요합니다.' );
			}
			$key    = $idempotency->fingerprint();
			$digest = hash( 'sha256', $this->canonical->encode( $input ) );
			if ( 'adopt_worksheet' === $operation ) {
				Project_Configuration::keys( $input['expected'], array() );
				Project_Configuration::keys( $input['payload'], array() );
				return $this->envelopes->success( array( 'project' => $this->projects->adopt_worksheet( $actor, $path['worksheet_id'] ) ), $correlation );
			}
			if ( 'archive' === $operation ) {
				Project_Configuration::keys( $input['expected'], array( 'lock_version' ) );
				Project_Configuration::keys( $input['payload'], array( 'archived' ) );
				if ( ! is_int( $input['expected']['lock_version'] ) || ! is_bool( $input['payload']['archived'] ) ) {
					throw new Project_Input_Failure( '평가 보관 요청 형식이 올바르지 않습니다.' );
				}
				$project = $this->projects->set_archived( $actor, $project_id, $input['expected']['lock_version'], $input['payload']['archived'] );
				return $this->envelopes->success( array( 'project' => $project ), $correlation );
			}
			if ( 'create' === $operation ) {
				Project_Configuration::keys( $input['expected'], array( 'expected_state' ) );
				if ( 'absent' !== $input['expected']['expected_state'] ) {
					throw new Project_Input_Failure( '새 평가 생성 상태가 올바르지 않습니다.' );
				}
				$project = $this->projects->create( $actor, $input['payload'], $key, $digest );
			} else {
				Project_Configuration::keys( $input['expected'], array( 'revision_id' ) );
				if ( ! is_string( $input['expected']['revision_id'] ) ) {
					throw new Project_Input_Failure( '평가 기준 버전이 필요합니다.' );
				}
				if ( 'copy_worksheet' === $operation ) {
					Project_Configuration::keys( $input['payload'], array( 'worksheet_id', 'revision_id', 'state' ) );
					foreach ( $input['payload'] as $value ) {
						if ( ! is_string( $value ) ) {
							throw new Project_Input_Failure( '복사할 활동지와 버전이 올바르지 않습니다.' );
						}
					}
					$project = $this->projects->read( $actor, $project_id, $input['expected']['revision_id'] );
					$result  = $this->duplicate->execute(
						new \OJAI\Dashboard\Worksheet\Authoring\Application\Duplicate_Worksheet_Command(
							$actor,
							$input['payload']['worksheet_id'],
							$input['command_id'],
							$input['payload']['state'],
							$input['payload']['revision_id'],
							hash( 'sha256', $key . '|' . $project_id ),
							$correlation,
							$this->canonical
						),
						$project
					);
					return $this->envelopes->success(
						array(
							'worksheet_id' => $result->worksheet_id(),
							'project_id'   => $project_id,
						),
						$correlation,
						$result->replayed() ? 200 : 201
					);
				}
				if ( 'worksheet' === $operation ) {
					Project_Configuration::keys( $input['payload'], array( 'title' ) );
					$project = $this->projects->read( $actor, $project_id, $input['expected']['revision_id'] );
					$title   = Project_Configuration::text( $input['payload']['title'], 255 );
					$locale  = str_replace( '_', '-', get_user_locale( $actor ) );
					$result  = $this->worksheets->execute( new Create_Worksheet_Command( $actor, $input['command_id'], 'absent', $title, $locale, $key, $correlation, $this->canonical ), $project );
					return $this->envelopes->success(
						array(
							'worksheet_id' => $result->worksheet_id(),
							'project_id'   => $project_id,
						),
						$correlation,
						$result->replayed() ? 200 : 201
					);
				}
				$project = $this->projects->save( $actor, $project_id, $input['expected']['revision_id'], $input['payload'], $key, $digest );
			}
			return $this->envelopes->success( array( 'project' => $project ), $correlation, 201 );
		} catch ( Project_Failure $failure ) {
			return new \WP_Error(
				'assessment_project_failed',
				$failure->getMessage(),
				array(
					'status'         => $failure->status,
					'correlation_id' => $correlation,
				)
			);
		} catch ( Project_Input_Failure $failure ) {
			return new \WP_Error(
				'assessment_project_invalid',
				$failure->getMessage(),
				array(
					'status'         => 422,
					'correlation_id' => $correlation,
				)
			);
		} catch ( InvalidArgumentException ) {
			return new \WP_Error(
				'assessment_project_invalid',
				'평가 입력을 확인해 주세요. 활동지와 평가 기준의 형식이 올바르지 않습니다.',
				array(
					'status'         => 422,
					'correlation_id' => $correlation,
				)
			);
		} catch ( \OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure $failure ) {
			$status = match ( $failure->reason() ) {
				'worksheet_not_found' => 404, 'worksheet_idempotency_conflict', 'worksheet_revision_conflict' => 409, default => 503 };
			return new \WP_Error(
				'assessment_worksheet_failed',
				'활동지를 처리하지 못했습니다. 최신 상태와 권한을 확인해 주세요.',
				array(
					'status'         => $status,
					'correlation_id' => $correlation,
				)
			);
		} catch ( Throwable ) {
			return new \WP_Error(
				'assessment_project_unavailable',
				'평가를 처리하지 못했습니다. 새로고침으로 저장 여부를 확인하고 DB 업데이트와 시스템 상태를 확인해 주세요.',
				array(
					'status'         => 503,
					'correlation_id' => $correlation,
				)
			);
		}
	}
}
