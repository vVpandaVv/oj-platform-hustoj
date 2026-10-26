<?php
declare(strict_types=1);

namespace OjCodeArena\Engine;

/** Seeded PRNG (mulberry32), same idea as game-server/src/rng.js. */
final class Rng {
    private int $a;

    public function __construct(int $seed) {
        $this->a = $seed & 0xFFFFFFFF;
    }

    private static function imul(int $x, int $y): int {
        $x &= 0xFFFFFFFF;
        $y &= 0xFFFFFFFF;
        $lo = (($x & 0xFFFF) * $y) & 0xFFFFFFFF;
        $hi = ((($x >> 16) * $y) & 0xFFFF) << 16;
        return ($lo + $hi) & 0xFFFFFFFF;
    }

    public function next(): float {
        $this->a = ($this->a + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->a;
        $t = self::imul($t ^ ($t >> 15), $t | 1);
        $t = ($t ^ (($t + self::imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
    }

    public function int(int $n): int {
        return (int) floor($this->next() * $n);
    }

    /**
     * @template T
     * @param list<T> $list
     * @return list<T>
     */
    public function shuffle(array $list): array {
        for ($i = count($list) - 1; $i > 0; $i--) {
            $j = (int) floor($this->next() * ($i + 1));
            $tmp = $list[$i];
            $list[$i] = $list[$j];
            $list[$j] = $tmp;
        }
        return $list;
    }

    public static function random(): float {
        return mt_rand() / (mt_getrandmax() + 1);
    }

    public static function seed(): int {
        return random_int(0, 0x7FFFFFFF);
    }
}
