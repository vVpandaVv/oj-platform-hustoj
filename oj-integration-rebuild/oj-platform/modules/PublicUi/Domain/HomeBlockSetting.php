<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Domain;

use InvalidArgumentException;

final class HomeBlockSetting
{
    public function __construct(
        private readonly string $key,
        private readonly int $order,
        private readonly int $limit,
        private readonly bool $enabled
    ) {
        if (
            ! in_array($this->key, [
                'continue_learning',
                'learning_spaces',
                'recent_submissions',
                'notices',
                'staff_shortcuts',
            ], true)
            || $this->order < 0
            || $this->order > 99
            || $this->limit < 1
            || $this->limit > 20
        ) {
            throw new InvalidArgumentException('홈 블록 설정이 올바르지 않습니다.');
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        return new self(
            trim((string) ($value['key'] ?? '')),
            (int) ($value['order'] ?? 0),
            (int) ($value['limit'] ?? 5),
            ! array_key_exists('enabled', $value)
                || filter_var($value['enabled'], FILTER_VALIDATE_BOOL)
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'order' => $this->order,
            'limit' => $this->limit,
            'enabled' => $this->enabled,
        ];
    }
}
