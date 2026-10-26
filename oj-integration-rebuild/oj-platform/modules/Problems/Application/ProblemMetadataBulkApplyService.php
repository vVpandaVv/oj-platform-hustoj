<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\DifficultyScore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDraft;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode;
use DomainException;
use Throwable;

final class ProblemMetadataBulkApplyService
{
    public function __construct(
        private readonly ProblemAiDraftService $drafts,
        private readonly ProblemGateway $problems,
        private readonly ProblemMetadataRepository $metadata,
        private readonly ProblemEditorService $editor,
        private readonly ProblemAccessPolicy $access,
        private readonly ErrorReporter $errors
    ) {
    }

    /**
     * @param list<array{problem_id:int, reference:string}> $targets
     * @return array{applied:list<int>, failed:array<int,string>}
     */
    public function applyMany(int $actorUserId, array $targets): array
    {
        $applied = [];
        $failed = [];
        foreach ($targets as $target) {
            $problemIdValue = $target['problem_id'];
            try {
                $applied[] = $this->applyOne(
                    $actorUserId,
                    $problemIdValue,
                    $target['reference']
                );
            } catch (Throwable $error) {
                $this->errors->report($error);
                $failed[$problemIdValue] = $error instanceof DomainException
                    ? $error->getMessage()
                    : '내부 처리 오류로 승인하지 못했습니다. 시스템 기록을 확인해 주세요.';
            }
        }

        return ['applied' => $applied, 'failed' => $failed];
    }

    private function applyOne(
        int $actorUserId,
        int $problemIdValue,
        string $reference
    ): int {
        $problemId = new ProblemId($problemIdValue);
        if (
            ! $this->access->canGenerateMetadataAi($actorUserId, $problemId)
            || ! $this->access->canEdit($actorUserId, $problemId)
        ) {
            throw new DomainException('이 문제에 AI 초안을 적용할 권한이 없습니다.');
        }
        $validated = $this->drafts->metadata($reference, $actorUserId, $problemId);
        if ($validated === null) {
            throw new DomainException('AI 초안을 찾을 수 없거나 보관 시간이 지났습니다.');
        }
        $existingProblem = $this->problems->find($problemId);
        if ($existingProblem === null) {
            throw new DomainException('문제를 찾을 수 없습니다.');
        }
        $existingMetadata = $this->metadata->find($problemId);
        $command = new ProblemEditCommand(
            ProblemDraft::fromProblem($existingProblem),
            new DifficultyScore($validated['difficulty_score']),
            $validated['topic_keys'],
            $existingMetadata?->codeEntryMode() ?? CodeEntryMode::FullCode,
            $existingMetadata?->completionSchema(),
            $existingMetadata?->adminNotes() ?? '',
            'ai_reviewed',
            'ai',
            $existingMetadata?->visualGradingMode() ?? VisualGradingMode::None,
            $existingMetadata?->orderingSchema(),
            $existingMetadata?->orderingHash()
        );
        $this->editor->update($actorUserId, $problemId, $command);
        $this->drafts->discard($reference, $actorUserId);

        return $problemIdValue;
    }
}
