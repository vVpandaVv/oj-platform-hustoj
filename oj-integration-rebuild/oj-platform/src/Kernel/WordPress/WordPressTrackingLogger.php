<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\WordPress;

use CodingPanda\OjPlatform\Kernel\Error\ExceptionTrackingLogger;
use Throwable;

final class WordPressTrackingLogger implements ExceptionTrackingLogger
{
    public function log(string $trackingId, string $errorClass): void
    {
        error_log(sprintf(
            '[OJ Platform] tracking_id=%s error_class=%s',
            $trackingId,
            $errorClass
        ));
    }

    public function logException(string $trackingId, Throwable $error): void
    {
        $diagnostic = (new \CodingPanda\OjPlatform\Kernel\Error\ExceptionDiagnostics())->describe($error);
        error_log(sprintf('[OJ Platform] tracking_id=%s diagnostic_code=%s operation=%s summary=%s next_action=%s source=%s',
            $trackingId, $diagnostic['code'], $diagnostic['operation'], $diagnostic['summary'], $diagnostic['next_action'], $diagnostic['source']));
        $chain = [];
        for ($cause = $error; $cause !== null && count($chain) < 4; $cause = $cause->getPrevious()) {
            $chain[] = [
                'error_class' => $this->symbol($cause::class),
                'file' => $this->sourceFile($cause->getFile()),
                'line' => $cause->getLine(),
            ];
        }
        $frames = [];
        foreach (array_slice($error->getTrace(), 0, 8) as $frame) {
            $frames[] = [
                'file' => $this->sourceFile((string) ($frame['file'] ?? '')),
                'line' => (int) ($frame['line'] ?? 0),
                'call' => $this->symbol((string) ($frame['class'] ?? ''))
                    . (string) ($frame['type'] ?? '')
                    . $this->symbol((string) ($frame['function'] ?? '')),
            ];
        }
        // Do not include exception messages, arguments, URLs, source code or request data.
        $record = json_encode([
            'tracking_id' => $trackingId,
            'error_class' => $this->symbol($error::class),
            'diagnostic' => $diagnostic,
            'origin' => $chain[0],
            'causes' => $chain,
            'frames' => $frames,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        error_log('[OJ Platform] exception_context=' . $record);
    }

    private function sourceFile(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $plugins = strrpos($file, '/plugins/');

        return $plugins === false ? basename($file) : substr($file, $plugins + 9);
    }

    private function symbol(string $symbol): string
    {
        if (str_contains($symbol, '@anonymous')) {
            return explode('@anonymous', $symbol, 2)[0] . '@anonymous';
        }

        return str_starts_with($symbol, '{closure') ? '{closure}' : $symbol;
    }
}
