<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LoginThrottleControl;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;

final class LoginThrottleService implements AttemptLimiter, LoginThrottleControl
{
    private const KEY = 'login_security';
    private const DEFAULTS = ['enabled' => true, 'generation' => 'account-ip-v1'];

    public function __construct(
        private readonly AttemptLimiter $attempts,
        private readonly SettingsManager $settings
    ) {
    }

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read(self::KEY, self::DEFAULTS);
    }

    public function save(bool $enabled, int $expectedRevision): void
    {
        $value = $this->current();
        if ($value['enabled'] !== $enabled) {
            $value['generation'] = bin2hex(random_bytes(16));
        }
        $value['enabled'] = $enabled;
        $this->settings->save(self::KEY, $value, $expectedRevision);
    }

    public function reset(int $expectedRevision): void
    {
        $value = $this->current();
        // A new generation invalidates login counters even with an external object cache
        // or an older request still writing its counter. Other scopes stay untouched.
        $value['generation'] = bin2hex(random_bytes(16));
        $this->settings->save(self::KEY, $value, $expectedRevision);
    }

    public function consume(
        string $scope,
        string $identifier,
        int $limit,
        int $windowSeconds
    ): bool {
        if ($scope === 'login') {
            $value = $this->current();
            if (! $value['enabled']) {
                return true;
            }
            $identifier = $value['generation'] . "\0" . $identifier;
        }

        return $this->attempts->consume($scope, $identifier, $limit, $windowSeconds);
    }

    public function resetAll(): int
    {
        return $this->attempts->resetAll();
    }

    /** @return array{enabled:bool,generation:string} */
    private function current(): array
    {
        $value = $this->snapshot()->value();
        $value = is_array($value) ? $value : [];

        return [
            'enabled' => ($value['enabled'] ?? true) !== false,
            'generation' => is_string($value['generation'] ?? null)
                ? $value['generation'] : self::DEFAULTS['generation'],
        ];
    }
}
