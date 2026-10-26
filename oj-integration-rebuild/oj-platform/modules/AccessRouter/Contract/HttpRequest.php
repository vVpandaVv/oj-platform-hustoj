<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

use InvalidArgumentException;

final class HttpRequest
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, UploadedFile> $files
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly array $files = []
    ) {
        if (preg_match('/^[A-Z]+$/', $this->method) !== 1) {
            throw new InvalidArgumentException('HTTP 메서드 형식이 올바르지 않습니다.');
        }

        if (! str_starts_with($this->path, '/')) {
            throw new InvalidArgumentException('요청 경로는 /로 시작해야 합니다.');
        }
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function queryString(string $key, string $default = ''): string
    {
        return $this->scalarString($this->query[$key] ?? $default, $default);
    }

    public function bodyString(string $key, string $default = ''): string
    {
        return $this->scalarString($this->body[$key] ?? $default, $default);
    }

    public function bodyBool(string $key): bool
    {
        return in_array(
            $this->bodyString($key),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    public function bodyInt(string $key, int $default = 0): int
    {
        $value = $this->body[$key] ?? $default;

        return is_scalar($value) && preg_match('/^-?\d+$/', (string) $value) === 1
            ? (int) $value
            : $default;
    }

    /**
     * @return list<int>
     */
    public function bodyIntList(string $key): array
    {
        $value = $this->body[$key] ?? [];
        if (! is_array($value)) {
            $value = [$value];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_scalar($item) && preg_match('/^\d+$/', (string) $item) === 1) {
                $items[] = (int) $item;
            }
        }

        return array_values(array_unique($items));
    }

    public function header(string $name): ?string
    {
        $normalized = strtolower($name);

        return $this->headers[$normalized] ?? null;
    }

    public function file(string $key): ?UploadedFile
    {
        $file = $this->files[$key] ?? null;

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    private function scalarString(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
