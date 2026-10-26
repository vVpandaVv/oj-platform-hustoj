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

final class UserNewPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">사용자</p>'
            . '<h1>사용자 추가</h1></div></header>'
            . '<section class="ojp-section"><form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_console_create_user')
            . '<div class="ojp-form-grid">'
            . $this->field('로그인 아이디', 'user_login', 'text')
            . $this->field('이메일', 'email', 'email')
            . $this->field('표시 이름', 'display_name', 'text')
            . $this->field('소속', 'affiliation', 'text', false)
            . $this->field('생년', 'birth_date', 'date', false)
            . $this->field('초기 비밀번호', 'password', 'password')
            . '</div><p class="ojp-field-help">첫 로그인에서 비밀번호 변경이 필요합니다.</p>'
            . '<button class="ojp-button ojp-button--primary" type="submit">사용자 만들기</button>'
            . '</form></section>';

        return $this->pages->respond(
            '사용자 추가',
            $content,
            'users.new',
            $request
        );
    }

    private function field(
        string $label,
        string $name,
        string $type,
        bool $required = true
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->escape($label) . '</span><input class="ojp-input" type="'
            . $this->escape($type) . '" name="' . $this->escape($name) . '"'
            . ($required ? ' required' : '') . '></label>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
