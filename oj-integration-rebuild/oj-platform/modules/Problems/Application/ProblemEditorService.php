<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAssignmentRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeLock;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicLinkRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTransaction;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDraft;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemWriteConsistencyException;
use CodingPanda\OjPlatform\Shared\Judge\JudgeLanguageCatalog;
use CodingPanda\OjPlatform\Shared\CodeOrdering\CodeOrderingPuzzle;
use DomainException;
use Throwable;

final class ProblemEditorService
{
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemMetadataRepository $metadata,
        private readonly ProblemTopicLinkRepository $topicLinks,
        private readonly ProblemTopicDictionary $topics,
        private readonly ProblemAssignmentRepository $assignments,
        private readonly ProblemTransaction $transaction,
        private readonly ProblemAccessPolicy $access,
        private readonly CodeCompletionTemplatePolicy $completion,
        private readonly ProblemNumberChangeLock $numberChangeLock,
        private readonly JudgeLanguageCatalog $languages,
        private readonly ?CodeOrderingAuthoringPolicy $orderingAuthoring = null,
        private readonly ?\CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemChangePublisher $changes = null
    ) {
    }

    public function create(int $actorUserId, ProblemEditCommand $command): ProblemId
    {
        if (! $this->access->canCreate($actorUserId)) {
            throw new DomainException('문제를 만들 권한이 없습니다.');
        }
        if ($this->numberChangeLock->hasActiveChange()) {
            throw new DomainException('문제 번호 변경 중에는 새 문제를 만들 수 없습니다.');
        }
        $this->assertLanguageExclusivity($command);
        $this->topics->assertSelectableDetails($command->topicKeys());
        $candidateId = $this->problems->nextId();

        return $this->numberChangeLock->withExclusiveLock(
            [$candidateId->value()],
            function () use ($actorUserId, $command): ProblemId {
                $requestedVisibility = $command->problem()->visibility();
                $problemId = $this->problems->createNext(
                    $command->problem()->withVisibility(ProblemVisibility::Archived)
                );
                try {
                    $metadata = $this->metadataFor(
                        $problemId,
                        $actorUserId,
                        $command
                    );
                    $this->transaction->run(function () use (
                        $problemId,
                        $actorUserId,
                        $metadata,
                        $command
                    ): void {
                        $this->assignments->assignOwner(
                            $problemId,
                            $actorUserId,
                            $actorUserId
                        );
                        $this->metadata->save($metadata);
                        $this->topicLinks->replace(
                            $problemId,
                            $command->topicKeys(),
                            (string) $metadata->dictionaryVersion()
                        );
                    });
                } catch (Throwable $error) {
                    throw new ProblemWriteConsistencyException(
                        $problemId,
                        sprintf(
                            '문제 %d번 원문은 보관 상태로 만들었지만 플랫폼 정보를 저장하지 못했습니다.',
                            $problemId->value()
                        ),
                        null,
                        $error
                    );
                }
                if ($requestedVisibility === ProblemVisibility::Published) {
                    try {
                        $this->problems->archive($problemId, false);
                    } catch (Throwable $error) {
                        throw new ProblemWriteConsistencyException(
                            $problemId,
                            sprintf(
                                '문제 %d번은 저장했지만 게시 상태로 전환하지 못했습니다. 보관 문제에서 확인해 주세요.',
                                $problemId->value()
                            ),
                            null,
                            $error
                        );
                    }
                }

                $this->changes?->saved($problemId->value(),$actorUserId,'created');
                return $problemId;
            }
        );
    }

    public function update(
        int $actorUserId,
        ProblemId $problemId,
        ProblemEditCommand $command
    ): void {
        if (! $this->access->canEdit($actorUserId, $problemId)) {
            throw new DomainException('이 문제를 수정할 권한이 없습니다.');
        }
        $this->assertUnlocked($problemId);
        $existing = $this->problems->find($problemId);
        if ($existing === null) {
            throw new DomainException('수정할 문제를 찾을 수 없습니다.');
        }
        $this->assertLanguageExclusivity($command);
        $this->topics->assertSelectableDetails($command->topicKeys());
        $metadata = $this->metadataFor($problemId, $actorUserId, $command);
        $this->problems->update($problemId, $command->problem());
        try {
            $this->transaction->run(function () use (
                $metadata,
                $problemId,
                $command
            ): void {
                $this->metadata->save($metadata);
                $this->topicLinks->replace(
                    $problemId,
                    $command->topicKeys(),
                    (string) $metadata->dictionaryVersion()
                );
            });
        } catch (Throwable $error) {
            try {
                $this->problems->update(
                    $problemId,
                    ProblemDraft::fromProblem($existing)
                );
            } catch (Throwable $recoveryError) {
                throw new ProblemWriteConsistencyException(
                    $problemId,
                    '플랫폼 정보와 Judge 문제 원문을 모두 저장하지 못했고 자동 복구도 실패했습니다. 이 문제의 상태를 확인해 주세요.',
                    $recoveryError,
                    $error
                );
            }

            throw new DomainException(
                '플랫폼 정보 저장에 실패해 문제 원문을 이전 상태로 복구했습니다.',
                0,
                $error
            );
        }
        $this->changes?->saved($problemId->value(),$actorUserId,'updated');
    }

    public function archive(
        int $actorUserId,
        ProblemId $problemId,
        bool $archived
    ): void {
        if (! $this->access->canArchive($actorUserId, $problemId)) {
            throw new DomainException('이 문제를 보관하거나 복원할 권한이 없습니다.');
        }
        $this->assertUnlocked($problemId);
        if ($this->problems->find($problemId) === null) {
            throw new DomainException('문제를 찾을 수 없습니다.');
        }
        $this->problems->archive($problemId, $archived);
        $this->changes?->saved($problemId->value(),$actorUserId,'updated');
    }

    private function assertLanguageExclusivity(ProblemEditCommand $command): void
    {
        $problem = $command->problem();
        if ($command->entryMode() === CodeEntryMode::CodeOrdering) {
            $schema = (string) $command->orderingSchema();
            $hash = CodeOrderingPuzzle::hash($schema);
            ($this->orderingAuthoring ?? new CodeOrderingAuthoringPolicy())->assertFits(
                CodeOrderingPuzzle::source($schema), $problem->prefixCode(), $problem->suffixCode()
            );
            if ($command->orderingHash() === null || ! hash_equals($hash, $command->orderingHash())
                || $problem->starterCode() !== '') {
                throw new DomainException('코드 배열 설정을 확인해 주세요. 완성 코드는 시작 코드에 저장할 수 없습니다.');
            }
            if ($this->languages->enabledIds($problem->languageMask()) !== [
                $this->languages->idForKey(CodeOrderingPuzzle::languageKey($schema)),
            ]) {
                throw new DomainException('코드 배열은 완성 코드와 같은 언어 하나만 허용해야 합니다.');
            }
        }
        if ($command->visualGradingMode()->isEnabled()) {
            if (! $problem->specialJudge()) {
                throw new DomainException(
                    '이미지 채점 문제는 특수 채점기를 사용해야 합니다.'
                );
            }
            $enabled = $this->languages->enabledIds(
                $problem->languageMask() ?? ProblemDraft::DEFAULT_LANGUAGE_MASK
            );
            if ($enabled !== [JudgeLanguageCatalog::PYTHON_3_ID]) {
                throw new DomainException(
                    '이미지 채점 문제는 허용 언어를 Python 3 하나만 선택해야 합니다.'
                );
            }
        }
        $needsSingleLanguage = $problem->prefixCode() !== ''
            || $problem->suffixCode() !== ''
            || $command->entryMode() === CodeEntryMode::CodeCompletion;
        if (! $needsSingleLanguage) {
            return;
        }
        $mask = $problem->languageMask() ?? ProblemDraft::DEFAULT_LANGUAGE_MASK;
        if (count($this->languages->enabledIds($mask)) !== 1) {
            throw new DomainException(
                '앞·뒤 코드를 사용하거나 빈칸 완성 방식인 문제는 허용 언어를 정확히 1개만 선택해야 합니다.'
            );
        }
    }

    private function metadataFor(
        ProblemId $problemId,
        int $actorUserId,
        ProblemEditCommand $command
    ): ProblemMetadata {
        $schema = null;
        $hash = null;
        $orderingSchema = null;
        $orderingHash = null;
        if ($command->entryMode() === CodeEntryMode::CodeCompletion) {
            $prepared = $this->completion->prepare(
                $command->problem()->starterCode(),
                (string) $command->completionSchema(),
                $command->problem()->languageMask()
            );
            $schema = $prepared['schema'];
            $hash = $prepared['hash'];
        }
        if ($command->entryMode() === CodeEntryMode::CodeOrdering) {
            $orderingSchema = $command->orderingSchema();
            $existing = $this->metadata->find($problemId);
            if ($existing?->orderingSchema() !== null
                && CodeOrderingPuzzle::source($existing->orderingSchema()) === CodeOrderingPuzzle::source((string) $orderingSchema)
                && CodeOrderingPuzzle::languageKey($existing->orderingSchema()) === CodeOrderingPuzzle::languageKey((string) $orderingSchema)) {
                $orderingSchema = $existing->orderingSchema();
            }
            $orderingHash = CodeOrderingPuzzle::hash((string) $orderingSchema);
        }

        return new ProblemMetadata(
            $problemId,
            $command->difficulty(),
            $command->topicKeys(),
            $command->entryMode(),
            $schema,
            $hash,
            $command->generationMethod(),
            $command->adminNotes(),
            $actorUserId,
            $this->topics->version(),
            $command->difficultySource(),
            $command->visualGradingMode(),
            $orderingSchema,
            $orderingHash
        );
    }

    private function assertUnlocked(ProblemId $problemId): void
    {
        if ($this->numberChangeLock->isLocked($problemId)) {
            throw new DomainException(
                '문제 번호 변경 중에는 이 문제를 수정할 수 없습니다.'
            );
        }
    }
}
