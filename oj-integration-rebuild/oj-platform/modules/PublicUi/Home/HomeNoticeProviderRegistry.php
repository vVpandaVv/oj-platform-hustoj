<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Home;

use CodingPanda\OjPlatform\Modules\PublicUi\Contract\HomeNoticeItem;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\HomeNoticeProvider;
use Throwable;

final class HomeNoticeProviderRegistry implements HomeNoticeProvider
{
    /** @var array<string, HomeNoticeProvider> */
    private array $providers = [];

    public function register(string $key, HomeNoticeProvider $provider): void
    {
        $key = trim($key);
        if ($key === '' || isset($this->providers[$key])) {
            throw new \LogicException('홈 공지 Provider 키가 올바르지 않습니다.');
        }
        $this->providers[$key] = $provider;
    }

    public function current(int $userId, int $limit = 5): array
    {
        $limit = max(1, min(5, $limit));
        $items = [];
        foreach ($this->providers as $provider) {
            try {
                $current = $provider->current($userId, $limit);
            } catch (Throwable) {
                continue;
            }
            foreach ($current as $item) {
                if ($item instanceof HomeNoticeItem) {
                    $items[$item->noticeId()] = $item;
                }
            }
        }
        usort($items, static function (
            HomeNoticeItem $left,
            HomeNoticeItem $right
        ): int {
            if ($left->isPinned() !== $right->isPinned()) {
                return $left->isPinned() ? -1 : 1;
            }
            if ($left->isImportant() !== $right->isImportant()) {
                return $left->isImportant() ? -1 : 1;
            }

            return strcmp($right->publishedAt(), $left->publishedAt());
        });

        return array_slice(array_values($items), 0, $limit);
    }
}
