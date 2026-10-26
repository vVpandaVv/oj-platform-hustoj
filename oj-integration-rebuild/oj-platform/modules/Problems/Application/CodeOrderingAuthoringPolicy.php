<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionWrapperCode;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use DomainException;

/** Keep fixed Parsons pieces submittable under the current site byte limit. */
final class CodeOrderingAuthoringPolicy
{
    public function __construct(private readonly ?SettingsManager $settings = null)
    {
    }

    public function assertFits(string $source, string $prefixCode = '', string $suffixCode = ''): void
    {
        $rules = $this->settings?->read('submission_rules', [])->value();
        $rules = is_array($rules) ? $rules : [];
        $maximum = max(1, min(60, (int) ($rules['max_source_kb'] ?? 60))) * 1024;
        $source = $this->normalize($source);
        if (strlen($source) > $maximum) {
            throw new DomainException('배열할 완성 코드가 현재 제출 코드 제한(' . ($maximum / 1024) . 'KB)을 초과합니다.');
        }
        $parts = array_values(array_filter([
            $this->normalize((new SubmissionWrapperCode($prefixCode))->judgeCode()),
            $source,
            $this->normalize((new SubmissionWrapperCode($suffixCode))->judgeCode()),
        ], static fn (string $part): bool => $part !== ''));
        $judgeSource = array_shift($parts) ?? '';
        foreach ($parts as $part) {
            $judgeSource = rtrim($judgeSource, "\n") . "\n" . ltrim($part, "\n");
        }
        if (strlen($judgeSource) > $maximum) {
            throw new DomainException('앞·뒤 코드를 포함한 배열 코드가 현재 제출 코드 제한(' . ($maximum / 1024) . 'KB)을 초과합니다.');
        }
    }

    private function normalize(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }
}
