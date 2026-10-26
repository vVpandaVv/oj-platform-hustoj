<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingService;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\Message;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessageDraft;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;
use Throwable;

final class MessagesPageHandler implements RouteHandler
{
    private const PAGE_SIZE = 30;

    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly MessagingService $messaging,
        private readonly WordPressUserGateway $users,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter(),
        private readonly ?\CodingPanda\OjPlatform\Modules\Auth\Contract\PublicUserSearch $search = null
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $account = $this->accounts->current();
        $userId = $account->identity()?->userId() ?? 0;
        if ($userId < 1 || ! $account->isActive()) {
            return HttpResponse::redirect('/login');
        }

        if ($route->route()->key() === 'messaging.inbox' && $request->queryString('notification') === '1') {
            return new HttpResponse(200, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ], json_encode(['unread' => $this->messaging->unreadCount($userId)], JSON_THROW_ON_ERROR));
        }

        $page = max(1, (int) $request->queryString('page', '1'));
        $offset = ($page - 1) * self::PAGE_SIZE;

        return match ($route->route()->key()) {
            'messaging.sent' => $this->sent($userId, $page, $offset, $request),
            'messaging.thread' => $this->thread(
                $userId,
                (string) ($route->parameters()['thread_key'] ?? ''),
                $request
            ),
            'messaging.compose' => $this->compose(
                $userId,
                (int) ($route->parameters()['user_id'] ?? 0),
                $request
            ),
            'messaging.find' => $this->find($userId, $request),
            default => $this->inbox($userId, $page, $offset, $request),
        };
    }

    private function find(int $userId, HttpRequest $request): HttpResponse
    {
        $query = trim($request->queryString('login'));
        if ($this->search !== null) {
            $page = max(1, min(1000, (int) $request->queryString('page', '1')));
            $ids = $query === '' ? [] : $this->search->search($query, 21, ($page - 1) * 20);
            $rows = '';
            foreach ($this->users->findMany(array_slice($ids, 0, 20)) as $identity) {
                if (! $this->messaging->canReceiveFrom($userId, $identity->userId())) { continue; }
                $rows .= '<tr><td><a class="ojp-row-link" href="/users/' . $identity->userId() . '">'
                    . $this->escape($identity->displayName()) . '</a></td><td>'
                    . $this->escape($identity->userLogin()) . '</td><td><a class="ojp-button ojp-button--small" href="/messages/new/'
                    . $identity->userId() . '">쪽지 보내기</a></td></tr>';
            }
            $content = '<header class="ojp-page-header"><h1>사용자 찾기</h1>'
                . '<a class="ojp-button" href="/messages">받은 쪽지함</a></header>'
                . $this->searchForm($query)
                . ($query === '' ? '' : '<div class="ojp-table-wrap"><table class="ojp-table">'
                    . '<thead><tr><th>표시 이름</th><th>아이디</th><th>작업</th></tr></thead><tbody>'
                    . ($rows === '' ? '<tr><td colspan="3">쪽지를 보낼 수 있는 사용자가 없습니다.</td></tr>' : $rows)
                    . '</tbody></table></div>')
                . $this->pagination('/messages/find?login=' . rawurlencode($query), $page, count($ids) > 20);
            return $this->pages->authenticated('사용자 찾기', $content, $request);
        }
        if ($query === '') {
            return $this->inbox($userId, 1, 0, $request, '받는 사람의 로그인 아이디 또는 사용자 번호를 입력해 주세요.');
        }
        $recipient = preg_match('/^\d+$/', $query) === 1
            ? $this->users->findById((int) $query)
            : $this->users->findByLogin($query);
        if ($recipient === null || $recipient->userId() === $userId) {
            return $this->inbox(
                $userId,
                1,
                0,
                $request,
                '"' . $query . '" 사용자를 찾을 수 없습니다.'
            );
        }

        return HttpResponse::redirect('/messages/new/' . $recipient->userId());
    }

    private function inbox(
        int $userId,
        int $page,
        int $offset,
        HttpRequest $request,
        ?string $findError = null
    ): HttpResponse {
        $unreadOnly = $request->queryString('unread') === '1';
        $fetched = $this->messaging->inbox($userId, self::PAGE_SIZE + 1, $offset, $unreadOnly);
        $hasNext = count($fetched) > self::PAGE_SIZE;
        $messages = array_slice($fetched, 0, self::PAGE_SIZE);
        $identities = $this->identities($messages, $userId);
        $unreadKeys = $this->messaging->unreadThreadKeys($userId, array_map(static fn (Message $message): string => $message->threadKey(), $messages));

        $rows = '';
        foreach ($messages as $message) {
            $otherId = $message->otherParticipant($userId);
            $other = $identities[$otherId] ?? null;
            $isUnread = in_array($message->threadKey(), $unreadKeys, true)
                || ($message->recipientUserId() === $userId && ! $message->isRead());
            $rows .= '<tr' . ($isUnread ? ' class="ojp-message-unread"' : '') . '>'
                . '<td><a class="ojp-row-link" href="/messages/thread/' . $this->escape($message->threadKey()) . '">'
                . '<strong>' . $this->escape($other?->displayName() ?? '알 수 없음') . '</strong></a></td>'
                . '<td>' . $this->excerpt($message->body()) . '</td>'
                . '<td>' . $this->escape($this->dates->format($message->sentAt())) . '</td>'
                . '<td>' . (! $isUnread ? '읽음' : '<span class="ojp-status-badge ojp-status-badge--warning">안 읽음</span>') . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">받은 쪽지가 없습니다.</td></tr>';
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">쪽지</p>'
            . '<h1>받은 쪽지함</h1></div><div class="ojp-page-actions">'
            . '<a class="ojp-button ojp-button--primary" href="/messages/find">쪽지 쓰기</a>'
            . '<a class="ojp-button" href="/messages/sent">보낸 쪽지함</a></div></header>'
            . ($findError !== null
                ? '<div class="ojp-notice ojp-notice--warning"><p>' . $this->escape($findError) . '</p></div>'
                : '')
            . $this->searchForm()
            . '<nav class="ojp-command-group" aria-label="쪽지 필터"><a class="ojp-button" href="/messages"'
            . (! $unreadOnly ? ' aria-current="page"' : '') . '>전체</a>'
            . '<a class="ojp-button" href="/messages?unread=1"' . ($unreadOnly ? ' aria-current="page"' : '')
            . '>읽지 않음 ' . $this->messaging->unreadCount($userId) . '</a></nav>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>보낸 사람</th><th>내용</th><th>받은 시각</th><th>상태</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . $this->pagination($unreadOnly ? '/messages?unread=1' : '/messages', $page, $hasNext);

        return $this->pages->authenticated('받은 쪽지함', $content, $request);
    }

    private function sent(int $userId, int $page, int $offset, HttpRequest $request): HttpResponse
    {
        $fetched = $this->messaging->sent($userId, self::PAGE_SIZE + 1, $offset);
        $hasNext = count($fetched) > self::PAGE_SIZE;
        $messages = array_slice($fetched, 0, self::PAGE_SIZE);
        $identities = $this->identities($messages, $userId);

        $rows = '';
        foreach ($messages as $message) {
            $otherId = $message->otherParticipant($userId);
            $other = $identities[$otherId] ?? null;
            $rows .= '<tr><td><a class="ojp-row-link" href="/messages/thread/'
                . $this->escape($message->threadKey()) . '"><strong>'
                . $this->escape($other?->displayName() ?? '알 수 없음') . '</strong></a></td>'
                . '<td>' . $this->excerpt($message->body()) . '</td>'
                . '<td>' . $this->escape($this->dates->format($message->sentAt())) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3">보낸 쪽지가 없습니다.</td></tr>';
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">쪽지</p>'
            . '<h1>보낸 쪽지함</h1></div><div class="ojp-page-actions">'
            . '<a class="ojp-button" href="/messages">받은 쪽지함</a></div></header>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>받는 사람</th><th>내용</th><th>보낸 시각</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . $this->pagination('/messages/sent', $page, $hasNext);

        return $this->pages->authenticated('보낸 쪽지함', $content, $request);
    }

    private function thread(int $userId, string $threadKey, HttpRequest $request): HttpResponse
    {
        if (preg_match('/^\d+_\d+$/', $threadKey) !== 1) {
            return $this->notFound($request);
        }
        [$idA, $idB] = array_map('intval', explode('_', $threadKey, 2));
        if ($userId !== $idA && $userId !== $idB) {
            return $this->notFound($request);
        }
        $otherUserId = $userId === $idA ? $idB : $idA;
        $other = $this->users->findById($otherUserId);

        $messages = $this->messaging->thread($userId, $threadKey, 200, 0);
        $rows = '';
        foreach ($messages as $message) {
            $mine = $message->senderUserId() === $userId;
            $rows .= '<tr><td>' . ($mine ? '나' : $this->escape($other?->displayName() ?? '알 수 없음'))
                . '</td><td>' . nl2br($this->excerptFull($message->body())) . '</td>'
                . '<td>' . $this->escape($this->dates->format($message->sentAt())) . '</td>'
                . '<td><div class="ojp-command-group">'
                . '<form method="post" action="' . $this->escape($this->actionUrls->actionUrl()) . '">'
                . $this->security->hiddenFields('oj_message_delete')
                . '<input type="hidden" name="message_id" value="' . $message->id() . '">'
                . '<button class="ojp-button ojp-button--small" type="submit"'
                . ' data-ojp-confirm="이 쪽지를 삭제할까요? 상대방에게는 영향이 없습니다.">삭제</button></form>'
                . '<form method="post" action="' . $this->escape($this->actionUrls->actionUrl()) . '">'
                . $this->security->hiddenFields('oj_message_report')
                . '<input type="hidden" name="message_id" value="' . $message->id() . '">'
                . '<input class="ojp-input" type="text" name="reason" placeholder="신고 사유" required maxlength="500">'
                . '<button class="ojp-button ojp-button--small ojp-button--danger" type="submit">신고</button></form>'
                . '</div></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">주고받은 쪽지가 없습니다.</td></tr>';
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">쪽지</p>'
            . '<h1>' . $this->escape($other?->displayName() ?? '알 수 없음') . '</h1></div>'
            . '<div class="ojp-page-actions"><a class="ojp-button" href="/messages">받은 쪽지함</a></div>'
            . '</header><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>보낸 사람</th><th>내용</th><th>시각</th><th>작업</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_message_send')
            . '<input type="hidden" name="recipient_user_id" value="' . $otherUserId . '">'
            . '<label class="ojp-field"><span class="ojp-label">답장</span>'
            . '<textarea class="ojp-textarea" name="body" rows="4" maxlength="5000" required></textarea></label>'
            . '<div class="ojp-action-bar"><button class="ojp-button ojp-button--primary" type="submit">보내기</button></div>'
            . '</form>';

        return $this->pages->authenticated(
            $other?->displayName() ?? '쪽지',
            $content,
            $request
        );
    }

    private function compose(int $userId, int $recipientUserId, HttpRequest $request): HttpResponse
    {
        if ($recipientUserId < 1 || $recipientUserId === $userId) {
            return $this->notFound($request);
        }
        $recipient = $this->users->findById($recipientUserId);
        if ($recipient === null || ! $this->messaging->canReceiveFrom($userId, $recipientUserId)) {
            return $this->notFound($request);
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">쪽지</p>'
            . '<h1>' . $this->escape($recipient->displayName()) . '님에게 쪽지 보내기</h1></div>'
            . '<a class="ojp-button" href="/messages/find">받는 사람 변경</a></header>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_message_send')
            . '<input type="hidden" name="recipient_user_id" value="' . $recipientUserId . '">'
            . '<label class="ojp-field"><span class="ojp-label">내용</span>'
            . '<textarea class="ojp-textarea" name="body" rows="8" maxlength="5000" required></textarea></label>'
            . '<div class="ojp-action-bar"><button class="ojp-button ojp-button--primary" type="submit">보내기</button></div>'
            . '</form>';

        return $this->pages->authenticated('쪽지 보내기', $content, $request);
    }

    /**
     * @param list<Message> $messages
     * @return array<int, UserIdentity>
     */
    private function identities(array $messages, int $viewerUserId): array
    {
        $ids = [];
        foreach ($messages as $message) {
            $ids[$message->otherParticipant($viewerUserId)] = true;
        }
        $identities = [];
        foreach ($this->users->findMany(array_keys($ids)) as $identity) {
            $identities[$identity->userId()] = $identity;
        }

        return $identities;
    }

    private function excerptFull(string $body): string
    {
        return $this->escape(trim($body));
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

    private function pagination(string $basePath, int $page, bool $hasNext): string
    {
        if ($page === 1 && ! $hasNext) {
            return '';
        }

        $basePath .= str_contains($basePath, '?') ? '&amp;' : '?';
        return '<nav class="ojp-pagination" aria-label="페이지 이동">'
            . ($page > 1
                ? '<a class="ojp-pagination__link" href="' . $basePath . 'page=' . ($page - 1) . '">이전</a>'
                : '<span class="ojp-pagination__link" aria-disabled="true">이전</span>')
            . '<span class="ojp-pagination__current">' . $page . '페이지</span>'
            . ($hasNext
                ? '<a class="ojp-pagination__link" href="' . $basePath . 'page=' . ($page + 1) . '">다음</a>'
                : '<span class="ojp-pagination__link" aria-disabled="true">다음</span>')
            . '</nav>';
    }

    private function notFound(HttpRequest $request): HttpResponse
    {
        return $this->pages->authenticated(
            '쪽지 없음',
            '<div class="ojp-empty-state"><h1>쪽지를 열 수 없습니다.</h1></div>',
            $request,
            404
        );
    }

    private function searchForm(string $query = ''): string
    {
        return '<form class="ojp-inline-form" method="get" action="/messages/find">'
            . '<label class="ojp-sr-only" for="messaging-find-login">표시 이름 또는 아이디</label>'
            . '<input class="ojp-input" type="search" id="messaging-find-login" name="login" maxlength="200"'
            . ' placeholder="표시 이름 또는 아이디" value="' . $this->escape($query) . '" required>'
            . '<button class="ojp-button" type="submit">사용자 찾기</button></form>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
