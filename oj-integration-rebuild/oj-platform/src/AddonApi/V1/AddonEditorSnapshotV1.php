<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonEditorSnapshotV1
{
    public function __construct(
        private readonly string $theme,
        private readonly int $fontSize,
        private readonly bool $available,
        private readonly string $coreUrl,
        private readonly string $baseUrl
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $theme) !== 1
            || ! in_array($fontSize, [14, 16, 18], true)) {
            throw new InvalidArgumentException('편집기 표시 설정이 올바르지 않습니다.');
        }
        foreach ([$coreUrl, $baseUrl] as $url) {
            ValueValidator::text($url, 'editorAssetUrl', 2048, ! $available);
            if ($url !== '' && (preg_match('~^(?:https?://[^/\s]+/|/(?!/))[^\s]*$~D', $url) !== 1
                || str_contains($url, '\\'))) {
                throw new InvalidArgumentException('편집기 자산 주소가 올바르지 않습니다.');
            }
        }
        if (! $available && ($coreUrl !== '' || $baseUrl !== '')) {
            throw new InvalidArgumentException('사용할 수 없는 편집기는 자산 주소를 제공하지 않습니다.');
        }
    }

    public function theme(): string { return $this->theme; }
    public function fontSize(): int { return $this->fontSize; }
    public function isAvailable(): bool { return $this->available; }
    public function coreUrl(): string { return $this->coreUrl; }
    public function baseUrl(): string { return $this->baseUrl; }
}
