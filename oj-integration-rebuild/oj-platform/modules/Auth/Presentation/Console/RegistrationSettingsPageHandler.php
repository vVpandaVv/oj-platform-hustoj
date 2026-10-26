<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\Auth\Domain\ApprovalMode;

final class RegistrationSettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly RegistrationPolicyProvider $policies,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        $policy = $this->policies->current();
        $required = array_fill_keys($policy->requiredAgreementTypes(), true);
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">사용자</p>'
            . '<h1>가입 설정</h1></div></header>'
            . '<section class="ojp-section"><form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_registration_settings')
            . '<label class="ojp-check"><input type="checkbox" name="enabled" value="1"'
            . ($policy->isEnabled() ? ' checked' : '') . '><span>회원가입 허용</span></label>'
            . '<fieldset class="ojp-fieldset"><legend>가입 승인 방식</legend>'
            . $this->radio(
                'approval_mode',
                ApprovalMode::Manual->value,
                '수동 승인',
                $policy->approvalMode()->value
            )
            . $this->radio(
                'approval_mode',
                ApprovalMode::Automatic->value,
                '자동 승인',
                $policy->approvalMode()->value
            )
            . '</fieldset><fieldset class="ojp-fieldset"><legend>필수 동의</legend>'
            . $this->check('required_agreement_types[]', 'terms', '이용약관', isset($required['terms']))
            . $this->check('required_agreement_types[]', 'privacy', '개인정보 처리방침', isset($required['privacy']))
            . '</fieldset><div class="ojp-form-grid">'
            . '<label class="ojp-field"><span class="ojp-label">가입 기본 역할</span>'
            . '<select class="ojp-select" name="default_role"><option value="subscriber">학습자</option></select></label>'
            . '<label class="ojp-field"><span class="ojp-label">법정대리인 동의 기준 나이</span>'
            . '<input class="ojp-input" type="number" min="1" max="18" name="guardian_consent_age" value="'
            . $policy->guardianConsentAge() . '"></label>'
            . '<label class="ojp-field"><span class="ojp-label">확인 만료 시간</span>'
            . '<input class="ojp-input" type="number" min="1" max="168" name="guardian_consent_expires_hours" value="'
            . $policy->guardianConsentExpiresHours() . '"></label></div>'
            . '<label class="ojp-check"><input type="checkbox" name="guardian_consent_enabled" value="1"'
            . ($policy->guardianConsentEnabled() ? ' checked' : '')
            . '><span>법정대리인 동의 사용</span></label>'
            . '<p><a href="/oj-console/agreements">법정대리인 동의서 관리</a></p>'
            . '<button class="ojp-button ojp-button--primary" type="submit">가입 설정 저장</button>'
            . '</form></section>';

        return $this->pages->respond(
            '가입 설정',
            $content,
            'users.registration',
            $request
        );
    }

    private function radio(
        string $name,
        string $value,
        string $label,
        string $current
    ): string {
        return '<label class="ojp-check"><input type="radio" name="'
            . $this->escape($name) . '" value="' . $this->escape($value) . '"'
            . ($value === $current ? ' checked' : '') . '><span>'
            . $this->escape($label) . '</span></label>';
    }

    private function check(
        string $name,
        string $value,
        string $label,
        bool $checked
    ): string {
        return '<label class="ojp-check"><input type="checkbox" name="'
            . $this->escape($name) . '" value="' . $this->escape($value) . '"'
            . ($checked ? ' checked' : '') . '><span>'
            . $this->escape($label) . '</span></label>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
