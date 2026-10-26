<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

final class ProblemTestDataConfiguration
{
    public const DEFAULT_ROOT = '/home/judge/data';
    public const DEFAULT_C_COMPILER = '/usr/bin/gcc';
    public const DEFAULT_CPP_COMPILER = '/usr/bin/g++';
    public const DEFAULT_LIMIT_EXECUTABLE = '/usr/bin/prlimit';
    public const DEFAULT_SANDBOX_EXECUTABLE = '/usr/bin/bwrap';

    public function __construct(
        private readonly string $root,
        private readonly string $cCompiler,
        private readonly string $cppCompiler,
        private readonly string $limitExecutable,
        private readonly string $sandboxExecutable
    ) {
    }

    public static function from(mixed $value): self
    {
        $settings = is_array($value) ? $value : [];
        $compilers = is_array($settings['spj_compilers'] ?? null)
            ? $settings['spj_compilers']
            : [];

        return new self(
            self::value($settings['root'] ?? null, self::DEFAULT_ROOT),
            self::value($compilers['c'] ?? null, self::DEFAULT_C_COMPILER),
            self::value($compilers['cpp'] ?? null, self::DEFAULT_CPP_COMPILER),
            self::value(
                $settings['spj_limit_executable'] ?? null,
                self::DEFAULT_LIMIT_EXECUTABLE
            ),
            self::value(
                $settings['spj_sandbox_executable'] ?? null,
                self::DEFAULT_SANDBOX_EXECUTABLE
            )
        );
    }

    public function root(): string
    {
        return $this->root;
    }

    public function cCompiler(): string
    {
        return $this->cCompiler;
    }

    public function cppCompiler(): string
    {
        return $this->cppCompiler;
    }

    public function limitExecutable(): string
    {
        return $this->limitExecutable;
    }

    public function sandboxExecutable(): string
    {
        return $this->sandboxExecutable;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'root' => $this->root,
            'spj_compilers' => [
                'c' => $this->cCompiler,
                'cpp' => $this->cppCompiler,
            ],
            'spj_limit_executable' => $this->limitExecutable,
            'spj_sandbox_executable' => $this->sandboxExecutable,
        ];
    }

    private static function value(mixed $value, string $default): string
    {
        $normalized = is_scalar($value) ? trim((string) $value) : '';

        return $normalized === '' ? $default : $normalized;
    }
}
