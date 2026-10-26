<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\AI\Presentation\AiHtml;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;

final class FeedbackGuidePreviewPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly FeedbackGuideRepository $guides,
        private readonly AiHtml $html,
        private readonly ?FeedbackValidationPanel $validation = null
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $key = trim((string) ($route->parameters()['prompt_file'] ?? ''));
        $guide = $this->guides->find($key);
        if ($guide === null) {
            return $this->pages->respond(
                'AI 답변 지침',
                '<header class="ojp-page-header"><div><h1>지침을 찾을 수 없습니다.</h1></div></header>',
                'submissions.ai_guides',
                $request,
                404
            );
        }
        $revisions = str_ends_with($route->route()->key(), '.revisions');
        $body = '';
        if ($revisions) {
            foreach ($this->guides->revisions($key) as $revision) {
                $body .= '<tr><td>v' . (int) ($revision['revision'] ?? 0)
                    . '</td><td>'
                    . $this->html->escape($this->html->date(
                        (string) ($revision['updated_at'] ?? '')
                    ))
                    . '</td><td>'
                    . ((int) ($revision['updated_by_user_id'] ?? 0) ?: '-')
                    . '</td></tr>';
            }
            $body = '<div class="ojp-table-wrap"><table class="ojp-table">'
                . '<thead><tr><th>개정</th><th>저장 시각</th><th>수정 사용자 번호</th>'
                . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
        } else {
            $body = '<article class="ojp-feedback-preview"><h2>'
                . $this->html->escape($guide->name())
                . '</h2><p>' . $this->html->escape($guide->purpose())
                . '</p><div class="ojp-code-block">'
                . nl2br($this->html->escape($guide->instructions()))
                . '</div></article>';
        }
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">AI 답변 지침</p><h1>'
            . ($revisions ? '변경 기록' : '지침 미리보기')
            . '</h1><p>' . $this->html->escape($guide->name())
            . ' · 현재 v' . $guide->revision() . '</p></div>'
            . '<a class="ojp-button" href="/oj-console/submissions/ai-guides">'
            . '지침 목록</a></header><section class="ojp-section">'
            . $body . '</section>';

        if (!$revisions && $this->validation !== null) {
            $content .= $this->validation->render(
                $guide,
                (int) $request->queryString('validation_page', '1'),
                $request->queryString('validation_record')
            );
        }

        return $this->pages->respond(
            $revisions ? 'AI 답변 지침 변경 기록' : 'AI 답변 지침 미리보기',
            $content,
            'submissions.ai_guides',
            $request
        );
    }
}
