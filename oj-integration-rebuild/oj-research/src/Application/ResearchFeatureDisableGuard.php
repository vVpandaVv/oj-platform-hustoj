<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Application;

use CodingPanda\OjResearch\Contract\ResearchRepository;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDisableGuard;

final class ResearchFeatureDisableGuard implements FeatureDisableGuard
{
    public function __construct(private readonly ResearchRepository $store) {}
    public function featureKey(): string { return 'research'; }
    public function blockers(): array
    {
        foreach ($this->store->studies() as $study) {
            if (! in_array($study['status'], ['draft', 'archived', 'cancelled'], true)) { return ['활성 수업 연구를 종료·보관한 뒤 연구 기능을 꺼 주세요.']; }
        }
        return [];
    }
}
