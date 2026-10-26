<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Domain;

/** Assignment-owned guidance, not a project, executable document or learner answer. */
final class PortfolioTemplate
{
    public const MAX_MARKDOWN_BYTES = 32768;
    public const MAX_SECTIONS = 30;

    /** @return array{title:string,markdown:string}|null */
    public static function normalize(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_diff(array_keys($value), ['title', 'markdown']) !== []
            || !is_string($value['title'] ?? null) || !is_string($value['markdown'] ?? null)) {
            throw self::invalid('포트폴리오 양식의 제목과 문항을 입력하세요.');
        }
        $title = trim($value['title']);
        if (preg_match('/^[^\x00-\x1F\x7F<>]{1,120}$/u', $title) !== 1) {
            throw self::invalid('양식 제목은 HTML 없이 1~120자로 입력하세요.');
        }
        $markdown = $value['markdown'];
        if (strlen($markdown) > self::MAX_MARKDOWN_BYTES || preg_match('//u', $markdown) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $markdown) === 1) {
            throw self::invalid('양식은 UTF-8 일반 텍스트로 최대 32KiB까지 저장할 수 있습니다.');
        }
        // Match the upstream form parser: comments are author notes, not questions.
        $markdown = preg_replace('/<!--[\s\S]*?-->/', '', $markdown) ?? '';
        $markdown = trim(str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $markdown) ?? ''));
        if (preg_match('/<\s*\/?\s*[a-zA-Z][^>]*>|<!|<\?/', $markdown) === 1) {
            throw self::invalid('양식에는 HTML을 넣을 수 없습니다. 문항과 Markdown 안내문만 입력하세요.');
        }

        // Upstream treats an unindented ## heading as a question, not ### or #.
        $count = preg_match_all('/^##(?!#)[ \t]*([^\n]*)$/m', $markdown, $matches);
        if ($count === false || $count < 1 || $count > self::MAX_SECTIONS) {
            throw self::invalid('양식에는 ## 제목 형식의 문항을 1~30개 넣으세요.');
        }
        $ids = [];
        foreach ($matches[1] as $heading) {
            $question = trim($heading);
            if (preg_match('/\s*\{#([a-zA-Z0-9_-]+)\}\s*$/', $question, $id) === 1) {
                if (strlen($id[1]) > 200 || in_array($id[1], ['__proto__', 'constructor', 'prototype'], true)) {
                    throw self::invalid('문항 ID는 200자 이하의 일반 식별자를 사용하세요.');
                }
                if (isset($ids[$id[1]])) {
                    throw self::invalid('문항 ID가 중복됩니다. 각 문항에 서로 다른 ID를 사용하세요.');
                }
                $ids[$id[1]] = true;
                $question = trim(substr($question, 0, -strlen($id[0])));
            }
            if (preg_match('/^.{1,200}$/u', $question) !== 1) {
                throw self::invalid('각 문항 제목은 1~200자로 입력하세요.');
            }
        }
        if (strlen($markdown . "\n") > self::MAX_MARKDOWN_BYTES) {
            throw self::invalid('양식은 최대 32KiB까지 저장할 수 있습니다.');
        }
        return ['title' => $title, 'markdown' => $markdown . "\n"];
    }

    private static function invalid(string $message): Problem
    {
        return new Problem('OJ_ML_PORTFOLIO_TEMPLATE_INVALID', $message, 400);
    }
}
