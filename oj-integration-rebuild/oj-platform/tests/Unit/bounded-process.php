<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Process {
    function proc_open(array $command, array $descriptors, array &$pipes, string $directory, array $environment, array $options): mixed
    {
        unset($command, $descriptors, $directory, $environment, $options);
        foreach (['stdin', 'stdout', 'stderr'] as $index => $name) {
            $pipes[$index] = fopen('php://temp', 'w+b');
            $GLOBALS['bounded_process_pipes'][(int) $pipes[$index]] = $name;
        }
        return fopen('php://temp', 'w+b');
    }

    function stream_get_contents(mixed $pipe, ?int $length = null): string
    {
        $GLOBALS['bounded_process_read_lengths'][] = $length;
        $name = $GLOBALS['bounded_process_pipes'][(int) $pipe];
        $contents = array_shift($GLOBALS['bounded_process_reads'][$name]) ?? '';
        if ($length !== null && strlen($contents) > $length) {
            array_unshift($GLOBALS['bounded_process_reads'][$name], substr($contents, $length));
            return substr($contents, 0, $length);
        }
        return $contents;
    }

    function proc_get_status(mixed $process): array
    {
        unset($process);
        return ['running' => false, 'exitcode' => 0];
    }

    function proc_terminate(mixed $process, int $signal): bool
    {
        unset($process, $signal);
        ++$GLOBALS['bounded_process_terminations'];
        return true;
    }

    function proc_close(mixed $process): int
    {
        fclose($process);
        return 0;
    }
}

namespace {
    use CodingPanda\OjPlatform\Kernel\Autoloader;
    use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Process\NativeBoundedProcessRunner;

    $pluginRoot = dirname(__DIR__, 2);
    require_once $pluginRoot . '/src/Kernel/Autoloader.php';
    Autoloader::register($pluginRoot);
    $boundedAssertions = 0;

    function boundedAssert(bool $condition, string $message): void
    {
        ++$GLOBALS['boundedAssertions'];
        if (! $condition) { throw new RuntimeException($message); }
    }

    $runBoundedFixture = static function (array $stdout, array $stderr): \CodingPanda\OjPlatform\Modules\Problems\Domain\BoundedProcessResult {
        $GLOBALS['bounded_process_pipes'] = [];
        $GLOBALS['bounded_process_reads'] = ['stdout' => $stdout, 'stderr' => $stderr];
        $GLOBALS['bounded_process_read_lengths'] = [];
        $GLOBALS['bounded_process_terminations'] = 0;
        return (new NativeBoundedProcessRunner())->run(['/fixture/compiler'], __DIR__, 1, 1024);
    };

    $lateOutput = $runBoundedFixture(['', str_repeat('x', 1200)], ['', '']);
    boundedAssert($lateOutput->outputLimitExceeded(), '프로세스 종료 직전 남은 출력도 제한 초과로 처리해야 합니다.');
    boundedAssert(! $lateOutput->succeeded(), '종료 코드가 0이어도 출력 제한을 넘으면 성공으로 반환하면 안 됩니다.');
    boundedAssert(strlen($lateOutput->standardOutput()) + strlen($lateOutput->standardError()) <= 1024, 'stdout과 stderr를 합한 보관 출력은 상한 이하여야 합니다.');
    foreach ($GLOBALS['bounded_process_read_lengths'] as $length) {
        boundedAssert(is_int($length) && $length > 0 && $length <= 1025, '파이프는 남은 출력 예산과 초과 판별용 1바이트만 읽어야 합니다.');
    }

    $combinedOutput = $runBoundedFixture([str_repeat('o', 700)], [str_repeat('e', 700)]);
    boundedAssert($combinedOutput->outputLimitExceeded(), 'stdout과 stderr가 개별 상한 이하라도 합계가 넘으면 차단해야 합니다.');
    boundedAssert($GLOBALS['bounded_process_terminations'] === 1, '실행 중 출력 초과를 발견하면 프로세스를 종료해야 합니다.');
    boundedAssert(strlen($combinedOutput->standardOutput()) + strlen($combinedOutput->standardError()) === 1024, '반환할 두 출력도 같은 합산 바이트 예산을 사용해야 합니다.');

    $normalOutput = $runBoundedFixture(['hello'], ['diagnostic']);
    boundedAssert($normalOutput->succeeded() && ! $normalOutput->outputLimitExceeded(), '출력 예산 이내의 정상 실행은 보존해야 합니다.');
    boundedAssert($normalOutput->standardOutput() === 'hello' && $normalOutput->standardError() === 'diagnostic', '정상 stdout과 stderr를 그대로 반환해야 합니다.');
    $exactOutput = $runBoundedFixture(['', str_repeat('x', 1024)], ['', '']);
    boundedAssert($exactOutput->succeeded() && ! $exactOutput->outputLimitExceeded(), '출력 상한과 정확히 같은 바이트 수는 허용해야 합니다.');
    fwrite(STDOUT, sprintf("제한 프로세스 출력 경계 검사 통과: assertion %d개\n", $boundedAssertions));
}
