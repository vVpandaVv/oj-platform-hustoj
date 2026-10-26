<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

final class LoginPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly RegistrationPolicyProvider $registrationPolicy
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        $mode = $request->queryString('mode');
        $content = match ($mode) {
            'forgot' => $this->forgotForm(),
            'reset' => $this->resetForm(
                $request->queryString('login'),
                $request->queryString('key')
            ),
            'transition' => $this->transitionForm(
                $request->queryString('transition')
            ),
            default => $this->loginForm($request->queryString('redirect_to')),
        };

        $response = $this->pages->guest('로그인', $content, $request);

        return new HttpResponse($response->status(), array_replace($response->headers(), [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]), $response->body());
    }

    private function loginForm(string $redirectTo): string
    {
        $registerLink = $this->registrationPolicy->current()->isEnabled()
            ? '<a href="/register">회원가입</a>'
            : '';

        return '<section class="ojp-auth-panel">'
            . '<header><p class="ojp-eyebrow">학습용 프로그래밍 채점 시스템</p>'
            . '<h1>로그인</h1></header>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_login')
            . '<input type="hidden" name="operation" value="login">'
            . '<input type="hidden" name="redirect_to" value="'
            . $this->escape($redirectTo) . '">'
            . $this->field('로그인 아이디', 'user_login', 'text', 'username')
            . $this->field('비밀번호', 'password', 'password', 'current-password')
            . '<label class="ojp-check"><input type="checkbox" name="remember" value="1">'
            . '<span>로그인 상태 유지</span></label>'
            . '<button class="ojp-button ojp-button--primary" type="submit">로그인</button>'
            . '</form><footer class="ojp-auth-links">'
            . '<a href="/login?mode=forgot">비밀번호 복구</a>'
            . $registerLink
            . '</footer></section>';
    }

    private function forgotForm(): string
    {
        return '<section class="ojp-auth-panel">'
            . '<header><h1>비밀번호 복구</h1>'
            . '<p>가입한 로그인 아이디를 입력해 주세요.</p></header>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_lost_password')
            . '<input type="hidden" name="operation" value="request">'
            . $this->field('로그인 아이디', 'user_login', 'text', 'username')
            . '<button class="ojp-button ojp-button--primary" type="submit">복구 안내 받기</button>'
            . '</form><footer class="ojp-auth-links"><a href="/login">로그인으로 돌아가기</a></footer>'
            . '</section>';
    }

    private function resetForm(string $userLogin, string $key): string
    {
        return '<section class="ojp-auth-panel">'
            . '<header><h1>새 비밀번호 설정</h1></header>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_lost_password')
            . '<input type="hidden" name="operation" value="reset">'
            . '<input type="hidden" name="user_login" value="' . $this->escape($userLogin) . '">'
            . '<input type="hidden" name="reset_key" value="' . $this->escape($key) . '">'
            . $this->field('새 비밀번호', 'new_password', 'password', 'new-password')
            . $this->field('새 비밀번호 확인', 'new_password_confirm', 'password', 'new-password')
            . '<button class="ojp-button ojp-button--primary" type="submit">비밀번호 저장</button>'
            . '</form></section>';
    }

    private function transitionForm(string $token): string
    {
        return '<section class="ojp-auth-panel">'
            . '<header><h1>기존 계정 가져오기</h1>'
            . '<p>계정 정보를 다시 확인한 뒤 WordPress 계정으로 전환합니다.</p></header>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_login')
            . '<input type="hidden" name="operation" value="transition">'
            . '<input type="hidden" name="transition_token" value="' . $this->escape($token) . '">'
            . $this->field('로그인 아이디', 'user_login', 'text', 'username')
            . $this->field('비밀번호', 'password', 'password', 'current-password')
            . '<p class="ojp-field-help">아래 항목을 비워 두면 기존 Judge 정보를 가져옵니다. '
            . '기존 이메일을 사용할 수 없으면 새 이메일을 입력하세요.</p>'
            . $this->field('이메일 변경', 'email', 'email', 'email', false)
            . $this->field('표시 이름 변경', 'display_name', 'text', 'name', false)
            . $this->field('소속 변경', 'affiliation', 'text', 'organization', false)
            . '<label class="ojp-check"><input type="checkbox"'
            . ' name="confirm_transition" value="1" required>'
            . '<span>변경할 수 없는 로그인 아이디와 계정 가져오기 내용을 확인했습니다.</span></label>'
            . '<button class="ojp-button ojp-button--primary" type="submit">확인 후 가져오기</button>'
            . '</form><footer class="ojp-auth-links"><a href="/login">취소</a></footer>'
            . '</section>';
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
