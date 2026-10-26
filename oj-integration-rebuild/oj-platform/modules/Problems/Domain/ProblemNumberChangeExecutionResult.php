<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

final class ProblemNumberChangeExecutionResult
{
    private function __construct(
        private readonly string $outcome,
        private readonly string $message
    ) {
    }

    public static function succeeded(): self
    {
        return new self(
            'succeeded',
            '문제 번호와 모든 등록 참조를 변경하고 검증했습니다.'
        );
    }

    public static function compensated(): self
    {
        return new self(
            'compensated',
            '문제 번호 변경에 실패해 완료된 단계를 이전 번호로 복구했습니다.'
        );
    }

    public static function compensationFailed(): self
    {
        return new self(
            'compensation_failed',
            '문제 번호 변경과 자동 복구가 완료되지 않았습니다. 단계 기록을 확인해 주세요.'
        );
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function message(): string
    {
        return $this->message;
    }
}
