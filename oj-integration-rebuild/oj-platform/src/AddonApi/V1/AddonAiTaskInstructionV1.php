<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonAiTaskInstructionV1
{
    private readonly string $contractVersion;
    private readonly string $content;
    private readonly string $contentHash;

    public function __construct(string $contractVersion, string $content)
    {
        ValueValidator::safeToken($contractVersion, 'contractVersion', 64);
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        ValueValidator::text($normalized, 'content', 16384, false, true);
        if (strlen($normalized) > 16384 || preg_match('/\S/u', $normalized) !== 1) {
            throw new InvalidArgumentException('Addon API AI 작업 지시문이 올바르지 않습니다.');
        }

        $this->contractVersion = $contractVersion;
        $this->content = $normalized;
        $this->contentHash = hash('sha256', $normalized);
    }

    public function contractVersion(): string { return $this->contractVersion; }
    public function content(): string { return $this->content; }
    public function contentHash(): string { return $this->contentHash; }
}
