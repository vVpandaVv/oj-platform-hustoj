<?php
declare(strict_types=1);
namespace OjMicrobit\Support;

final class RequestContext
{
    private static ?string $id = null;
    public static function begin(): string { return self::$id = bin2hex(random_bytes(16)); }
    public static function id(): string { return self::$id ?? self::begin(); }
    public static function validId(mixed $value): bool { return is_string($value) && preg_match('/\A[a-f0-9]{32}\z/D', $value) === 1; }
    public static function log(string $code, int $status, int $durationMs = 0, ?string $upstreamId = null): void
    {
        if (preg_match('/\A[a-z_]{1,64}\z/D', $code) !== 1) { $code = 'unavailable'; }
        $metadata = ['requestId' => self::id(), 'code' => $code, 'status' => $status, 'durationMs' => max(0, $durationMs)];
        if (self::validId($upstreamId)) { $metadata['upstreamRequestId'] = $upstreamId; }
        error_log('[oj-microbit] ' . json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
