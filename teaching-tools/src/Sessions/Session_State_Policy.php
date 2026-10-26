<?php
declare(strict_types=1);

namespace TeachingTools\Sessions;

/** Shared payload limits for every session write path. */
final class Session_State_Policy {
    public const MAX_BYTES = 512 * 1024;
    public const MAX_WHITEBOARD_PAGES = 500;
    private const MAX_DEPTH = 14;
    private const MAX_NODES = 12000;
    private const MAX_STRING_BYTES = 12000;

    /** @param array<string, mixed> $state */
    public static function accepts(string $tool_type, array $state): bool {
        $encoded = wp_json_encode($state);
        if (false === $encoded || strlen($encoded) > self::MAX_BYTES) {
            return false;
        }

        $nodes = 0;
        // Dense freehand coordinates can exceed the generic node budget well before the byte limit.
        $max_nodes = 'whiteboard' === $tool_type ? 150000 : self::MAX_NODES;
        if (! self::walk($state, 0, $nodes, $max_nodes)) {
            return false;
        }

        return match ($tool_type) {
            'seating' => self::list_limit($state['seats'] ?? array(), 400),
            'whiteboard' => self::whiteboard($state),
            'quickboard' => self::list_limit($state['elements'] ?? array(), 1000),
            'quiz' => self::list_limit($state['responses'] ?? $state['votes'] ?? array(), 2000),
            'grouping' => self::grouping($state),
            'timeattack' => self::list_limit($state['roster'] ?? array(), 500)
                && self::list_limit($state['problems'] ?? array(), 100),
            'classroom' => self::list_limit($state['activities'] ?? array(), 100),
            default => true,
        };
    }

    private static function walk(mixed $value, int $depth, int &$nodes, int $maximum): bool {
        $nodes++;
        if ($depth > self::MAX_DEPTH || $nodes > $maximum) {
            return false;
        }
        if (is_string($value)) {
            return strlen($value) <= self::MAX_STRING_BYTES;
        }
        if (! is_array($value)) {
            return is_null($value) || is_bool($value) || is_int($value) || is_float($value);
        }
        foreach ($value as $child) {
            if (! self::walk($child, $depth + 1, $nodes, $maximum)) {
                return false;
            }
        }

        return true;
    }

    private static function list_limit(mixed $value, int $maximum): bool {
        return ! is_array($value) || count($value) <= $maximum;
    }

    /** @param array<string, mixed> $state */
    private static function whiteboard(array $state): bool {
        $pages = $state['pages'] ?? array();
        if (! is_array($pages) || count($pages) > self::MAX_WHITEBOARD_PAGES) {
            return false;
        }
        foreach ($pages as $page) {
            if (! is_array($page) || ! self::list_limit($page['elements'] ?? array(), 2000)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $state */
    private static function grouping(array $state): bool {
        $groups = $state['groups'] ?? array();
        if (! is_array($groups) || count($groups) > 50) {
            return false;
        }
        $members = 0;
        foreach ($groups as $group) {
            if (! is_array($group) || ! is_array($group['members'] ?? null)) {
                return false;
            }
            $members += count($group['members']);
        }

        return $members <= 500;
    }
}
