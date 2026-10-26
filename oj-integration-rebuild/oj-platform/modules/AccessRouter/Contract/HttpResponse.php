<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

use InvalidArgumentException;

final class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly string $body
    ) {
        if ($this->status < 100 || $this->status > 599) {
            throw new InvalidArgumentException('HTTP 응답 상태 코드가 올바르지 않습니다.');
        }

        foreach ($this->headers as $name => $value) {
            if (
                preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1
                || str_contains($value, "\r")
                || str_contains($value, "\n")
            ) {
                throw new InvalidArgumentException('HTTP 응답 헤더가 올바르지 않습니다.');
            }
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self(
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Content-Security-Policy' => "frame-ancestors 'self';",
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
                'Cache-Control' => 'private, no-store',
            ],
            $body
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if (
            ! str_starts_with($location, '/')
            || str_starts_with($location, '//')
        ) {
            throw new InvalidArgumentException('내부 절대 경로로만 이동할 수 있습니다.');
        }

        return new self($status, ['Location' => $location], '');
    }

    public static function download(string $filename, string $contentType, string $body): self
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $filename) !== 1) {
            throw new InvalidArgumentException('다운로드 파일 이름이 올바르지 않습니다.');
        }

        return new self(200, [
            'Content-Type' => $contentType . '; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $body);
    }

    public static function notFound(string $body = ''): self
    {
        return self::html($body, 404);
    }

    public static function unauthorized(string $body = ''): self
    {
        return self::html($body, 401);
    }

    public static function forbidden(string $body = ''): self
    {
        return self::html($body, 403);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }
}
