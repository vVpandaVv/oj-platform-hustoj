<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SmtpTransport;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SmtpSettings;

final class WordPressSmtpTransport implements SmtpTransport
{
    public function sendTest(
        SmtpSettings $settings,
        string $recipient,
        string $subject,
        string $message
    ): bool {
        if (
            ! function_exists('add_action')
            || ! function_exists('remove_action')
            || ! function_exists('wp_mail')
        ) {
            return false;
        }
        $configure = static function (object $mailer) use ($settings): void {
            if (! method_exists($mailer, 'isSMTP')) {
                return;
            }
            $mailer->isSMTP();
            $mailer->Host = $settings->host();
            $mailer->Port = $settings->port();
            $mailer->SMTPAuth = $settings->authentication();
            $mailer->Username = $settings->username();
            $mailer->Password = $settings->password();
            $mailer->SMTPSecure = $settings->encryption() === 'smtps'
                ? 'ssl'
                : 'tls';
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
        };
        add_action('phpmailer_init', $configure, 999);
        try {
            return (bool) wp_mail(
                $recipient,
                $subject,
                $message,
                ['Content-Type: text/plain; charset=UTF-8']
            );
        } finally {
            remove_action('phpmailer_init', $configure, 999);
        }
    }
}
