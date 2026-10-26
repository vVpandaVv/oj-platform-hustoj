<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Settings;

use CodingPanda\OjPlatform\Modules\Problems\Contract\SpjCompilerProfileProvider;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTestDataConfiguration;
use CodingPanda\OjPlatform\Modules\Problems\Domain\SpjCompilerProfile;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use DomainException;

final class SettingsSpjCompilerProfileProvider implements SpjCompilerProfileProvider
{
    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function forSource(string $sourceName): SpjCompilerProfile
    {
        $extension = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
        $snapshot = $this->settings->read('problem_test_data', []);
        $configuration = ProblemTestDataConfiguration::from($snapshot->value());
        if ($extension === 'c') {
            $compiler = $this->approvedExecutable(
                $configuration->cCompiler(),
                ['gcc', 'clang']
            );
            $profile = new SpjCompilerProfile(
                'c11',
                'C11',
                $this->approvedExecutable(
                    $configuration->limitExecutable(),
                    ['prlimit']
                ),
                $compiler,
                ['-std=c11', '-O2', '-pipe', '-s']
            );
        } elseif (in_array($extension, ['cc', 'cpp'], true)) {
            $compiler = $this->approvedExecutable(
                $configuration->cppCompiler(),
                ['g++', 'clang++']
            );
            $profile = new SpjCompilerProfile(
                'cpp17',
                'C++17',
                $this->approvedExecutable(
                    $configuration->limitExecutable(),
                    ['prlimit']
                ),
                $compiler,
                ['-std=c++17', '-O2', '-pipe', '-s']
            );
        } else {
            throw new DomainException('SPJ 소스는 spj.c, spj.cc, spj.cpp만 허용합니다.');
        }
        return $profile;
    }

    /**
     * @param list<string> $allowedNames
     */
    private function approvedExecutable(
        string $configured,
        array $allowedNames
    ): string {
        $executable = realpath($configured);
        if (
            $executable === false
            || ! str_starts_with($executable, '/usr/')
            || ! in_array(basename($configured), $allowedNames, true)
            || ! is_file($executable)
            || ! is_executable($executable)
        ) {
            throw new DomainException(
                '승인된 /usr 경로의 prlimit와 C/C++ 컴파일러를 설정해 주세요.'
            );
        }

        return $executable;
    }
}
