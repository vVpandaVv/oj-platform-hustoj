<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionStoredSource;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionStoredSourceDecoder;
use DomainException;

final class BlocklyStoredSourceCodec implements SubmissionStoredSourceDecoder
{
    private const MARKER = '# OJ_BLOCKLY:blockly-json-v1:gzip-base64:';

    public function key(): string
    {
        return 'blockly';
    }

    public function encode(string $generatedSource, string $canonicalJson): string
    {
        if (! function_exists('gzencode')) {
            throw new DomainException(
                'Blockly 복원 정보를 압축할 수 없습니다.'
            );
        }
        $compressed = gzencode($canonicalJson, 9);
        if (! is_string($compressed)) {
            throw new DomainException(
                'Blockly 복원 정보를 압축하지 못했습니다.'
            );
        }

        return rtrim($generatedSource, "\n") . "\n\n"
            . self::MARKER . base64_encode($compressed) . "\n";
    }

    public function decode(string $storedSource): ?SubmissionStoredSource
    {
        $position = strrpos($storedSource, "\n" . self::MARKER);
        if ($position === false) {
            return null;
        }
        $encoded = trim(substr(
            $storedSource,
            $position + strlen("\n" . self::MARKER)
        ));
        if (
            $encoded === ''
            || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $encoded) !== 1
        ) {
            return null;
        }
        $compressed = base64_decode($encoded, true);
        if (! is_string($compressed) || ! function_exists('gzdecode')) {
            return null;
        }
        $json = gzdecode($compressed, 1048576);
        if (! is_string($json)) {
            return null;
        }
        $payload = json_decode($json, true);
        if (
            ! is_array($payload)
            || ($payload['format'] ?? '') !== BlocklyProfileCatalog::FORMAT
        ) {
            return null;
        }

        return new SubmissionStoredSource(
            rtrim(substr($storedSource, 0, $position), "\n") . "\n",
            BlocklyProfileCatalog::FORMAT,
            [
                'profile' => is_scalar($payload['profile'] ?? null)
                    ? (string) $payload['profile']
                    : null,
                'engine_version' => is_scalar(
                    $payload['engine_version'] ?? null
                ) ? (string) $payload['engine_version'] : null,
                'generator_version' => is_scalar(
                    $payload['generator_version'] ?? null
                ) ? (string) $payload['generator_version'] : null,
            ]
        );
    }
}
