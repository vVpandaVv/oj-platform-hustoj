<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Process;

use CodingPanda\OjPlatform\Modules\Problems\Contract\BoundedProcessRunner;
use CodingPanda\OjPlatform\Modules\Problems\Domain\BoundedProcessResult;
use RuntimeException;

final class NativeBoundedProcessRunner implements BoundedProcessRunner
{
    public function run(
        array $command,
        string $workingDirectory,
        int $timeoutSeconds,
        int $maximumOutputBytes
    ): BoundedProcessResult {
        if (
            $command === []
            || ! is_dir($workingDirectory)
            || $timeoutSeconds < 1
            || $timeoutSeconds > 60
            || $maximumOutputBytes < 1024
            || $maximumOutputBytes > 1048576
        ) {
            throw new RuntimeException('제한 프로세스 실행 조건이 올바르지 않습니다.');
        }
        foreach ($command as $argument) {
            if (! is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                throw new RuntimeException('제한 프로세스 명령 인자가 올바르지 않습니다.');
            }
        }
        if (! function_exists('proc_open')) {
            throw new RuntimeException('서버에서 제한 프로세스를 실행할 수 없습니다.');
        }

        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
            [
                'PATH' => '/usr/local/bin:/usr/bin:/bin',
                'LANG' => 'C.UTF-8',
                'LC_ALL' => 'C.UTF-8',
            ],
            ['bypass_shell' => true]
        );
        if (! is_resource($process)) {
            throw new RuntimeException('제한 프로세스를 시작하지 못했습니다.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $startedAt = microtime(true);
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $outputLimitExceeded = false;
        $exitCode = -1;
        try {
            while (true) {
                $stdout .= $this->readAvailable($pipes[1], $maximumOutputBytes + 1 - strlen($stdout) - strlen($stderr));
                $stderr .= $this->readAvailable($pipes[2], $maximumOutputBytes + 1 - strlen($stdout) - strlen($stderr));
                if (strlen($stdout) + strlen($stderr) > $maximumOutputBytes) {
                    $outputLimitExceeded = true;
                    proc_terminate($process, 9);
                    break;
                }
                $status = proc_get_status($process);
                if (! is_array($status)) {
                    throw new RuntimeException('제한 프로세스 상태를 읽지 못했습니다.');
                }
                if (! (bool) ($status['running'] ?? false)) {
                    $exitCode = (int) ($status['exitcode'] ?? -1);
                    break;
                }
                if ((microtime(true) - $startedAt) >= $timeoutSeconds) {
                    $timedOut = true;
                    proc_terminate($process, 9);
                    break;
                }
                usleep(20000);
            }
            $stdout .= $this->readAvailable($pipes[1], $maximumOutputBytes + 1 - strlen($stdout) - strlen($stderr));
            $stderr .= $this->readAvailable($pipes[2], $maximumOutputBytes + 1 - strlen($stdout) - strlen($stderr));
            $outputLimitExceeded = $outputLimitExceeded
                || strlen($stdout) + strlen($stderr) > $maximumOutputBytes;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedExitCode = proc_close($process);
            if ($exitCode < 0 && is_int($closedExitCode)) {
                $exitCode = $closedExitCode;
            }
        }

        return new BoundedProcessResult(
            $exitCode,
            substr($stdout, 0, $maximumOutputBytes),
            substr($stderr, 0, max(0, $maximumOutputBytes - strlen($stdout))),
            $timedOut,
            $outputLimitExceeded
        );
    }

    private function readAvailable(mixed $pipe, int $remainingBytes): string
    {
        if ($remainingBytes < 1) {
            return '';
        }
        $contents = stream_get_contents($pipe, $remainingBytes);

        return is_string($contents) ? $contents : '';
    }
}
