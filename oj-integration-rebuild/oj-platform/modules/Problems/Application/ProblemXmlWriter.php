<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAssignmentRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemFileManager;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicLinkRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTransaction;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDraft;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlImportPlan;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlItem;
use DomainException;
use Throwable;

final class ProblemXmlWriter
{
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemMetadataRepository $metadata,
        private readonly ProblemTopicLinkRepository $topicLinks,
        private readonly ProblemAssignmentRepository $assignments,
        private readonly ProblemTransaction $transaction,
        private readonly ProblemFileManager $files,
        private readonly ProblemXmlMaterializer $materializer
    ) {
    }

    public function create(
        int $actorUserId,
        ProblemId $problemId,
        ProblemXmlItem $item,
        ProblemXmlImportPlan $plan
    ): void {
        $draft = $this->materializer->draftFor($item, null);
        $this->problems->create(
            $problemId,
            $draft->withVisibility(ProblemVisibility::Archived)
        );

        $this->finishCreate($actorUserId, $problemId, $item, $plan, $draft);
    }

    public function createNext(
        int $actorUserId,
        ProblemXmlItem $item,
        ProblemXmlImportPlan $plan
    ): ProblemId {
        $draft = $this->materializer->draftFor($item, null);
        $problemId = $this->problems->createNext(
            $draft->withVisibility(ProblemVisibility::Archived)
        );
        $this->finishCreate($actorUserId, $problemId, $item, $plan, $draft);

        return $problemId;
    }

    private function finishCreate(
        int $actorUserId,
        ProblemId $problemId,
        ProblemXmlItem $item,
        ProblemXmlImportPlan $plan,
        ProblemDraft $draft
    ): void {
        $requestedVisibility = $draft->visibility();
        $newFileHashes = [];
        $platformStored = false;
        try {
            if ($plan->includeTestData() && $item->testFiles() !== []) {
                $expected = array_fill_keys(
                    array_keys($item->testFiles()),
                    null
                );
                $newFileHashes = $this->files->replaceMany(
                    $problemId,
                    $item->testFiles(),
                    $expected
                );
            }
            $metadata = $this->materializer->metadataFor(
                $problemId,
                $actorUserId,
                $item,
                $plan->fallbackTopicKey(),
                $plan->includeMetadata()
            );
            $this->transaction->run(function () use (
                $problemId,
                $actorUserId,
                $metadata
            ): void {
                $this->assignments->assignOwner(
                    $problemId,
                    $actorUserId,
                    $actorUserId
                );
                $this->metadata->save($metadata);
                $this->topicLinks->replace(
                    $problemId,
                    $metadata->topicKeys(),
                    (string) $metadata->dictionaryVersion()
                );
            });
            $platformStored = true;
            if ($requestedVisibility === ProblemVisibility::Published) {
                $this->problems->archive($problemId, false);
            }
        } catch (Throwable $error) {
            if (! $platformStored && $newFileHashes !== []) {
                try {
                    $this->files->deleteMany($problemId, $newFileHashes);
                } catch (Throwable) {
                }
            }
            throw new DomainException(
                '새 문제는 보관 상태로 남겼습니다. 실패 사유: '
                    . $error->getMessage(),
                0,
                $error
            );
        }
    }

    public function update(
        int $actorUserId,
        ProblemId $problemId,
        JudgeProblem $existing,
        ProblemXmlItem $item,
        ProblemXmlImportPlan $plan
    ): void {
        $draft = $this->materializer->draftFor($item, $existing);
        $metadata = $this->materializer->metadataFor(
            $problemId,
            $actorUserId,
            $item,
            $plan->fallbackTopicKey(),
            $plan->includeMetadata()
        );
        $fileBackup = $plan->includeTestData()
            ? $this->fileBackup($problemId, $item->testFiles())
            : ['contents' => [], 'expected' => []];
        $newFileHashes = [];
        $judgeUpdated = false;
        try {
            if ($plan->includeTestData() && $item->testFiles() !== []) {
                $newFileHashes = $this->files->replaceMany(
                    $problemId,
                    $item->testFiles(),
                    $fileBackup['expected']
                );
            }
            $this->problems->update($problemId, $draft);
            $judgeUpdated = true;
            $this->transaction->run(function () use (
                $metadata,
                $problemId
            ): void {
                $this->metadata->save($metadata);
                $this->topicLinks->replace(
                    $problemId,
                    $metadata->topicKeys(),
                    (string) $metadata->dictionaryVersion()
                );
            });
        } catch (Throwable $error) {
            $recoveryErrors = [];
            if ($judgeUpdated) {
                try {
                    $this->problems->update(
                        $problemId,
                        ProblemDraft::fromProblem($existing)
                    );
                } catch (Throwable $recoveryError) {
                    $recoveryErrors[] = $recoveryError;
                }
            }
            if ($newFileHashes !== []) {
                try {
                    $this->restoreFiles(
                        $problemId,
                        $fileBackup['contents'],
                        $newFileHashes
                    );
                } catch (Throwable $recoveryError) {
                    $recoveryErrors[] = $recoveryError;
                }
            }
            if ($recoveryErrors !== []) {
                throw new DomainException(
                    '문제 적용과 자동 복구가 모두 실패했습니다. 보관 상태와 테스트 파일을 확인해 주세요.',
                    0,
                    $error
                );
            }

            throw new DomainException(
                '문제 적용에 실패해 기존 원문과 테스트 파일을 복구했습니다.',
                0,
                $error
            );
        }
    }

    /**
     * @param array<string, string> $newContents
     * @return array{
     *     contents:array<string,string>,
     *     expected:array<string,string|null>
     * }
     */
    private function fileBackup(
        ProblemId $problemId,
        array $newContents
    ): array {
        if ($newContents === []) {
            return ['contents' => [], 'expected' => []];
        }
        $current = [];
        foreach ($this->files->files($problemId) as $file) {
            $current[$file->name()] = $file;
        }
        $contents = [];
        $expected = [];
        foreach (array_keys($newContents) as $fileName) {
            $file = $current[$fileName] ?? null;
            $expected[$fileName] = $file?->sha256();
            if ($file !== null) {
                $contents[$fileName] = $this->files->read(
                    $problemId,
                    $fileName
                );
            }
        }

        return ['contents' => $contents, 'expected' => $expected];
    }

    /**
     * @param array<string, string> $oldContents
     * @param array<string, string> $newHashes
     */
    private function restoreFiles(
        ProblemId $problemId,
        array $oldContents,
        array $newHashes
    ): void {
        if ($oldContents !== []) {
            $restoreExpected = [];
            foreach (array_keys($oldContents) as $fileName) {
                $restoreExpected[$fileName] = $newHashes[$fileName];
            }
            $this->files->replaceMany(
                $problemId,
                $oldContents,
                $restoreExpected
            );
        }
        $addedHashes = array_diff_key($newHashes, $oldContents);
        if ($addedHashes !== []) {
            $this->files->deleteMany($problemId, $addedHashes);
        }
    }
}
