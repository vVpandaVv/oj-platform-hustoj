<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Application;

use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyUserResolver;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DomainException;
use JsonException;

final class LegacyTransformValues
{
    public function __construct(
        private readonly LegacyUserResolver $users,
        private readonly Clock $clock
    ) {
    }

    /** @param array<string, mixed> $row */
    public function user(
        array $row,
        string $wordpressKey,
        string $loginKey = '',
        bool $allowMissing = false
    ): ?int {
        $wordpressId = $this->positiveOrNull($row[$wordpressKey] ?? null);
        $loginId = $loginKey === '' ? null : $this->text($row[$loginKey] ?? null);

        return $this->users->resolve($wordpressId, $loginId, $allowMissing);
    }

    public function assignedOwner(MigrationPlan $plan, string $type, int $id): int
    {
        $owner = $plan->ownerAssignments()[$type . ':' . $id] ?? 0;
        if ($owner < 1) {
            throw new DomainException(sprintf(
                '%s:%d 항목의 WordPress 담당자를 먼저 배정해 주세요.',
                $type,
                $id
            ));
        }

        return $owner;
    }

    public function positive(mixed $value, string $label): int
    {
        $number = $this->positiveOrNull($value);
        if ($number === null) {
            throw new DomainException($label . '가 올바르지 않습니다.');
        }

        return $number;
    }

    public function positiveOrNull(mixed $value): ?int
    {
        $number = is_int($value) || (is_string($value) && ctype_digit($value))
            ? (int) $value
            : 0;

        return $number > 0 ? $number : null;
    }

    public function flag(mixed $value): bool
    {
        return in_array($value, [1, '1', true, 'true', 'yes', 'on'], true);
    }

    public function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    public function dateOnly(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1
            ? $text
            : null;
    }

    public function dateTime(mixed $value): ?string
    {
        $text = $this->text($value);
        if ($text === null) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $text) === 1
            ? $text
            : null;
    }

    public function requiredDateTime(mixed $value): string
    {
        return $this->dateTime($value) ?? $this->clock->now()->format('Y-m-d H:i:s');
    }

    public function nullableJson(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $this->json($value);
        }
        if (! is_string($value)) {
            return $this->json(['legacy_value' => $value]);
        }

        try {
            return $this->json(json_decode($value, true, 32, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return $this->json(['legacy_value' => $value]);
        }
    }

    /** @return list<string> */
    public function list(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
                $value = is_array($decoded) ? $decoded : [$decoded];
            } catch (JsonException) {
                $value = preg_split('/\s*,\s*/', $value) ?: [];
            }
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item)
                ? trim((string) $item)
                : '',
            $value
        ), static fn (string $item): bool => $item !== ''));
    }

    /** @return array<string, mixed> */
    public function object(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    public function score(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return number_format(max(0.0, min(100.0, (float) $value)), 1, '.', '');
    }

    public function json(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $error) {
            throw new DomainException('기존 데이터를 JSON으로 변환하지 못했습니다.', 0, $error);
        }
    }

    public function uuid(string $seed): string
    {
        $hash = hash('sha256', $seed);

        return substr($hash, 0, 8) . '-'
            . substr($hash, 8, 4) . '-'
            . '4' . substr($hash, 13, 3) . '-'
            . '8' . substr($hash, 17, 3) . '-'
            . substr($hash, 20, 12);
    }
}

