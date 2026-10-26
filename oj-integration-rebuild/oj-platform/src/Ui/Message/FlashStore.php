<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Message;

interface FlashStore
{
    public function issue(UiMessage $message, int $userId = 0): string;

    public function consume(string $token, int $userId = 0): ?UiMessage;
}
