<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemSpjCompiler;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;

final class ProblemSpjCompileJobHandler implements JobHandler
{
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemSpjCompiler $compiler,
        private readonly ProblemAccessPolicy $access
    ) {
    }

    public function jobType(): string
    {
        return 'problem_spj_compile';
    }

    public function handle(JobWorkItem $item): JobHandlerResult
    {
        $payload = $item->payload();
        if (
            ! is_array($payload)
            || ! is_int($payload['problem_id'] ?? null)
            || (int) $payload['problem_id'] < 1
        ) {
            return JobHandlerResult::failed(
                'problem.invalid_job_payload',
                'SPJ 컴파일 작업 입력이 올바르지 않습니다.'
            );
        }
        $problemId = new ProblemId((int) $payload['problem_id']);
        $problem = $this->problems->find($problemId);
        if (
            $problem === null
            || ! $problem->specialJudge()
            || ! $this->access->canManageTestData(
                $item->claim()->createdByUserId(),
                $problemId
            )
        ) {
            return JobHandlerResult::needsAttention(
                'problem.spj_permission_changed',
                '현재 문제 설정이나 권한으로 SPJ를 컴파일할 수 없습니다.'
            );
        }
        try {
            $result = $this->compiler->compile(
                $problemId,
                $this->requiredString($payload['source_name'] ?? null),
                $this->requiredHash($payload['source_sha256'] ?? null),
                $this->nullableHash($payload['binary_sha256'] ?? null),
                $this->nullableHash($payload['log_sha256'] ?? null)
            );
            if (! $result->succeeded()) {
                return JobHandlerResult::failed(
                    $result->resultCode(),
                    'SPJ 컴파일에 실패했습니다. 컴파일 로그를 확인해 주세요.'
                );
            }

            return JobHandlerResult::succeeded(
                $result->resultCode(),
                'SPJ를 컴파일하고 실행 파일을 원자적으로 교체했습니다.',
                [
                    'profile_key' => $result->profileKey(),
                    'binary_sha256' => $result->binarySha256(),
                    'binary_bytes' => $result->binaryBytes(),
                    'log_sha256' => $result->logSha256(),
                ]
            );
        } catch (DomainException $error) {
            return JobHandlerResult::needsAttention(
                'problem.spj_input_changed',
                $error->getMessage()
            );
        }
    }

    private function requiredString(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException('SPJ 컴파일 문자열 입력이 올바르지 않습니다.');
        }

        return trim($value);
    }

    private function requiredHash(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new DomainException('SPJ 소스 해시가 올바르지 않습니다.');
        }

        return $value;
    }

    private function nullableHash(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->requiredHash($value);
    }
}
