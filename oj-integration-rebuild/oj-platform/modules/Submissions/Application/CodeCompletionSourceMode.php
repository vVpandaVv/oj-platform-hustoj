<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceMode;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourcePayload;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceRequest;
use DomainException;
use JsonException;

final class CodeCompletionSourceMode implements SubmissionSourceMode
{
    private const TOKEN_PATTERN = '/\{\{OJ_BLANK:([a-zA-Z0-9_-]{1,64})\}\}/';

    public function __construct(
        private readonly SubmissionLanguageCatalog $languages
    ) {
    }

    public function key(): string
    {
        return 'code_completion';
    }

    public function build(
        SubmissionSourceRequest $request
    ): SubmissionSourcePayload {
        $policy = $request->policy();
        $expectedHash = $policy->completionHash();
        if (
            $policy->entryMode() !== 'code_completion'
            || $expectedHash === null
            || ! hash_equals($expectedHash, $request->templateHash())
        ) {
            throw new DomainException(
                '문제의 코드 빈칸이 변경되었습니다. 화면을 새로 고친 뒤 다시 작성해 주세요.'
            );
        }
        $schema = $this->schema($policy->completionSchema());
        $answers = $request->completionAnswers();
        $blanks = $schema['blanks'];
        $expectedIds = array_column($blanks, 'id');
        $actualIds = array_keys($answers);
        sort($expectedIds, SORT_STRING);
        sort($actualIds, SORT_STRING);
        if ($expectedIds !== $actualIds) {
            throw new DomainException('모든 코드 빈칸에 답을 입력해 주세요.');
        }

        $byId = [];
        foreach ($blanks as $blank) {
            $id = $blank['id'];
            $answer = $this->normalize((string) $answers[$id]);
            if (str_contains($answer, "\0") || trim($answer) === '') {
                throw new DomainException($blank['label'] . ' 빈칸의 답을 확인해 주세요.');
            }
            if ($blank['kind'] === 'inline' && preg_match('/\R/u', $answer) === 1) {
                throw new DomainException($blank['label'] . ' 빈칸에는 한 줄만 입력할 수 있습니다.');
            }
            if (strlen($answer) > $blank['max_bytes']) {
                throw new DomainException($blank['label'] . ' 빈칸의 최대 길이를 초과했습니다.');
            }
            $byId[$id] = $answer;
        }

        [$source, $ranges] = $this->reconstruct(
            $this->normalize($policy->starterCode()),
            $byId
        );
        $languageId = $this->languages->idForKey($schema['language_key']);

        return new SubmissionSourcePayload(
            $this->key(),
            $languageId,
            $source,
            $source,
            $ranges,
            false,
            ['template_hash' => $expectedHash]
        );
    }

    /**
     * @return array{
     *   language_key: string,
     *   blanks: list<array{id: string, label: string, kind: string, max_bytes: int}>
     * }
     */
    private function schema(?string $schemaJson): array
    {
        try {
            $decoded = json_decode(
                (string) $schemaJson,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $error) {
            throw new DomainException('코드 빈칸 설정을 읽을 수 없습니다.', 0, $error);
        }
        if (
            ! is_array($decoded)
            || trim((string) ($decoded['language_key'] ?? '')) === ''
            || ! is_array($decoded['blanks'] ?? null)
        ) {
            throw new DomainException('코드 빈칸 설정이 올바르지 않습니다.');
        }
        $blanks = [];
        foreach ($decoded['blanks'] as $blank) {
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
                throw new DomainException('코드 빈칸 설정이 올바르지 않습니다.');
            }
            $blanks[] = [
                'id' => $id,
                'label' => $label,
                'kind' => $kind,
                'max_bytes' => $maxBytes,
            ];
        }
        if (count($blanks) < 1 || count($blanks) > 30) {
            throw new DomainException('코드 빈칸 수가 올바르지 않습니다.');
        }

        return [
            'language_key' => trim((string) $decoded['language_key']),
            'blanks' => $blanks,
        ];
    }

    /**
     * @param array<string, string> $answers
     * @return array{0: string, 1: list<array{start: int, length: int}>}
     */
    private function reconstruct(string $template, array $answers): array
    {
        preg_match_all(
            self::TOKEN_PATTERN,
            $template,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        $tokens = $matches[0] ?? [];
        $ids = $matches[1] ?? [];
        if (count($tokens) !== count($answers)) {
            throw new DomainException('코드 빈칸 템플릿과 답안이 일치하지 않습니다.');
        }

        $source = '';
        $ranges = [];
        $cursor = 0;
        foreach ($tokens as $index => $tokenMatch) {
            $token = (string) ($tokenMatch[0] ?? '');
            $position = (int) ($tokenMatch[1] ?? -1);
            $id = (string) ($ids[$index][0] ?? '');
            if ($position < $cursor || ! array_key_exists($id, $answers)) {
                throw new DomainException('코드 빈칸 템플릿을 복원할 수 없습니다.');
            }
            $source .= substr($template, $cursor, $position - $cursor);
            $answer = $answers[$id];
            $ranges[] = [
                'start' => strlen($source),
                'length' => strlen($answer),
            ];
            $source .= $answer;
            $cursor = $position + strlen($token);
        }
        $source .= substr($template, $cursor);

        return [$source, $ranges];
    }

    private function normalize(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }
}
