<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTestDataConfiguration;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use DomainException;

final class ProblemTestDataSettingsService
{
    private const SETTINGS_KEY = 'problem_test_data';

    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function saveRoot(string $root, int $expectedRevision): void
    {
        $snapshot = $this->settings->read(self::SETTINGS_KEY, []);
        $configuration = ProblemTestDataConfiguration::from($snapshot->value());
        $values = $configuration->toArray();
        $values['root'] = $this->approvedRoot($root);

        $this->settings->save(self::SETTINGS_KEY, $values, $expectedRevision);
    }

    public function saveSpj(
        string $cCompiler,
        string $cppCompiler,
        string $limitExecutable,
        string $sandboxExecutable,
        int $expectedRevision
    ): void {
        $snapshot = $this->settings->read(self::SETTINGS_KEY, []);
        $configuration = ProblemTestDataConfiguration::from($snapshot->value());
        $values = $configuration->toArray();
        $values['spj_compilers'] = [
            'c' => $this->approvedExecutable($cCompiler, ['gcc', 'clang']),
            'cpp' => $this->approvedExecutable($cppCompiler, ['g++', 'clang++']),
        ];
        $values['spj_limit_executable'] = $this->approvedExecutable(
            $limitExecutable,
            ['prlimit']
        );
        $values['spj_sandbox_executable'] = $this->approvedExecutable(
            $sandboxExecutable,
            ['bwrap']
        );

        $this->settings->save(self::SETTINGS_KEY, $values, $expectedRevision);
    }

    private function approvedRoot(string $configured): string
    {
        $configured = trim($configured);
        if ($configured === '' || ! $this->isAbsolute($configured)) {
            throw new DomainException('테스트 데이터 루트는 절대 경로여야 합니다.');
        }

        $root = realpath($configured);
        if (
            $root === false
            || $this->normalizedPath($root) === '/'
            || preg_match('/^[A-Za-z]:\/$/', $this->normalizedPath($root)) === 1
            || ! is_dir($root)
            || ! is_readable($root)
            || ! is_writable($root)
        ) {
            throw new DomainException('웹 서버가 읽고 쓸 수 있는 테스트 데이터 폴더를 지정해 주세요.');
        }

        if (defined('ABSPATH')) {
            $wordpressRoot = realpath((string) constant('ABSPATH'));
            if (
                is_string($wordpressRoot)
                && (
                    $this->contains($wordpressRoot, $root)
                    || $this->contains($root, $wordpressRoot)
                )
            ) {
                throw new DomainException('테스트 데이터 폴더는 WordPress 공개 경로 밖에 두어야 합니다.');
            }
        }

        return rtrim($root, '/\\\\');
    }

    /** @param list<string> $allowedNames */
    private function approvedExecutable(string $configured, array $allowedNames): string
    {
        $configured = trim($configured);
        $configuredDirectory = realpath(dirname($configured));
        $executable = realpath($configured);
        if (
            $configuredDirectory === false
            || $executable === false
            || ! str_starts_with(
                $this->normalizedPath($configuredDirectory),
                '/usr/'
            )
            || ! str_starts_with($this->normalizedPath($executable), '/usr/')
            || ! in_array(basename($configured), $allowedNames, true)
            || ! is_file($configured)
            || ! is_executable($configured)
        ) {
            throw new DomainException(
                'SPJ 실행 파일은 승인된 /usr 경로의 gcc, g++, clang, clang++, prlimit, bwrap만 사용할 수 있습니다.'
            );
        }

        return rtrim($configuredDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . basename($configured);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function contains(string $parent, string $child): bool
    {
        $parent = rtrim($this->normalizedPath($parent), '/');
        $child = $this->normalizedPath($child);

        return $child === $parent || str_starts_with($child, $parent . '/');
    }

    private function normalizedPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
