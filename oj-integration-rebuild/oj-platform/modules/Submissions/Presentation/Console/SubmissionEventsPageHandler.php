<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionEventText;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml;

final class SubmissionEventsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly SubmissionQueryService $submissions,
        private readonly SubmissionHtml $html,
        private readonly SubmissionEventText $text
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $rows = '';
        foreach ($this->submissions->recentEvents(200) as $event) {
            $submissionId = (int) ($event['submission_id'] ?? 0);
            $eventType = (string) ($event['event_type'] ?? '');
            $eventLabel = $this->text->eventLabel($eventType);
            $eventDescription = $this->text->eventDescription(
                $eventType,
                (string) ($event['message'] ?? '')
            );
            $rows .= '<tr><td>'
                . $this->html->escape($this->html->date(
                    (string) ($event['created_at'] ?? '')
                ))
                . '</td><td>'
                . $this->html->escape($eventLabel)
                . '</td><td>'
                . ($submissionId > 0
                    ? '<a class="ojp-row-link" href="/oj-console/submissions/'
                        . $submissionId . '">#' . $submissionId . '</a>'
                    : '없음')
                . '</td><td>'
                . number_format((int) ($event['actor_user_id'] ?? 0))
                . '</td><td>'
                . $this->html->escape($eventDescription)
                . '</td><td>' . $this->metadata(
                    (string) ($event['metadata'] ?? '')
                ) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">기록된 제출 이벤트가 없습니다.</td></tr>';
        }
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">제출 관리</p><h1>제출 처리 기록</h1>'
            . '<p>제출 접수, 채점 대기열 등록, 재채점, 무효 처리와 복원 이력을 확인합니다.</p>'
            . '</div></header><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>시각</th><th>종류</th><th>제출</th>'
            . '<th>실행자 번호</th><th>내용</th><th>세부 정보</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';

        return $this->pages->respond(
            '제출 처리 기록',
            $content,
            'submissions.events',
            $request
        );
    }

    private function metadata(string $json): string
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || $decoded === []) {
            return '<span class="ojp-muted">없음</span>';
        }
        $items = '';
        foreach ($decoded as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $label = $this->text->metadataLabel((string) $key);
            if ($label === null) {
                continue;
            }
            $items .= '<span><strong>' . $this->html->escape($label)
                . '</strong> ' . $this->html->escape(
                    $this->text->metadataValue((string) $key, $value)
                )
                . '</span>';
        }
        if ($items === '') {
            return '<span class="ojp-muted">표시할 정보 없음</span>';
        }

        return '<div class="ojp-metadata-list">' . $items . '</div>';
    }
}
