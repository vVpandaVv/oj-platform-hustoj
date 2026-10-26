<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

final class SubmissionWrapperCode
{
    private readonly string $visibleCode;
    private readonly string $judgeCode;
    private readonly bool $hasHiddenLines;

    public function __construct(string $source)
    {
        $visibleLines = [];
        $judgeLines = [];
        $hidden = false;
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $source));
        foreach ($lines as $line) {
            if (preg_match('/[ \t]*#hidden[ \t]*$/D', $line, $marker, PREG_OFFSET_CAPTURE) === 1) {
                $hidden = true;
                // Keep executable code and its line number; the marker is metadata, not source.
                $judgeLines[] = substr($line, 0, $marker[0][1]);
            } else {
                $visibleLines[] = $line;
                $judgeLines[] = $line;
            }
        }
        $this->visibleCode = implode("\n", $visibleLines);
        $this->judgeCode = implode("\n", $judgeLines);
        $this->hasHiddenLines = $hidden;
    }

    public function visibleCode(): string
    {
        return $this->visibleCode;
    }

    public function judgeCode(): string
    {
        return $this->judgeCode;
    }

    public function hasHiddenLines(): bool
    {
        return $this->hasHiddenLines;
    }
}
