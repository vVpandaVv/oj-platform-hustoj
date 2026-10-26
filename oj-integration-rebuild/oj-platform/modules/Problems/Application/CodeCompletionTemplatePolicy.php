<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use DomainException;
use JsonException;

final class CodeCompletionTemplatePolicy
{
    private const TOKEN_PATTERN = '/\{\{OJ_BLANK:([a-zA-Z0-9_-]{1,64})\}\}/';

    /**
     * @return array{schema: string, hash: string}
     */
    public function prepare(
        string $template,
        string $schemaJson,
        ?int $languageMask
    ): array {
        $template = $this->normalize($template);
        try {
            $decoded = json_decode($schemaJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new DomainException('빈칸 완성 스키마가 올바른 JSON이 아닙니다.', 0, $error);
        }
        if (! is_array($decoded)) {
            throw new DomainException('빈칸 완성 스키마가 올바르지 않습니다.');
        }
        $languageKey = trim((string) ($decoded['language_key'] ?? ''));
        $blanks = $decoded['blanks'] ?? null;
        if ($languageKey === '' || ! is_array($blanks)) {
            throw new DomainException('빈칸 완성 언어와 빈칸 목록이 필요합니다.');
        }
        if (count($blanks) < 1 || count($blanks) > 30) {
            throw new DomainException('코드 빈칸은 1개부터 30개까지 만들 수 있습니다.');
        }

        $canonicalBlanks = [];
        foreach (array_values($blanks) as $blank) {
            if (! is_array($blank)) {
                throw new DomainException('코드 빈칸 설정이 올바르지 않습니다.');
            }
            $id = trim((string) ($blank['id'] ?? ''));
            $label = trim((string) ($blank['label'] ?? ''));
            $kind = (string) ($blank['kind'] ?? '');
            $maxBytes = (int) ($blank['max_bytes'] ?? 0);
            if (
                preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1
                || $label === ''
                || ! in_array($kind, ['inline', 'block'], true)
                || $maxBytes < 1
                || $maxBytes > 65536
            ) {
                throw new DomainException('코드 빈칸의 ID·이름·형식·최대 길이를 확인해 주세요.');
            }
            if (isset($canonicalBlanks[$id])) {
                throw new DomainException('코드 빈칸 ID는 중복될 수 없습니다.');
            }
            $canonicalBlanks[$id] = [
                'id' => $id,
                'label' => $label,
                'kind' => $kind,
                'max_bytes' => $maxBytes,
            ];
        }
        preg_match_all(self::TOKEN_PATTERN, $template, $matches);
        $tokens = $matches[1] ?? [];
        if (
            count($tokens) !== count($canonicalBlanks)
            || count(array_unique($tokens)) !== count($tokens)
            || array_diff($tokens, array_keys($canonicalBlanks)) !== []
            || array_diff(array_keys($canonicalBlanks), $tokens) !== []
        ) {
            throw new DomainException('코드 템플릿 토큰과 빈칸 설정이 일치하지 않습니다.');
        }

        ksort($canonicalBlanks);
        $canonical = [
            'language_key' => $languageKey,
            'blanks' => array_values($canonicalBlanks),
        ];
        $encoded = json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return [
            'schema' => $encoded,
            'hash' => hash(
                'sha256',
                $template . "\n" . $encoded . "\n" . (string) $languageMask
            ),
        ];
    }

    private function normalize(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }
}
