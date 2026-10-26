<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Contract;

interface LegacyInstallationPresence
{
    public function inspect(): LegacyInstallationReport;
}
