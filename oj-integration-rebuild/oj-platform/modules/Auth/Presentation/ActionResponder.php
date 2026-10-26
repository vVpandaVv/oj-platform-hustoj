<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Ui\Message\FlashStore;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use InvalidArgumentException;

final class ActionResponder implements ActionResponse
{
    public function __construct(private readonly FlashStore $flash)
    {
    }

    public function redirect(
        string $path,
        UiMessage $message,
        int $userId = 0
    ): HttpResponse {
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Action 이동 경로가 올바르지 않습니다.');
        }

        $token = $this->flash->issue($message, $userId);
        if ($token !== '') {
            $path .= str_contains($path, '?') ? '&' : '?';
            $path .= 'notice=' . rawurlencode($token);
        }

        return HttpResponse::redirect($path);
    }
}
