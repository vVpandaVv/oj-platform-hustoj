<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Infrastructure;

use CodingPanda\OjPlatform\AddonApi\V1\Research\ResearchLearningDataV1;
use CodingPanda\OjResearch\Contract\PilotResources;
use CodingPanda\OjResearch\Contract\ResearchLearningGateway;

final class PlatformLearningGateway implements ResearchLearningGateway, PilotResources
{
    public function __construct(private readonly ResearchLearningDataV1 $host) {}
    public function levelSnapshot(int $userId): array { return $this->host->levelSnapshot($userId); }
    public function problemHashes(int $actorUserId, array $problemIds): array { return $this->host->problemHashes($actorUserId, $problemIds); }
    public function submissions(int $userId, array $problemIds, string $from, string $until): array { return $this->host->submissions($userId, $problemIds, $from, $until); }
    public function guides(): array { return $this->host->guides(); }
    public function problems(int $actor, array $ids): array { return $this->host->problems($actor, $ids); }
}
