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
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingService;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class MessagingConsolePageHandler implements RouteHandler
{
    private const PAGE_SIZE = 30;

    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly MessagingService $messaging,
        private readonly WordPressUserGateway $users,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        if ($route->route()->key() === 'console.messaging.thread') {
            return $this->thread(
                (string) ($route->parameters()['thread_key'] ?? ''),
                $request
            );
        }

        return $this->index($request);
    }

    private function index(HttpRequest $request): HttpResponse
    {
        $filterUserId = (int) $request->queryString('user_id', '0');
        $fromDate = trim($request->queryString('from'));
        $toDate = trim($request->queryString('to'));
        $page = max(1, (int) $request->queryString('page', '1'));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $fetched = $this->messaging->adminSearch($filterUserId, $fromDate, $toDate, self::PAGE_SIZE + 1, $offset);
        $hasNext = count($fetched) > self::PAGE_SIZE;
        $messages = array_slice($fetched, 0, self::PAGE_SIZE);

        $ids = [];
        foreach ($messages as $message) {
            $ids[$message->senderUserId()] = true;
            $ids[$message->recipientUserId()] = true;
        }
        $identities = [];
        foreach ($this->users->findMany(array_keys($ids)) as $identity) {
            $identities[$identity->userId()] = $identity;
        }

        $rows = '';
        foreach ($messages as $message) {
            $sender = $identities[$message->senderUserId()] ?? null;
            $recipient = $identities[$message->recipientUserId()] ?? null;
            $rows .= '<tr><td><a class="ojp-row-link" href="/oj-console/messages/thread/'
                . $this->escape($message->threadKey()) . '">'
                . $this->escape($sender?->displayName() ?? '알 수 없음') . ' → '
                . $this->escape($recipient?->displayName() ?? '알 수 없음') . '</a></td>'
                . '<td>' . $this->excerpt($message->body()) . '</td>'
                . '<td>' . $this->escape($this->dates->format($message->sentAt())) . '</td>'
                . '<td>' . ($message->isHidden() ? '<span class="ojp-status-badge ojp-status-badge--danger">숨김</span>' : '정상') . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">표시할 쪽지가 없습니다.</td></tr>';
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">쪽지 운영</p>'
            . '<h1>쪽지 조회</h1><p>사용자 번호와 기간으로 전체 쪽지를 조회합니다.</p></div></header>'
            . '<form class="ojp-filter-bar" method="get">'
            . '<input class="ojp-input" type="number" name="user_id" placeholder="사용자 번호" value="'
            . ($filterUserId > 0 ? $filterUserId : '') . '">'
            . '<input class="ojp-input" type="date" name="from" value="' . $this->escape($fromDate) . '">'
            . '<input class="ojp-input" type="date" name="to" value="' . $this->escape($toDate) . '">'
            . '<button class="ojp-button" type="submit">검색</button></form>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>발신 → 수신</th><th>내용</th><th>시각</th><th>상태</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . $this->pagination($filterUserId, $fromDate, $toDate, $page, $hasNext);

        return $this->pages->respond('쪽지 조회', $content, 'messaging.browse', $request);
    }

    private function thread(string $threadKey, HttpRequest $request): HttpResponse
    {
        if (preg_match('/^\d+_\d+$/', $threadKey) !== 1) {
            return $this->notFound($request);
        }
        [$idA, $idB] = array_map('intval', explode('_', $threadKey, 2));
        $identityA = $this->users->findById($idA);
        $identityB = $this->users->findById($idB);

        $messages = $this->messaging->adminThread($threadKey, 500, 0);
        $rows = '';
        foreach ($messages as $message) {
            $senderName = $message->senderUserId() === $idA
                ? $identityA?->displayName()
                : $identityB?->displayName();
            $rows .= '<tr><td>' . $this->escape($senderName ?? '알 수 없음') . '</td>'
                . '<td>' . nl2br($this->escape(trim($message->body()))) . '</td>'
                . '<td>' . $this->escape($this->dates->format($message->sentAt())) . '</td>'
                . '<td>' . ($message->isHidden()
                    ? '<span class="ojp-status-badge ojp-status-badge--danger">숨김: '
                        . $this->escape($message->hiddenReason() ?? '') . '</span>'
                    : '정상') . '</td>'
                . '<td><form method="post" action="' . $this->escape($this->actionUrls->actionUrl()) . '">'
                . $this->security->hiddenFields('oj_message_admin_hide')
                . '<input type="hidden" name="message_id" value="' . $message->id() . '">'
                . '<input type="hidden" name="hidden" value="' . ($message->isHidden() ? '0' : '1') . '">'
                . '<input class="ojp-input" type="text" name="reason" placeholder="사유" maxlength="500">'
                . '<button class="ojp-button ojp-button--small" type="submit">'
                . ($message->isHidden() ? '숨김 해제' : '숨김') . '</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">표시할 쪽지가 없습니다.</td></tr>';
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">쪽지 운영</p>'
            . '<h1>' . $this->escape($identityA?->displayName() ?? '알 수 없음') . ' ↔ '
            . $this->escape($identityB?->displayName() ?? '알 수 없음') . '</h1></div>'
            . '<div class="ojp-page-actions"><a class="ojp-button" href="/oj-console/messages">쪽지 조회로 이동</a></div>'
            . '</header><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>보낸 사람</th><th>내용</th><th>시각</th><th>상태</th><th>운영</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';

        return $this->pages->respond('쪽지 스레드', $content, 'messaging.browse', $request);
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

    private function pagination(int $userId, string $from, string $to, int $page, bool $hasNext): string
    {
        if ($page === 1 && ! $hasNext) {
            return '';
        }
        $query = http_build_query(array_filter([
            'user_id' => $userId > 0 ? $userId : null,
            'from' => $from !== '' ? $from : null,
            'to' => $to !== '' ? $to : null,
        ]));
        $link = static fn (int $targetPage): string =>
            '/oj-console/messages?' . ($query === '' ? '' : $query . '&') . 'page=' . $targetPage;

        return '<nav class="ojp-pagination" aria-label="페이지 이동">'
            . ($page > 1
                ? '<a class="ojp-pagination__link" href="' . $this->escape($link($page - 1)) . '">이전</a>'
                : '<span class="ojp-pagination__link" aria-disabled="true">이전</span>')
            . '<span class="ojp-pagination__current">' . $page . '페이지</span>'
            . ($hasNext
                ? '<a class="ojp-pagination__link" href="' . $this->escape($link($page + 1)) . '">다음</a>'
                : '<span class="ojp-pagination__link" aria-disabled="true">다음</span>')
            . '</nav>';
    }

    private function notFound(HttpRequest $request): HttpResponse
    {
        return $this->pages->respond(
            '쪽지 없음',
            '<div class="ojp-empty-state"><h1>스레드를 찾을 수 없습니다.</h1></div>',
            'messaging.browse',
            $request,
            404
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
