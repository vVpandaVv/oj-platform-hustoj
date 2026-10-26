<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessageReportService;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingService;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\Message;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessageReport;

final class MessageReportsPageHandler implements RouteHandler
{
    private const PAGE_SIZE = 30;

    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly MessageReportService $reports,
        private readonly MessagingService $messaging,
        private readonly WordPressUserGateway $users,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $page = max(1, (int) $request->queryString('page', '1'));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $fetched = $this->reports->listQueue(self::PAGE_SIZE + 1, $offset);
        $hasNext = count($fetched) > self::PAGE_SIZE;
        $queue = array_slice($fetched, 0, self::PAGE_SIZE);

        $ids = [];
        $messagesById = [];
        foreach ($queue as $report) {
            $message = $this->messaging->find($report->messageId());
            $messagesById[$report->id()] = $message;
            if ($message !== null) {
                $ids[$message->senderUserId()] = true;
                $ids[$message->recipientUserId()] = true;
            }
            $ids[$report->reporterUserId()] = true;
        }
        $identities = [];
        foreach ($this->users->findMany(array_keys($ids)) as $identity) {
            $identities[$identity->userId()] = $identity;
        }

        $rows = '';
        foreach ($queue as $report) {
            $message = $messagesById[$report->id()];
            $reporter = $identities[$report->reporterUserId()] ?? null;
            $sender = $message !== null ? ($identities[$message->senderUserId()] ?? null) : null;
            $rows .= '<tr><td>' . $this->escape($reporter?->displayName() ?? '알 수 없음') . '</td>'
                . '<td>' . $this->escape($sender?->displayName() ?? '알 수 없음') . '</td>'
                . '<td>' . $this->excerpt($message?->body() ?? '') . '</td>'
                . '<td>' . $this->escape($report->reason()) . '</td>'
                . '<td>' . $this->escape($this->dates->format($report->reportedAt())) . '</td>'
                . '<td>' . $this->resolveForm($report, $message, $sender) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">대기 중인 신고가 없습니다.</td></tr>';
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">쪽지 운영</p>'
            . '<h1>신고 처리</h1><p>처리 대기 중인 쪽지 신고 목록입니다.</p></div></header>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>신고자</th><th>발신자</th><th>내용</th><th>사유</th><th>접수 시각</th><th>처리</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . $this->pagination($page, $hasNext);

        return $this->pages->respond('신고 처리', $content, 'messaging.reports', $request);
    }

    private function resolveForm(MessageReport $report, ?Message $message, ?UserIdentity $sender): string
    {
        $threadLink = $message !== null
            ? '<a class="ojp-button ojp-button--small" href="/oj-console/messages/thread/'
                . $this->escape($message->threadKey()) . '">스레드 보기</a>'
            : '';
        $accountLink = $sender !== null
            ? '<a class="ojp-button ojp-button--small" href="/oj-console/users/' . $sender->userId() . '">발신자 계정 관리</a>'
            : '';

        return '<div class="ojp-command-group">' . $threadLink . $accountLink . '</div>'
            . '<form class="ojp-command-group" method="post" action="' . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_message_report_resolve')
            . '<input type="hidden" name="report_id" value="' . $report->id() . '">'
            . '<select class="ojp-input" name="resolution">'
            . '<option value="none">기각</option>'
            . '<option value="message_hidden">쪽지 숨김</option>'
            . '</select>'
            . '<input class="ojp-input" type="text" name="note" placeholder="처리 메모" maxlength="500">'
            . '<button class="ojp-button ojp-button--small ojp-button--primary" type="submit">처리</button>'
            . '</form>';
    }

    private function excerpt(string $body): string
    {
        $plain = trim($body);

        return $this->escape(
            function_exists('mb_strimwidth')
                ? mb_strimwidth($plain, 0, 80, '...', 'UTF-8')
                : substr($plain, 0, 80)
        );
    }

    private function pagination(int $page, bool $hasNext): string
    {
        if ($page === 1 && ! $hasNext) {
            return '';
        }

        return '<nav class="ojp-pagination" aria-label="페이지 이동">'
            . ($page > 1
                ? '<a class="ojp-pagination__link" href="/oj-console/messages/reports?page=' . ($page - 1) . '">이전</a>'
                : '<span class="ojp-pagination__link" aria-disabled="true">이전</span>')
            . '<span class="ojp-pagination__current">' . $page . '페이지</span>'
            . ($hasNext
                ? '<a class="ojp-pagination__link" href="/oj-console/messages/reports?page=' . ($page + 1) . '">다음</a>'
                : '<span class="ojp-pagination__link" aria-disabled="true">다음</span>')
            . '</nav>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
