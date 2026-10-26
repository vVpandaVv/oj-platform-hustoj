<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Contract;

interface JudgeSchemaExtensionInstaller
{
    public function canProvision(JudgeReadinessReport $report): bool;

    /**
     * @return list<string>
     */
    public function pendingColumns(JudgeReadinessReport $report): array;

    /**
     * @return list<string>
     */
    public function provision(JudgeConnectionSettings $settings): array;
}
