<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionWrapperCode;

final class SubmissionSourceComposer
{
    public function compose(
        string $prefixCode,
        string $userSource,
        string $suffixCode
    ): string {
        $parts = array_values(array_filter(
            array_map([$this, 'normalize'], [
                (new SubmissionWrapperCode($prefixCode))->judgeCode(),
                $userSource,
                (new SubmissionWrapperCode($suffixCode))->judgeCode(),
            ]),
            static fn (string $part): bool => $part !== ''
        ));
        if ($parts === []) {
            return '';
        }

        $result = array_shift($parts);
        foreach ($parts as $part) {
            $result = rtrim($result, "\n")
                . "\n"
                . ltrim($part, "\n");
        }

        return $result;
    }

    private function normalize(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }
}
