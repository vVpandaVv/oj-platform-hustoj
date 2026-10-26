<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourcePayload;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionMode;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSettings;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\ValidatedSubmissionSource;
use DomainException;

final class SubmissionSourcePolicyValidator
{
    public function __construct(
        private readonly SubmissionLanguageCatalog $languages,
        private readonly SubmissionSourceComposer $composer
    ) {
    }

    public function validate(
        SubmissionSourcePayload $payload,
        SubmissionCodePolicy $policy,
        SubmissionSettings $settings
    ): ValidatedSubmissionSource {
        $mode = SubmissionMode::tryFrom($payload->mode());
        if ($mode === null) {
            throw new DomainException('코드 작성 방식이 올바르지 않습니다.');
        }
        $this->assertMode($mode, $policy);
        $source = $this->normalize($payload->userSource());
        $storedSource = $this->normalize($payload->storedUserSource());
        $this->assertSource($source, $settings->maxSourceBytes());
        if (strlen($storedSource) > $settings->maxSourceBytes()) {
            throw new DomainException('복원 정보를 포함한 제출 코드가 최대 크기를 초과했습니다.');
        }
        $this->assertLanguage($payload, $policy, $settings);
        $this->assertForbidden(
            $source,
            $payload->controlledRanges(),
            $policy->forbiddenFragments()
        );
        $judgeSource = $this->composer->compose(
            $policy->prefixCode(),
            $source,
            $policy->suffixCode()
        );
        if (strlen($judgeSource) > $settings->maxSourceBytes()) {
            throw new DomainException(
                '문제에서 자동으로 붙는 코드를 포함한 최종 코드가 최대 크기를 초과했습니다.'
            );
        }

        return new ValidatedSubmissionSource(
            $mode,
            $payload->languageId(),
            $source,
            $storedSource,
            $judgeSource,
            $payload->metadata() + ['policy_version' => $policy->policyVersion()]
        );
    }

    private function assertMode(
        SubmissionMode $mode,
        SubmissionCodePolicy $policy
    ): void {
        if (
            $policy->entryMode() === 'code_ordering'
            && $mode !== SubmissionMode::CodeOrdering
        ) {
            throw new DomainException('이 문제는 코드 순서 배열 방식으로만 제출할 수 있습니다.');
        }
        if (
            $policy->entryMode() !== 'code_ordering'
            && $mode === SubmissionMode::CodeOrdering
        ) {
            throw new DomainException('이 문제에는 코드 순서 배열 방식이 설정되어 있지 않습니다.');
        }
        if (
            $policy->entryMode() === 'code_completion'
            && $mode !== SubmissionMode::CodeCompletion
        ) {
            throw new DomainException('이 문제는 코드 빈칸 완성 방식으로만 제출할 수 있습니다.');
        }
        if (
            $policy->entryMode() === 'full_code'
            && $mode === SubmissionMode::CodeCompletion
        ) {
            throw new DomainException('이 문제에는 코드 빈칸 완성 방식이 설정되어 있지 않습니다.');
        }
    }

    private function assertSource(string $source, int $maximumBytes): void
    {
        if (str_contains($source, "\0") || trim($source) === '') {
            throw new DomainException('제출할 코드를 입력해 주세요.');
        }
        $compact = preg_replace('/\s+/u', '', $source);
        if (! is_string($compact) || strlen($compact) < 5) {
            throw new DomainException('제출 코드가 너무 짧습니다.');
        }
        if (strlen($source) > $maximumBytes) {
            throw new DomainException('제출 코드가 최대 크기를 초과했습니다.');
        }
    }

    private function assertLanguage(
        SubmissionSourcePayload $payload,
        SubmissionCodePolicy $policy,
        SubmissionSettings $settings
    ): void {
        if ($payload->allowsLanguageOverride()) {
            if (
                $payload->mode() !== SubmissionMode::Blockly->value
                || $payload->languageId() !== SubmissionLanguageCatalog::BLOCKLY_PYTHON_ID
            ) {
                throw new DomainException('블록 제출 언어 자동 허용 정보가 올바르지 않습니다.');
            }

            return;
        }
        if (! $this->languages->isAllowed(
            $payload->languageId(),
            $settings->disabledLanguageMask(),
            $policy->disabledLanguageMask()
        )) {
            throw new DomainException('이 문제에서 사용할 수 없는 제출 언어입니다.');
        }
    }

    /**
     * @param list<array{start: int, length: int}> $ranges
     * @param list<string> $fragments
     */
    private function assertForbidden(
        string $source,
        array $ranges,
        array $fragments
    ): void {
        foreach ($fragments as $fragment) {
            $offset = 0;
            $fragmentLength = strlen($fragment);
            while (($position = strpos($source, $fragment, $offset)) !== false) {
                if ($this->overlaps($position, $fragmentLength, $ranges)) {
                    throw new DomainException(
                        '제출 코드에 금지어가 포함되어 있습니다: ' . $fragment
                    );
                }
                $offset = $position + 1;
            }
        }
    }

    /**
     * @param list<array{start: int, length: int}> $ranges
     */
    private function overlaps(int $start, int $length, array $ranges): bool
    {
        $end = $start + $length;
        foreach ($ranges as $range) {
            $rangeStart = $range['start'];
            $rangeEnd = $rangeStart + $range['length'];
            if ($start < $rangeEnd && $rangeStart < $end) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }
}
