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

final class UserBulkImportPageHandler implements RouteHandler
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

        $template = "\xEF\xBB\xBFuser_login,email,password,display_name,affiliation,birth_date,role\r\n"
            . "learner01,learner01@example.com,change-this-password,학습자 01,,,subscriber\r\n";
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">CSV Jobs</p>'
            . '<h1>사용자 일괄 등록</h1>'
            . '<p>검증과 미리보기를 통과한 계정만 항목별로 생성합니다.</p></div>'
            . '<a class="ojp-button" download="user-import-template.csv" href="data:text/csv;charset=utf-8,'
            . rawurlencode($template) . '">CSV 양식 받기</a></header>'
            . '<section class="ojp-section">'
            . '<form class="ojp-form-stack" method="post" enctype="multipart/form-data" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_console_bulk_preview_users')
            . '<label class="ojp-upload-field"><span class="ojp-label">UTF-8 CSV 파일</span>'
            . '<input class="ojp-input" type="file" name="user_csv" accept=".csv,text/csv" required>'
            . '<small>최대 2MB, 사용자 500명까지 미리볼 수 있습니다.</small></label>'
            . '<button class="ojp-button ojp-button--primary" type="submit">검증 미리보기</button>'
            . '</form></section>'
            . '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>CSV 열</h2><p>머리글 이름을 그대로 사용합니다.</p></div></div>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>열</th><th>필수</th><th>내용</th></tr></thead><tbody>'
            . $this->column('user_login', '필수', '48자 이하 로그인 아이디')
            . $this->column('email', '필수', '중복되지 않은 이메일')
            . $this->column('password', '필수', '12자 이상 초기 비밀번호')
            . $this->column('display_name', '필수', '화면에 표시할 이름')
            . $this->column('affiliation', '선택', '소속')
            . $this->column('birth_date', '선택', 'YYYY-MM-DD')
            . $this->column(
                'role',
                '선택',
                '기본 subscriber, 운영 역할은 역할 관리 권한 필요'
            )
            . '</tbody></table></div></section>';

        return $this->pages->respond(
            '사용자 일괄 등록',
            $content,
            'users.bulk_import',
            $request
        );
    }

    private function column(string $name, string $required, string $description): string
    {
        return '<tr><td><code>' . $this->escape($name) . '</code></td><td>'
            . $this->escape($required) . '</td><td>'
            . $this->escape($description) . '</td></tr>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
