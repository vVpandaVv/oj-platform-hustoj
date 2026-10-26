<?php
declare(strict_types=1);

namespace OjCodeArena\Engine;

/**
 * PHP twin of shared/arena-moves.js and shared/arena-grade.js. The browser
 * still predicts moves with the JS file; both must give the same results
 * (tests/php/engine.php checks the same cases as game-server/test).
 */
final class Rules {
    private const DIRS = array('L' => array(0, -1), 'R' => array(0, 1), 'U' => array(-1, 0), 'D' => array(1, 0));
    private const INVERT = array('L' => 'R', 'R' => 'L', 'U' => 'D', 'D' => 'U');

    // ── moves ─────────────────────────────────────────────────
    // $solved(i): bool, $blocked(i): bool

    private static function open(int $cols, int $rows, callable $blocked, int $r, int $c): bool {
        return $r >= 0 && $c >= 0 && $r < $rows && $c < $cols && ! $blocked($r * $cols + $c);
    }

    /** @return array{0:int,1:int} */
    public static function step(int $cols, int $rows, callable $solved, callable $blocked, int $r, int $c, string $dir, bool $jump): array {
        if (! isset(self::DIRS[$dir])) {
            return array($r, $c);
        }
        list($dr, $dc) = self::DIRS[$dir];
        $nr = $r + $dr;
        $nc = $c + $dc;
        if (! $jump) {
            return self::open($cols, $rows, $blocked, $nr, $nc) ? array($nr, $nc) : array($r, $c);
        }
        if (! self::open($cols, $rows, $blocked, $nr, $nc)) {
            return array($r, $c);
        }
        $here = $solved($r * $cols + $c);
        $next = $solved($nr * $cols + $nc);
        if (! $here && ! $next) {
            while (self::open($cols, $rows, $blocked, $nr + $dr, $nc + $dc) && ! $solved(($nr + $dr) * $cols + $nc + $dc)) {
                $nr += $dr;
                $nc += $dc;
            }
            return array($nr, $nc);
        }
        while ($solved($nr * $cols + $nc) && self::open($cols, $rows, $blocked, $nr + $dr, $nc + $dc)) {
            $nr += $dr;
            $nc += $dc;
        }
        return array($nr, $nc);
    }

    /** @return array{0:int,1:int} */
    public static function edge(int $cols, int $rows, callable $blocked, int $r, int $c, string $to): array {
        if ('home' === $to || 'end' === $to) {
            $dc = 'home' === $to ? -1 : 1;
            $cc = $c;
            while (self::open($cols, $rows, $blocked, $r, $cc + $dc)) {
                $cc += $dc;
            }
            return array($r, $cc);
        }
        if ('first' === $to || 'last' === $to) {
            $total = $rows * $cols;
            $i = 'first' === $to ? 0 : $total - 1;
            $di = 'first' === $to ? 1 : -1;
            while ($i >= 0 && $i < $total && $blocked($i)) {
                $i += $di;
            }
            if ($i < 0 || $i >= $total) {
                return array($r, $c);
            }
            return array(intdiv($i, $cols), $i % $cols);
        }
        return array($r, $c);
    }

    /**
     * @param array{dir?: ?string, jump?: bool, to?: ?string} $cmd
     * @return array{0:int,1:int}
     */
    public static function move(int $cols, int $rows, callable $solved, callable $blocked, int $r, int $c, array $cmd, bool $flipped): array {
        if (! empty($cmd['to'])) {
            return self::edge($cols, $rows, $blocked, $r, $c, (string) $cmd['to']);
        }
        $dir = (string) ($cmd['dir'] ?? '');
        if ($flipped && isset(self::INVERT[$dir])) {
            $dir = self::INVERT[$dir];
        }
        return self::step($cols, $rows, $solved, $blocked, $r, $c, $dir, ! empty($cmd['jump']));
    }

    // ── grading ───────────────────────────────────────────────

    /** Canonical form of a line of code, or null when a string is left open. */
    public static function canonical(string $src): ?string {
        $out = '';
        $len = strlen($src);
        $i = 0;
        while ($i < $len) {
            $ch = $src[$i];
            if ("'" === $ch || '"' === $ch) {
                $triple = substr($src, $i, 3) === $ch . $ch . $ch;
                $q = $triple ? $ch . $ch . $ch : $ch;
                $j = $i + strlen($q);
                $body = '';
                $closed = false;
                while ($j < $len) {
                    if ('\\' === $src[$j] && $j + 1 < $len) {
                        $nx = $src[$j + 1];
                        $body .= ("'" === $nx || '"' === $nx) ? $nx : '\\' . $nx;
                        $j += 2;
                        continue;
                    }
                    if (substr($src, $j, strlen($q)) === $q) {
                        $closed = true;
                        $j += strlen($q);
                        break;
                    }
                    if (! $triple && ("\n" === $src[$j] || "\r" === $src[$j])) {
                        return null;
                    }
                    $body .= $src[$j];
                    $j++;
                }
                if (! $closed) {
                    return null;
                }
                $out .= "\x01" . $body . "\x02";
                $i = $j;
                continue;
            }
            if (! ctype_space($ch)) {
                $out .= $ch;
            }
            $i++;
        }
        return $out;
    }

    public static function same_code(string $input, string $expected): bool {
        $a = self::canonical($input);
        $b = self::canonical($expected);
        return null !== $a && null !== $b && $a === $b;
    }

    public static function squash(string $text): string {
        return (string) preg_replace('/\s+/u', '', $text);
    }

    /** @param array<string,mixed> $problem code, answers?, blank? */
    public static function check(array $problem, string $input): bool {
        $answers = isset($problem['answers']) && is_array($problem['answers']) && $problem['answers'] ? $problem['answers'] : null;
        if (null === $answers) {
            return self::same_code($input, (string) ($problem['code'] ?? ''));
        }
        if (! empty($problem['blank'])) {
            foreach ($answers as $a) {
                if (self::same_code($input, (string) $a)) {
                    return true;
                }
            }
            return false;
        }
        $got = self::squash($input);
        if ('' === $got) {
            return false;
        }
        foreach ($answers as $a) {
            if ($got === self::squash((string) $a)) {
                return true;
            }
        }
        return false;
    }
}
