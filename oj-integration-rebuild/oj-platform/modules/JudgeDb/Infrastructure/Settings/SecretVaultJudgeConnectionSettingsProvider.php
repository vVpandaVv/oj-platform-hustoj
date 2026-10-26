<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Settings;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeCredentials;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretReference;
use RuntimeException;

final class SecretVaultJudgeConnectionSettingsProvider implements
    JudgeConnectionSettingsProvider
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly SecretVault $vault
    ) {
    }

    public function current(): ?JudgeConnectionSettings
    {
        $reference = $this->settings->read('judge_database')->value();
        if ($reference === null) {
            return null;
        }
        if (! $reference instanceof SecretReference) {
            throw new RuntimeException('Judge DB 설정의 비밀값 참조가 올바르지 않습니다.');
        }

        $payload = $this->vault->reveal($reference);
        if (! is_array($payload)) {
            throw new RuntimeException('Judge DB 설정을 해석할 수 없습니다.');
        }

        $read = $this->credentials($payload, 'read');
        $write = $this->credentials($payload, 'write');
        $tls = is_array($payload['tls'] ?? null) ? $payload['tls'] : [];
        $caPath = trim((string) ($tls['ca_path'] ?? ''));

        return new JudgeConnectionSettings(
            trim((string) ($payload['host'] ?? '')),
            (int) ($payload['port'] ?? 3306),
            trim((string) ($payload['database'] ?? 'jol')),
            $read,
            $write,
            filter_var($tls['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            $caPath !== '' ? $caPath : null,
            ($payload['timestamp_contract'] ?? null) === 'utc-v1'
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function credentials(array $payload, string $key): JudgeCredentials
    {
        $value = is_array($payload[$key] ?? null) ? $payload[$key] : [];

        return new JudgeCredentials(
            trim((string) ($value['username'] ?? '')),
            (string) ($value['password'] ?? '')
        );
    }
}
