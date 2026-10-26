<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class SpjCompilerProfile
{
    /**
     * @param list<string> $arguments
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $limitExecutable,
        private readonly string $compilerExecutable,
        private readonly array $arguments
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_]*$/', $this->key) !== 1
            || trim($this->label) === ''
            || ! $this->isAbsolutePath($this->limitExecutable)
            || ! $this->isAbsolutePath($this->compilerExecutable)
            || $this->arguments === []
        ) {
            throw new InvalidArgumentException('SPJ 컴파일러 프로필이 올바르지 않습니다.');
        }
        foreach ($this->arguments as $argument) {
            if (
                ! is_string($argument)
                || $argument === ''
                || str_contains($argument, "\0")
            ) {
                throw new InvalidArgumentException('SPJ 컴파일 인자가 올바르지 않습니다.');
            }
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @return list<string>
     */
    public function command(string $sourceName, string $outputName): array
    {
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $sourceName) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $outputName) !== 1
        ) {
            throw new InvalidArgumentException('SPJ 빌드 파일 이름이 올바르지 않습니다.');
        }

        return [
            $this->limitExecutable,
            '--cpu=15',
            '--as=536870912',
            '--fsize=67108864',
            '--nproc=16',
            '--nofile=128',
            '--',
            $this->compilerExecutable,
            ...$this->arguments,
            $sourceName,
            '-o',
            $outputName,
        ];
    }

    public function compilerExecutable(): string
    {
        return $this->compilerExecutable;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
