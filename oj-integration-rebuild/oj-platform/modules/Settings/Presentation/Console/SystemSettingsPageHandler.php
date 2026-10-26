<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LoginThrottleControl;
use CodingPanda\OjPlatform\Modules\Settings\Application\SiteSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SystemSettingsService;

final class SystemSettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly SystemSettingsService $settings,
        private readonly SiteSettingsService $site,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly LoginThrottleControl $loginThrottle,
        private readonly ?\CodingPanda\OjPlatform\Modules\Settings\Contract\ServerInstallationLink $serverInstallation = null
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $snapshot = $this->settings->snapshot();
        $current = $this->settings->current();
        $frozen = $current->effectiveFrozenModules();
        $loginSnapshot = $this->loginThrottle->snapshot();
        $loginValue = $loginSnapshot->value();
        $loginEnabled = ! is_array($loginValue) || ($loginValue['enabled'] ?? true) !== false;
        $content = OperationsHtml::header(
            '고위험 운영 권한',
            '운영 설정',
            '사용자 안내를 위한 점검 모드와 데이터 이전용 쓰기 동결을 구분합니다.'
        )
            . '<div class="ojp-two-column"><section class="ojp-section"><h2>점검 모드</h2>'
            . '<form class="ojp-form-stack" method="post" action="'
            . OperationsHtml::escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_system_settings')
            . '<input type="hidden" name="operation" value="save">'
            . '<input type="hidden" name="expected_revision" value="'
            . $snapshot->revision() . '">'
            . '<label class="ojp-check"><input type="checkbox" name="maintenance_enabled" value="1"'
            . OperationsHtml::checked($current->maintenanceEnabled())
            . '><span>사용자 업무 화면 닫기</span></label>'
            . '<label class="ojp-field"><span class="ojp-label">안내 문구</span>'
            . '<textarea class="ojp-textarea" name="maintenance_message" maxlength="1000">'
            . OperationsHtml::escape($current->maintenanceMessage()) . '</textarea></label>'
            . '<label class="ojp-field"><span class="ojp-label">예상 종료</span>'
            . '<input class="ojp-input" type="datetime-local" name="maintenance_ends_at" value="'
            . OperationsHtml::escape($current->maintenanceEndsAt() ?? '') . '"></label>'
            . '<button class="ojp-button ojp-button--primary" type="submit">운영 설정 저장</button>'
            . '</form></section><aside class="ojp-section"><h2>모듈별 쓰기 동결</h2>'
            . '<p>쓰기 동결은 Migration 승인 계획만 변경할 수 있습니다.</p>'
            . ($frozen === []
                ? '<span class="ojp-badge ojp-badge--success">현재 동결 없음</span>'
                : '<p><strong>현재 동결:</strong> '
                    . OperationsHtml::escape(implode(', ', $frozen)) . '</p>')
            . '</aside></div><section class="ojp-section" id="login-security"><h2>로그인 보호</h2>'
            . '<p>같은 IP와 로그인 아이디로 10분 동안 10회까지 시도할 수 있습니다.'
            . ' 같은 IP를 사용하는 다른 아이디의 시도는 합산하지 않습니다.</p>'
            . ($loginEnabled ? '' : '<p class="ojp-badge ojp-badge--warning">로그인 횟수 제한이 꺼져 있습니다.</p>')
            . '<form class="ojp-form-stack" method="post" action="'
            . OperationsHtml::escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_system_settings')
            . '<input type="hidden" name="operation" value="save_login_throttle">'
            . '<input type="hidden" name="expected_revision" value="' . $loginSnapshot->revision() . '">'
            . '<label class="ojp-check"><input type="checkbox" name="login_throttle_enabled" value="1"'
            . OperationsHtml::checked($loginEnabled)
            . '><span>로그인 횟수 제한 사용</span></label>'
            . '<p>끄면 반복 로그인 시도에 대한 보호가 해제됩니다. 비밀번호 찾기·회원가입 등의 제한은 유지됩니다.</p>'
            . '<button class="ojp-button ojp-button--primary" type="submit">로그인 보호 설정 저장</button>'
            . '</form><hr><p>일시적으로 차단된 사용자의 로그인을 다시 허용하려면 제한 기록을 초기화하세요.</p>'
            . '<form class="ojp-form-stack" method="post" action="'
            . OperationsHtml::escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_system_settings')
            . '<input type="hidden" name="operation" value="reset_login_throttle">'
            . '<input type="hidden" name="expected_revision" value="' . $loginSnapshot->revision() . '">'
            . '<button class="ojp-button" type="submit">로그인 제한 초기화</button>'
            . '</form></section><details class="ojp-danger-details"><summary>'
            . '플러그인 제거 시 데이터 삭제 일회성 승인</summary><div>'
            . '<p>기본 정책은 데이터 보존입니다. 백업과 분리 환경 복구 증거를 확인한 뒤'
            . ' 30분 동안 한 번만 유효한 승인을 발급합니다.</p>'
            . '<form class="ojp-form-stack" method="post" action="'
            . OperationsHtml::escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_system_settings')
            . '<input type="hidden" name="operation" value="issue_uninstall_approval">'
            . '<input type="hidden" name="expected_revision" value="'
            . $snapshot->revision() . '"><div class="ojp-form-grid">'
            . $this->field('backup_proof', '백업 증거 참조')
            . $this->field('restore_proof', '복구 리허설 참조')
            . $this->field(
                'confirmed_service_name',
                '서비스 이름 다시 입력',
                $this->site->serviceName()
            )
            . '</div><button class="ojp-button ojp-button--danger" type="submit">'
            . '일회성 승인 발급</button></form></div></details>';

        $installationUrl = $this->serverInstallation?->url();
        if ($installationUrl !== null) {
            $content .= '<section class="ojp-section"><h2>서버 설치</h2><p>추가 기능에 필요한 서버 준비 명령을 확인합니다.</p>'
                . '<a class="ojp-button" target="_blank" rel="noopener noreferrer" href="' . htmlspecialchars($installationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">서버 설치·복구</a></section>';
        }

        return $this->pages->respond(
            '운영 설정',
            $content,
            'operations.system',
            $request
        );
    }

    private function field(
        string $name,
        string $label,
        string $placeholder = ''
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . OperationsHtml::escape($label) . '</span><input class="ojp-input" name="'
            . OperationsHtml::escape($name) . '" placeholder="'
            . OperationsHtml::escape($placeholder) . '" required></label>';
    }
}
