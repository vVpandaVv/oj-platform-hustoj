<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;
use JsonException;

final class ProblemNumberMap
{
    /**
     * @var array<int, int>
     */
    private array $pairs;

    /**
     * @param array<int, int> $pairs
     */
    public function __construct(array $pairs)
    {
        if ($pairs === [] || count($pairs) > 200) {
            throw new InvalidArgumentException('문제 번호 매핑은 1개부터 200개까지 허용합니다.');
        }
        $normalized = [];
        $targets = [];
        foreach ($pairs as $source => $target) {
            if (
                ! is_int($source)
                || ! is_int($target)
                || $source < 1
                || $target < 1
                || $source === $target
                || isset($normalized[$source])
                || isset($targets[$target])
            ) {
                throw new InvalidArgumentException(
                    '문제 번호 매핑은 중복 없는 서로 다른 양의 정수 쌍이어야 합니다.'
                );
            }
            $normalized[$source] = $target;
            $targets[$target] = true;
        }
        ksort($normalized, SORT_NUMERIC);
        $this->pairs = $normalized;
    }

    public static function range(
        int $sourceStart,
        int $sourceEnd,
        int $targetStart
    ): self {
        if (
            $sourceStart < 1
            || $sourceEnd < $sourceStart
            || $targetStart < 1
            || ($sourceEnd - $sourceStart + 1) > 200
        ) {
            throw new InvalidArgumentException('연속 문제 번호 범위가 올바르지 않습니다.');
        }
        $pairs = [];
        for ($source = $sourceStart; $source <= $sourceEnd; ++$source) {
            $pairs[$source] = $targetStart + ($source - $sourceStart);
        }

        return new self($pairs);
    }

    /**
     * @return array<int, int>
     */
    public function pairs(): array
    {
        return $this->pairs;
    }

    /**
     * @return list<int>
     */
    public function sourceIds(): array
    {
        return array_keys($this->pairs);
    }

    /**
     * @return list<int>
     */
    public function targetIds(): array
    {
        return array_values($this->pairs);
    }

    public function count(): int
    {
        return count($this->pairs);
    }

    public function inverse(): self
    {
        $inverse = [];
        foreach ($this->pairs as $source => $target) {
            $inverse[$target] = $source;
        }

        return new self($inverse);
    }

    public function hash(): string
    {
        try {
            return hash(
                'sha256',
                json_encode(
                    $this->pairs,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                )
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException('문제 번호 매핑을 직렬화하지 못했습니다.', 0, $error);
        }
    }
}
