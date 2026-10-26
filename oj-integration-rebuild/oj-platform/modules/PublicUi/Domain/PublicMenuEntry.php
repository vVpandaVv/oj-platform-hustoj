<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Domain;

use InvalidArgumentException;

final class PublicMenuEntry
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly int $level,
        private readonly ?string $parentKey,
        private readonly ?string $routeKey,
        private readonly ?string $externalUrl,
        private readonly string $scope,
        private readonly int $order,
        private readonly string $mobilePosition,
        private readonly bool $enabled
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $this->key) !== 1
            || trim($this->label) === ''
            || $this->textLength($this->label) > 30
            || ! in_array($this->level, [1, 2], true)
            || (
                $this->level === 1
                && $this->parentKey !== null
            )
            || (
                $this->level === 2
                && (
                    $this->parentKey === null
                    || preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $this->parentKey) !== 1
                )
            )
            || ($this->routeKey === null) === ($this->externalUrl === null)
            || (
                $this->routeKey !== null
                && $this->routeKey !== 'first_accessible_child'
                && preg_match('/^[a-z][a-z0-9_.-]*$/', $this->routeKey) !== 1
            )
            || ! in_array($this->scope, ['all', 'learner', 'staff', 'system_admin'], true)
            || $this->order < 0
            || $this->order > 999
            || ! in_array($this->mobilePosition, ['primary', 'more'], true)
        ) {
            throw new InvalidArgumentException('사용자 메뉴 항목이 올바르지 않습니다.');
        }
        if ($this->externalUrl !== null) {
            $parts = parse_url($this->externalUrl);
            if (
                ! is_array($parts)
                || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || ! is_string($parts['host'] ?? null)
                || trim($parts['host']) === ''
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                throw new InvalidArgumentException(
                    '외부 사용자 메뉴는 사용자 정보가 없는 HTTPS 주소여야 합니다.'
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $parent = trim((string) ($value['parent_menu_key'] ?? ''));
        $route = trim((string) ($value['route_key'] ?? ''));
        $url = trim((string) ($value['external_url'] ?? ''));

        return new self(
            trim((string) ($value['key'] ?? '')),
            trim((string) ($value['label'] ?? '')),
            (int) ($value['level'] ?? 0),
            $parent !== '' ? $parent : null,
            $route !== '' ? $route : null,
            $url !== '' ? $url : null,
            trim((string) ($value['scope'] ?? 'all')),
            (int) ($value['order'] ?? 0),
            trim((string) ($value['mobile_position'] ?? 'primary')),
            ! array_key_exists('enabled', $value)
                || filter_var($value['enabled'], FILTER_VALIDATE_BOOL)
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function level(): int
    {
        return $this->level;
    }

    public function parentKey(): ?string
    {
        return $this->parentKey;
    }

    public function routeKey(): ?string
    {
        return $this->routeKey;
    }

    public function externalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function mobilePosition(): string
    {
        return $this->mobilePosition;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function isExternal(): bool
    {
        return $this->externalUrl !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'level' => $this->level,
            'parent_menu_key' => $this->parentKey,
            'route_key' => $this->routeKey,
            'external_url' => $this->externalUrl,
            'scope' => $this->scope,
            'order' => $this->order,
            'mobile_position' => $this->mobilePosition,
            'enabled' => $this->enabled,
        ];
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }
}
