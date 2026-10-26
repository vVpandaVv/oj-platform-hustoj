<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemMetadata
{
    /**
     * @param list<string> $topicKeys
     */
    public function __construct(
        private readonly ProblemId $problemId,
        private readonly ?DifficultyScore $difficulty,
        private readonly array $topicKeys,
        private readonly CodeEntryMode $codeEntryMode,
        private readonly ?string $completionSchema,
        private readonly ?string $completionHash,
        private readonly string $generationMethod,
        private readonly string $adminNotes,
        private readonly int $updatedByUserId,
        private readonly ?string $dictionaryVersion = null,
        private readonly ?string $difficultySource = null,
        private readonly VisualGradingMode $visualGradingMode = VisualGradingMode::None,
        private readonly ?string $orderingSchema = null,
        private readonly ?string $orderingHash = null
    ) {
        if (count($this->topicKeys) > 3) {
            throw new InvalidArgumentException('문제 주제는 최대 3개까지 선택할 수 있습니다.');
        }
        if (count(array_unique($this->topicKeys)) !== count($this->topicKeys)) {
            throw new InvalidArgumentException('같은 문제 주제를 중복해서 선택할 수 없습니다.');
        }
        foreach ($this->topicKeys as $topicKey) {
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $topicKey) !== 1) {
                throw new InvalidArgumentException('문제 주제 키 형식이 올바르지 않습니다.');
            }
        }
        if ($this->updatedByUserId < 1) {
            throw new InvalidArgumentException('수정 사용자 번호가 올바르지 않습니다.');
        }
        if ($this->codeEntryMode !== CodeEntryMode::CodeCompletion) {
            if ($this->completionSchema !== null || $this->completionHash !== null) {
                throw new InvalidArgumentException('전체 코드 작성에는 빈칸 완성 스키마를 저장할 수 없습니다.');
            }
        } elseif (
            $this->completionSchema === null
            || preg_match('/^[a-f0-9]{64}$/', (string) $this->completionHash) !== 1
        ) {
            throw new InvalidArgumentException('빈칸 완성 스키마와 본문 해시가 필요합니다.');
        }
        if ($this->codeEntryMode === CodeEntryMode::CodeOrdering) {
            if ($this->orderingSchema === null || preg_match('/^[a-f0-9]{64}$/D', (string) $this->orderingHash) !== 1) {
                throw new InvalidArgumentException('코드 배열 스키마와 해시가 필요합니다.');
            }
        } elseif ($this->orderingSchema !== null || $this->orderingHash !== null) {
            throw new InvalidArgumentException('코드 배열 방식에서만 배열 스키마를 저장할 수 있습니다.');
        }
        if (
            $this->visualGradingMode->isEnabled()
            && $this->codeEntryMode !== CodeEntryMode::FullCode
        ) {
            throw new InvalidArgumentException('이미지 채점은 전체 코드 작성 방식에서만 사용할 수 있습니다.');
        }
    }

    public function problemId(): ProblemId
    {
        return $this->problemId;
    }

    public function difficulty(): ?DifficultyScore
    {
        return $this->difficulty;
    }

    /**
     * @return list<string>
     */
    public function topicKeys(): array
    {
        return $this->topicKeys;
    }

    public function codeEntryMode(): CodeEntryMode
    {
        return $this->codeEntryMode;
    }

    public function completionSchema(): ?string
    {
        return $this->completionSchema;
    }

    public function completionHash(): ?string
    {
        return $this->completionHash;
    }

    public function orderingSchema(): ?string { return $this->orderingSchema; }

    public function orderingHash(): ?string { return $this->orderingHash; }

    public function generationMethod(): string
    {
        return $this->generationMethod;
    }

    public function adminNotes(): string
    {
        return $this->adminNotes;
    }

    public function updatedByUserId(): int
    {
        return $this->updatedByUserId;
    }

    public function dictionaryVersion(): ?string
    {
        return $this->dictionaryVersion;
    }

    public function difficultySource(): ?string
    {
        return $this->difficultySource;
    }

    public function visualGradingMode(): VisualGradingMode
    {
        return $this->visualGradingMode;
    }
}
