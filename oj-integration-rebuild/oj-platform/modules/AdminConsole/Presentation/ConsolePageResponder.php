<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\ConsoleMenuResolver;
use CodingPanda\OjPlatform\Modules\AdminConsole\Shell\ConsoleShell;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Ui\Message\FlashStore;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

final class ConsolePageResponder implements ConsoleResponder
{
    public function __construct(
        private readonly CurrentAccountProvider $accounts,
        private readonly ConsoleMenuResolver $menus,
        private readonly ConsoleShell $shell,
        private readonly FlashStore $flash
    ) {
    }

    public function respond(
        string $title,
        string $content,
        string $currentMenu,
        HttpRequest $request,
        int $status = 200
    ): HttpResponse {
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }

        $message = $this->flash->consume(
            $request->queryString('notice'),
            $identity->userId()
        );
        $body = $this->renderMessage($message) . $content;

        return HttpResponse::html(
            $this->shell->render(
                $title,
                $body,
                $currentMenu,
                $identity,
                $this->menus->resolve($identity->userId())
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

        return '<div class="ojp-notice ojp-notice--' . $this->escape($tone)
            . '" role="status" data-ojp-flash><strong>' . $this->escape($message->title())
            . '</strong><p>' . $this->escape($message->body())
            . '</p></div>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
