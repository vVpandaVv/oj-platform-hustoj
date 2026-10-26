<?php
/**
 * Assessment project page handler.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface;
use OJAI\Dashboard\Assessment\Project\Domain\Project_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assessment project page handler.
 */
final class Assessment_Project_Page_Handler implements AddonPageHandlerV1 {
	/**
	 * Initialize assessment project page handler dependencies and state.
	 *
	 * @param Access_Policy_Port_Interface           $policy Policy.
	 * @param Project_Repository_Port_Interface|null $projects Projects.
	 */
	public function __construct( private Access_Policy_Port_Interface $policy, private ?Project_Repository_Port_Interface $projects = null ) {
	}
	/**
	 * Handle assessment project page handler.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return AddonPageResponseV1
	 */
	public function handle( AddonPageRequestV1 $request ): AddonPageResponseV1 {
		$navigation = Worksheet_Workflow::workspace( $request, $this->policy, true );
		$allowed    = function ( string $capability ) use ( $request ): bool {
			return $this->policy->authorize( new Access_Policy_Request( $request->actorUserId(), Access_Policy_Request::SHELL_CONSOLE, Access_Policy_Request::SURFACE_PAGE, 'assessment_project.view', array( $capability ), null, null, true, $request->correlationId() ) )->is_allowed();
		};
		if ( ! $allowed( Addon_Capabilities::MANAGE_WORKSHEETS ) ) {
			$available = Worksheet_Workflow::has_other_workspace( $request, $this->policy );
			return Addon_Page_Response::html( '평가 프로젝트', $navigation . '<section class="oj-ai-projects"><header class="ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>평가 프로젝트</h1><p>평가를 만들 권한이 없습니다. 담당 업무를 선택해 주세요.</p></div></header></section>', $available ? 200 : 403 );
		}
		$query        = $request->query();
		$project_id   = $query['project_id'] ?? '';
		$stage        = $query['stage'] ?? 'criteria';
		$worksheet_id = $query['worksheet_id'] ?? '';
		if ( ! is_string( $project_id ) || ( '' !== $project_id && ! Public_Id::is_valid( $project_id ) ) || ! is_string( $worksheet_id ) || ( '' !== $worksheet_id && ! Public_Id::is_valid( $worksheet_id ) ) || ! in_array( $stage, array( 'criteria', 'author', 'review', 'assignment', 'assessment', 'report', 'release' ), true ) ) {
			return Addon_Page_Response::html( '평가 프로젝트', $navigation . '<p>평가 주소가 올바르지 않습니다.</p>', 422 );
		}
		$body = '';
		if ( '' !== $project_id ) {
			if ( null === $this->projects ) {
				return AddonPageResponseV1::unavailable( '평가 정보를 확인할 수 없습니다.', 'assessment_project_unavailable' );
			}
			try {
				$project = $this->projects->read( $request->actorUserId(), $project_id );
				$ids     = array_column( $project['worksheets'], 'worksheet_id' );
				if ( '' !== $worksheet_id && ! in_array( $worksheet_id, $ids, true ) ) {
					return Addon_Page_Response::html( '평가 프로젝트', $navigation . '<p>이 평가의 활동지를 찾을 수 없습니다.</p>', 404 );
				}
				$worksheet_id = ( $worksheet_id ? $worksheet_id : $ids[0] ?? '' );
			} catch ( Project_Failure $failure ) {
				return Addon_Page_Response::html( '평가 프로젝트', $navigation . '<p>평가를 열 수 없습니다. 접근 권한을 확인해 주세요.</p>', $failure->status );
			} catch ( \Throwable ) {
				return AddonPageResponseV1::unavailable( '평가 정보를 불러오지 못했습니다.', 'assessment_project_unavailable' );
			}
			if ( ! Worksheet_Workflow::stage_allowed( $request, $this->policy, $stage ) ) {
				$body = '<p role="status">이 단계의 작업 권한이 없습니다. 다른 단계를 선택해 주세요.</p>';
			} elseif ( '' !== $worksheet_id && 'criteria' !== $stage ) {
				$query['worksheet_id'] = $worksheet_id;
				if ( in_array( $stage, array( 'assessment', 'report', 'release' ), true ) ) {
					$query['type'] = 'submission';
				}
				$child_request = new AddonPageRequestV1( $request->routeKey(), $request->actorUserId(), array( 'worksheet_id' => $worksheet_id ), $query, $request->locale(), $request->correlationId() );
				$handler       = match ( $stage ) {
					'author', 'review' => new Worksheet_Workbench_Page_Handler( $this->policy, $request->routeKey(), Worksheet_Workbench_Page_Handler::MODE_EDIT ),
					'assignment' => new Assignment_Workbench_Page_Handler( $this->policy, $request->routeKey() ),
					default => new Worksheet_Evaluation_Workbench_Page_Handler( $this->policy, $request->routeKey() ),
				};
				$response = $handler->handle( $child_request );
				$body     = 200 === $response->status() ? (string) $response->body() : '<p role="status">이 작업을 열 수 없습니다. 활동지 상태와 접근 권한을 확인해 주세요.</p>';
			}
		}
		$attrs                      = array(
			'id'                     => 'oj-ai-projects',
			'class'                  => 'oj-ai-projects oj-ai-project-scope',
			'data-api-base'          => rest_url( 'oj-ai/v1' ),
			'data-nonce'             => wp_create_nonce( 'wp_rest' ),
			'data-console-base'      => home_url( '/oj-console/addons/oj-ai-dashboard/' ),
			'data-icon-url'          => Addon_Icon::sprite_url(),
			'data-project-worksheet' => $worksheet_id,
			'data-project-stage'     => $stage,
		);
		$attrs['data-can-template'] = $allowed( Addon_Capabilities::MANAGE_POLICIES ) ? '1' : '0';
		$attrs['data-can-summary']  = $allowed( Addon_Capabilities::VIEW_REPORTS ) ? '1' : '0';
		$capabilities               = array(
			'assignment' => Addon_Capabilities::ASSIGN_WORKSHEETS,
			'assessment' => Addon_Capabilities::REVIEW_ASSESSMENTS,
			'report'     => Addon_Capabilities::REVIEW_ASSESSMENTS,
			'release'    => Addon_Capabilities::PUBLISH_FEEDBACK,
		);
		foreach ( $capabilities as $key => $capability ) {
			$attrs[ 'data-can-' . $key ] = $allowed( $capability ) ? '1' : '0';
		}
		$html = $navigation . '<section';
		foreach ( $attrs as $key => $value ) {
			$html .= ' ' . $key . '="' . htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
		}
		$html .= '><div data-project-notice role="status" aria-live="polite">평가를 불러오는 중입니다.</div>';
		if ( '' !== $project_id ) {
			$html .= '<section class="oj-ai-project-workspace"><div data-project-header></div>' . Worksheet_Workflow::navigation( $request, $stage, $worksheet_id, false, true, $this->policy ) . '<div data-project-selection></div></section>';
		}
		$html .= '<div data-project-content></div><div data-project-stage-body>' . $body . '</div><dialog data-project-dialog aria-labelledby="project-dialog-title"></dialog></section>';
		return Addon_Page_Response::html( '평가 프로젝트', $html );
	}
}
