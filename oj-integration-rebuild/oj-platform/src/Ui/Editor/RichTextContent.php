<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Editor;

use RuntimeException;

final class RichTextContent
{
    public function forStorage(string $value): string
    {
        if (! $this->containsHtml($value)) {
            return $value;
        }
        if (! function_exists('wp_kses_post')) {
            throw new RuntimeException('HTML 문서 저장을 준비하지 못했습니다.');
        }

        return (string) wp_kses_post($value);
    }

    public function render(string $value): string
    {
        if ($this->containsHtml($value) && function_exists('wp_kses_post')) {
            return (string) wp_kses_post($value);
        }

        return nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    private function containsHtml(string $value): bool
    {
        return preg_match('/<[a-z][^>]*>/i', $value) === 1;
    }
}
