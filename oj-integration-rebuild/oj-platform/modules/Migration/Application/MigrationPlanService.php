<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Application;

use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationStage;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationState;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationWave;
use DomainException;

final class MigrationPlanService
{
    public function __construct(private readonly MigrationCatalog $catalog)
    {
    }

    public function create(MigrationState $state, ?MigrationStage $requested = null): MigrationPlan
    {
        $fingerprint = $state->baseline()['database_fingerprint'] ?? null;
        if (! is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new DomainException('먼저 이전 대상 사전 검사를 실행해 주세요.');
        }
        if (! $state->hasRecoveryEvidence()) {
            throw new DomainException('백업과 분리 환경 복구 리허설 증거를 먼저 등록해 주세요.');
        }
        if ((int) ($state->baseline()['blocker_count'] ?? 0) > 0) {
            throw new DomainException('사전 검사 차단 항목을 모두 해결하고 다시 검사해 주세요.');
        }
        if ($state->status() === 'completed') {
            throw new DomainException('이미 완료된 데이터 이전은 다시 실행할 수 없습니다.');
        }
        if (in_array($state->status(), ['queued', 'running'], true)) {
            throw new DomainException('현재 이전 작업이 끝난 뒤 다음 계획을 만들 수 있습니다.');
        }

        $stage = $this->nextStage($state);
        if ($requested !== null && $requested !== $stage) {
            throw new DomainException(sprintf(
                '현재 실행할 수 있는 단계는 %d단계 %s입니다.',
                $stage->value,
                $stage->label()
            ));
        }
        if ($stage === MigrationStage::FinalCopy && $state->legacyPlugins() !== []) {
            throw new DomainException(
                '최종 복사 전에 사전 검사에 표시된 기존 플러그인을 수동으로 비활성화한 뒤 다시 검사해 주세요.'
            );
        }
        if ($stage === MigrationStage::JudgeCompatibility
            && count($state->completedWaves()) !== 7) {
            throw new DomainException('모든 데이터 파동의 7일 관찰을 먼저 완료해 주세요.');
        }
        if ($stage === MigrationStage::Cleanup
            && $state->lastResultCode() !== 'migration.judge_compatible') {
            throw new DomainException('Judge 호환 검증을 먼저 완료해 주세요.');
        }

        return new MigrationPlan(
            $state->wave(),
            $stage,
            $fingerprint,
            $this->catalog->forWave($state->wave()),
            $state->recoveryEvidence(),
            $state->ownerAssignments()
        );
    }

    private function nextStage(MigrationState $state): MigrationStage
    {
        if ($state->status() === 'ready_for_next') {
            return $state->wave()->next() === null
                ? MigrationStage::JudgeCompatibility
                : MigrationStage::TargetSchema;
        }
        if ($state->status() === 'observing') {
            return MigrationStage::Observation;
        }
        if ($state->status() === 'needs_attention') {
            return $state->stage();
        }
        if ($state->lastResultCode() === 'migration.plan_created'
            || $state->lastResultCode() === 'migration.plan_approved'
            || $state->lastResultCode() === 'migration.job_queued') {
            return $state->stage();
        }

        return match ($state->stage()) {
            MigrationStage::Baseline,
            MigrationStage::RecoveryRehearsal => MigrationStage::TargetSchema,
            MigrationStage::TargetSchema => MigrationStage::PreCopy,
            MigrationStage::PreCopy => MigrationStage::FinalCopy,
            MigrationStage::FinalCopy => MigrationStage::Validation,
            MigrationStage::Validation => MigrationStage::Cutover,
            MigrationStage::Cutover => MigrationStage::Resume,
            MigrationStage::Resume => MigrationStage::Observation,
            MigrationStage::Observation => MigrationStage::Observation,
            MigrationStage::JudgeCompatibility => MigrationStage::Cleanup,
            MigrationStage::Cleanup => MigrationStage::Cleanup,
        };
    }
}

