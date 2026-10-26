<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Process;

use CodingPanda\OjPlatform\Modules\Problems\Contract\BoundedProcessRunner;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemFileManager;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemSpjCompiler;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SpjCompilerProfileProvider;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\SpjCompileResult;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\ProblemDataWorkspace;
use DomainException;
use RuntimeException;

final class NativeProblemSpjCompiler implements ProblemSpjCompiler
{
    private const MAX_BINARY_BYTES = 67108864;
    private const MAX_LOG_BYTES = 262144;

    public function __construct(
        private readonly ProblemFileManager $files,
        private readonly ProblemDataWorkspace $workspace,
        private readonly SpjCompilerProfileProvider $profiles,
        private readonly BoundedProcessRunner $processes
    ) {
    }

    public function compile(
        ProblemId $problemId,
        string $sourceName,
        string $expectedSourceSha256,
        ?string $expectedBinarySha256,
        ?string $expectedLogSha256
    ): SpjCompileResult {
        if (
            ! in_array($sourceName, ['spj.c', 'spj.cc', 'spj.cpp'], true)
            || preg_match('/^[a-f0-9]{64}$/', $expectedSourceSha256) !== 1
        ) {
            throw new DomainException('SPJ 컴파일 입력이 올바르지 않습니다.');
        }
        $currentSourceHash = $this->workspace->currentHash($problemId, $sourceName);
        if (
            $currentSourceHash === null
            || ! hash_equals($expectedSourceSha256, $currentSourceHash)
        ) {
            throw new DomainException('SPJ 소스가 변경되었습니다. 다시 등록해 주세요.');
        }
        $source = $this->files->read($problemId, $sourceName);
        $profile = $this->profiles->forSource($sourceName);
        $temporaryDirectory = $this->temporaryDirectory();
        try {
            $temporarySource = $temporaryDirectory . DIRECTORY_SEPARATOR . $sourceName;
            $temporaryBinary = $temporaryDirectory . DIRECTORY_SEPARATOR . 'spj';
            if (file_put_contents($temporarySource, $source, LOCK_EX) !== strlen($source)) {
                throw new RuntimeException('SPJ 임시 소스를 기록하지 못했습니다.');
            }
            chmod($temporarySource, 0600);
            $result = $this->processes->run(
                $profile->command($sourceName, 'spj'),
                $temporaryDirectory,
                20,
                self::MAX_LOG_BYTES
            );
            $log = $this->buildLog(
                $profile->label(),
                $result->exitCode(),
                $result->timedOut(),
                $result->outputLimitExceeded(),
                $result->standardOutput(),
                $result->standardError(),
                $temporaryDirectory,
                $profile->compilerExecutable()
            );
            $temporaryLog = $temporaryDirectory . DIRECTORY_SEPARATOR . 'spj-build.log';
            if (file_put_contents($temporaryLog, $log, LOCK_EX) !== strlen($log)) {
                throw new RuntimeException('SPJ 컴파일 로그를 기록하지 못했습니다.');
            }
            if (! $result->succeeded()) {
                $hashes = $this->workspace->replaceMany(
                    $problemId,
                    ['spj-build.log' => $temporaryLog],
                    ['spj-build.log' => $expectedLogSha256],
                    [],
                    [$sourceName => $expectedSourceSha256]
                );
                $code = $result->timedOut()
                    ? 'spj.compile_timeout'
                    : ($result->outputLimitExceeded()
                        ? 'spj.compile_output_limit'
                        : 'spj.compile_error');

                return new SpjCompileResult(
                    false,
                    $profile->key(),
                    null,
                    0,
                    $hashes['spj-build.log'],
                    $code
                );
            }
            $this->assertBinary($temporaryBinary);
            $binarySize = filesize($temporaryBinary);
            if ($binarySize === false) {
                throw new RuntimeException('SPJ 실행 파일 크기를 확인하지 못했습니다.');
            }
            $hashes = $this->workspace->replaceMany(
                $problemId,
                [
                    'spj' => $temporaryBinary,
                    'spj-build.log' => $temporaryLog,
                ],
                [
                    'spj' => $expectedBinarySha256,
                    'spj-build.log' => $expectedLogSha256,
                ],
                [
                    'spj' => 0750,
                    'spj-build.log' => 0640,
                ],
                [$sourceName => $expectedSourceSha256]
            );

            return new SpjCompileResult(
                true,
                $profile->key(),
                $hashes['spj'],
                (int) $binarySize,
                $hashes['spj-build.log'],
                'spj.compiled'
            );
        } finally {
            $this->removeTemporaryDirectory($temporaryDirectory);
        }
    }

    private function assertBinary(string $path): void
    {
        if (! is_file($path) || is_link($path)) {
            throw new DomainException('컴파일러가 SPJ 실행 파일을 만들지 않았습니다.');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_BINARY_BYTES) {
            throw new DomainException('SPJ 실행 파일 크기가 제한을 초과했습니다.');
        }
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            throw new RuntimeException('SPJ 실행 파일 형식을 확인하지 못했습니다.');
        }
        try {
            $signature = fread($handle, 4);
        } finally {
            fclose($handle);
        }
        if (
            ! is_string($signature)
            || (
                $signature !== "\x7FELF"
                && ! str_starts_with($signature, 'MZ')
            )
        ) {
            throw new DomainException('컴파일된 SPJ 실행 파일 형식이 올바르지 않습니다.');
        }
    }

    private function buildLog(
        string $profileLabel,
        int $exitCode,
        bool $timedOut,
        bool $outputLimitExceeded,
        string $stdout,
        string $stderr,
        string $temporaryDirectory,
        string $compilerExecutable
    ): string {
        $output = str_replace(
            [$temporaryDirectory, $compilerExecutable],
            ['[build]', '[compiler]'],
            trim($stdout . "\n" . $stderr)
        );
        $output = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $output) ?? '';
        $header = [
            'profile=' . $profileLabel,
            'exit_code=' . $exitCode,
            'timed_out=' . ($timedOut ? 'yes' : 'no'),
            'output_limit=' . ($outputLimitExceeded ? 'exceeded' : 'ok'),
            'completed_at=' . gmdate('c'),
        ];

        return implode("\n", $header) . "\n\n"
            . substr($output, 0, self::MAX_LOG_BYTES - 256) . "\n";
    }

    private function temporaryDirectory(): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'oj-platform-spj-'
            . bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700, false)) {
            throw new RuntimeException('SPJ 임시 빌드 폴더를 만들지 못했습니다.');
        }

        return $directory;
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        $prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'oj-platform-spj-';
        if (! str_starts_with($directory, $prefix) || ! is_dir($directory)) {
            return;
        }
        $entries = scandir($directory);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (is_file($path) && ! is_link($path)) {
                    unlink($path);
                }
            }
        }
        rmdir($directory);
    }
}
