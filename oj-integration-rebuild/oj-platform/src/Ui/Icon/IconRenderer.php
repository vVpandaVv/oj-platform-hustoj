<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Icon;

use CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator;

final class IconRenderer
{
    private ?string $resolvedSpriteUrl = null;

    public function __construct(
        private readonly string|UiAssetLocator $spriteSource
    )
    {
    }

    public function render(IconName $icon, ?string $accessibleLabel = null): string
    {
        $label = $accessibleLabel === null ? '' : trim($accessibleLabel);
        $attributes = $label === ''
            ? 'aria-hidden="true" focusable="false"'
            : 'role="img" aria-label="' . $this->escape($label) . '"';

        return '<svg class="ojp-icon" ' . $attributes . '>'
            . '<use href="' . $this->escape($this->spriteUrl()) . '#'
            . $this->escape($icon->value) . '"></use></svg>';
    }

    private function spriteUrl(): string
    {
        $this->resolvedSpriteUrl ??= is_string($this->spriteSource)
            ? $this->spriteSource
            : $this->spriteSource->iconSpriteUrl();

        return $this->resolvedSpriteUrl;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
