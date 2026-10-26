<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Domain;

final class ContestScoringPolicy
{
    public const STANDARD = 'standard_score_v1';
    public const ICPC = 'icpc_v1';
    public const OI = 'oi_v1';

    public const STANDARD_RANKING = 'score_solved_time_v1';
    public const ICPC_RANKING = 'solved_penalty_v1';
    public const OI_RANKING = 'score_solved_penalty_v1';

    /** @return list<string> */
    public static function scoringPolicies(): array
    {
        return [self::STANDARD, self::ICPC, self::OI];
    }

    public static function normalize(string $scoringPolicy): string
    {
        return in_array($scoringPolicy, self::scoringPolicies(), true)
            ? $scoringPolicy
            : self::STANDARD;
    }

    public static function rankingFor(string $scoringPolicy): string
    {
        return match (self::normalize($scoringPolicy)) {
            self::ICPC => self::ICPC_RANKING,
            self::OI => self::OI_RANKING,
            default => self::STANDARD_RANKING,
        };
    }

    public static function isValidPair(
        string $scoringPolicy,
        string $rankingPolicy
    ): bool {
        return in_array($scoringPolicy, self::scoringPolicies(), true)
            && self::rankingFor($scoringPolicy) === $rankingPolicy;
    }
}
