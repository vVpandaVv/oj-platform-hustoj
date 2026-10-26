<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Operations;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusItem;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class LastFatalSystemStatusProvider implements SystemStatusProvider
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function key(): string
    {
        return 'last_fatal';
    }

    public function current(): SystemStatusItem
    {
        $value = $this->settings->read('last_fatal')->value();
        $value = is_array($value) ? $value : [];
        $trackingId = trim((string) ($value['tracking_id'] ?? ''));
        $hasFailure = $trackingId !== '';
        $diagnostic = $hasFailure && is_array($value['diagnostic'] ?? null) ? $value['diagnostic'] : [];
        $details = $hasFailure ? [
            '추적 ID' => $trackingId,
            '오류 분류' => (string) ($value['error_class'] ?? 'unknown'),
            '기록 시각' => $this->dates->format((string) ($value['reported_at'] ?? '')),
        ] : [];
        foreach (['code' => '오류 코드', 'operation' => '발생 작업', 'classification' => '원인 분류', 'source' => '발생 위치', 'cause_source' => '원인 예외 위치', 'root_cause_class' => '원인 예외'] as $key => $label) {
            if (($key === 'cause_source' && ($diagnostic[$key] ?? null) === ($diagnostic['source'] ?? null))
                || ($key === 'root_cause_class' && ($diagnostic[$key] ?? null) === ($diagnostic['error_class'] ?? null))) {
                continue;
            }
            if (is_string($diagnostic[$key] ?? null)) { $details[$label] = $diagnostic[$key]; }
        }
        if (isset($details['원인 분류'])) { $details['원인 분류'] = $diagnostic['classification'] === 'identified' ? '오류 유형 확인됨' : '정확한 원인 확인 필요'; }

        return new SystemStatusItem(
            $this->key(),
            '마지막 기록 오류',
            $hasFailure ? 'danger' : 'success',
            $hasFailure
                ? (($diagnostic['summary'] ?? '') !== '' ? (string) ($diagnostic['operation'] ?? '플랫폼 요청 처리') . ': ' . (string) $diagnostic['summary'] . ' ' : '')
                    . '마지막으로 기록한 오류입니다. 현재 요청의 재발 여부와는 별개입니다.'
                : '기록된 치명 오류가 없습니다.',
            $hasFailure
                ? (($diagnostic['next_action'] ?? '') !== '' ? (string) $diagnostic['next_action'] . ' ' : '서버 로그에서 추적 ID를 확인하세요. ')
                    . '표시를 지워도 오류 원인이 해결되지는 않습니다.'
                : '현재 조치가 필요하지 않습니다.',
            $details,
            'oj_view_system_status'
        );
    }
}
