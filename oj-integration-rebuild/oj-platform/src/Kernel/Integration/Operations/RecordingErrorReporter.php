<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Operations;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Error\ExceptionTrackingLogger;
use CodingPanda\OjPlatform\Kernel\Error\TrackingLogger;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingStore;
use Throwable;

final class RecordingErrorReporter implements ErrorReporter
{
    private const OPTION_NAME = 'oj_platform_last_fatal';

    public function __construct(
        private readonly ErrorReporter $delegate,
        private readonly SettingStore $store,
        private readonly TrackingLogger $trackingLogger
    ) {
    }

    public function report(Throwable $error): void
    {
        $trackingId = bin2hex(random_bytes(8));
        try {
            $diagnostic = (new \CodingPanda\OjPlatform\Kernel\Error\ExceptionDiagnostics())->describe($error);
            $stored = $this->store->read(self::OPTION_NAME, null);
            $revision = is_array($stored)
                ? max(0, (int) ($stored['revision'] ?? 0))
                : 0;
            $this->store->write(self::OPTION_NAME, [
                'revision' => $revision + 1,
                'value' => [
                    'tracking_id' => $trackingId,
                    'error_class' => $diagnostic['error_class'],
                    'reported_at' => gmdate('c'),
                    'diagnostic' => $diagnostic,
                ],
            ]);
        } catch (Throwable) {
        }

        try {
            if ($this->trackingLogger instanceof ExceptionTrackingLogger) {
                $this->trackingLogger->logException($trackingId, $error);
            } else {
                $this->trackingLogger->log($trackingId, $error::class);
            }
        } catch (Throwable) {
            // Reporting failures must not prevent the original failure from being reported.
        }
        try {
            $this->delegate->report($error);
        } catch (Throwable) {
            // Error reporting is best effort and must not break the error boundary itself.
        }
    }
}
