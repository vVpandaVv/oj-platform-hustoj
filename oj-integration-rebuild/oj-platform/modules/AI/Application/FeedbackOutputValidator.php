<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Application;

use DomainException;

final class FeedbackOutputValidator
{
    /**
     * @param array<string, mixed> $output
     * @return array{
     *   result_understanding: string,
     *   step_hints: list<string>,
     *   review_question: string
     * }
     */
    public function validate(array $output): array
    {
        $understanding = $this->text(
            $output['result_understanding'] ?? null,
            2000
        );
        $question = $this->text(
            $output['review_question'] ?? null,
            1000
        );
        $rawHints = $output['step_hints'] ?? null;
        if (! is_array($rawHints) || $rawHints === [] || count($rawHints) > 5) {
            throw new DomainException('AI 피드백 단계별 힌트 형식이 올바르지 않습니다.');
        }
        $hints = [];
        foreach ($rawHints as $hint) {
            $hints[] = $this->text($hint, 1000);
        }
        $joined = $understanding . "\n" . implode("\n", $hints)
            . "\n" . $question;
        if (
            str_contains($joined, '```')
            || preg_match('/<\\/?(?:script|iframe|object)\\b/i', $joined)
        ) {
            throw new DomainException('AI 피드백에 허용되지 않은 전체 코드 또는 태그가 포함되었습니다.');
        }

        if ($this->containsCopyReadyCode($joined)) {
            throw new DomainException(
                'AI 피드백에 복사 가능한 정답 코드나 수정 패치가 포함되었습니다.'
            );
        }

        return [
            'result_understanding' => $understanding,
            'step_hints' => $hints,
            'review_question' => $question,
        ];
    }

    private function text(mixed $value, int $maximum): string
    {
        if (
            ! is_string($value)
            || trim($value) === ''
            || $this->length($value) > $maximum
        ) {
            throw new DomainException('AI 피드백 응답 형식이 올바르지 않습니다.');
        }

        return trim($value);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }

    private function containsCopyReadyCode(string $value): bool
    {
        if (
            preg_match(
                '~(?:^|\R)\s*(?:#\s*include\b|using\s+namespace\b|'
                    . '(?:public\s+static\s+void|(?:int|void))\s+main\s*\(|'
                    . 'fun\s+main\s*\(|if\s+__name__\s*==\s*'
                    . '(?:\x27|\x22)__main__(?:\x27|\x22)\s*:|<\?php\b)~mi',
                $value
            )
            || preg_match(
                '~(?:^|\R)\s*(?:---|\+\+\+)\s+[ab]/~m',
                $value
            )
        ) {
            return true;
        }

        $codeLines = 0;
        foreach (preg_split('~\R~u', $value) ?: [] as $line) {
            $candidate = trim($line);
            if ($candidate === '') {
                continue;
            }
            if (preg_match('~^[+-]\s?(?=[A-Za-z_#<{])~', $candidate)) {
                $candidate = ltrim(substr($candidate, 1));
            }
            if (preg_match('~[가-힣]~u', $candidate)) {
                continue;
            }
            if (
                preg_match(
                    '~^(?:print|input|printf|scanf|(?:std::)?cout|'
                        . 'console\.log|System\.out\.print(?:ln)?)'
                        . '\s*(?:<<|\().*(?:\)|;)\s*$~i',
                    $candidate
                )
                || preg_match(
                    '~^(?:def|class)\s+[A-Za-z_]\w*\s*[(:]~',
                    $candidate
                )
                || preg_match(
                    '~^(?:import\s+[A-Za-z_]|from\s+[A-Za-z_].*\s+import\b)~',
                    $candidate
                )
                || preg_match(
                    '~^[A-Za-z_]\w*(?:\[[^\]]+\]|\.[A-Za-z_]\w*)*'
                        . '\s*(?:=|\+=|-=|\*=|\/=|//=|%=)\s*[^=].*$~',
                    $candidate
                )
                || preg_match(
                    '~^(?:const|let|var|auto|int|long|double|float|char|'
                        . 'bool|string|String|Scanner)\s+[A-Za-z_]\w*'
                        . '(?:\s*=.*)?;?$~',
                    $candidate
                )
                || preg_match(
                    '~^(?:return|raise|throw|yield)\b.*;?$~',
                    $candidate
                )
            ) {
                return true;
            }
            if (
                preg_match(
                    '~^(?:if|elif|else|for|while|switch|case|try|except|'
                        . 'finally|with)\b.*[:{]\s*$~',
                    $candidate
                )
                || preg_match(
                    '~^(?:return|raise|throw|yield|break|continue)\b.*;?$~',
                    $candidate
                )
                || preg_match('~^[{}]+;?$~', $candidate)
                || preg_match('~;\s*$~', $candidate)
            ) {
                ++$codeLines;
            }
        }

        return $codeLines >= 2;
    }
}
