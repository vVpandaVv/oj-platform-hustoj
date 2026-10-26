<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

use CodingPanda\OjPlatform\Modules\Settings\Domain\SmtpSettings;

interface SmtpTransport
{
    public function sendTest(
        SmtpSettings $settings,
        string $recipient,
        string $subject,
        string $message
    ): bool;
}
