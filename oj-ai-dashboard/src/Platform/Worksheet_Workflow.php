<?php
/**
 * Worksheet workflow.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Security\Addon_Capabilities as Caps;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared navigation only; page and command policies remain authoritative.
 */
final class Worksheet_Workflow {

	private const STEPS = array(
		'criteria'   => '평가 기준',
		'author'     => '활동지 작성',
		'review'     => '활동지 검토',
		'assignment' => '배포',
		'assessment' => 'AI 평가',
		'report'     => '보고서 작성',
		'release'    => '학생 공개',
	);

	/**
	 * Allowed.
	 *
	 * @param AddonPageRequestV1           $request Request.
	 * @param Access_Policy_Port_Interface $policy Policy.
	 * @param string                       ...$capabilities Capabilities.
	 * @return bool
	 */
	public static function allowed( AddonPageRequestV1 $request, Access_Policy_Port_Interface $policy, string ...$capabilities ): bool {
		return $policy->authorize( new Access_Policy_Request( $request->actorUserId(), Access_Policy_Request::SHELL_CONSOLE, Access_Policy_Request::SURFACE_PAGE, 'workflow.navigation', $capabilities, null, null, true, $request->correlationId() ) )->is_allowed();
	}

	/**
	 * Stage allowed.
	 *
	 * @param AddonPageRequestV1           $request Request.
	 * @param Access_Policy_Port_Interface $policy Policy.
	 * @param string                       $stage Stage.
	 * @return bool
	 */
	public static function stage_allowed( AddonPageRequestV1 $request, Access_Policy_Port_Interface $policy, string $stage ): bool {
		return match ( $stage ) {
			'criteria', 'author', 'review' => self::allowed( $request, $policy, Caps::MANAGE_WORKSHEETS ),
			'assignment' => self::allowed( $request, $policy, Caps::ASSIGN_WORKSHEETS ),
			'assessment' => self::allowed( $request, $policy, Caps::VIEW_WORKSPACE, Caps::ASSESS_SUBMISSIONS ) || self::allowed( $request, $policy, Caps::VIEW_WORKSPACE, Caps::REVIEW_ASSESSMENTS ),
			'report' => self::allowed( $request, $policy, Caps::VIEW_WORKSPACE, Caps::REVIEW_ASSESSMENTS ),
			'release' => self::allowed( $request, $policy, Caps::PUBLISH_FEEDBACK ) && self::stage_allowed( $request, $policy, 'assessment' ),
			default => false,
		};
	}

	/**
	 * Whether the platform draws the console local navigation (Addon API 1.3).
	 *
	 * @var bool
	 */
	private static bool $platform_local_navigation = false;

	/**
	 * Record whether the platform renders the local navigation for this request.
	 *
	 * @param bool $enabled Enabled.
	 * @return void
	 */
	public static function use_platform_local_navigation( bool $enabled ): void {
		self::$platform_local_navigation = $enabled;
	}

	/**
	 * Whether the platform renders the local navigation.
	 *
	 * @return bool
	 */
	public static function platform_local_navigation(): bool {
		return self::$platform_local_navigation;
	}

	/**
	 * Work areas the current user may open, in navigation order.
	 *
	 * @param AddonPageRequestV1           $request Request.
	 * @param Access_Policy_Port_Interface $policy Policy.
	 * @return array<int,array{0:string,1:string,2:string,3:bool}>
	 */
	public static function workspace_items( AddonPageRequestV1 $request, Access_Policy_Port_Interface $policy ): array {
		$worksheets = self::allowed( $request, $policy, Caps::VIEW_WORKSPACE, Caps::VIEW_WORKSHEETS );
		$settings   = self::allowed( $request, $policy, Caps::MANAGE_POLICIES );
		return array(
			array( 'projects', '평가 프로젝트', Addon_Manifest::CONSOLE_PROJECTS, self::allowed( $request, $policy, Caps::VIEW_WORKSPACE ) ),
			array( $worksheets ? 'worksheets' : 'criteria', '활동지 보관함', Addon_Manifest::CONSOLE_WORKSHEETS, $worksheets || $settings ),
			array( 'settings', '설정', Addon_Manifest::CONSOLE_SETTINGS, $settings ),
		);
	}

	/**
	 * Whether the user has a work area other than the project list.
	 *
	 * @param AddonPageRequestV1           $request Request.
	 * @param Access_Policy_Port_Interface $policy Policy.
	 * @return bool
	 */
	public static function has_other_workspace( AddonPageRequestV1 $request, Access_Policy_Port_Interface $policy ): bool {
		foreach ( self::workspace_items( $request, $policy ) as [ $path, , , $visible ] ) {
			if ( $visible && 'projects' !== $path ) {
				return true;
			}
		}
		return self::stage_allowed( $request, $policy, 'assignment' )
			|| self::allowed( $request, $policy, Caps::VIEW_WORKSPACE, Caps::ASSESS_WORKSHEETS )
			|| self::stage_allowed( $request, $policy, 'assessment' )
			|| self::allowed( $request, $policy, Caps::VIEW_REPORTS );
	}

	/**
	 * Workspace navigation for platforms without the console local navigation.
	 *
	 * On OJ Platform 2.1+ the platform draws the local navigation under the
	 * sidebar menu, so only a hidden marker for the workflow script is emitted.
	 *
	 * @param AddonPageRequestV1           $request Request.
	 * @param Access_Policy_Port_Interface $policy Policy.
	 * @param bool                         $project_owner Project owner.
	 * @return string
	 */
	public static function workspace( AddonPageRequestV1 $request, Access_Policy_Port_Interface $policy, bool $project_owner = false ): string {
		if ( Addon_Manifest::CONSOLE_PROJECTS === $request->routeKey() && ! $project_owner ) {
			return '';
		}
		$project_id = $request->query()['project_id'] ?? '';
		if ( is_string( $project_id ) && Public_Id::is_valid( $project_id ) && in_array( $request->routeKey(), array( Addon_Manifest::CONSOLE_PROJECTS, Addon_Manifest::CONSOLE_WORKSHEET_EDIT, Addon_Manifest::CONSOLE_ASSIGNMENTS, Addon_Manifest::CONSOLE_ASSESSMENTS, Addon_Manifest::CONSOLE_REPORTS ), true ) ) {
			return '';
		}
		if ( self::$platform_local_navigation ) {
			return '<a class="oj-ai-workspace-return" data-workspace-return data-workspace-base="' . self::escape( self::base() ) . '" data-workspace-reports="'
				. ( self::allowed( $request, $policy, Caps::VIEW_REPORTS ) ? '1' : '0' ) . '" hidden></a>';
		}
		$assessment = self::allowed( $request, $policy, Caps::VIEW_WORKSPACE, Caps::ASSESS_WORKSHEETS ) || self::stage_allowed( $request, $policy, 'assessment' );
		$worksheets = self::allowed( $request, $policy, Caps::VIEW_WORKSPACE, Caps::VIEW_WORKSHEETS );
		$settings   = self::allowed( $request, $policy, Caps::MANAGE_POLICIES );
		$items      = self::workspace_items( $request, $policy );
		$current    = match ( $request->routeKey() ) {
			Addon_Manifest::CONSOLE_WORKSHEET_EDIT, Addon_Manifest::CONSOLE_CRITERIA => Addon_Manifest::CONSOLE_WORKSHEETS,
			Addon_Manifest::CONSOLE_ASSIGNMENTS, Addon_Manifest::CONSOLE_ASSESSMENTS, Addon_Manifest::CONSOLE_REPORTS => Addon_Manifest::CONSOLE_PROJECTS,
			Addon_Manifest::CONSOLE_STORAGE_DETAIL => Addon_Manifest::CONSOLE_SETTINGS,
			default => $request->routeKey(),
		};
		$html = '<nav class="oj-ai-workspace-nav" aria-label="활동지 평가 메뉴" data-workspace-navigation>';
		foreach ( $items as [ $path, $label, $route, $visible ] ) {
			if ( ! $visible ) {
				continue;
			}
			$html .= '<a data-workspace-link="' . $path . '" href="' . self::escape( self::base() . $path ) . '"' . ( $current === $route ? ' aria-current="page"' : '' ) . '>' . $label . '</a>';
		}
		$html     .= '</nav><a class="oj-ai-workspace-return" data-workspace-return hidden></a>';
		$library   = Addon_Manifest::CONSOLE_WORKSHEETS === $current;
		$secondary = $library ? array(
			array( 'worksheets', '활동지 보관함', Addon_Manifest::CONSOLE_WORKSHEETS, $worksheets ),
			array( 'criteria', '평가 기준', Addon_Manifest::CONSOLE_CRITERIA, $settings ),
		) : array(
			array( 'assignments', '배포·제출', Addon_Manifest::CONSOLE_ASSIGNMENTS, self::stage_allowed( $request, $policy, 'assignment' ) ),
			array( 'assessments', '평가', Addon_Manifest::CONSOLE_ASSESSMENTS, $assessment ),
			array( 'reports', '보고서', Addon_Manifest::CONSOLE_REPORTS, self::allowed( $request, $policy, Caps::VIEW_REPORTS ) ),
		);
		if ( Addon_Manifest::CONSOLE_SETTINGS === $current ) {
			return $html;
		}
		$links = '';
		foreach ( $secondary as [ $path, $label, $route, $visible ] ) {
			if ( ! $visible ) {
				continue;
			}
			$links .= '<a data-workspace-link="' . $path . '" href="' . self::escape( self::base() . $path ) . '"' . ( $request->routeKey() === $route ? ' aria-current="page"' : '' ) . '>' . $label . '</a>';
		}
		return $html . ( '' === $links ? '' : '<nav class="oj-ai-workspace-links" aria-label="' . ( $library ? '보관함 종류' : '전체 업무' ) . '">' . $links . '</nav>' );
	}

	/**
	 * Base.
	 *
	 * @return string
	 */
	private static function base(): string {
		return function_exists( 'home_url' ) ? (string) home_url( '/oj-console/addons/oj-ai-dashboard/' ) : '/oj-console/addons/oj-ai-dashboard/';
	}

	/**
	 * Navigation.
	 *
	 * @param AddonPageRequestV1                $request Request.
	 * @param string                            $stage Stage.
	 * @param string                            $worksheet_id Worksheet public identifier.
	 * @param bool                              $editor Editor.
	 * @param bool                              $project_owner Project owner.
	 * @param Access_Policy_Port_Interface|null $policy Policy.
	 * @return string
	 */
	public static function navigation( AddonPageRequestV1 $request, string $stage, string $worksheet_id = '', bool $editor = false, bool $project_owner = false, ?Access_Policy_Port_Interface $policy = null ): string {
		if ( Addon_Manifest::CONSOLE_PROJECTS === $request->routeKey() && ! $project_owner ) {
			return '';
		}
		$query   = $request->query();
		$id      = $worksheet_id ? $worksheet_id : ( is_string( $query['worksheet_id'] ?? null ) ? $query['worksheet_id'] : '' );
		$id      = Public_Id::is_valid( $id ) ? $id : '';
		$base    = function_exists( 'home_url' ) ? (string) home_url( '/oj-console/addons/oj-ai-dashboard/' ) : '/oj-console/addons/oj-ai-dashboard/';
		$html    = '<nav class="oj-ai-workflow oj-ai-workbench__phases" data-oj-ai-workflow data-workflow-base="' . self::escape( $base ) . '" data-current-step="' . self::escape( $stage ) . '" data-workflow-worksheet="' . self::escape( $id ) . '" aria-label="' . ( $project_owner ? '평가 진행 단계' : '활동지 진행 단계' ) . '"' . ( '' === $id && ! $project_owner ? ' hidden' : '' ) . '>';
		$html   .= '<label class="oj-ai-workflow__compact">진행 단계<select data-workflow-select>';
		$enabled = array();
		foreach ( self::STEPS as $key => $label ) {
			$disabled        = null !== $policy && ! self::stage_allowed( $request, $policy, $key );
			$enabled[ $key ] = ! $disabled;
			$html           .= '<option value="' . $key . '"' . ( $stage === $key ? ' selected' : '' ) . ( $disabled ? ' disabled' : '' ) . '>' . $label . ( $disabled ? ' (권한 필요)' : '' ) . '</option>';
		}
		$html  .= '</select></label><ol>';
		$number = 0;
		foreach ( self::STEPS as $key => $label ) {
			++$number;
			if ( ! $enabled[ $key ] ) {
				$html .= '<li><span data-workflow-step="' . $key . '" aria-disabled="true" title="이 단계의 작업 권한이 필요합니다."><b>' . $number . '</b><span>' . $label . '</span></span></li>';
				continue;
			}
			$params = array(
				'worksheet_id' => $id,
				'stage'        => $key,
			);
			foreach ( array( 'project_id', 'assignment_id', 'context_type', 'context_id', 'context_revision', 'publication_id', 'publication_hash' ) as $name ) {
				$value = $query[ $name ] ?? '';
				if ( is_string( $value ) && strlen( $value ) <= 191 && preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
					$params[ $name ] = $value;
				}
			}
			$path = match ( $key ) {
				'criteria', 'author', 'review' => 'worksheets/' . rawurlencode( $id ),
				'assignment' => 'assignments',
				default => 'assessments',
			};
			if ( 'assessments' === $path ) {
				$params['type'] = 'submission';
			}
			if ( $project_owner ) {
				$path = 'projects';
			}
			$url     = $base . $path . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
			$current = $key === $stage ? ' aria-current="step"' : '';
			$local   = $editor && in_array( $key, array( 'criteria', 'author', 'review' ), true );
			$html   .= '<li>' . ( $local ? '<button type="button" data-action="workflow-' . $key . '"' : '<a href="' . self::escape( $url ) . '"' )
				. ' data-workflow-step="' . $key . '"' . $current . '><b>' . $number . '</b><span>' . $label . '</span>' . ( $local ? '</button>' : '</a>' ) . '</li>';
		}
		return $html . '</ol></nav>';
	}

	/**
	 * Header.
	 *
	 * @param AddonPageRequestV1                $request Request.
	 * @param string                            $stage Stage.
	 * @param Access_Policy_Port_Interface|null $policy Policy.
	 * @return string
	 */
	public static function header( AddonPageRequestV1 $request, string $stage, ?Access_Policy_Port_Interface $policy = null ): string {
		if ( Addon_Manifest::CONSOLE_PROJECTS === $request->routeKey() ) {
			return '';
		}
		return '<section class="oj-ai-workflow-context" data-workflow-context hidden><a data-workflow-return href="' . self::escape( self::base() . 'projects' ) . '">평가 프로젝트</a><strong data-workflow-title>활동지 작업</strong><span data-workflow-scope></span></section>'
			. self::navigation( $request, $stage, '', false, false, $policy );
	}

	/**
	 * Escape.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
