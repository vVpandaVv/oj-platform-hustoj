<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Contract;

final class JudgeResult
{
    public function __construct(
        private readonly int $code,
        private readonly string $key,
        private readonly string $label,
        private readonly string $tone,
        private readonly bool $processing
    ) {
    }

    public function code(): int
    {
        return $this->code;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function tone(): string
    {
        return $this->tone;
    }

    public function isProcessing(): bool
    {
        return $this->processing;
    }

    /**
     * 채점 결과마다 다른 색을 쓰기 위한 짧은 이름. 색 구분이 필요 없는 결과는 빈 문자열.
     */
    public function verdict(): string
    {
        if ($this->processing) {
            return $this->key === 'OJ_MC' ? 'manual' : 'pending';
        }

        return [
            'OJ_AC' => 'ac',
            'OJ_PE' => 'pe',
            'OJ_WA' => 'wa',
            'OJ_TL' => 'tle',
            'OJ_ML' => 'mle',
            'OJ_OL' => 'ole',
            'OJ_RE' => 're',
            'OJ_CE' => 'ce',
        ][$this->key] ?? '';
    }

    /**
     * 배지 class 목록. 기본 톤에 결과별 색 class를 덧붙인다.
     */
    public function badgeClass(string $base = 'ojp-badge'): string
    {
        $tone = in_array($this->tone, ['success', 'danger', 'warning', 'info'], true)
            ? $this->tone
            : 'neutral';
        $verdict = $this->verdict();

        return $base . ' ' . $base . '--' . $tone
            . ($verdict === '' ? '' : ' ojp-verdict ojp-verdict--' . $verdict);
    }
}
