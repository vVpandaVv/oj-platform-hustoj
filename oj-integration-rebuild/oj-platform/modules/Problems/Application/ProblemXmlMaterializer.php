<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemFileManager;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicLinkRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDraft;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTestDataInspection;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlItem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode;
use CodingPanda\OjPlatform\Shared\Judge\JudgeLanguageCatalog;
use CodingPanda\OjPlatform\Shared\CodeOrdering\CodeOrderingPuzzle;
use DomainException;
use JsonException;

final class ProblemXmlMaterializer
{
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemMetadataRepository $metadata,
        private readonly ProblemTopicLinkRepository $topicLinks,
        private readonly ProblemTopicDictionary $topics,
        private readonly ProblemFileManager $files,
        private readonly CodeCompletionTemplatePolicy $completion,
        private readonly ?CodeOrderingAuthoringPolicy $orderingAuthoring = null
    ) {
    }

    public function validateImport(
        ProblemXmlItem $item,
        string $fallbackTopicKey,
        bool $includeMetadata
    ): void {
        $topicKeys = $this->effectiveTopicKeys(
            $item,
            $fallbackTopicKey,
            $includeMetadata
        );
        $this->topics->assertSelectableDetails($topicKeys);
        if ($item->codeEntryMode() === CodeEntryMode::CodeOrdering) {
            if (! $includeMetadata) {
                throw new DomainException('코드 배열 문제는 메타데이터를 포함해 가져와야 합니다.');
            }
            $this->validateOrdering($item);
        }
        if (
            $includeMetadata
            && $item->codeEntryMode() === CodeEntryMode::CodeCompletion
        ) {
            $this->completion->prepare(
                $item->draft()->starterCode(),
                (string) $item->completionSchema(),
                $item->draft()->languageMask()
            );
        }
    }

    public function draftFor(
        ProblemXmlItem $item,
        ?JudgeProblem $existing
    ): ProblemDraft {
        if ($item->visibilitySpecified()) {
            return $item->draft();
        }

        return $item->draft()->withVisibility(
            $existing?->visibility() ?? ProblemVisibility::Archived
        );
    }

    public function metadataFor(
        ProblemId $problemId,
        int $actorUserId,
        ProblemXmlItem $item,
        string $fallbackTopicKey,
        bool $includeMetadata
    ): ProblemMetadata {
        if ($item->codeEntryMode() === CodeEntryMode::CodeOrdering && ! $includeMetadata) {
            throw new DomainException('코드 배열 문제는 메타데이터를 포함해 가져와야 합니다.');
        }
        $topicKeys = $this->effectiveTopicKeys(
            $item,
            $fallbackTopicKey,
            $includeMetadata
        );
        $this->topics->assertSelectableDetails($topicKeys);
        $entryMode = $includeMetadata
            ? $item->codeEntryMode()
            : CodeEntryMode::FullCode;
        $schema = null;
        $hash = null;
        $orderingSchema = null;
        $orderingHash = null;
        if ($entryMode === CodeEntryMode::CodeCompletion) {
            $prepared = $this->completion->prepare(
                $item->draft()->starterCode(),
                (string) $item->completionSchema(),
                $item->draft()->languageMask()
            );
            $schema = $prepared['schema'];
            $hash = $prepared['hash'];
        }
        if ($entryMode === CodeEntryMode::CodeOrdering) {
            $this->validateOrdering($item);
            $orderingSchema = $item->orderingSchema();
            $orderingHash = CodeOrderingPuzzle::hash((string) $orderingSchema);
        }
        $existing = $this->metadata->find($problemId);
        $draft = $item->draft();
        // Image grading is judge configuration that XML does not carry: keep it
        // only while the imported draft still satisfies its requirements.
        $visualMode = $existing?->visualGradingMode() ?? VisualGradingMode::None;
        if (
            $visualMode->isEnabled()
            && (
                $entryMode !== CodeEntryMode::FullCode
                || ! $draft->specialJudge()
                || (new JudgeLanguageCatalog())->enabledIds(
                    $draft->languageMask() ?? ProblemDraft::DEFAULT_LANGUAGE_MASK
                ) !== [JudgeLanguageCatalog::PYTHON_3_ID]
            )
        ) {
            $visualMode = VisualGradingMode::None;
        }

        return new ProblemMetadata(
            $problemId,
            $includeMetadata ? $item->difficulty() : null,
            $topicKeys,
            $entryMode,
            $schema,
            $hash,
            'xml_import',
            $existing?->adminNotes() ?? '',
            $actorUserId,
            $this->topics->version(),
            $includeMetadata && $item->difficulty() !== null ? 'xml' : null,
            $visualMode,
            $orderingSchema,
            $orderingHash
        );
    }

    public function exportItem(
        ProblemId $problemId,
        bool $includeTestData
    ): ProblemXmlItem {
        $problem = $this->problems->find($problemId);
        if ($problem === null) {
            throw new DomainException(
                '문제 ' . $problemId->value() . '번을 찾을 수 없습니다.'
            );
        }
        $metadata = $this->metadata->find($problemId);
        $topicKeys = $this->topicLinks->keysFor($problemId);
        if ($topicKeys === [] && $metadata !== null) {
            $topicKeys = $metadata->topicKeys();
        }

        return new ProblemXmlItem(
            $problemId->value(),
            $problemId,
            ProblemDraft::fromProblem($problem),
            true,
            $metadata?->difficulty(),
            $topicKeys,
            $metadata?->codeEntryMode() ?? CodeEntryMode::FullCode,
            $metadata?->completionSchema(),
            $includeTestData ? $this->testFilesForExport($problemId) : [],
            $metadata?->orderingSchema()
        );
    }

    public function stateHash(
        ProblemId $problemId,
        bool $includeTestData
    ): ?string {
        $problem = $this->problems->find($problemId);
        if ($problem === null) {
            return null;
        }
        $metadata = $this->metadata->find($problemId);
        $topicKeys = $this->topicLinks->keysFor($problemId);
        sort($topicKeys, SORT_STRING);
        $fileStates = [];
        if ($includeTestData) {
            foreach ($this->files->files($problemId) as $file) {
                if (! in_array($file->extension(), ['in', 'out', 'ans'], true)) {
                    continue;
                }
                $fileStates[$file->name()] = [
                    'size' => $file->size(),
                    'sha256' => $file->sha256(),
                ];
            }
            ksort($fileStates, SORT_NATURAL | SORT_FLAG_CASE);
        }
        try {
            return hash('sha256', json_encode(
                [
                    'problem' => $this->draftArray(
                        ProblemDraft::fromProblem($problem)
                    ),
                    'metadata' => $metadata === null
                        ? null
                        : [
                            'difficulty' => $metadata->difficulty()?->value(),
                            'topics' => $metadata->topicKeys(),
                            'entry_mode' => $metadata->codeEntryMode()->value,
                            'completion_schema' => $metadata->completionSchema(),
                            'completion_hash' => $metadata->completionHash(),
                            'ordering_schema' => $metadata->orderingSchema(),
                            'ordering_hash' => $metadata->orderingHash(),
                            'generation_method' => $metadata->generationMethod(),
                            'admin_notes' => $metadata->adminNotes(),
                            'dictionary_version' => $metadata->dictionaryVersion(),
                            'difficulty_source' => $metadata->difficultySource(),
                        ],
                    'topic_links' => $topicKeys,
                    'test_files' => $fileStates,
                ],
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException $error) {
            throw new DomainException(
                '문제 XML 현재 상태 해시를 만들 수 없습니다.',
                0,
                $error
            );
        }
    }

    /**
     * @return list<string>
     */
    private function effectiveTopicKeys(
        ProblemXmlItem $item,
        string $fallbackTopicKey,
        bool $includeMetadata
    ): array {
        if ($includeMetadata && $item->topicKeys() !== []) {
            return $item->topicKeys();
        }

        return $fallbackTopicKey === '' ? [] : [$fallbackTopicKey];
    }

    private function validateOrdering(ProblemXmlItem $item): void
    {
        $schema = (string) $item->orderingSchema();
        CodeOrderingPuzzle::hash($schema);
        ($this->orderingAuthoring ?? new CodeOrderingAuthoringPolicy())->assertFits(
            CodeOrderingPuzzle::source($schema), $item->draft()->prefixCode(), $item->draft()->suffixCode()
        );
        $languages = new JudgeLanguageCatalog();
        if ($item->draft()->starterCode() !== ''
            || $languages->enabledIds($item->draft()->languageMask()) !== [
                $languages->idForKey(CodeOrderingPuzzle::languageKey($schema)),
            ]) {
            throw new DomainException('코드 배열 XML은 시작 코드가 비어 있고 배열 언어 하나만 허용해야 합니다.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function testFilesForExport(ProblemId $problemId): array
    {
        $files = $this->files->files($problemId);
        $inspection = ProblemTestDataInspection::inspect($files);
        if (! $inspection->isValid()) {
            throw new DomainException(
                '문제 ' . $problemId->value()
                    . '번의 테스트데이터 쌍을 먼저 정리해 주세요.'
            );
        }
        if ($inspection->pairCount() > 20) {
            throw new DomainException(
                '문제당 내보낼 수 있는 테스트데이터는 최대 20쌍입니다.'
            );
        }
        $byName = [];
        foreach ($files as $file) {
            $byName[$file->name()] = $file;
        }
        $result = [];
        $totalBytes = 0;
        foreach ($files as $file) {
            if ($file->extension() !== 'in') {
                continue;
            }
            $base = pathinfo($file->name(), PATHINFO_FILENAME);
            $outputName = isset($byName[$base . '.out'])
                ? $base . '.out'
                : $base . '.ans';
            if (! isset($byName[$outputName])) {
                continue;
            }
            foreach ([$file->name(), $outputName] as $fileName) {
                $dataFile = $byName[$fileName];
                $totalBytes += $dataFile->size();
                if ($dataFile->size() > 8388608 || $totalBytes > 16777216) {
                    throw new DomainException(
                        '내보낼 테스트데이터는 파일당 8MB, 문제당 16MB 이하여야 합니다.'
                    );
                }
                $result[$fileName] = $this->files->read(
                    $problemId,
                    $fileName
                );
            }
        }

        return $result;
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    private function draftArray(ProblemDraft $draft): array
    {
        return [
            'title' => $draft->title(),
            'description' => $draft->description(),
            'input' => $draft->input(),
            'output' => $draft->output(),
            'sample_input' => $draft->sampleInput(),
            'sample_output' => $draft->sampleOutput(),
            'hint' => $draft->hint(),
            'source' => $draft->source(),
            'time_limit' => $draft->timeLimitSeconds(),
            'memory_limit' => $draft->memoryLimitMegabytes(),
            'special_judge' => $draft->specialJudge(),
            'visibility' => $draft->visibility()->value,
            'forbidden_code' => $draft->forbiddenCode(),
            'prefix_code' => $draft->prefixCode(),
            'starter_code' => $draft->starterCode(),
            'suffix_code' => $draft->suffixCode(),
            'language_mask' => $draft->languageMask(),
        ];
    }
}
