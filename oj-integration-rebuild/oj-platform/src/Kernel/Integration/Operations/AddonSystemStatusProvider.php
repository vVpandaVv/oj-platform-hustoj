<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Operations;

use CodingPanda\OjPlatform\Kernel\Addon\AcceptedAddonCatalog;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCatalogEntry;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifest;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRuntimeReadiness;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusItem;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusProvider;

final class AddonSystemStatusProvider implements SystemStatusProvider
{
    public function __construct(
        private readonly AcceptedAddonCatalog $catalog,
        private readonly AddonRuntimeReadiness $readiness
    ) {
    }

    public function key(): string
    {
        return 'addons';
    }

    public function current(): SystemStatusItem
    {
        $entries = $this->catalog->all();
        if ($entries === []) {
            return new SystemStatusItem(
                $this->key(),
                '애드온',
                'info',
                '이번 부팅에서 등록된 애드온 후보가 없습니다.',
                '애드온이 활성화되어 있고 등록 훅을 사용하는지 확인하세요.',
                ['후보' => '0개']
            );
        }

        $counts = ['accepted' => 0, 'incompatible' => 0, 'rejected' => 0];
        $runtimeCounts = [
            AddonRuntimeReadiness::READY => 0,
            AddonRuntimeReadiness::NOT_REQUIRED => 0,
            AddonRuntimeReadiness::PENDING => 0,
            AddonRuntimeReadiness::FAILED => 0,
        ];
        $details = [];
        foreach ($entries as $addonId => $entry) {
            if (! $entry instanceof AddonCatalogEntry) {
                continue;
            }
            ++$counts[$entry->status()];
            $runtimeState = null;
            if ($entry->status() === 'accepted') {
                $runtimeState = $this->readiness->state($addonId) ?? [
                    'status' => AddonRuntimeReadiness::PENDING,
                    'code' => 'addon_runtime_state_missing',
                ];
                ++$runtimeCounts[$runtimeState['status']];
            }
            $details['애드온 ' . $addonId] = $this->entrySummary($entry, $runtimeState);
        }
        $details = [
            '후보' => count($entries) . '개',
            '승인' => $counts['accepted'] . '개',
            '비호환' => $counts['incompatible'] . '개',
            '거부' => $counts['rejected'] . '개',
            '런타임 준비' => $runtimeCounts[AddonRuntimeReadiness::READY] . '개',
            '런타임 준비 불필요' => $runtimeCounts[AddonRuntimeReadiness::NOT_REQUIRED] . '개',
            '런타임 대기' => $runtimeCounts[AddonRuntimeReadiness::PENDING] . '개',
            '런타임 실패' => $runtimeCounts[AddonRuntimeReadiness::FAILED] . '개',
        ] + $details;

        if ($counts['rejected'] > 0) {
            return new SystemStatusItem(
                $this->key(),
                '애드온',
                'danger',
                '등록이 거부된 애드온이 있습니다.',
                '표시된 상태 코드에 따라 manifest와 애드온 버전을 확인하세요.',
                $details
            );
        }
        if ($runtimeCounts[AddonRuntimeReadiness::FAILED] > 0) {
            return new SystemStatusItem(
                $this->key(),
                '애드온',
                'danger',
                '승인 후 런타임 준비에 실패한 애드온이 있습니다.',
                '애드온 오류 로그를 확인한 뒤 애드온을 점검하세요.',
                $details
            );
        }
        if ($counts['incompatible'] > 0) {
            return new SystemStatusItem(
                $this->key(),
                '애드온',
                'warning',
                '현재 플랫폼과 호환되지 않는 애드온이 있습니다.',
                '플랫폼 및 Addon API 버전 요구사항을 확인하세요.',
                $details
            );
        }
        if ($runtimeCounts[AddonRuntimeReadiness::PENDING] > 0) {
            return new SystemStatusItem(
                $this->key(),
                '애드온',
                'warning',
                '승인된 애드온의 런타임 준비가 끝나지 않았습니다.',
                '부팅 완료 후에도 계속되면 애드온 오류 로그를 확인하세요.',
                $details
            );
        }

        return new SystemStatusItem(
            $this->key(),
            '애드온',
            'success',
            '등록된 애드온이 모두 정상 승인되고 준비되었습니다.',
            '사용자 > 역할과 권한에서 필요한 애드온 권한을 배정하세요.',
            $details
        );
    }

    /** @param array{status: string, code: string}|null $runtimeState */
    private function entrySummary(AddonCatalogEntry $entry, ?array $runtimeState): string
    {
        $labels = [
            'accepted' => '승인',
            'incompatible' => '비호환',
            'rejected' => '거부',
        ];
        $runtimeLabels = [
            AddonRuntimeReadiness::READY => '런타임 준비',
            AddonRuntimeReadiness::NOT_REQUIRED => '런타임 준비 불필요',
            AddonRuntimeReadiness::PENDING => '런타임 대기',
            AddonRuntimeReadiness::FAILED => '런타임 실패',
        ];
        $parts = [$labels[$entry->status()] ?? $entry->status()];
        $manifest = $entry->manifest();
        if ($manifest instanceof AddonManifest) {
            $parts[] = 'v' . $manifest->version();
            $parts[] = $manifest->diagnosticLabel();
        }
        if ($entry->code() !== '') {
            $parts[] = $entry->code();
        }
        if ($runtimeState !== null) {
            $parts[] = $runtimeLabels[$runtimeState['status']] ?? $runtimeState['status'];
            if ($runtimeState['code'] !== '') {
                $parts[] = $runtimeState['code'];
            }
        }

        return implode(' · ', $parts);
    }
}
