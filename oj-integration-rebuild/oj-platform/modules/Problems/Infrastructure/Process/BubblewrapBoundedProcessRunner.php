<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Process;

use CodingPanda\OjPlatform\Modules\Problems\Contract\BoundedProcessRunner;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SpjSandboxProfileProvider;
use CodingPanda\OjPlatform\Modules\Problems\Domain\BoundedProcessResult;
use RuntimeException;

final class BubblewrapBoundedProcessRunner implements BoundedProcessRunner
{
    public function __construct(
        private readonly SpjSandboxProfileProvider $profiles,
        private readonly BoundedProcessRunner $host
    ) {
    }

    public function run(
        array $command,
        string $workingDirectory,
        int $timeoutSeconds,
        int $maximumOutputBytes
    ): BoundedProcessResult {
        $directory = realpath($workingDirectory);
        if (
            $directory === false
            || ! is_dir($directory)
            || is_link($workingDirectory)
        ) {
            throw new RuntimeException('SPJ 격리 빌드 폴더가 올바르지 않습니다.');
        }

        return $this->host->run(
            $this->sandboxCommand(
                $this->profiles->current()->executable(),
                $directory,
                $command
            ),
            $directory,
            $timeoutSeconds,
            $maximumOutputBytes
        );
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    private function sandboxCommand(
        string $sandboxExecutable,
        string $workingDirectory,
        array $command
    ): array {
        if ($command === []) {
            throw new RuntimeException('SPJ 격리 명령이 비어 있습니다.');
        }

        return [
            $sandboxExecutable,
            '--die-with-parent',
            '--new-session',
            '--unshare-all',
            '--cap-drop',
            'ALL',
            '--clearenv',
            '--ro-bind',
            '/usr',
            '/usr',
            '--ro-bind-try',
            '/lib',
            '/lib',
            '--ro-bind-try',
            '/lib64',
            '/lib64',
            '--dir',
            '/etc',
            '--ro-bind-try',
            '/etc/ld.so.cache',
            '/etc/ld.so.cache',
            '--proc',
            '/proc',
            '--dev',
            '/dev',
            '--size',
            '134217728',
            '--tmpfs',
            '/tmp',
            '--bind',
            $workingDirectory,
            '/build',
            '--chdir',
            '/build',
            '--setenv',
            'HOME',
            '/tmp',
            '--setenv',
            'PATH',
            '/usr/local/bin:/usr/bin',
            '--setenv',
            'LANG',
            'C.UTF-8',
            '--setenv',
            'LC_ALL',
            'C.UTF-8',
            '--hostname',
            'spj-build',
            '--',
            ...$command,
        ];
    }
}
