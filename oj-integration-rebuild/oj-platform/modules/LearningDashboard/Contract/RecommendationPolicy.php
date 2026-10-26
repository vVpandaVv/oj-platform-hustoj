<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\LearningDashboard\Contract;
interface RecommendationPolicy
{
    /** null: ordinary recommendations; []: disabled; ordered IDs: restricted research list. */
    public function recommendationIds(int $actor): ?array;
}
