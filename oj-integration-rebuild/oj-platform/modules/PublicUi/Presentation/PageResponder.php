<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder as PageResponderContract;
use CodingPanda\OjPlatform\Modules\PublicUi\Navigation\PublicMenuResolver;
use CodingPanda\OjPlatform\Modules\PublicUi\Shell\PublicShell;
use CodingPanda\OjPlatform\Ui\Message\FlashStore;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

final class PageResponder implements PageResponderContract
{
    public function __construct(
        private readonly CurrentAccountProvider $accounts,
        private readonly PublicMenuResolver $menus,
        private readonly Authorization $permissions,
        private readonly PublicShell $shell,
        private readonly FlashStore $flash
    ) {
    }

    public function guest(
        string $title,
        string $content,
        HttpRequest $request,
        int $status = 200
    ): HttpResponse {
        $message = $this->flash->consume($request->queryString('notice'));
        $body = $this->renderMessage($message) . $content;

        return HttpResponse::html($this->shell->guest($title, $body), $status);
    }

    public function authenticated(
        string $title,
        string $content,
        HttpRequest $request,
        int $status = 200
    ): HttpResponse {
        $account = $this->accounts->current();
        $identity = $account->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }

        $message = $this->flash->consume(
            $request->queryString('notice'),
            $identity->userId()
        );
        $body = $this->renderMessage($message) . $content;
        $canAccessConsole = $this->permissions->can(
            $identity->userId(),
            'oj_access_console'
        );

        return HttpResponse::html(
            $this->shell->authenticated(
                $title,
                $body,
                $identity,
                $this->menus->resolve($identity),
                $canAccessConsole,
                $request->path()
            ),
            $status
        );
    }

    private function renderMessage(?UiMessage $message): string
    {
        if ($message === null) {
            return '';
        }

        $tone = $message->tone()->value === 'neutral'
            ? 'info'
            : $message->tone()->value;

        return '<div class="ojp-notice ojp-notice--'
            . $this->escape($tone)
            . '" role="status"><strong>'
            . $this->escape($message->title())
            . '</strong><p>'
            . $this->escape($message->body())
            . '</p></div>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
