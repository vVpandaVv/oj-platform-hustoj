<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use InvalidArgumentException;

/** @internal */
final class SemverRange
{
    private const VERSION = '(0|[1-9][0-9]*)(?:\.(0|[1-9][0-9]*))?(?:\.(0|[1-9][0-9]*))?'
        . '(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?'
        . '(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?';

    private function __construct()
    {
    }

    public static function matches(string $actual, string $range): bool
    {
        $actual = self::normalizeVersion($actual, false);
        $range = trim($range);
        if ($range === '' || strlen($range) > 240) {
            throw new InvalidArgumentException('Invalid semantic version range.');
        }

        foreach (preg_split('/\s*\|\|\s*/', $range) ?: [] as $alternative) {
            $tokens = preg_split('/\s+/', trim($alternative)) ?: [];
            if ($tokens === []) {
                throw new InvalidArgumentException('Invalid semantic version range.');
            }
            $comparators = [];
            $mentionsPrerelease = false;
            foreach ($tokens as $token) {
                foreach (self::comparators($token) as [$operator, $version]) {
                    $comparators[] = [$operator, $version];
                    $mentionsPrerelease = $mentionsPrerelease || str_contains($version, '-');
                }
            }
            if (str_contains($actual, '-') && ! $mentionsPrerelease) {
                continue;
            }
            $matches = true;
            foreach ($comparators as [$operator, $version]) {
                if (! version_compare($actual, $version, $operator)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function comparators(string $token): array
    {
        if (preg_match('/^(\^|~)(.+)$/D', $token, $match) === 1) {
            $parts = self::versionParts($match[2]);
            $lower = self::normalizeVersion($match[2], true);
            [$major, $minor, $patch] = $parts;
            if ($match[1] === '~') {
                $upper = sprintf('%d.%d.0', $major, $minor + 1);
            } elseif ($major > 0) {
                $upper = sprintf('%d.0.0', $major + 1);
            } elseif ($minor > 0) {
                $upper = sprintf('0.%d.0', $minor + 1);
            } else {
                $upper = sprintf('0.0.%d', $patch + 1);
            }

            return [['>=', $lower], ['<', $upper]];
        }

        if (preg_match('/^(>=|<=|>|<|=)?(.+)$/D', $token, $match) !== 1) {
            throw new InvalidArgumentException('Invalid semantic version range.');
        }
        $operator = $match[1] !== '' ? $match[1] : '=';
        $raw = $match[2];
        if (preg_match('/^(\d+)(?:\.(\d+))?\.(?:x|X|\*)$/D', $raw, $wildcard) === 1) {
            $major = (int) $wildcard[1];
            $minor = isset($wildcard[2]) && $wildcard[2] !== '' ? (int) $wildcard[2] : null;
            if ($operator !== '=') {
                throw new InvalidArgumentException('Invalid semantic version range.');
            }
            if ($minor === null) {
                return [['>=', sprintf('%d.0.0', $major)], ['<', sprintf('%d.0.0', $major + 1)]];
            }

            return [
                ['>=', sprintf('%d.%d.0', $major, $minor)],
                ['<', sprintf('%d.%d.0', $major, $minor + 1)],
            ];
        }

        return [[$operator, self::normalizeVersion($raw, true)]];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function versionParts(string $version): array
    {
        if (preg_match('/^' . self::VERSION . '$/D', $version, $match) !== 1) {
            throw new InvalidArgumentException('Invalid semantic version.');
        }

        return [(int) $match[1], (int) ($match[2] ?? 0), (int) ($match[3] ?? 0)];
    }

    private static function normalizeVersion(string $version, bool $allowShort): string
    {
        if (preg_match('/^' . self::VERSION . '$/D', $version, $match) !== 1) {
            throw new InvalidArgumentException('Invalid semantic version.');
        }
        if (! $allowShort && (($match[2] ?? '') === '' || ($match[3] ?? '') === '')) {
            throw new InvalidArgumentException('Invalid semantic version.');
        }
        $normalized = sprintf(
            '%d.%d.%d',
            (int) $match[1],
            (int) (($match[2] ?? '') !== '' ? $match[2] : 0),
            (int) (($match[3] ?? '') !== '' ? $match[3] : 0)
        );
        if (($match[4] ?? '') !== '') {
            $normalized .= '-' . $match[4];
        }
        if (($match[5] ?? '') !== '') {
            $normalized .= '+' . $match[5];
        }

        return $normalized;
    }
}
