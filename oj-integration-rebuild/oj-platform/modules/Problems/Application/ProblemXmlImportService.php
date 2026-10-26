<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeLock;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlApplyResult;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlApplyRow;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlImportPlan;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlItem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlPreviewRow;
use DomainException;
use Throwable;

final class ProblemXmlImportService
{
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemNumberChangeLock $numberChangeLock,
        private readonly ProblemXmlMaterializer $materializer,
        private readonly ProblemXmlPreviewService $previews,
        private readonly ProblemXmlWriter $writer
    ) {
    }

    /**
     * @param list<int> $selectedIndexes
     */
    public function apply(
        int $actorUserId,
        string $reference,
        array $selectedIndexes
    ): ProblemXmlApplyResult {
        $this->previews->assertCanApply($actorUserId);
        $selectedIndexes = $this->selectedIndexes($selectedIndexes);
        $plan = $this->previews->plan($actorUserId, $reference);
        $rowsByIndex = [];
        foreach ($plan->preview()->rows() as $row) {
            $rowsByIndex[$row->index()] = $row;
        }
        $itemsByIndex = [];
        foreach ($plan->document()->items() as $item) {
            $itemsByIndex[$item->index()] = $item;
        }
        foreach ($selectedIndexes as $index) {
            if (! isset($rowsByIndex[$index], $itemsByIndex[$index])) {
                throw new DomainException(
                    '미리보기에 없는 문제 항목이 선택되었습니다.'
                );
            }
        }

        $resultRows = [];
        foreach ($selectedIndexes as $index) {
            $previewRow = $rowsByIndex[$index];
            $item = $itemsByIndex[$index];
            if ($previewRow->blocked()) {
                $resultRows[] = $this->failedRow(
                    $item,
                    $previewRow,
                    $previewRow->message()
                );
                continue;
            }
            try {
                [$problemId, $status] = $this->applyItem(
                    $actorUserId,
                    $item,
                    $previewRow,
                    $plan
                );
                $resultRows[] = new ProblemXmlApplyRow(
                    $index,
                    $problemId,
                    $item->draft()->title(),
                    $status,
                    ''
                );
            } catch (Throwable $error) {
                $resultRows[] = $this->failedRow(
                    $item,
                    $previewRow,
                    trim($error->getMessage()) !== ''
                        ? $error->getMessage()
                        : '문제를 적용하지 못했습니다.'
                );
            }
        }

        return $this->previews->complete(
            $actorUserId,
            $reference,
            $resultRows
        );
    }

    /**
     * @return array{0:ProblemId,1:string}
     */
    private function applyItem(
        int $actorUserId,
        ProblemXmlItem $item,
        ProblemXmlPreviewRow $previewRow,
        ProblemXmlImportPlan $plan
    ): array {
        $requestedProblemId = $item->requestedProblemId();
        if ($requestedProblemId === null) {
            $candidateId = $this->problems->nextId();

            return $this->numberChangeLock->withExclusiveLock(
                [$candidateId->value()],
                function () use (
                    $actorUserId,
                    $item,
                    $previewRow,
                    $plan
                ): array {
                    $baseline = $plan->baselineFor($item->index());
                    if (
                        $baseline['operation'] !== ProblemXmlPreviewRow::CREATE
                        || $baseline['state_hash'] !== 'missing'
                        || $baseline['content_hash'] !== $item->contentHash()
                        || $previewRow->operation() !== ProblemXmlPreviewRow::CREATE
                    ) {
                        throw new DomainException(
                            '미리보기 이후 가져오기 항목이 변경되었습니다. 다시 분석해 주세요.'
                        );
                    }
                    $this->materializer->validateImport(
                        $item,
                        $plan->fallbackTopicKey(),
                        $plan->includeMetadata()
                    );
                    $problemId = $this->writer->createNext(
                        $actorUserId,
                        $item,
                        $plan
                    );

                    return [$problemId, ProblemXmlApplyRow::CREATED];
                }
            );
        }

        $problemId = $requestedProblemId;
        return $this->numberChangeLock->withExclusiveLock(
            [$problemId->value()],
            function () use (
                $actorUserId,
                $item,
                $previewRow,
                $plan,
                $problemId
            ): array {
                $existing = $this->problems->find($problemId);
                $operation = $existing === null
                    ? ProblemXmlPreviewRow::CREATE
                    : ProblemXmlPreviewRow::UPDATE;
                $baseline = $plan->baselineFor($item->index());
                $currentState = $this->materializer->stateHash(
                    $problemId,
                    $plan->includeTestData()
                ) ?? 'missing';
                if (
                    $baseline['operation'] !== $operation
                    || $baseline['state_hash'] !== $currentState
                    || $baseline['content_hash'] !== $item->contentHash()
                    || $operation !== $previewRow->operation()
                ) {
                    throw new DomainException(
                        '미리보기 이후 현재 문제가 변경되었습니다. 다시 분석해 주세요.'
                    );
                }
                $this->materializer->validateImport(
                    $item,
                    $plan->fallbackTopicKey(),
                    $plan->includeMetadata()
                );
                if ($operation === ProblemXmlPreviewRow::CREATE) {
                    $this->writer->create(
                        $actorUserId,
                        $problemId,
                        $item,
                        $plan
                    );

                    return [$problemId, ProblemXmlApplyRow::CREATED];
                }
                if (! $plan->allowOverwrite()) {
                    throw new DomainException(
                        '기존 문제 덮어쓰기가 허용되지 않았습니다.'
                    );
                }
                $this->writer->update(
                    $actorUserId,
                    $problemId,
                    $existing,
                    $item,
                    $plan
                );

                return [$problemId, ProblemXmlApplyRow::UPDATED];
            }
        );
    }

    /**
     * @param list<int> $selectedIndexes
     * @return list<int>
     */
    private function selectedIndexes(array $selectedIndexes): array
    {
        $selectedIndexes = array_values(array_unique($selectedIndexes));
        sort($selectedIndexes, SORT_NUMERIC);
        if (
            $selectedIndexes === []
            || count($selectedIndexes) > 200
            || array_filter(
                $selectedIndexes,
                static fn (mixed $index): bool =>
                    ! is_int($index) || $index < 0
            ) !== []
        ) {
            throw new DomainException('적용할 문제를 1개 이상 선택해 주세요.');
        }

        return $selectedIndexes;
    }

    private function failedRow(
        ProblemXmlItem $item,
        ProblemXmlPreviewRow $previewRow,
        string $message
    ): ProblemXmlApplyRow {
        return new ProblemXmlApplyRow(
            $item->index(),
            $previewRow->targetProblemId(),
            $item->draft()->title(),
            ProblemXmlApplyRow::FAILED,
            $message
        );
    }
}
