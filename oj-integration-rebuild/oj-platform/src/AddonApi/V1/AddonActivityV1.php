<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonActivityV1
{
    public function __construct(
        private readonly string $key,
        private readonly string $title,
        private readonly string $description,
        private readonly string $url,
        private readonly string $kind,
        private readonly string $sourceLabel,
        private readonly string $contentType = ''
    ) {
        ValueValidator::safeToken($this->key, 'key', 160);
        ValueValidator::label($this->title, 'title');
        ValueValidator::text($this->description, 'description', 240, true);
        ValueValidator::oneOf($this->kind, ['page', 'material', 'game', 'worksheet', 'practice', 'other'], 'kind');
        ValueValidator::label($this->sourceLabel, 'sourceLabel');
        ValueValidator::text($this->contentType, 'contentType', 120, true);
        if ('' !== $this->contentType && preg_match('/\A[a-z0-9.+-]+\/[a-z0-9.+-]+(?:; charset=[A-Za-z0-9._-]+)?\z/i', $this->contentType) !== 1) {
            throw new InvalidArgumentException('Addon API 값이 올바르지 않습니다: contentType');
        }
        if (
            strlen($this->url) > 2048
            || preg_match('~^(?:https?://[^\s]+|/[A-Za-z0-9._\~/%?&=+:#@-]*)$~D', $this->url) !== 1
        ) {
            throw new InvalidArgumentException('Addon API 값이 올바르지 않습니다: url');
        }
    }

    public function key(): string { return $this->key; }
    public function title(): string { return $this->title; }
    public function description(): string { return $this->description; }
    public function url(): string { return $this->url; }
    public function kind(): string { return $this->kind; }
    public function sourceLabel(): string { return $this->sourceLabel; }
    public function contentType(): string { return $this->contentType; }
}
