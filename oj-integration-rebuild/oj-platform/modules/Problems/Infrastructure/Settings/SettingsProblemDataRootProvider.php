<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Settings;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDataRootProvider;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTestDataConfiguration;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use RuntimeException;

final class SettingsProblemDataRootProvider implements ProblemDataRootProvider
{
    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function root(): string
    {
        $snapshot = $this->settings->read('problem_test_data', []);
        $root = ProblemTestDataConfiguration::from($snapshot->value())->root();
        if ($root === '' || ! $this->isAbsolute($root)) {
            throw new RuntimeException('테스트 데이터 루트는 절대 경로여야 합니다.');
        }
        $real = realpath($root);
        if ($real === false || ! is_dir($real) || ! is_readable($real)) {
            throw new RuntimeException('테스트 데이터 루트를 읽을 수 없습니다.');
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    public function problemDirectory(ProblemId $problemId): string
    {
        return $this->root() . DIRECTORY_SEPARATOR . $problemId->value();
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
