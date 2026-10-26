<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemXmlImportPlan
{
    /**
     * @param array<int|string, mixed> $baselines
     */
    public function __construct(
        private readonly ProblemXmlPreview $preview,
        private readonly ProblemXmlDocument $document,
        private readonly array $baselines
    ) {
        foreach ($this->document->items() as $item) {
            $baseline = $this->baselines[$item->index()] ?? null;
            if (
                ! is_array($baseline)
                || ! in_array(
                    $baseline['operation'] ?? null,
                    [ProblemXmlPreviewRow::CREATE, ProblemXmlPreviewRow::UPDATE],
                    true
                )
                || (
                    ($baseline['state_hash'] ?? null) !== 'missing'
                    && preg_match(
                        '/^[a-f0-9]{64}$/',
                        is_string($baseline['state_hash'] ?? null)
                            ? $baseline['state_hash']
                            : ''
                    ) !== 1
                )
                || preg_match(
                    '/^[a-f0-9]{64}$/',
                    is_string($baseline['content_hash'] ?? null)
                        ? $baseline['content_hash']
                        : ''
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    '문제 XML 가져오기 기준 상태가 올바르지 않습니다.'
                );
            }
        }
    }

    public function preview(): ProblemXmlPreview
    {
        return $this->preview;
    }

    public function document(): ProblemXmlDocument
    {
        return $this->document;
    }

    public function fallbackTopicKey(): string
    {
        return $this->preview->fallbackTopicKey();
    }

    public function allowOverwrite(): bool
    {
        return $this->preview->allowOverwrite();
    }

    public function includeMetadata(): bool
    {
        return $this->preview->includeMetadata();
    }

    public function includeTestData(): bool
    {
        return $this->preview->includeTestData();
    }

    /**
     * @return array{operation:string,state_hash:string,content_hash:string}
     */
    public function baselineFor(int $index): array
    {
        /** @var array{operation:string,state_hash:string,content_hash:string} */
        return $this->baselines[$index];
    }
}
