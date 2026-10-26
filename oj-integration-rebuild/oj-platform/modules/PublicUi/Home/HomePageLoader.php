<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Home;

use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\HomeSettingsService;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockContext;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use RuntimeException;

final class HomePageLoader
{
    public function __construct(
        private readonly CurrentAccountProvider $accounts,
        private readonly HomeSettingsService $settings,
        private readonly HomeBlockRegistry $blocks,
        private readonly Clock $clock
    ) {
    }

    public function load(): HomePageState
    {
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            throw new RuntimeException('홈 화면에 로그인 사용자 정보가 없습니다.');
        }
        $now = $this->clock->now();
        $locale = function_exists('get_user_locale')
            ? (string) get_user_locale($identity->userId())
            : 'ko_KR';
        $context = new HomeBlockContext($identity, $locale, $now);
        $loaded = [];
        foreach ($this->settings->blocks() as $setting) {
            if (! $setting->enabled()) {
                continue;
            }
            $block = $this->blocks->load(
                $setting->key(),
                $context,
                $setting->limit()
            );
            if ($block !== null) {
                $loaded[] = $block;
            }
        }

        return new HomePageState($identity, $now, $loaded);
    }
}
