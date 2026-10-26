<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Settings\Application\SmtpSettingsService;
use Throwable;

final class WordPressSmtpConfigurator
{
    public function __construct(private readonly SmtpSettingsService $settings)
    {
    }

    public function configure(object $mailer): void
    {
        try {
            $settings = $this->settings->current();
        } catch (Throwable) {
            return;
        }
        if ($settings === null || ! $settings->enabled() || ! method_exists($mailer, 'isSMTP')) {
            return;
        }
        $mailer->isSMTP();
        $mailer->Host = $settings->host();
        $mailer->Port = $settings->port();
        $mailer->SMTPAuth = $settings->authentication();
        $mailer->Username = $settings->username();
        $mailer->Password = $settings->password();
        $mailer->SMTPSecure = $settings->encryption() === 'smtps' ? 'ssl' : 'tls';
        $mailer->SMTPAutoTLS = true;
        $mailer->Timeout = 10;
        $mailer->SMTPOptions = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ];
        if (method_exists($mailer, 'setFrom')) {
            $mailer->setFrom(
                $settings->fromAddress(),
                $settings->fromName(),
                false
            );
        }
    }
}
