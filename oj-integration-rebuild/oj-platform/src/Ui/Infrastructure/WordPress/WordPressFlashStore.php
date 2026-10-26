<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Ui\Message\FlashStore;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

final class WordPressFlashStore implements FlashStore
{
    private const TTL_SECONDS = 300;

    public function issue(UiMessage $message, int $userId = 0): string
    {
        $token = bin2hex(random_bytes(32));
        $key = $this->key($token);
        $stored = set_transient(
            $key,
            [
                'code' => $message->code(),
                'tone' => $message->tone()->value,
                'title' => $message->title(),
                'body' => $message->body(),
                'user_id' => max(0, $userId),
                'expires_at' => time() + self::TTL_SECONDS,
            ],
            self::TTL_SECONDS
        );

        return $stored ? $token : '';
    }

    public function consume(string $token, int $userId = 0): ?UiMessage
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $key = $this->key($token);
        $value = get_transient($key);
        delete_transient($key);
        if (! is_array($value)) {
            return null;
        }

        if (
            (int) ($value['expires_at'] ?? 0) < time()
            || (int) ($value['user_id'] ?? -1) !== max(0, $userId)
        ) {
            return null;
        }

        $tone = MessageTone::tryFrom((string) ($value['tone'] ?? ''));
        if ($tone === null) {
            return null;
        }

        return new UiMessage(
            (string) ($value['code'] ?? ''),
            $tone,
            (string) ($value['title'] ?? ''),
            (string) ($value['body'] ?? '')
        );
    }

    private function key(string $token): string
    {
        return 'oj_platform_flash_' . hash('sha256', $token);
    }
}
