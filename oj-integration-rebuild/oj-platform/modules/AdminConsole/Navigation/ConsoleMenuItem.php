<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Navigation;

final class ConsoleMenuItem
{
    /**
     * @param list<ConsoleMenuItem> $children
     * @param list<ConsoleMenuItem> $members pages shown as in-page segments
     *        (or, when isAction(), as buttons) instead of separate sidebar entries
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $url,
        private readonly array $children = [],
        private readonly string $section = '',
        private readonly array $members = [],
        private readonly bool $action = false,
        private readonly string $icon = ''
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function icon(): string
    {
        return $this->icon;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * @return list<ConsoleMenuItem>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function section(): string
    {
        return $this->section;
    }

    /**
     * @return list<ConsoleMenuItem>
     */
    public function members(): array
    {
        return $this->members;
    }

    /** A member that starts a task (추가, 글쓰기) rather than showing a view. */
    public function isAction(): bool
    {
        return $this->action;
    }

    /** True when $menuKey is this item or one of its in-page members. */
    public function matches(string $menuKey): bool
    {
        if ($this->key === $menuKey) {
            return true;
        }
        foreach ($this->members as $member) {
            if ($member->key() === $menuKey) {
                return true;
            }
        }

        return false;
    }
}
