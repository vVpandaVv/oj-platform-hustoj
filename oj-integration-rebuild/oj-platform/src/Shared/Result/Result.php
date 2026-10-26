<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Result;

use InvalidArgumentException;
use LogicException;

/**
 * @template TValue
 */
final class Result
{
    /**
     * @param TValue|null $value
     * @param list<string> $errors
     */
    private function __construct(
        private readonly bool $successful,
        private readonly mixed $value,
        private readonly array $errors
    ) {
    }

    /**
     * @template TSuccess
     *
     * @param TSuccess|null $value
     *
     * @return self<TSuccess>
     */
    public static function success(mixed $value = null): self
    {
        return new self(true, $value, []);
    }

    /**
     * @return self<never>
     */
    public static function failure(string ...$errors): self
    {
        $normalized = array_values(array_filter(
            array_map(static fn (string $error): string => trim($error), $errors),
            static fn (string $error): bool => $error !== ''
        ));

        if ($normalized === []) {
            throw new InvalidArgumentException('A failed result requires at least one error.');
        }

        return new self(false, null, $normalized);
    }

    public function isSuccess(): bool
    {
        return $this->successful;
    }

    public function isFailure(): bool
    {
        return ! $this->successful;
    }

    /**
     * @return TValue|null
     */
    public function value(): mixed
    {
        if ($this->isFailure()) {
            throw new LogicException('A failed result does not contain a value.');
        }

        return $this->value;
    }

    /**
     * @template TFallback
     *
     * @param TFallback $fallback
     *
     * @return TValue|TFallback|null
     */
    public function valueOr(mixed $fallback): mixed
    {
        return $this->isSuccess() ? $this->value : $fallback;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
