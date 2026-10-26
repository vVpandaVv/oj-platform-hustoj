<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Domain;

enum MigrationStage: int
{
    case Baseline = 0;
    case RecoveryRehearsal = 1;
    case TargetSchema = 2;
    case PreCopy = 3;
    case FinalCopy = 4;
    case Validation = 5;
    case Cutover = 6;
    case Resume = 7;
    case Observation = 8;
    case JudgeCompatibility = 9;
    case Cleanup = 10;

    public function label(): string
    {
        return match ($this) {
            self::Baseline => '기준 보고서',
            self::RecoveryRehearsal => '복구 리허설',
            self::TargetSchema => '목표 구조 준비',
            self::PreCopy => '사전 복사',
            self::FinalCopy => '최종 복사',
            self::Validation => '최종 검증',
            self::Cutover => '모듈 전환',
            self::Resume => '제한 점검·재개',
            self::Observation => '7일 관찰',
            self::JudgeCompatibility => 'Judge 호환 검증',
            self::Cleanup => '기존 구조 정리',
        };
    }

    public function requiresApproval(): bool
    {
        return $this->value >= self::TargetSchema->value;
    }
}
