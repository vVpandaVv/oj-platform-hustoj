<?php
/**
 * OJ Console external AI consent settings Page handler.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use OJAI\Dashboard\Curriculum\Catalog\Domain\Curriculum_Catalog_Profile;
use OJAI\Dashboard\Operations\SchemaRepair\Domain\Schema_Repair_State;
use OJAI\Dashboard\Operations\SchemaRepair\Port\Schema_Repair_State_Port_Interface;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OJ Console external AI consent settings Page handler.
 */
final class External_Ai_Consent_Settings_Page_Handler implements AddonPageHandlerV1 {
	/**
	 * Initialize external ai consent settings page handler dependencies and state.
	 *
	 * @param Access_Policy_Port_Interface            $policy Policy.
	 * @param string                                  $route_key Route key.
	 * @param Access_Policy_Port_Interface|null       $recovery_policy Recovery policy.
	 * @param Schema_Repair_State_Port_Interface|null $schema_repair Schema repair.
	 */
	public function __construct(
		private Access_Policy_Port_Interface $policy,
		private string $route_key,
		private ?Access_Policy_Port_Interface $recovery_policy = null,
		private ?Schema_Repair_State_Port_Interface $schema_repair = null
	) {
	}

	/**
	 * Handle external ai consent settings page handler.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return AddonPageResponseV1
	 */
	public function handle( AddonPageRequestV1 $request ): AddonPageResponseV1 {
		if ( $request->routeKey() !== $this->route_key ) {
			return AddonPageResponseV1::unavailable( '요청한 화면을 열 수 없습니다.', 'addon_handler_failed' );
		}
		$decision = $this->policy->authorize(
			new Access_Policy_Request(
				$request->actorUserId(),
				Access_Policy_Request::SHELL_CONSOLE,
				Access_Policy_Request::SURFACE_PAGE,
				'external_ai.consent.read',
				array( Addon_Capabilities::MANAGE_POLICIES ),
				null,
				null,
				true,
				$request->correlationId()
			)
		);
		if ( ! $decision->is_allowed() ) {

			if ( 503 === $decision->status() && $this->recovery_policy instanceof Access_Policy_Port_Interface ) {
				$recovery = $this->recovery_policy->authorize(
					new Access_Policy_Request(
						$request->actorUserId(),
						Access_Policy_Request::SHELL_CONSOLE,
						Access_Policy_Request::SURFACE_PAGE,
						'operations.schema.repair.view',
						array( Addon_Capabilities::MANAGE_MIGRATIONS ),
						null,
						null,
						true,
						$request->correlationId()
					)
				);
				if ( $recovery->is_allowed() ) {
					return Addon_Page_Response::html(
						'시스템 상태',
						$this->recovery_markup( $request ) . ( ! $this->library_mode( $request ) && function_exists( 'current_user_can' ) && current_user_can( 'install_plugins' ) ? self::server_install_markup() : '' )
					);
				}
			}
			if ( 503 === $decision->status() && ! $this->library_mode( $request ) && function_exists( 'current_user_can' ) && current_user_can( 'install_plugins' ) ) {
				return Addon_Page_Response::html( '서버 준비', '<div class="ojp-notice ojp-notice--warning" role="status">서버 준비 또는 시스템 점검이 필요합니다.</div>' . self::server_install_markup() );
			}
			return $this->denied_response( $decision );
		}

		$can_manage_data = $this->can_manage_data( $request );
		$can_purge_data  = $can_manage_data
			&& function_exists( 'current_user_can' )
			&& current_user_can( 'delete_plugins' );

		return Addon_Page_Response::html(
			$this->library_mode( $request ) ? '평가 기준' : '설정',
			$this->markup( $request, $can_manage_data, $can_purge_data )
		);
	}

	/**
	 * Library mode.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return bool
	 */
	private function library_mode( AddonPageRequestV1 $request ): bool {
		return Addon_Manifest::CONSOLE_CRITERIA === $this->route_key
			|| in_array( $request->query()['tab'] ?? '', array( 'rubric', 'curriculum' ), true );
	}

	/**
	 * Can manage data.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return bool
	 */
	private function can_manage_data( AddonPageRequestV1 $request ): bool {
		if ( ! $this->recovery_policy instanceof Access_Policy_Port_Interface ) {
			return false;
		}

		return $this->recovery_policy->authorize(
			new Access_Policy_Request(
				$request->actorUserId(),
				Access_Policy_Request::SHELL_CONSOLE,
				Access_Policy_Request::SURFACE_PAGE,
				'operations.data.manage',
				array( Addon_Capabilities::MANAGE_MIGRATIONS ),
				null,
				null,
				true,
				$request->correlationId()
			)
		)->is_allowed();
	}

	/**
	 * Markup.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param bool               $can_manage_data Can manage data.
	 * @param bool               $can_purge_data Can purge data.
	 * @return string
	 */
	private function markup(
		AddonPageRequestV1 $request,
		bool $can_manage_data,
		bool $can_purge_data
	): string {
		$attributes = $this->attributes( $request );
		$library    = $this->library_mode( $request );
		$tabs       = $library ? array(
			'rubric'     => '평가 기준',
			'curriculum' => '성취기준',
		)
			: array(
				'policy'      => '평가 정책',
				'review-rule' => '검토·승인',
				'connection'  => 'AI 연결',
				'system'      => '시스템 상태',
			);
		$tab_markup = '';
		foreach ( $tabs as $key => $label ) {
			$tab_markup .= '<button type="button" id="oj-ai-settings-tab-' . $key . '" role="tab" data-settings-tab="' . $key . '" aria-controls="oj-ai-settings-panel-' . $key . '" aria-selected="false">' . $label . '</button>';
		}
		$data_tab   = $can_manage_data
			? '<button type="button" id="oj-ai-settings-tab-data" role="tab" data-settings-tab="data" aria-controls="oj-ai-settings-panel-data" aria-selected="false">데이터 관리</button>'
			: '';
		$data_panel = $can_manage_data ? $this->data_management_markup( $can_purge_data ) : '';

		$server_install = ! $library && function_exists( 'current_user_can' ) && current_user_can( 'install_plugins' ) ? self::server_install_markup() : '';

		return Worksheet_Workflow::workspace( $request, $this->policy ) . Worksheet_Workflow::header( $request, 'criteria', $this->policy )
			. $server_install
			. '<section id="oj-ai-assessment-settings" class="oj-ai-settings" data-settings-mode="' . ( $library ? 'library' : 'operations' ) . '" ' . $attributes . $this->worksheet_policy_contract_attributes() . '>'
			. '<header class="oj-ai-settings__header ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>' . ( $library ? '평가 기준' : '설정' ) . '</h1></div>'
			. '<button type="button" class="oj-ai-settings__refresh ojp-button" data-config-action="refresh" aria-label="새로고침" title="새로고침">' . Addon_Icon::markup( 'refresh-cw' ) . '</button></header>'
			. '<nav class="oj-ai-settings__tabs" role="tablist" aria-label="설정 영역">'
			. $tab_markup . ( $library ? '' : $data_tab ) . '</nav>'
			. '<div class="oj-ai-settings__subject" data-config-role="subject-switch" aria-label="평가 종류">'
			. '<button type="button" data-config-subject="worksheet_quality" aria-pressed="true">활동지 자체 평가</button>'
			. '<button type="button" data-config-subject="student_submission" aria-pressed="false">학생 제출 평가</button></div>'
			. '<section id="oj-ai-assessment-configuration-app">'
			. '<p class="oj-ai-config__notice" data-config-role="notice" role="status">평가 설정을 불러오는 중입니다.</p>'
			. $this->policy_markup()
			. $this->rubric_markup()
			. $this->review_rule_markup()
			. '</section>'
			. $this->curriculum_catalog_markup()
			. '<section id="oj-ai-settings-panel-connection" data-settings-panel="connection" role="tabpanel" aria-labelledby="oj-ai-settings-tab-connection" hidden>'
			. $this->consent_markup( $attributes )
			. '</section>'
			. $data_panel
			. '<section id="oj-ai-settings-panel-system" data-settings-panel="system" role="tabpanel" aria-labelledby="oj-ai-settings-tab-system" hidden>'
			. $this->diagnostics_markup( $attributes )
			. '</section></section>';
	}

	/**
	 * Data management markup.
	 *
	 * @param bool $can_purge Can purge.
	 * @return string
	 */
	private function data_management_markup( bool $can_purge ): string {
		$purge_action = $can_purge
			? '<button type="button" class="oj-ai-data__button oj-ai-data__button--danger ojp-button ojp-button--danger" data-data-action="purge-open">'
				. Addon_Icon::markup( 'trash' ) . '<span>영구 삭제</span></button>'
			: '<p class="oj-ai-data__permission">영구 삭제에는 플러그인 삭제 권한이 필요합니다.</p>';

		return '<section id="oj-ai-settings-panel-data" class="oj-ai-data" data-settings-panel="data" role="tabpanel" aria-labelledby="oj-ai-settings-tab-data" hidden>'
			. '<div class="oj-ai-data__preservation">' . Addon_Icon::markup( 'database' )
			. '<div><strong>데이터는 자동으로 삭제되지 않습니다.</strong>'
			. '<p>플러그인을 비활성화하거나 제거해도 활동지와 평가 결과는 보존됩니다.</p></div></div>'
			. '<div class="oj-ai-data__rows">'
			. '<section class="oj-ai-data__row"><div><h2>이전 데이터 가져오기</h2>'
			. '<p>이 플러그인의 이전 버전에서 만든 활동지와 평가 자료를 가져옵니다.</p></div>'
			. '<button type="button" class="oj-ai-data__button ojp-button" data-data-action="migration-open">'
			. Addon_Icon::markup( 'download' ) . '<span>가져오기</span></button></section>'
			. '<section class="oj-ai-data__row oj-ai-data__row--danger"><div><h2>모든 데이터 영구 삭제</h2>'
			. '<p>활동지, 학생 제출, 평가 결과와 비공개 파일을 되돌릴 수 없게 삭제합니다.</p></div>'
			. $purge_action . '</section></div>'
			. $this->migration_dialog_markup()
			. $this->purge_dialog_markup( $can_purge )
			. '</section>';
	}

	/**
	 * Migration dialog markup.
	 *
	 * @return string
	 */
	private function migration_dialog_markup(): string {
		return '<dialog class="oj-ai-data__dialog" data-data-dialog="migration" aria-labelledby="oj-ai-migration-title">'
			. '<form><header><h2 id="oj-ai-migration-title">이전 데이터 가져오기</h2>'
			. '<button type="button" class="oj-ai-data__icon-button ojp-button ojp-button--plain" data-data-action="dialog-close" aria-label="닫기" title="닫기">'
			. Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<p class="oj-ai-data__notice" data-data-role="migration-notice" role="status">가져올 데이터를 확인하는 중입니다.</p>'
			. '<div class="oj-ai-data__dialog-body" data-data-role="migration-summary" hidden>'
			. '<dl class="oj-ai-data__metrics"><div><dt>가져올 항목</dt><dd data-data-value="migration-candidates">0</dd></div>'
			. '<div><dt>연결 확인</dt><dd data-data-value="migration-mapping">0</dd></div>'
			. '<div><dt>제외 항목</dt><dd data-data-value="migration-excluded">0</dd></div></dl>'
			. '<p class="oj-ai-data__detail" data-data-role="migration-detail"></p>'
			. '<label class="oj-ai-data__check"><input type="checkbox" data-data-field="migration-ack">'
			. '<span>원본 데이터는 바꾸거나 삭제하지 않습니다.</span></label></div>'
			. '<footer><button type="button" data-data-action="dialog-close">취소</button>'
			. '<button type="button" class="oj-ai-data__button oj-ai-data__button--primary ojp-button ojp-button--primary" data-data-action="migration-start" disabled>가져오기 시작</button></footer>'
			. '</form></dialog>';
	}

	/**
	 * Purge dialog markup.
	 *
	 * @param bool $can_purge Can purge.
	 * @return string
	 */
	private function purge_dialog_markup( bool $can_purge ): string {
		if ( ! $can_purge ) {
			return '';
		}

		return '<dialog class="oj-ai-data__dialog oj-ai-data__dialog--danger" data-data-dialog="purge" aria-labelledby="oj-ai-purge-title">'
			. '<form><header><h2 id="oj-ai-purge-title">모든 데이터 영구 삭제</h2>'
			. '<button type="button" class="oj-ai-data__icon-button ojp-button ojp-button--plain" data-data-action="dialog-close" aria-label="닫기" title="닫기">'
			. Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<p class="oj-ai-data__notice" data-data-role="purge-notice" role="status">삭제할 데이터를 확인하는 중입니다.</p>'
			. '<div class="oj-ai-data__dialog-body" data-data-role="purge-summary" hidden>'
			. '<dl class="oj-ai-data__metrics"><div><dt>데이터 행</dt><dd data-data-value="purge-rows">0</dd></div>'
			. '<div><dt>비공개 파일</dt><dd data-data-value="purge-files">0</dd></div>'
			. '<div><dt>진행 중 작업</dt><dd data-data-value="purge-jobs">0</dd></div></dl>'
			. '<p class="oj-ai-data__warning">삭제 후에는 복구할 수 없습니다. 최근 백업을 먼저 확인하세요.</p>'
			. '<label class="oj-ai-data__check"><input type="checkbox" data-data-field="purge-backup-ack"><span>최근 백업을 확인했습니다.</span></label>'
			. '<label class="oj-ai-data__field"><span>백업 확인 메모</span><input type="text" maxlength="200" autocomplete="off" data-data-field="purge-backup-reference"></label>'
			. '<label class="oj-ai-data__field"><span>백업 확인 시각</span><input type="datetime-local" data-data-field="purge-backup-time"></label>'
			. '<label class="oj-ai-data__field"><span><strong>영구 삭제</strong>를 입력하세요.</span>'
			. '<input type="text" maxlength="20" autocomplete="off" data-data-field="purge-phrase"></label></div>'
			. '<footer><button type="button" data-data-action="dialog-close">취소</button>'
			. '<button type="button" class="oj-ai-data__button oj-ai-data__button--danger ojp-button ojp-button--danger" data-data-action="purge-start" disabled>영구 삭제 시작</button></footer>'
			. '</form></dialog>';
	}

	/**
	 * Recovery markup.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return string
	 */
	private function recovery_markup( AddonPageRequestV1 $request ): string {
		$attributes = $this->attributes( $request );

		return '<section id="oj-ai-assessment-settings" class="oj-ai-settings oj-ai-settings--recovery" ' . $attributes . '>'
			. '<header class="oj-ai-settings__header ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>시스템 상태</h1>'
			. '<p>데이터 구조를 확인하고 필요한 복구를 실행합니다.</p></div></header>'
			. $this->diagnostics_markup( $attributes, $this->repair_projection() ) . '</section>';
	}

	/**
	 * Diagnostics markup.
	 *
	 * @param string                   $attributes Attributes.
	 * @param array<string,mixed>|null $repair Repair.
	 * @return string
	 */
	private function diagnostics_markup( string $attributes, ?array $repair = null ): string {
		$operations_url     = function_exists( 'home_url' ) ? home_url( '/oj-console/system-status' ) : '/oj-console/system-status';
		$ai_url             = function_exists( 'home_url' ) ? home_url( '/oj-console/settings/ai-routing' ) : '/oj-console/settings/ai-routing';
		$storage_detail_url = function_exists( 'home_url' )
			? home_url( '/oj-console/addons/oj-ai-dashboard/settings/storage-detail' )
			: '/oj-console/addons/oj-ai-dashboard/settings/storage-detail';

		return '<section id="oj-ai-operations-diagnostics" class="oj-ai-diagnostics" ' . $attributes
			. ' data-platform-operations-url="' . $this->escape( (string) $operations_url )
			. '" data-platform-ai-url="' . $this->escape( (string) $ai_url )
			. '" data-storage-detail-url="' . $this->escape( (string) $storage_detail_url ) . '"' . $this->repair_attributes( $repair ) . '>'
			. '<header class="oj-ai-diagnostics__header"><div><h2>시스템 상태</h2>'
			. '<p data-diagnostics-role="checked-at">확인 중</p></div><div class="oj-ai-diagnostics__heading-actions">'
			. '<span class="oj-ai-diagnostics__badge" data-diagnostics-role="summary" data-status="attention">확인 중</span>'
			. '<button type="button" class="oj-ai-diagnostics__refresh ojp-button" data-diagnostics-action="refresh"'
			. ' aria-label="다시 확인" title="다시 확인">' . Addon_Icon::markup( 'refresh-cw' ) . '</button></div></header>'
			. '<p class="oj-ai-diagnostics__notice" data-diagnostics-role="notice" role="status">상태를 확인하는 중입니다.</p>'
			. '<div class="oj-ai-diagnostics__list" data-diagnostics-role="list" role="list" aria-live="polite" aria-busy="true" hidden></div>'
			. '<section class="oj-ai-diagnostics__repair" data-diagnostics-role="repair" hidden>'
			. '<header><h3>데이터 구조 복구</h3><p>최근 백업을 확인한 뒤 복구를 실행합니다.</p></header>'
			. '<div class="oj-ai-diagnostics__repair-form">'
			. '<label><span>백업 확인 메모</span><input type="text" maxlength="200" autocomplete="off" data-diagnostics-field="backup-reference"></label>'
			. '<label><span>백업 확인 시각</span><input type="datetime-local" data-diagnostics-field="backup-verified-at"></label>'
			. '<label class="oj-ai-diagnostics__repair-check"><input type="checkbox" data-diagnostics-field="forward-only">'
			. '<span>앞으로만 복구</span></label>'
			. '<button type="button" data-diagnostics-action="repair" disabled>복구</button></div>'
			. '<p data-diagnostics-role="repair-status" role="status"></p>'
			. '</section>'
			. '</section>';
	}

	/**
	 * Repair projection.
	 *
	 * @return array<string,mixed>
	 */
	private function repair_projection(): array {
		if ( ! $this->schema_repair instanceof Schema_Repair_State_Port_Interface ) {
			return Schema_Repair_State::blocked( 'schema_repair_state_unavailable' )->projection();
		}
		try {
			return $this->schema_repair->inspect()->projection();
		} catch ( \Throwable ) {
			return Schema_Repair_State::blocked( 'schema_repair_state_unavailable' )->projection();
		}
	}

	/**
	 * Repair attributes.
	 *
	 * @param array<string,mixed>|null $repair Repair.
	 * @return string
	 */
	private function repair_attributes( ?array $repair ): string {
		if ( null === $repair ) {
			return '';
		}
		$values   = array(
			'data-recovery-mode'    => 'true',
			'data-repair-available' => true === ( $repair['available'] ?? false ) ? 'true' : 'false',
			'data-repair-code'      => is_string( $repair['code'] ?? null ) ? $repair['code'] : 'schema_repair_state_unavailable',
		);
		$expected = $repair['expected'] ?? null;
		if ( 'true' === $values['data-repair-available'] && is_array( $expected ) ) {
			$values += array(
				'data-repair-installation-state'  => is_string( $expected['installation_state'] ?? null ) ? $expected['installation_state'] : '',
				'data-repair-catalog-fingerprint' => is_string( $expected['catalog_fingerprint'] ?? null ) ? $expected['catalog_fingerprint'] : '',
				'data-repair-state-hash'          => is_string( $expected['repair_state_hash'] ?? null ) ? $expected['repair_state_hash'] : '',
			);
		}

		$attributes = '';
		foreach ( $values as $name => $value ) {
			$attributes .= ' ' . $name . '="' . $this->escape( $value ) . '"';
		}

		return $attributes;
	}

	/**
	 * Curriculum catalog markup.
	 *
	 * @return string
	 */
	private function curriculum_catalog_markup(): string {
		return '<section id="oj-ai-settings-panel-curriculum" class="oj-ai-catalog" data-settings-panel="curriculum" role="tabpanel" aria-labelledby="oj-ai-settings-tab-curriculum" hidden>'
			. '<header class="oj-ai-catalog__header"><div><h2>성취기준</h2><p>교육과정별 성취기준을 관리합니다.</p></div>'
			. '<button type="button" class="oj-ai-catalog__primary ojp-button ojp-button--primary" data-catalog-action="curriculum-new">'
			. Addon_Icon::markup( 'plus' ) . '<span>교육과정 추가</span></button></header>'
			. '<p class="oj-ai-catalog__notice" data-catalog-role="notice" role="status">불러오는 중</p>'
			. '<div class="oj-ai-catalog__curriculum-bar"><label><span>교육과정</span>'
			. '<select data-catalog-field="curriculum"></select></label>'
			. '<button type="button" class="oj-ai-catalog__icon-button ojp-button ojp-button--plain" data-catalog-action="curriculum-edit" aria-label="교육과정 수정" title="교육과정 수정">'
			. Addon_Icon::markup( 'edit' ) . '</button></div>'
			. '<div class="oj-ai-catalog__standard-tools"><label><span class="screen-reader-text">성취기준 검색</span>'
			. '<span class="oj-ai-catalog__search-icon">' . Addon_Icon::markup( 'search' ) . '</span>'
			. '<input type="search" data-catalog-field="search" placeholder="코드 또는 내용 검색" autocomplete="off"></label>'
			. '<button type="button" class="oj-ai-catalog__primary ojp-button ojp-button--primary" data-catalog-action="standard-new">'
			. Addon_Icon::markup( 'plus' ) . '<span>성취기준 추가</span></button></div>'
			. '<div class="oj-ai-catalog__list" data-catalog-role="standards" aria-live="polite"></div>'
			. $this->curriculum_dialog_markup()
			. $this->standard_dialog_markup()
			. '</section>';
	}

	/**
	 * Curriculum dialog markup.
	 *
	 * @return string
	 */
	private function curriculum_dialog_markup(): string {
		return '<dialog class="oj-ai-catalog__dialog" data-catalog-dialog="curriculum" aria-labelledby="oj-ai-curriculum-dialog-title">'
			. '<form data-catalog-form="curriculum"><header><h2 id="oj-ai-curriculum-dialog-title" data-catalog-role="curriculum-dialog-title">교육과정 추가</h2>'
			. '<button type="button" class="oj-ai-catalog__icon-button ojp-button ojp-button--plain" data-catalog-action="dialog-close" aria-label="닫기" title="닫기">'
			. Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<input type="hidden" data-catalog-field="curriculum-id"><input type="hidden" data-catalog-field="curriculum-lock">'
			. '<label><span>이름</span><input type="text" maxlength="' . Curriculum_Catalog_Profile::MAX_TITLE_CHARACTERS . '" required data-catalog-field="curriculum-title" autocomplete="off"></label>'
			. '<div class="oj-ai-catalog__field-row"><label><span>교육과정 코드</span><input type="text" maxlength="' . Curriculum_Catalog_Profile::MAX_SOURCE_KEY_CHARACTERS . '" required data-catalog-field="curriculum-source-key" autocomplete="off"></label>'
			. '<label><span>버전</span><input type="text" maxlength="' . Curriculum_Catalog_Profile::MAX_SOURCE_VERSION_CHARACTERS . '" required data-catalog-field="curriculum-source-version" autocomplete="off"></label></div>'
			. '<label><span>언어</span><input type="text" maxlength="' . Curriculum_Catalog_Profile::MAX_LOCALE_CHARACTERS . '" required data-catalog-field="curriculum-locale" autocomplete="off"></label>'
			. '<p data-catalog-role="curriculum-error" role="alert"></p>'
			. '<footer><button type="button" data-catalog-action="dialog-close">취소</button>'
			. '<button type="submit" class="oj-ai-catalog__primary ojp-button ojp-button--primary">' . Addon_Icon::markup( 'save' ) . '<span>저장</span></button></footer>'
			. '</form></dialog>';
	}

	/**
	 * Standard dialog markup.
	 *
	 * @return string
	 */
	private function standard_dialog_markup(): string {
		return '<dialog class="oj-ai-catalog__dialog" data-catalog-dialog="standard" aria-labelledby="oj-ai-standard-dialog-title">'
			. '<form data-catalog-form="standard"><header><h2 id="oj-ai-standard-dialog-title" data-catalog-role="standard-dialog-title">성취기준 추가</h2>'
			. '<button type="button" class="oj-ai-catalog__icon-button ojp-button ojp-button--plain" data-catalog-action="dialog-close" aria-label="닫기" title="닫기">'
			. Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<input type="hidden" data-catalog-field="standard-id"><input type="hidden" data-catalog-field="standard-lock">'
			. '<div class="oj-ai-catalog__field-row"><label><span>코드</span><input type="text" maxlength="' . Curriculum_Catalog_Profile::MAX_STANDARD_CODE_CHARACTERS . '" required data-catalog-field="standard-code" autocomplete="off"></label>'
			. '<label><span>학년·범위</span><input type="text" maxlength="' . Curriculum_Catalog_Profile::MAX_GRADE_LABEL_CHARACTERS . '" data-catalog-field="standard-grade" autocomplete="off"></label></div>'
			. '<label><span>성취기준</span><input type="text" maxlength="' . Curriculum_Catalog_Profile::MAX_TITLE_CHARACTERS . '" required data-catalog-field="standard-title" autocomplete="off"></label>'
			. '<label><span>설명</span><textarea rows="5" maxlength="' . Curriculum_Catalog_Profile::MAX_STANDARD_DESCRIPTION_CHARACTERS . '" data-catalog-field="standard-description"></textarea></label>'
			. '<p data-catalog-role="standard-error" role="alert"></p>'
			. '<footer><button type="button" data-catalog-action="dialog-close">취소</button>'
			. '<button type="submit" class="oj-ai-catalog__primary ojp-button ojp-button--primary">' . Addon_Icon::markup( 'save' ) . '<span>저장</span></button></footer>'
			. '</form></dialog>';
	}

	/**
	 * Policy markup.
	 *
	 * @return string
	 */
	private function policy_markup(): string {
		return '<section id="oj-ai-settings-panel-policy" class="oj-ai-config__panel" data-settings-panel="policy" role="tabpanel" aria-labelledby="oj-ai-settings-tab-policy">'
			. '<header class="oj-ai-config__panel-header"><div><h2>평가 정책</h2><p data-config-role="subject-label">활동지 자체 평가</p></div>'
			. '<span class="oj-ai-config__summary" data-config-role="policy-summary">확인 중</span></header>'
			. '<p class="oj-ai-config__resource-error" data-config-role="policy-error" role="alert" hidden></p>'
			. '<div class="oj-ai-config__form"><label class="oj-ai-config__toggle">'
			. '<input type="checkbox" data-config-field="policy-enabled" checked><span>평가 사용</span></label>'
			. '<div class="oj-ai-config__actions"><span></span><button type="button" class="oj-ai-config__button oj-ai-config__button--primary ojp-button ojp-button--primary" data-config-action="save-policy">새 초안 저장</button></div></div>'
			. '<section class="oj-ai-config__history"><h3>변경 이력</h3><div class="oj-ai-config__history-list" data-config-role="policy-revisions"></div></section></section>';
	}

	/**
	 * Rubric markup.
	 *
	 * @return string
	 */
	private function rubric_markup(): string {
		return '<section id="oj-ai-settings-panel-rubric" class="oj-ai-config__panel" data-settings-panel="rubric" role="tabpanel" aria-labelledby="oj-ai-settings-tab-rubric" hidden>'
			. '<header class="oj-ai-config__panel-header"><div><h2>평가 기준</h2><p data-config-role="subject-label">활동지 자체 평가</p></div>'
			. '<span class="oj-ai-config__summary" data-config-role="rubric-summary">확인 중</span></header>'
			. '<p class="oj-ai-config__guidance" data-config-role="rubric-guidance"></p>'
			. '<p class="oj-ai-config__resource-error" data-config-role="rubric-error" role="alert" hidden></p>'
			. '<div class="oj-ai-config__form"><div class="oj-ai-config__editor" data-config-role="rubric-editor"></div>'
			. '<div class="oj-ai-config__actions"><span></span><button type="button" class="oj-ai-config__button oj-ai-config__button--primary ojp-button ojp-button--primary" data-config-action="save-rubric">새 초안 저장</button></div></div>'
			. '<section class="oj-ai-config__history"><h3>변경 이력</h3><div class="oj-ai-config__history-list" data-config-role="rubric-revisions"></div></section></section>';
	}

	/**
	 * Review rule markup.
	 *
	 * @return string
	 */
	private function review_rule_markup(): string {
		return '<section id="oj-ai-settings-panel-review-rule" class="oj-ai-config__panel" data-settings-panel="review-rule" role="tabpanel" aria-labelledby="oj-ai-settings-tab-review-rule" hidden>'
			. '<header class="oj-ai-config__panel-header"><div><h2>검토·승인 기준</h2><p data-config-role="subject-label">활동지 자체 평가</p></div>'
			. '<span class="oj-ai-config__summary" data-config-role="review-rule-summary">확인 중</span></header>'
			. '<p class="oj-ai-config__resource-error" data-config-role="review-rule-error" role="alert" hidden></p>'
			. '<div class="oj-ai-config__form"><label class="oj-ai-config__fixed-rule"><input type="checkbox" checked disabled data-config-fixed><span>사람 검토 필수</span></label>'
			. '<label class="oj-ai-config__fixed-rule"><input type="checkbox" checked disabled data-config-fixed><span>명시적 공개 필수</span></label>'
			. '<label class="oj-ai-config__field oj-ai-config__threshold"><span>우선 검토 기준</span><input type="number" min="0" max="1" step="0.05" value="0.8" data-config-field="confidence-threshold"></label>'
			. '<div class="oj-ai-config__actions"><span></span><button type="button" class="oj-ai-config__button oj-ai-config__button--primary ojp-button ojp-button--primary" data-config-action="save-review-rule">새 초안 저장</button></div></div>'
			. '<section class="oj-ai-config__history"><h3>변경 이력</h3><div class="oj-ai-config__history-list" data-config-role="review-rule-revisions"></div></section></section>';
	}

	/**
	 * Consent markup.
	 *
	 * @param string $attributes Attributes.
	 * @return string
	 */
	private function consent_markup( string $attributes ): string {
		return '<div class="oj-ai-consent-settings-shell">'
			. '<div class="oj-ai-consent-settings__scope" role="tablist" aria-label="AI 처리 대상">'
			. '<button type="button" id="oj-ai-consent-tab-worksheet" role="tab" data-consent-tab="worksheet" aria-selected="true" aria-controls="oj-ai-external-consent-app">활동지 평가</button>'
			. '<button type="button" id="oj-ai-consent-tab-submission" role="tab" data-consent-tab="submission" aria-selected="false" aria-controls="oj-ai-submission-external-consent-app">학생 제출 평가</button></div>'
			. $this->consent_panel(
				'oj-ai-external-consent-app',
				'worksheet',
				'활동지 평가',
				'활동지 자체 평가에 사용할 외부 AI를 관리합니다.',
				'/settings/external-ai-consent',
				'external_ai_consent',
				array( '활동지 내용', '활동지의 텍스트 자료', '평가 기준' ),
				$attributes,
				false
			)
			. $this->consent_panel(
				'oj-ai-submission-external-consent-app',
				'submission',
				'학생 제출 평가',
				'학생 제출 평가에 사용할 외부 AI를 관리합니다.',
				'/settings/submission-external-ai-consent',
				'submission_external_ai_consent',
				array( '학생이 입력한 답변', '제출한 자료의 텍스트', '평가 정책과 루브릭', '활동지 채점 내용' ),
				$attributes,
				true
			)
			. '</div>';
	}

	/**
	 * Consent panel.
	 *
	 * @param string            $id Id.
	 * @param string            $scope Scope.
	 * @param string            $title Title.
	 * @param string            $description Description.
	 * @param string            $status_path Status path.
	 * @param string            $response_key Response key.
	 * @param array<int,string> $categories Categories.
	 * @param string            $attributes Attributes.
	 * @param bool              $hidden Hidden.
	 * @return string
	 */
	private function consent_panel(
		string $id,
		string $scope,
		string $title,
		string $description,
		string $status_path,
		string $response_key,
		array $categories,
		string $attributes,
		bool $hidden
	): string {
		$category_markup = '';
		foreach ( $categories as $category ) {
			$category_markup .= '<li>' . $this->escape( $category ) . '</li>';
		}
		$id_attribute = $this->escape( $id );
		$tab_id       = $this->escape( 'oj-ai-consent-tab-' . $scope );
		return '<section id="' . $id_attribute . '" class="oj-ai-consent-settings" data-consent-root data-consent-panel="'
			. $this->escape( $scope ) . '" data-status-path="' . $this->escape( $status_path )
			. '" data-response-key="' . $this->escape( $response_key ) . '" role="tabpanel" aria-labelledby="' . $tab_id . '" ' . $attributes . ( $hidden ? ' hidden' : '' ) . '>'
			. '<header class="oj-ai-consent-settings__header"><div><h1>' . $this->escape( $title ) . '</h1>'
			. '<p>' . $this->escape( $description ) . '</p></div>'
			. '<button type="button" class="oj-ai-consent-settings__icon ojp-button ojp-button--plain" data-action="refresh" aria-label="새로고침" title="새로고침">' . Addon_Icon::markup( 'refresh-cw' ) . '</button></header>'
			. '<p class="oj-ai-consent-settings__notice" data-role="notice" role="status">상태를 불러오는 중입니다.</p>'
			. '<div class="oj-ai-consent-settings__content" data-role="content" hidden>'
			. '<section class="oj-ai-consent-settings__status" aria-labelledby="' . $id_attribute . '-status-title">'
			. '<div><span class="oj-ai-consent-settings__badge" data-role="status-badge">확인 중</span>'
			. '<h2 id="' . $id_attribute . '-status-title" data-role="status-title">AI 처리 승인</h2>'
			. '<p data-role="status-message"></p></div>'
			. '<dl><div><dt>승인 일시</dt><dd data-role="approved-at">-</dd></div>'
			. '<div><dt>승인자</dt><dd data-role="approved-by">-</dd></div></dl></section>'
			. '<section class="oj-ai-consent-settings__section" aria-labelledby="' . $id_attribute . '-destination-title">'
			. '<h2 id="' . $id_attribute . '-destination-title">연결 대상</h2><dl class="oj-ai-consent-settings__destination">'
			. '<div><dt>제공자</dt><dd data-role="provider">-</dd></div>'
			. '<div><dt>모델</dt><dd data-role="model">-</dd></div>'
			. '<div><dt>연결 상태</dt><dd data-role="routing-state">확인 중</dd></div></dl></section>'
			. '<section class="oj-ai-consent-settings__section" aria-labelledby="' . $id_attribute . '-data-title">'
			. '<h2 id="' . $id_attribute . '-data-title">전송 항목</h2><ul data-role="categories">'
			. $category_markup . '</ul></section>'
			. '<section class="oj-ai-consent-settings__actions" aria-labelledby="' . $id_attribute . '-action-title">'
			. '<h2 id="' . $id_attribute . '-action-title">처리 승인</h2>'
			. '<label><input type="checkbox" data-field="acknowledgement">'
			. '<span>위 항목이 표시된 외부 AI로 전송되는 것에 동의합니다.</span></label>'
			. '<div><button type="button" class="oj-ai-consent-settings__primary ojp-button ojp-button--primary" data-action="approve" disabled>승인</button>'
			. '<button type="button" class="oj-ai-consent-settings__danger ojp-button ojp-button--danger" data-action="revoke" disabled>승인 철회</button></div></section>'
			. '</div></section>';
	}

	/**
	 * Attributes.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return string
	 */
	private function attributes( AddonPageRequestV1 $request ): string {
		$rest_root  = function_exists( 'rest_url' ) ? rest_url( 'oj-ai/v1' ) : '/wp-json/oj-ai/v1';
		$nonce      = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '';
		$values     = array(
			'data-rest-root'                  => rtrim( (string) $rest_root, '/' ),
			'data-rest-nonce'                 => (string) $nonce,
			'data-locale'                     => $request->locale(),
			'data-timezone'                   => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'Asia/Seoul',
			'data-icon-sprite'                => Addon_Icon::sprite_url(),
			'data-catalog-page-limit'         => (string) Curriculum_Catalog_Profile::MAX_QUERY_ITEMS,
			'data-catalog-manual-source-kind' => Curriculum_Catalog_Profile::SOURCE_MANUAL,
		);
		$attributes = array();
		foreach ( $values as $name => $value ) {
			$attributes[] = $name . '="' . $this->escape( $value ) . '"';
		}
		return implode( ' ', $attributes );
	}

	/**
	 * Worksheet policy contract attributes.
	 *
	 * @return string
	 */
	private function worksheet_policy_contract_attributes(): string {
		$values     = array(
			'data-worksheet-prompt-contract' => Worksheet_Evaluation_Input_Builder::PROMPT_CONTRACT,
			'data-worksheet-prompt-hash'     => Worksheet_Evaluation_Input_Builder::PROMPT_CONTENT_HASH,
			'data-worksheet-input-schema'    => Worksheet_Evaluation_Input_Builder::REQUEST_SCHEMA,
			'data-worksheet-input-version'   => Worksheet_Evaluation_Input_Builder::REQUEST_VERSION,
			'data-worksheet-input-hash'      => hash( 'sha256', Worksheet_Evaluation_Input_Builder::REQUEST_SCHEMA . '@' . Worksheet_Evaluation_Input_Builder::REQUEST_VERSION ),
			'data-worksheet-output-schema'   => Worksheet_Evaluation_Input_Builder::OUTPUT_SCHEMA,
			'data-worksheet-output-version'  => Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION,
			'data-worksheet-output-hash'     => hash( 'sha256', Worksheet_Evaluation_Input_Builder::OUTPUT_SCHEMA . '@' . Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION ),
		);
		$attributes = '';
		foreach ( $values as $name => $value ) {
			$attributes .= ' ' . $name . '="' . $this->escape( $value ) . '"';
		}
		return $attributes;
	}

	/**
	 * Denied response.
	 *
	 * @param Access_Policy_Decision $decision Decision.
	 * @return AddonPageResponseV1
	 */
	private function denied_response( Access_Policy_Decision $decision ): AddonPageResponseV1 {
		if ( 503 === $decision->status() ) {
			return AddonPageResponseV1::unavailable( 'AI 연결 설정을 지금 열 수 없습니다.', 'addon_runtime_unavailable' );
		}
		$status = 401 === $decision->status() ? 403 : $decision->status();
		if ( ! in_array( $status, array( 403, 404, 409 ), true ) ) {
			$status = 403;
		}
		$message = 409 === $status ? '내용이 바뀌었습니다. 새로고침해 주세요.' : '이 설정을 볼 수 없습니다.';
		$body    = '<section class="oj-ai-consent-settings" data-oj-ai-code="' . $this->escape( $decision->code() ) . '">'
			. '<header class="oj-ai-consent-settings__header"><h1>설정</h1></header>'
			. '<p class="oj-ai-consent-settings__notice" role="alert">' . $message . '</p></section>';
		return Addon_Page_Response::html( '설정', $body, $status );
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

	/** The installer belongs to this add-on; the platform only supplies modal UI. */
	private static function server_install_markup(): string {
		$quote                    = static fn ( string $value ): string => "'" . str_replace( "'", "'\"'\"'", $value ) . "'";
		$installer                = dirname( __DIR__, 2 ) . '/resources/server/install.sh';
		$id                       = 'oj-ai-dashboard-server-install';
		$commands                 = array();
		$commands['AI 저장소 준비']    = 'sudo bash ' . $quote( $installer ) . ' ' . $quote( 'ai' ) . ' ' . $quote( rtrim( ABSPATH, '/\\' ) ) . ' ' . $quote( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ) . ' --apply';
		$commands['첨부파일 검사 (선택)'] = 'sudo bash ' . $quote( $installer ) . ' ' . $quote( 'clamav' ) . ' ' . $quote( rtrim( ABSPATH, '/\\' ) ) . ' ' . $quote( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ) . ' --apply';
		$content                  = '';
		$fallback                 = '';
		$index                    = 0;
		foreach ( $commands as $label => $command ) {
			$command_id = $id . '-command-' . $index;
			$part       = '<p>' . esc_html( $label ) . '</p><textarea class="ojp-textarea" id="' . esc_attr( $command_id ) . '" rows="3" readonly aria-label="' . esc_attr( $label ) . '">' . esc_textarea( $command ) . '</textarea>'
				. '<button type="button" class="ojp-button" data-ojp-copy-target="' . esc_attr( $command_id ) . '">명령 복사</button>';
			$content   .= $index > 0 ? '<details><summary>' . esc_html( $label ) . '</summary>' . $part . '</details>' : $part;
			$fallback  .= '<p>' . esc_html( $label ) . '</p><textarea class="ojp-textarea" rows="3" readonly aria-label="' . esc_attr( $label ) . '">' . esc_textarea( $command ) . '</textarea>';
			++$index;
		}
		// This readiness probe must check the local PHP worker's filesystem permissions.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		$storage_ready = defined( 'OJ_AI_PRIVATE_STORAGE_DIR' ) && is_dir( (string) constant( 'OJ_AI_PRIVATE_STORAGE_DIR' ) ) && is_writable( (string) constant( 'OJ_AI_PRIVATE_STORAGE_DIR' ) );
		$notice        = $storage_ready ? '' : '<div class="ojp-notice ojp-notice--warning" role="status">비공개 저장소 준비가 필요합니다. 아래 설치 창에서 명령을 확인하세요.</div>';
		return $notice . '<section class="ojp-section"><h2>서버 설치</h2><p>처음 사용할 때 서버 SSH에서 한 번 실행하세요.</p>'
			. '<button type="button" class="ojp-button" style="display:none" hidden data-ojp-server-open="' . esc_attr( $id ) . '">서버 설치·복구</button>'
			. '<details data-ojp-server-fallback="' . esc_attr( $id ) . '"><summary>서버 설치 명령</summary>' . $fallback . '</details>'
			. '<dialog class="ojp-server-install" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id . '-title' ) . '"><h2 id="' . esc_attr( $id . '-title' ) . '">서버 설치·복구</h2>'
			. '<p>명령 복사 → 같은 서버의 SSH에서 실행 → 상태 확인</p>' . $content
			. '<details><summary>변경 내용</summary><p>이 플러그인의 설치기만 실행합니다. 기존 설정과 충돌하면 중단하고, 설정 파일은 변경 전에 백업합니다. PHP-FPM이 재시작될 수 있으므로 수업이 없는 시간에 실행하세요.</p></details>'
			. '<button type="button" class="ojp-button" data-ojp-server-close>닫기</button></dialog></section>';
	}
}
