<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Application;

use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessagingScope;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessagingSettings;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use DomainException;

final class MessagingSettingsService
{
    private const DEFAULTS = [
        'scope' => 'open',
        'send_rate_limit' => 10,
        'max_message_length' => 1000,
    ];

    public function __construct(
        private readonly SettingsManager $settings,
        private readonly FeatureFlags $features
    ) {
    }

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read('messaging', self::DEFAULTS);
    }

    public function current(): MessagingSettings
    {
        $value = $this->snapshot()->value();
        $value = is_array($value) ? $value : [];

        return new MessagingSettings(
            MessagingScope::tryFrom((string) ($value['scope'] ?? 'open')) ?? MessagingScope::Open,
            (int) ($value['send_rate_limit'] ?? 10),
            (int) ($value['max_message_length'] ?? 1000)
        );
    }

    public function save(
        MessagingScope $scope,
        int $sendRateLimit,
        int $maxMessageLength,
        int $expectedRevision
    ): void {
        if ($scope === MessagingScope::GroupOnly && ! $this->features->isEnabled('groups')) {
            throw new DomainException(
                '그룹 기능이 꺼져 있어 같은 그룹만 허용 모드로 설정할 수 없습니다.'
            );
        }

        $validated = new MessagingSettings($scope, $sendRateLimit, $maxMessageLength);

        $this->settings->save('messaging', [
            'scope' => $validated->scope()->value,
            'send_rate_limit' => $validated->sendRateLimit(),
            'max_message_length' => $validated->maxMessageLength(),
        ], $expectedRevision);
    }
}
