<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;
use JsonException;

final class ProblemXmlItem
{
    /**
     * @param list<string> $topicKeys
     * @param array<string, string> $testFiles
     */
    public function __construct(
        private readonly int $index,
        private readonly ?ProblemId $requestedProblemId,
        private readonly ProblemDraft $draft,
        private readonly bool $visibilitySpecified,
        private readonly ?DifficultyScore $difficulty,
        private readonly array $topicKeys,
        private readonly CodeEntryMode $codeEntryMode,
        private readonly ?string $completionSchema,
        private readonly array $testFiles,
        private readonly ?string $orderingSchema = null
    ) {
        if (
            $this->index < 0
            || count($this->topicKeys) > 3
            || count(array_unique($this->topicKeys)) !== count($this->topicKeys)
            || count($this->testFiles) > 40
        ) {
            throw new InvalidArgumentException('문제 XML 항목 형식이 올바르지 않습니다.');
        }
        foreach ($this->topicKeys as $topicKey) {
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $topicKey) !== 1) {
                throw new InvalidArgumentException('문제 XML 주제 키가 올바르지 않습니다.');
            }
        }
        if (
            $this->codeEntryMode === CodeEntryMode::CodeCompletion
            && ($this->completionSchema === null || trim($this->completionSchema) === '')
        ) {
            throw new InvalidArgumentException('빈칸 완성 문제 XML에는 빈칸 스키마가 필요합니다.');
        }
        if (
            $this->codeEntryMode === CodeEntryMode::FullCode
            && $this->completionSchema !== null
        ) {
            throw new InvalidArgumentException('전체 코드 문제 XML에는 빈칸 스키마를 넣을 수 없습니다.');
        }
        if (($this->codeEntryMode === CodeEntryMode::CodeOrdering) !== ($this->orderingSchema !== null)) {
            throw new InvalidArgumentException('코드 배열 문제 XML에는 배열 스키마가 필요합니다.');
        }
        $totalBytes = 0;
        foreach ($this->testFiles as $fileName => $contents) {
            if (
                ! is_string($fileName)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,126}\.(?:in|out|ans)$/', $fileName) !== 1
                || ! is_string($contents)
                || preg_match('//u', $contents) !== 1
                || strlen($contents) > 8388608
            ) {
                throw new InvalidArgumentException('문제 XML 테스트 파일이 올바르지 않습니다.');
            }
            $totalBytes += strlen($contents);
        }
        if ($totalBytes > 16777216) {
            throw new InvalidArgumentException('문제 XML 테스트 파일 전체 크기가 너무 큽니다.');
        }
    }

    public function index(): int
    {
        return $this->index;
    }

    public function requestedProblemId(): ?ProblemId
    {
        return $this->requestedProblemId;
    }

    public function draft(): ProblemDraft
    {
        return $this->draft;
    }

    public function visibilitySpecified(): bool
    {
        return $this->visibilitySpecified;
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

    public function orderingSchema(): ?string { return $this->orderingSchema; }

    /**
     * @return array<string, string>
     */
    public function testFiles(): array
    {
        return $this->testFiles;
    }

    public function contentHash(): string
    {
        try {
            return hash('sha256', json_encode(
                [
                    'problem_id' => $this->requestedProblemId?->value(),
                    'draft' => [
                        'title' => $this->draft->title(),
                        'description' => $this->draft->description(),
                        'input' => $this->draft->input(),
                        'output' => $this->draft->output(),
                        'sample_input' => $this->draft->sampleInput(),
                        'sample_output' => $this->draft->sampleOutput(),
                        'hint' => $this->draft->hint(),
                        'source' => $this->draft->source(),
                        'time_limit' => $this->draft->timeLimitSeconds(),
                        'memory_limit' => $this->draft->memoryLimitMegabytes(),
                        'special_judge' => $this->draft->specialJudge(),
                        'visibility' => $this->draft->visibility()->value,
                        'forbidden_code' => $this->draft->forbiddenCode(),
                        'prefix_code' => $this->draft->prefixCode(),
                        'starter_code' => $this->draft->starterCode(),
                        'suffix_code' => $this->draft->suffixCode(),
                        'language_mask' => $this->draft->languageMask(),
                    ],
                    'visibility_specified' => $this->visibilitySpecified,
                    'difficulty' => $this->difficulty?->value(),
                    'topics' => $this->topicKeys,
                    'code_entry_mode' => $this->codeEntryMode->value,
                    'completion_schema' => $this->completionSchema,
                    'ordering_schema' => $this->orderingSchema,
                    'test_files' => $this->testFiles,
                ],
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                '문제 XML 항목 해시를 만들 수 없습니다.',
                0,
                $error
            );
        }
    }
}
