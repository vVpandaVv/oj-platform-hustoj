<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemEditCommand;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\DifficultyScore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDraft;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode;
use CodingPanda\OjPlatform\Shared\Judge\JudgeLanguageCatalog;
use CodingPanda\OjPlatform\Shared\CodeOrdering\CodeOrderingPuzzle;
use DomainException;

final class ProblemRequestFactory
{
    private const MAX_CONTENT_BYTES = 1048576;

    public function __construct(
        private readonly JudgeLanguageCatalog $languages
    ) {
    }

    public function editCommand(
        HttpRequest $request,
        string $generationMethod = 'manual',
        ?string $difficultySource = 'manual'
    ): ProblemEditCommand {
        $difficultyValue = trim($request->bodyString('difficulty_score'));
        $difficulty = $difficultyValue === ''
            ? null
            : new DifficultyScore($this->strictInt($difficultyValue, '난이도'));
        $mode = CodeEntryMode::tryFrom($request->bodyString('code_entry_mode'));
        if ($mode === null) {
            throw new DomainException('코드 작성 방식을 선택해 주세요.');
        }
        $visualValue = trim($request->bodyString('visual_grading_mode', 'none'));
        $visualMode = $visualValue === ''
            ? VisualGradingMode::None
            : VisualGradingMode::tryFrom($visualValue);
        if ($visualMode === null) {
            throw new DomainException('이미지 채점 방식을 다시 선택해 주세요.');
        }
        if ($visualMode->isEnabled() && $mode !== CodeEntryMode::FullCode) {
            throw new DomainException('이미지 채점은 전체 코드 작성 방식에서만 사용할 수 있습니다.');
        }
        $visibility = ProblemVisibility::tryFrom(
            $request->bodyString('visibility')
        );
        if ($visibility === null) {
            throw new DomainException('문제 공개 상태를 선택해 주세요.');
        }
        $topicKeys = $this->stringList($request->body()['topic_keys'] ?? []);
        $languageMask = $mode === CodeEntryMode::CodeOrdering ? null : $this->languages->disabledMaskForEnabled(
            $request->bodyIntList('enabled_language_ids')
        );
        $orderingSchema = null;
        $orderingHash = null;
        if ($mode === CodeEntryMode::CodeOrdering) {
            $languageKey = trim($request->bodyString('ordering_language_key'));
            $orderingSchema = CodeOrderingPuzzle::encode(CodeOrderingPuzzle::create(
                $this->text($request, 'ordering_source', 65536, '배열할 완성 코드'),
                $languageKey
            ));
            $orderingHash = CodeOrderingPuzzle::hash($orderingSchema);
            $languageMask = $this->languages->disabledMaskForEnabled([$this->languages->idForKey($languageKey)]);
        }

        $problem = new ProblemDraft(
            $this->text($request, 'title', 191, '문제 제목'),
            $this->richText($request, 'description', '문제 설명'),
            $this->richText($request, 'input', '입력 설명'),
            $this->richText($request, 'output', '출력 설명'),
            $this->text($request, 'sample_input', self::MAX_CONTENT_BYTES, '예시 입력'),
            $this->text($request, 'sample_output', self::MAX_CONTENT_BYTES, '예시 출력'),
            $this->richText($request, 'hint', '힌트'),
            $this->text($request, 'source', 191, '출처'),
            $this->strictInt($request->bodyString('time_limit'), '시간 제한'),
            $this->strictInt($request->bodyString('memory_limit'), '메모리 제한'),
            $request->bodyBool('special_judge') || $visualMode->isEnabled(),
            $visibility,
            $this->text($request, 'forbidden_code', self::MAX_CONTENT_BYTES, '제출 금지어'),
            $this->text($request, 'prefix_code', ProblemDraft::MAX_WRAPPER_CODE_BYTES, '앞 코드'),
            $mode === CodeEntryMode::CodeOrdering ? '' : $this->text($request, 'starter_code', ProblemDraft::MAX_WRAPPER_CODE_BYTES, '시작 코드'),
            $this->text($request, 'suffix_code', ProblemDraft::MAX_WRAPPER_CODE_BYTES, '뒤 코드'),
            $languageMask,
            $this->text($request, 'credits', self::MAX_CONTENT_BYTES, '크레딧')
        );

        return new ProblemEditCommand(
            $problem,
            $difficulty,
            $topicKeys,
            $mode,
            $mode === CodeEntryMode::CodeCompletion
                ? $request->bodyString('code_completion_schema')
                : null,
            $this->text($request, 'admin_notes', self::MAX_CONTENT_BYTES, '관리자 메모'),
            $generationMethod,
            $difficultySource,
            $visualMode,
            $orderingSchema,
            $orderingHash
        );
    }

    private function text(
        HttpRequest $request,
        string $key,
        int $maxBytes,
        string $label
    ): string {
        $value = str_replace(["\r\n", "\r"], "\n", $request->bodyString($key));
        if (preg_match('//u', $value) !== 1) {
            throw new DomainException($label . '은 UTF-8 문자여야 합니다.');
        }
        if (strlen($value) > $maxBytes) {
            throw new DomainException($label . '의 입력 길이를 줄여 주세요.');
        }

        return $value;
    }

    private function richText(
        HttpRequest $request,
        string $key,
        string $label
    ): string {
        $value = $this->text($request, $key, self::MAX_CONTENT_BYTES, $label);
        if (! function_exists('wp_kses_post')) {
            return $value;
        }

        $sanitized = wp_kses_post($value);
        if (strlen($sanitized) > self::MAX_CONTENT_BYTES) {
            throw new DomainException($label . '의 입력 길이를 줄여 주세요.');
        }

        return $sanitized;
    }

    private function strictInt(string $value, string $label): int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new DomainException($label . '은 정수로 입력해 주세요.');
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }
        $items = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }
}
