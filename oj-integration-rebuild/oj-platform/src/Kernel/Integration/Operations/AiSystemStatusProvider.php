<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Operations;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusItem;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusProvider;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCatalog;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiTaskRoutingService;

final class AiSystemStatusProvider implements SystemStatusProvider
{
    public function __construct(
        private readonly AiProviderSettingsService $providers,
        private readonly AiTaskRoutingService $routing
    ) {
    }

    public function key(): string
    {
        return 'ai';
    }

    public function current(): SystemStatusItem
    {
        $profiles = $this->providers->profiles();
        $assignments = $this->routing->assignments();
        $missing = array_values(array_diff(
            AiProviderCatalog::TASK_TYPES,
            array_keys($assignments)
        ));
        $tone = $profiles === [] || $missing !== [] ? 'warning' : 'success';

        return new SystemStatusItem(
            $this->key(),
            'AI 연결과 작업 배정',
            $tone,
            sprintf(
                '검사 완료 프로필 %d개, 작업 배정 %d개입니다.',
                count($profiles),
                count($assignments)
            ),
            $tone === 'success'
                ? '프로필 변경 시 작업 허용 상태를 다시 검사하세요.'
                : 'AI 연결 후보 검사와 작업별 배정을 완료하세요.',
            [
                '프로필 키' => implode(', ', array_map(
                    static fn ($profile): string => $profile->key(),
                    $profiles
                )) ?: '없음',
                '미배정 작업' => implode(', ', $missing) ?: '없음',
            ],
            'oj_manage_settings'
        );
    }
}
