<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityContentSanitizer;

final class WordPressCommunityContentSanitizer implements CommunityContentSanitizer
{
    public function title(string $value): string
    {
        $value = function_exists('sanitize_text_field')
            ? sanitize_text_field($value)
            : strip_tags($value);

        return trim((string) $value);
    }

    public function body(string $value): string
    {
        return $this->sanitizeBody($value, true);
    }

    private function sanitizeBody(string $value, bool $tables): string
    {
        $allowed = [
            'p' => [],
            'br' => [],
            'strong' => [],
            'em' => [],
            'b' => [],
            'i' => [],
            'u' => [],
            'code' => [],
            'pre' => [],
            'blockquote' => [],
            'ul' => [],
            'ol' => [],
            'li' => [],
            'a' => [
                'href' => true,
                'title' => true,
                'rel' => true,
            ],
        ];
        if ($tables) {
            $allowed += [
                'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
                'span' => [], 'div' => [], 's' => [], 'sub' => [], 'sup' => [], 'hr' => [],
                'font' => ['size' => true],
                'table' => [],
                'caption' => [],
                'thead' => [],
                'tbody' => [],
                'tfoot' => [],
                'tr' => [],
                'th' => ['colspan' => true, 'rowspan' => true, 'scope' => true],
                'td' => ['colspan' => true, 'rowspan' => true],
                'colgroup' => ['span' => true],
                'col' => ['span' => true],
            ];
            foreach ($allowed as &$attributes) {
                $attributes['style'] = true;
            }
            unset($attributes);
        }
        if (function_exists('wp_kses')) {
            $styles = static fn (array $properties): array => array_values(array_intersect($properties, [
                'color', 'background-color', 'font-size', 'font-family', 'font-weight', 'font-style',
                'text-align', 'text-decoration', 'border', 'border-width', 'border-style', 'border-color',
                'border-collapse', 'padding', 'width', 'height', 'vertical-align',
            ]));
            $filterStyles = $tables && function_exists('add_filter') && function_exists('remove_filter');
            if ($filterStyles) {
                add_filter('safe_style_css', $styles);
            }
            try {
                return trim((string) wp_kses($value, $allowed));
            } finally {
                if ($filterStyles) {
                    remove_filter('safe_style_css', $styles);
                }
            }
        }

        return trim(strip_tags($value));
    }

    public function comment(string $value): string
    {
        return $this->sanitizeBody($value, false);
    }
}
