<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1\Internal;

use InvalidArgumentException;
use JsonException;

/** @internal */
final class ValueValidator
{
    private const ERROR_CODES = [
        'addon_api_incompatible',
        'addon_platform_incompatible',
        'addon_manifest_invalid',
        'addon_contribution_conflict',
        'addon_registration_failed',
        'addon_runtime_unavailable',
        'addon_handler_failed',
        'addon_public_menu_unavailable',
        'addon_access_denied',
        'addon_capability_assignment_invalid',
        'addon_context_unavailable',
        'addon_context_denied',
        'addon_ai_task_unroutable',
        'addon_ai_task_failed',
        'addon_ai_request_pending',
        'addon_ai_outcome_uncertain',
        'addon_execution_busy',
        'addon_execution_timeout',
    ];

    private function __construct()
    {
    }

    public static function contributionKey(string $value, string $field = 'key'): void
    {
        self::match(
            $value,
            '/^addon\.[a-z][a-z0-9_]{2,63}\.[a-z][a-z0-9_.-]*$/D',
            $field,
            160
        );
    }

    public static function capabilityKey(string $value, string $field = 'capability'): void
    {
        self::match($value, '/^[a-z][a-z0-9_]{2,159}$/D', $field, 160);
    }

    public static function simpleKey(string $value, string $field): void
    {
        self::match($value, '/^[a-z][a-z0-9_.-]{0,159}$/D', $field, 160);
    }

    public static function safeToken(string $value, string $field, int $maximum = 160): void
    {
        self::match($value, '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $field, $maximum);
    }

    /**
     * Like safeToken(), but also allows a single '/' segment separator —
     * some providers namespace their model ids that way (Gemini stores
     * models as "models/gemini-2.5-flash"). Kept separate from safeToken()
     * so every other field validated by it (contextId, correlationId,
     * revisions, ...) stays as restrictive as before.
     */
    public static function modelKey(string $value, string $field = 'modelKey', int $maximum = 160): void
    {
        self::match($value, '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $field, $maximum);
    }

    public static function semanticVersion(string $value, string $field): void
    {
        self::match(
            $value,
            '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)'
                . '(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?'
                . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D',
            $field,
            120
        );
    }

    public static function label(string $value, string $field = 'label'): void
    {
        self::text($value, $field, 80, false, false);
        if ($value !== strip_tags($value)) {
            throw self::invalid($field);
        }
    }

    public static function text(
        string $value,
        string $field,
        int $maximum,
        bool $allowEmpty = false,
        bool $allowNewlines = false
    ): void {
        if (preg_match('//u', $value) !== 1) {
            throw self::invalid($field);
        }
        if (! $allowEmpty && $value === '') {
            throw self::invalid($field);
        }
        if (! $allowNewlines && preg_match('/[\r\n]/', $value) === 1) {
            throw self::invalid($field);
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw self::invalid($field);
        }
        if (preg_match_all('/./us', $value, $matches) === false || count($matches[0]) > $maximum) {
            throw self::invalid($field);
        }
    }

    public static function positiveUserId(int $value, string $field): void
    {
        if ($value < 1) {
            throw self::invalid($field);
        }
    }

    /** @param list<string> $allowed */
    public static function oneOf(string $value, array $allowed, string $field): void
    {
        if (! in_array($value, $allowed, true)) {
            throw self::invalid($field);
        }
    }

    public static function routePath(string $value, string $field = 'path'): void
    {
        if (
            strlen($value) > 512
            || preg_match('/^\/[a-z0-9._~{}-]+(?:\/[a-z0-9._~{}-]+)*$/D', $value) !== 1
            || str_contains($value, '..')
            || str_contains($value, '//')
        ) {
            throw self::invalid($field);
        }
    }

    /** @param array<mixed> $values */
    public static function stringList(array $values, string $field, int $maximum): void
    {
        if (! array_is_list($values) || count($values) > $maximum) {
            throw self::invalid($field);
        }
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw self::invalid($field);
            }
            self::text($value, $field, 512);
        }
    }

    /** @param array<mixed> $values */
    public static function relativeAssetList(
        array $values,
        string $field,
        string $extension,
        int $maximum
    ): void {
        self::stringList($values, $field, $maximum);
        foreach ($values as $value) {
            $normalized = str_replace('\\', '/', $value);
            if (
                $normalized !== $value
                || str_starts_with($value, '/')
                || str_contains($value, '://')
                || str_contains('/' . $value . '/', '/../')
                || preg_match('/^[a-zA-Z0-9._-]+(?:\/[a-zA-Z0-9._-]+)*\.' . $extension . '$/D', $value) !== 1
            ) {
                throw self::invalid($field);
            }
        }
    }

    /** @param array<mixed> $values */
    public static function handleList(array $values, string $field, int $maximum): void
    {
        self::stringList($values, $field, $maximum);
        foreach ($values as $value) {
            self::match($value, '/^[a-z][a-z0-9._-]{0,159}$/D', $field, 160);
        }
    }

    /** @param array<mixed> $values */
    public static function routeParameterMap(array $values, string $field): void
    {
        foreach ($values as $key => $value) {
            if (
                ! is_string($key)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1
                || ! is_string($value)
            ) {
                throw self::invalid($field);
            }
            self::text($value, $field, 512, true, false);
        }
    }

    /** @param array<mixed> $values */
    public static function queryMap(array $values, string $field): void
    {
        foreach ($values as $key => $value) {
            if (! is_string($key) || preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $key) !== 1) {
                throw self::invalid($field);
            }
            if (is_string($value)) {
                self::text($value, $field, 2048, true, true);
                continue;
            }
            if (! is_array($value)) {
                throw self::invalid($field);
            }
            self::stringList($value, $field, 100);
        }
    }

    /** @param array<mixed> $values */
    public static function scalarMap(array $values, string $field): void
    {
        foreach ($values as $key => $value) {
            if (! is_string($key) || preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $key) !== 1) {
                throw self::invalid($field);
            }
            if (! is_bool($value) && ! is_int($value) && ! is_float($value) && ! is_string($value) && $value !== null) {
                throw self::invalid($field);
            }
            if (is_float($value) && (! is_finite($value))) {
                throw self::invalid($field);
            }
            if (is_string($value)) {
                self::text($value, $field, 2048, true, true);
            }
        }
    }

    /** @param array<mixed> $values */
    public static function contributionCountMap(array $values, string $field): void
    {
        foreach ($values as $key => $value) {
            if (
                ! is_string($key)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1
                || ! is_int($value)
                || $value < 0
            ) {
                throw self::invalid($field);
            }
        }
    }

    /** @param array<mixed> $values */
    public static function jsonMap(array $values, string $field, ?int $maximumBytes = null): void
    {
        foreach (array_keys($values) as $key) {
            if (! is_string($key)) {
                throw self::invalid($field);
            }
        }
        self::jsonValue($values, $field);
        try {
            $encoded = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw self::invalid($field);
        }
        if ($maximumBytes !== null && strlen($encoded) > $maximumBytes) {
            throw self::invalid($field);
        }
    }

    public static function errorCode(string $value, string $field = 'code'): void
    {
        self::oneOf($value, self::ERROR_CODES, $field);
    }

    public static function correlationId(string $value): void
    {
        self::safeToken($value, 'correlationId', 128);
    }

    private static function match(
        string $value,
        string $pattern,
        string $field,
        int $maximum
    ): void {
        if ($value === '' || strlen($value) > $maximum || preg_match($pattern, $value) !== 1) {
            throw self::invalid($field);
        }
    }

    private static function jsonValue(mixed $value, string $field): void
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw self::invalid($field);
            }
            return;
        }
        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw self::invalid($field);
            }
            return;
        }
        if (! is_array($value)) {
            throw self::invalid($field);
        }
        foreach ($value as $key => $child) {
            if (! is_int($key) && ! is_string($key)) {
                throw self::invalid($field);
            }
            if (is_string($key) && preg_match('//u', $key) !== 1) {
                throw self::invalid($field);
            }
            self::jsonValue($child, $field);
        }
    }

    private static function invalid(string $field): InvalidArgumentException
    {
        return new InvalidArgumentException('Addon API 값이 올바르지 않습니다: ' . $field);
    }
}
