<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Settings;

use CodingPanda\OjPlatform\Modules\Problems\Contract\SpjSandboxProfileProvider;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTestDataConfiguration;
use CodingPanda\OjPlatform\Modules\Problems\Domain\SpjSandboxProfile;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use DomainException;

final class SettingsSpjSandboxProfileProvider implements SpjSandboxProfileProvider
{
    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function current(): SpjSandboxProfile
    {
        $snapshot = $this->settings->read('problem_test_data', []);
        $configured = ProblemTestDataConfiguration::from(
            $snapshot->value()
        )->sandboxExecutable();
        $executable = realpath($configured);
        if (
            $executable === false
            || basename($configured) !== 'bwrap'
            || ! str_starts_with($executable, '/usr/')
            || ! is_file($executable)
            || ! is_executable($executable)
        ) {
            throw new DomainException(
                'SPJ 격리 실행기 bwrap의 승인된 /usr 경로를 설정해 주세요.'
            );
        }

        return new SpjSandboxProfile($executable);
    }
}
