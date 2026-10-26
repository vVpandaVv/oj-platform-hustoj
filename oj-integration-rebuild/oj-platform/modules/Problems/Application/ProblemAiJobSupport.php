<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskAssignmentSnapshot;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskResponse;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use DomainException;

final class ProblemAiJobSupport
{
    public function __construct(
        private readonly ProblemAiInputFactory $inputs,
        private readonly AiTaskRouter $ai
    ) {
    }

    /**
     * @return array{
     *   assignment:AiTaskAssignmentSnapshot,
     *   input:array<string,mixed>,
     *   input_hash:string,
     *   payload:array<string,mixed>
     * }
     */
    public function context(
        JobWorkItem $item,
        string $expectedTaskType,
        string $expectedContractVersion
    ): array {
        $payload = $item->payload();
        if (! is_array($payload) || ! is_array($payload['input'] ?? null)) {
            throw new DomainException('문제 AI 작업 입력이 올바르지 않습니다.');
        }
        $assignmentValue = $payload['assignment'] ?? null;
        if (! is_array($assignmentValue)) {
            throw new DomainException('문제 AI 작업 배정이 올바르지 않습니다.');
        }
        $assignment = AiTaskAssignmentSnapshot::fromArray($assignmentValue);
        $input = $payload['input'];
        $inputHash = is_string($payload['problem_input_hash'] ?? null)
            ? $payload['problem_input_hash']
            : '';
        if (
            $assignment->taskType() !== $expectedTaskType
            || ($input['contract_version'] ?? null) !== $expectedContractVersion
            || preg_match('/^[a-f0-9]{64}$/', $inputHash) !== 1
            || ! hash_equals($inputHash, $this->inputs->hash($input))
            || (
                $item->claim()->targetVersion() !== null
                && ! hash_equals($inputHash, $item->claim()->targetVersion())
            )
        ) {
            throw new DomainException('문제 AI 작업 입력 스냅샷이 일치하지 않습니다.');
        }

        return [
            'assignment' => $assignment,
            'input' => $input,
            'input_hash' => $inputHash,
            'payload' => $payload,
        ];
    }

    public function execute(
        AiTaskAssignmentSnapshot $assignment,
        string $contractVersion,
        array $input
    ): AiTaskResponse {
        $response = $this->ai->execute(
            $assignment,
            new AiTaskRequest(
                $assignment->taskType(),
                $contractVersion,
                $input
            )
        );
        $allowedProfiles = array_filter([
            $assignment->primaryProfileKey(),
            $assignment->fallbackProfileKey(),
        ]);
        if (
            ! hash_equals(
                $assignment->profileFingerprint(),
                $response->profileFingerprint()
            )
            || ! in_array($response->profileKey(), $allowedProfiles, true)
        ) {
            throw new DomainException('AI 연결 프로필이 등록 시점과 달라졌습니다.');
        }

        return $response;
    }
}
