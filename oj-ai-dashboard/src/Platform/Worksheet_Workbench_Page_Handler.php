<?php
/**
 * OJ Console worksheet authoring workbench Page handler.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use OJAI\Dashboard\Curriculum\Catalog\Domain\Curriculum_Catalog_Profile;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the worksheet list and the Plate-backed editor inside OJ Console.
 */
final class Worksheet_Workbench_Page_Handler implements AddonPageHandlerV1 {
	public const MODE_LIST = 'list';
	public const MODE_EDIT = 'edit';

	/**
	 * Policy.
	 *
	 * @var Access_Policy_Port_Interface
	 */
	private Access_Policy_Port_Interface $policy;
	/**
	 * Route key.
	 *
	 * @var string
	 */
	private string $route_key;
	/**
	 * Mode.
	 *
	 * @var string
	 */
	private string $mode;

	/**
	 * Initialize worksheet workbench page handler dependencies and state.
	 *
	 * @param Access_Policy_Port_Interface $policy Policy.
	 * @param string                       $route_key Route key.
	 * @param string                       $mode Mode.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		Access_Policy_Port_Interface $policy,
		string $route_key,
		string $mode
	) {
		if ( ! in_array( $mode, array( self::MODE_LIST, self::MODE_EDIT ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported worksheet workbench mode.' );
		}

		$this->policy    = $policy;
		$this->route_key = $route_key;
		$this->mode      = $mode;
	}

	/**
	 * Handle worksheet workbench page handler.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return AddonPageResponseV1
	 */
	public function handle( AddonPageRequestV1 $request ): AddonPageResponseV1 {
		if ( $request->routeKey() !== $this->route_key ) {
			return AddonPageResponseV1::unavailable( '요청한 화면을 열 수 없습니다.', 'addon_handler_failed' );
		}

		$worksheet_id = null;
		$object       = null;
		$capabilities = array( Addon_Capabilities::VIEW_WORKSHEETS );
		if ( self::MODE_EDIT === $this->mode ) {
			$worksheet_id = $request->parameters()['worksheet_id'] ?? '';
			if ( ! Public_Id::is_valid( $worksheet_id ) ) {
				return $this->message_response( '활동지 편집', '활동지를 찾을 수 없습니다.', 404, 'worksheet_not_found' );
			}
			$object       = new Object_Scope( Object_Scope::TYPE_WORKSHEET, $worksheet_id, 'edit' );
			$capabilities = array( Addon_Capabilities::MANAGE_WORKSHEETS );
		}

		$decision = $this->authorize( $request, $this->route_key, $capabilities, $object );
		if ( ! $decision->is_allowed() ) {
			if ( self::MODE_LIST === $this->mode && 403 === $decision->status()
				&& $this->authorize( $request, 'worksheet.workspace.open', array( Addon_Capabilities::VIEW_WORKSPACE ), null )->is_allowed() ) {
				return Addon_Page_Response::html( '활동지 평가', '<section class="oj-ai-workbench"><header class="ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>활동지 보관함</h1></div></header>' . $this->workspace_links( $request ) . '</section>' );
			}
			return $this->denied_response( $decision );
		}

		if ( self::MODE_EDIT === $this->mode ) {
			$export = $this->authorize(
				$request,
				'worksheet.export.open',
				array( Addon_Capabilities::EXPORT_REPORTS ),
				null
			);

			return Addon_Page_Response::html(
				'활동지 편집',
				Worksheet_Workflow::workspace( $request, $this->policy ) . $this->edit_markup( $request, (string) $worksheet_id, $export->is_allowed() )
			);
		}

		$manage = $this->authorize(
			$request,
			'worksheet.create',
			array( Addon_Capabilities::MANAGE_WORKSHEETS ),
			null
		);

		return Addon_Page_Response::html(
			'활동지',
			Worksheet_Workflow::workspace( $request, $this->policy ) . $this->list_markup( $request, $manage->is_allowed() )
		);
	}

	/**
	 * Authorize.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param string             $action_key Action key.
	 * @param array<int,string>  $capabilities Capabilities.
	 * @param Object_Scope|null  $object Object.
	 * @return Access_Policy_Decision
	 */
	private function authorize(
		AddonPageRequestV1 $request,
		string $action_key,
		array $capabilities,
		?Object_Scope $object // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
	): Access_Policy_Decision {
		return $this->policy->authorize(
			new Access_Policy_Request(
				$request->actorUserId(),
				Access_Policy_Request::SHELL_CONSOLE,
				Access_Policy_Request::SURFACE_PAGE,
				$action_key,
				$capabilities,
				null,
				$object,
				true,
				$request->correlationId()
			)
		);
	}

	/**
	 * List markup.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param bool               $can_manage Can manage.
	 * @return string
	 */
	private function list_markup( AddonPageRequestV1 $request, bool $can_manage ): string {
		$attributes = $this->application_attributes( $request, self::MODE_LIST, null, $can_manage );
		if ( Worksheet_Workflow::stage_allowed( $request, $this->policy, 'assignment' ) ) {
			$attributes .= ' data-assignments-url="' . $this->escape( $this->assignments_url() ) . '"';
		}
		$new_button = $can_manage
			? '<a class="oj-ai-workbench__primary ojp-button ojp-button--primary" data-workspace-link="projects" href="' . $this->escape( $this->workflow_url( 'projects' ) ) . '">새 평가에서 작성</a>'
			: '';

		return '<section id="oj-ai-worksheet-authoring-app" class="oj-ai-workbench" ' . $attributes . '>'
			. '<header class="oj-ai-workbench__header ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>활동지 보관함</h1><p data-role="summary">불러오는 중</p></div>'
			. $new_button . '</header>'
			. '<div class="oj-ai-workbench__filters">'
			. '<label class="oj-ai-workbench__search"><span class="screen-reader-text">활동지 검색</span>'
			. '<input type="search" data-field="search" placeholder="활동지 검색" autocomplete="off"></label>'
			. '<label><span class="screen-reader-text">상태</span><select data-field="state">'
			. '<option value="">전체</option><option value="draft">작성 중</option><option value="active">사용 중</option>'
			. '<option value="archived">보관</option></select></label></div>'
			. '<div class="oj-ai-workbench__notice" data-role="notice" role="status">활동지를 불러오는 중입니다.</div>'
			. '<div class="oj-ai-workbench__table-wrap" data-role="content" hidden>'
			. '<table class="oj-ai-workbench__table"><thead><tr><th>활동지</th><th>발행</th><th>배포·제출</th><th>상태</th><th>수정</th><th>재사용</th></tr></thead>'
			. '<tbody data-role="worksheet-list"></tbody></table></div>'
			. '</section>';
	}

	/**
	 * Workspace links.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return string
	 */
	private function workspace_links( AddonPageRequestV1 $request ): string {
		return Worksheet_Workflow::workspace( $request, $this->policy );
	}

	/**
	 * Edit markup.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param string             $worksheet_id Worksheet public identifier.
	 * @param bool               $can_export Can export.
	 * @return string
	 */
	private function edit_markup( AddonPageRequestV1 $request, string $worksheet_id, bool $can_export ): string {
		$attributes = $this->application_attributes( $request, self::MODE_EDIT, $worksheet_id, true, $can_export );
		if ( Addon_Manifest::CONSOLE_PROJECTS !== $request->routeKey() ) {
			return '<div data-worksheet-entry-shell><section data-worksheet-entry role="status"><p data-worksheet-entry-message>활동지를 불러오는 중입니다.</p><button type="button" data-worksheet-entry-retry hidden>다시 시도</button></section><div data-worksheet-entry-content hidden><section id="oj-ai-worksheet-authoring-app" class="oj-ai-workbench" ' . $attributes . '></section></div></div>';
		}
		$export = $can_export ? '<section class="oj-ai-workbench__export" data-role="export-controls"><div><strong>내보내기</strong><span data-role="export-status" role="status">저장된 버전</span></div><div><button type="button" data-action="export-pdf" disabled>PDF</button><button type="button" data-action="export-package" disabled>활동지 파일</button></div></section>' : '';

		return '<div data-worksheet-entry-shell><section data-worksheet-entry role="status"><p data-worksheet-entry-message>활동지를 불러오는 중입니다.</p>'
			. '<button type="button" class="ojp-button" data-worksheet-entry-retry hidden>다시 시도</button>'
			. '<a class="ojp-button ojp-button--plain" href="' . $this->escape( $this->list_url() ) . '">활동지 보관함</a></section>'
			. '<div data-worksheet-entry-content hidden>'
			. '<section id="oj-ai-worksheet-authoring-app" class="oj-ai-workbench oj-ai-workbench--editor" data-workflow-stage="author" ' . $attributes . '>'
			. '<header class="oj-ai-workbench__header oj-ai-workbench__header--editor">'
			. '<a class="oj-ai-workbench__back ojp-button ojp-button--plain" data-workflow-editor-return href="' . $this->escape( $this->list_url() ) . '" aria-label="활동지 목록으로" title="뒤로">'
			. Addon_Icon::markup( 'arrow-left' ) . '</a>'
			. '<div class="oj-ai-workbench__heading"><label class="oj-ai-workbench__title"><span class="screen-reader-text">활동지 제목</span>'
			. '<input id="ws-template-title" type="text" maxlength="200" autocomplete="off" aria-label="활동지 제목"></label>'
			. '<p class="oj-ai-workbench__meta"><span class="oj-ai-workbench__state" data-role="worksheet-state">작성 중</span>'
			. '<span data-role="save-status" role="status">불러오는 중</span></p></div>'
			. '<div class="oj-ai-workbench__actions">'
			. '<a class="oj-ai-workbench__check ojp-button" data-role="quality-link" href="' . $this->escape( $this->workflow_url( 'worksheets/' . $worksheet_id . '?stage=review' ) ) . '">활동지 검토'
			. '<span class="screen-reader-text" data-role="quality-status">저장 후 점검할 수 있습니다.</span></a>'
			. '<details class="oj-ai-workbench__more"><summary aria-label="편집 도구 더보기" title="더보기">' . Addon_Icon::markup( 'ellipsis' ) . '</summary>'
			. '<div class="oj-ai-workbench__more-menu" role="group" aria-label="편집 도구">'
			. '<button type="button" data-action="asset-upload" disabled>파일 추가</button>'
			. '<button type="button" data-action="import-template" disabled>가져오기</button>'
			. '<button type="button" data-action="duplicate" disabled>복제</button>'
			. '<button type="button" data-action="validate" disabled>문서 검증</button>'
			. '<button type="button" data-action="preview-open" disabled>미리보기</button></div></details>'
			. '<button type="button" class="oj-ai-workbench__primary oj-ai-workbench__save ojp-button ojp-button--primary" data-action="save" disabled>'
			. Addon_Icon::markup( 'save' ) . '<span>저장</span></button></div></header>'
			. '<input type="file" data-field="asset-file" accept=".jpg,.jpeg,.png,.webp,.mp3,.ogg,.wav,.mp4,.webm,.pdf,.txt,.csv,.docx,.xlsx,.pptx" hidden>'
			. '<input type="file" data-field="template-package" accept=".ojws,application/vnd.oj.ai-worksheet+zip" hidden>'
			. '<div class="oj-ai-workbench__banner" data-role="project-criteria-status" hidden><span data-role="project-criteria-version"></span><button type="button" data-action="refresh-project-criteria">최신 확정 기준 적용</button></div>'
			. '<details class="oj-ai-workbench__basis" data-role="confirmed-basis" hidden><summary>이 활동지의 기준</summary><div data-role="basis-summary"></div><button type="button" data-action="workflow-criteria">기준 수정</button></details>'
			. '<div class="oj-ai-workbench__banner" data-role="recovery" hidden><span>복구할 내용이 있습니다.</span>'
			. '<div><button type="button" data-action="restore">복구</button><button type="button" data-action="discard-recovery">삭제</button></div></div>'
			. '<div class="oj-ai-workbench__banner oj-ai-workbench__banner--warning" data-role="conflict" hidden><span>다른 변경이 저장되었습니다.</span>'
			. '<div><button type="button" data-action="reload-latest">최신 내용 불러오기</button>'
			. '<button type="button" data-action="retry-local">내 내용 다시 저장</button></div></div>'
			. '<div class="oj-ai-workbench__notice" data-role="notice" role="alert" hidden></div>'
			. '<div class="oj-ai-workbench__banner" data-role="preparation-required" hidden><span>평가 기준 확인 필요 · 읽기 전용</span><button type="button" data-action="workflow-criteria">평가 기준 열기</button></div>'
			. '<section class="oj-ai-workbench__asset-tray" data-role="asset-tray" hidden>'
			. '<strong>첨부 파일</strong><div data-role="staged-assets"></div></section>'
			. '<textarea id="ws-builder-schema" hidden aria-hidden="true"></textarea>'
			. '<textarea id="ws-render-schema" hidden aria-hidden="true"></textarea>'
			. '<div class="oj-ai-workbench__compact-tools" role="group" aria-label="작성 화면 도구">'
			. '<button type="button" data-action="toggle-outline" aria-expanded="false" aria-controls="oj-ai-question-outline" title="문항 목록 열기 또는 닫기">' . Addon_Icon::markup( 'list' ) . '<span>문항 목록</span></button>'
			. '<button type="button" data-action="add-question" title="문항 추가">' . Addon_Icon::markup( 'plus' ) . '<span>문항 추가</span></button>'
			. '<button type="button" data-action="toggle-inspector" aria-expanded="false" aria-controls="oj-ai-question-inspector" title="설정 열기 또는 닫기">' . Addon_Icon::markup( 'settings' ) . '<span>설정</span></button></div>'
			. '<div class="oj-ai-workbench__workspace">'
			. '<aside id="oj-ai-question-outline" class="oj-ai-workbench__outline" aria-label="문항 목록"><header><h2>문항</h2>'
			. '<button type="button" class="oj-ai-workbench__icon-button ojp-button ojp-button--plain" data-action="close-author-panel" aria-label="문항 목록 닫기" title="문항 목록 닫기">'
			. Addon_Icon::markup( 'x' ) . '</button></header><span class="oj-ai-workbench__outline-count" data-role="outline-count">0개</span>'
			. '<ol data-role="document-outline"><li class="oj-ai-workbench__outline-empty">내용을 불러오는 중</li></ol></aside>'
			. '<main class="oj-ai-workbench__canvas" aria-label="활동지 편집 영역">'
			. '<div id="ws-plate-template-builder-root" class="oj-ai-workbench__editor" aria-busy="true">'
			. '<p class="oj-ai-workbench__loading">편집기를 불러오는 중입니다.</p></div></main>'
			. '<aside id="oj-ai-question-inspector" class="oj-ai-workbench__inspector" aria-label="활동지 설정"><header><h2>설정</h2>'
			. '<button type="button" class="oj-ai-workbench__icon-button ojp-button ojp-button--plain" data-action="close-author-panel" aria-label="설정 닫기" title="설정 닫기">' . Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<div class="oj-ai-workbench__inspector-tabs" role="tablist" aria-label="설정 종류">'
			. '<button type="button" role="tab" data-inspector-tab="question" aria-selected="true">문항</button>'
			. '<button type="button" role="tab" data-inspector-tab="goals" aria-selected="false">학습 목표</button>'
			. '<button type="button" role="tab" data-inspector-tab="assessment" aria-selected="false">평가 연결</button></div>'
			. '<section data-inspector-panel="question"><div id="ws-response-inspector-root" class="oj-ai-workbench__question-settings">'
			. '<p class="oj-ai-workbench__inspector-empty">문항을 선택하거나 추가하세요.</p></div></section>'
			. '<section data-inspector-panel="goals" hidden>'
			. '<section class="oj-ai-workbench__inspector-section oj-ai-workbench__inspector-section--first"><h3>학습 목표</h3>'
			. '<label class="oj-ai-workbench__inspector-search"><span>목표 문장</span>'
			. '<textarea rows="3" maxlength="' . Worksheet_Document_Profile::MAX_LEARNING_GOAL_CHARACTERS . '" data-field="learning-goal-text" placeholder="학생이 이 활동을 통해 할 수 있어야 하는 내용을 입력하세요."></textarea></label>'
			. '<label class="oj-ai-workbench__inspector-search"><span>적용 대상</span>'
			. '<select data-field="learning-goal-add-target"><option value="worksheet">활동지 전체</option></select></label>'
			. '<button type="button" class="oj-ai-workbench__inspector-add ojp-button" data-action="add-learning-goal">목표 추가</button>'
			. '<p class="oj-ai-workbench__inspector-status" data-role="learning-goal-status" role="status"></p>'
			. '<div data-role="learning-goals"></div></section>'
			. '<section class="oj-ai-workbench__inspector-section"><h3>성취기준 연결</h3>'
			. '<div data-project-hide="standards"><label class="oj-ai-workbench__inspector-search"><span>교육과정</span><select data-field="curriculum-select"><option value="">불러오는 중</option></select></label>'
			. '<label class="oj-ai-workbench__inspector-search"><span>적용 대상</span><select data-field="achievement-add-target"><option value="worksheet">활동지 전체</option></select></label>'
			. '<label class="oj-ai-workbench__inspector-search"><span>성취기준 찾기</span>'
			. '<input type="search" data-field="achievement-search" placeholder="코드 또는 내용 검색" autocomplete="off"></label></div>'
			. '<p class="oj-ai-workbench__inspector-status" data-role="achievement-status" role="status"></p>'
			. '<h4>연결됨</h4><div data-role="achievement-links"></div>'
			. '<div data-project-hide="standards"><h4>선택</h4><div data-role="achievement-standards"></div></div></section></section>'
			. '<section data-inspector-panel="assessment" hidden><p class="oj-ai-workbench__inspector-status" data-role="assessment-map-summary"></p>'
			. '<h3>평가 요소</h3><div class="oj-ai-workbench__assessment-map" data-role="assessment-map"></div></section></aside></div>'
			. '<section class="oj-ai-workbench__review-stage" data-role="worksheet-review-stage" hidden><header><h2>활동지 검토</h2><button type="button" data-action="review-validate">문서 확인</button><button type="button" data-action="preview-open">학생 미리보기</button></header>'
			. ( new Worksheet_Evaluation_Workbench_Page_Handler( $this->policy, $this->route_key ) )->worksheet_review_panel( $request ) . '</section>'
			. '<section class="oj-ai-workbench__workflow" aria-label="검토와 발행">'
			. '<section class="oj-ai-workbench__validation" data-role="validation-result" role="status" hidden>'
			. '<div><strong data-role="validation-title"></strong><span data-role="validation-message"></span></div>'
			. '<button type="button" data-action="validate-retry">다시 검증</button></section>'
			. '<section id="oj-ai-release-controls" class="oj-ai-workbench__release" data-role="release-controls">'
			. '<div><strong data-role="release-title">작성 중</strong><span data-role="release-message">문서 확인 후 교사 검토 승인이 필요합니다.</span></div>'
			. '<div><button type="button" data-action="approve" disabled>교사 검토 승인</button>'
			. '<button type="button" class="oj-ai-workbench__primary ojp-button ojp-button--primary" data-action="publish" disabled>배포용 버전 발행</button>'
			. '<button type="button" data-action="archive" disabled>보관</button>'
			. '<button type="button" data-action="restore-worksheet" disabled hidden>복원</button></div></section>'
			. $export
			. '</section>'
			. '<div class="oj-ai-workbench__preview-shell" data-role="preview-shell" data-kind="student" hidden>'
			. '<button type="button" class="oj-ai-workbench__preview-scrim" data-action="preview-close" aria-label="미리보기 닫기"></button>'
			. '<section class="oj-ai-workbench__preview-panel" role="dialog" aria-modal="true" aria-labelledby="oj-ai-preview-title">'
			. '<header><h2 id="oj-ai-preview-title" data-role="preview-title">학생 미리보기</h2>'
			. '<div><button type="button" data-action="preview-print-page" hidden>인쇄</button>'
			. '<button type="button" class="oj-ai-workbench__preview-close ojp-button ojp-button--plain" data-action="preview-close" aria-label="닫기" title="닫기">&times;</button></div></header>'
			. '<div class="oj-ai-workbench__preview-tabs" role="tablist" aria-label="미리보기 종류">'
			. '<button type="button" role="tab" data-action="preview-student" aria-selected="true">학생</button>'
			. '<button type="button" role="tab" data-action="preview-print" aria-selected="false">인쇄</button></div>'
			. '<div class="oj-ai-workbench__preview-status" data-role="preview-status" role="status">불러오는 중</div>'
			. '<div class="oj-ai-workbench__preview-content" data-role="preview-content"></div>'
			. '</section></div>'
			. '</section></div></div>';
	}

	/**
	 * Workflow url.
	 *
	 * @param string $suffix Suffix.
	 * @return string
	 */
	private function workflow_url( string $suffix ): string {
		$path = '/oj-console/addons/oj-ai-dashboard/' . $suffix;
		return function_exists( 'home_url' ) ? (string) home_url( $path ) : $path;
	}

	/**
	 * Application attributes.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param string             $view View.
	 * @param string|null        $worksheet_id Worksheet public identifier.
	 * @param bool               $can_manage Can manage.
	 * @param bool               $can_export Can export.
	 * @return string
	 */
	private function application_attributes(
		AddonPageRequestV1 $request,
		string $view,
		?string $worksheet_id,
		bool $can_manage,
		bool $can_export = false
	): string {
		$rest_root = function_exists( 'rest_url' ) ? rest_url( 'oj-ai/v1' ) : '/wp-json/oj-ai/v1';
		$nonce     = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '';
		$values    = array(
			'data-view'                         => $view,
			'data-rest-root'                    => rtrim( (string) $rest_root, '/' ),
			'data-rest-nonce'                   => (string) $nonce,
			'data-list-url'                     => $this->list_url(),
			'data-edit-url-template'            => $this->edit_url_template(),
			'data-locale'                       => $request->locale(),
			'data-timezone'                     => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'Asia/Seoul',
			'data-can-manage'                   => $can_manage ? '1' : '0',
			'data-can-export'                   => $can_export ? '1' : '0',
			'data-assessments-url'              => $this->assessments_url(),
			'data-max-learning-goals'           => (string) Worksheet_Document_Profile::MAX_LEARNING_GOALS,
			'data-max-learning-goal-characters' => (string) Worksheet_Document_Profile::MAX_LEARNING_GOAL_CHARACTERS,
			'data-catalog-page-limit'           => (string) Curriculum_Catalog_Profile::MAX_QUERY_ITEMS,
		);
		if ( null !== $worksheet_id ) {
			$values['data-worksheet-id'] = $worksheet_id;
		}

		$attributes = array();
		foreach ( $values as $name => $value ) {
			$attributes[] = $name . '="' . $this->escape( $value ) . '"';
		}

		return implode( ' ', $attributes );
	}

	/**
	 * List url.
	 *
	 * @return string
	 */
	private function list_url(): string {
		$path = '/oj-console/addons/oj-ai-dashboard/worksheets';
		return function_exists( 'home_url' ) ? (string) home_url( $path ) : $path;
	}

	/**
	 * Edit url template.
	 *
	 * @return string
	 */
	private function edit_url_template(): string {
		$path = '/oj-console/addons/oj-ai-dashboard/worksheets/{worksheet_id}';
		return function_exists( 'home_url' ) ? (string) home_url( $path ) : $path;
	}

	/**
	 * Assignments url.
	 *
	 * @param string|null $worksheet_id Worksheet public identifier.
	 * @return string
	 */
	private function assignments_url( ?string $worksheet_id = null ): string {
		$path = '/oj-console/addons/oj-ai-dashboard/assignments';
		if ( null !== $worksheet_id && '' !== $worksheet_id ) {
			$path .= '?worksheet_id=' . rawurlencode( $worksheet_id );
		}
		return function_exists( 'home_url' ) ? (string) home_url( $path ) : $path;
	}

	/**
	 * Assessments url.
	 *
	 * @param string|null $worksheet_id Worksheet public identifier.
	 * @return string
	 */
	private function assessments_url( ?string $worksheet_id = null ): string {
		$path = '/oj-console/addons/oj-ai-dashboard/assessments';
		if ( null !== $worksheet_id && '' !== $worksheet_id ) {
			$path .= '?type=worksheet&worksheet_id=' . rawurlencode( $worksheet_id );
		}
		return function_exists( 'home_url' ) ? (string) home_url( $path ) : $path;
	}

	/**
	 * Denied response.
	 *
	 * @param Access_Policy_Decision $decision Decision.
	 * @return AddonPageResponseV1
	 */
	private function denied_response( Access_Policy_Decision $decision ): AddonPageResponseV1 {
		if ( 503 === $decision->status() ) {
			return AddonPageResponseV1::unavailable( '활동지 평가를 지금 열 수 없습니다.', 'addon_runtime_unavailable' );
		}

		$status = 401 === $decision->status() ? 403 : $decision->status();
		if ( ! in_array( $status, array( 403, 404, 409 ), true ) ) {
			$status = 403;
		}
		$messages = array(
			403 => '이 화면을 볼 수 없습니다.',
			404 => '활동지를 찾을 수 없습니다.',
			409 => '내용이 바뀌었습니다. 새로고침해 주세요.',
		);

		return $this->message_response( '활동지', $messages[ $status ], $status, $decision->code() );
	}

	/**
	 * Message response.
	 *
	 * @param string $title Title.
	 * @param string $message Message.
	 * @param int    $status Status.
	 * @param string $code Code.
	 * @return AddonPageResponseV1
	 */
	private function message_response( string $title, string $message, int $status, string $code ): AddonPageResponseV1 {
		$body = '<section class="oj-ai-workbench" data-oj-ai-code="' . $this->escape( $code ) . '">'
			. '<header class="oj-ai-workbench__header ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>' . $this->escape( $title ) . '</h1></div></header>'
			. '<p class="oj-ai-workbench__notice" role="alert">' . $this->escape( $message ) . '</p></section>';

		return Addon_Page_Response::html( $title, $body, $status );
	}

	/**
	 * Escape.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
