<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Application;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusItem;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusReader;
use InvalidArgumentException;
use Throwable;

final class SystemStatusRegistry implements SystemStatusReader
{
    /**
     * @var array<string, SystemStatusProvider>
     */
    private array $providers = [];

    /**
     * @param list<SystemStatusProvider> $providers
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(SystemStatusProvider $provider): void
    {
        $key = $provider->key();
        if (
            preg_match('/^[a-z][a-z0-9_.-]*$/', $key) !== 1
            || isset($this->providers[$key])
        ) {
            throw new InvalidArgumentException('시스템 상태 Provider 키가 중복되었거나 올바르지 않습니다.');
        }
        $this->providers[$key] = $provider;
        ksort($this->providers);
    }

    public function current(): array
    {
        $items = [];
        foreach ($this->providers as $key => $provider) {
            try {
                $items[] = $provider->current();
            } catch (Throwable) {
                $items[] = new SystemStatusItem(
                    $key,
                    '상태 수집',
                    'danger',
                    '상태 정보를 안전하게 수집하지 못했습니다.',
                    '시스템 로그의 추적 ID를 확인하세요.'
                );
            }
        }

        return $items;
    }
}
