<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

final class RegistrationPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly AgreementRepository $agreements,
        private readonly RegistrationPolicyProvider $policies,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        $policy = $this->policies->current();
        $agreements = '';
        foreach ($this->agreements->activeDocuments() as $document) {
            if (! $document->isForUserConsent()) {
                continue;
            }
            $agreements .= '<details><summary>' . $this->escape($document->title())
                . ' · 버전 ' . $this->escape($document->version()) . ' 본문</summary>'
                . '<div class="ojp-policy-content ojp-prose">'
                . (new \CodingPanda\OjPlatform\Ui\Editor\RichTextContent())->render($document->content())
                . '</div></details><label class="ojp-check">'
                . '<input type="checkbox" name="agreement_document_ids[]" value="'
                . $document->documentId() . '"'
                . ($document->isRequired() ? ' required' : '') . '>'
                . '<span>' . $this->escape($document->title())
                . ($document->isRequired() ? ' (필수)' : ' (선택)')
                . '</span></label>';
        }

        $guardianPanel = $policy->guardianConsentEnabled()
            ? $this->guardianPanel()
            : '';
        $guardianCodeField = $policy->guardianConsentEnabled()
            ? $this->field(
                '법정대리인 확인 코드',
                'guardian_code',
                'text',
                'one-time-code',
                false
            )
            : '';

        $content = $guardianPanel
            . '<section class="ojp-auth-panel ojp-auth-panel--wide">'
            . '<header><h1>회원가입</h1></header>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_register_user')
            . '<div class="ojp-form-grid">'
            . $this->field('로그인 아이디', 'user_login', 'text', 'username')
            . $this->field('이메일', 'email', 'email', 'email')
            . $this->field('표시 이름', 'display_name', 'text', 'name')
            . $this->field('소속', 'affiliation', 'text', 'organization', false)
            . $this->field(
                '생년',
                'birth_date',
                'date',
                'bday',
                $policy->guardianConsentEnabled()
            )
            . $guardianCodeField
            . $this->field('비밀번호', 'password', 'password', 'new-password')
            . $this->field('비밀번호 확인', 'password_confirm', 'password', 'new-password')
            . '</div><fieldset class="ojp-fieldset"><legend>동의</legend>'
            . ($agreements !== '' ? $agreements : '<p>게시된 동의 문서가 없습니다.</p>')
            . '</fieldset>'
            . '<button class="ojp-button ojp-button--primary" type="submit">가입 신청</button>'
            . '</form><footer class="ojp-auth-links"><a href="/login">로그인으로 돌아가기</a></footer>'
            . '</section>';

        return $this->pages->guest('회원가입', $content, $request);
    }

    private function guardianPanel(): string
    {
        return '<section class="ojp-auth-panel ojp-auth-panel--wide">'
            . '<header><h1>법정대리인 동의 확인</h1>'
            . '<p>기준 나이 미만 가입자는 먼저 동의 요청을 보내야 합니다.</p></header>'
            . '<div class="ojp-split-actions"><form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_send_guardian_consent_request')
            . $this->field('가입 이메일', 'child_email', 'email', 'email')
            . $this->field('법정대리인 이름', 'guardian_name', 'text', 'name')
            . $this->field('법정대리인 이메일', 'guardian_email', 'email', 'email')
            . '<button class="ojp-button" type="submit">동의 요청 보내기</button>'
            . '</form><form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_check_guardian_consent_request')
            . $this->field('가입 이메일', 'child_email', 'email', 'email')
            . $this->field('확인 코드', 'guardian_code', 'text', 'one-time-code')
            . '<button class="ojp-button" type="submit">동의 상태 확인</button>'
            . '</form></div></section>';
    }

    private function field(
        string $label,
        string $name,
        string $type,
        string $autocomplete,
        bool $required = true
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->escape($label)
            . '</span><input class="ojp-input" type="' . $this->escape($type)
            . '" name="' . $this->escape($name)
            . '" autocomplete="' . $this->escape($autocomplete)
            . '"' . ($required ? ' required' : '') . '></label>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
