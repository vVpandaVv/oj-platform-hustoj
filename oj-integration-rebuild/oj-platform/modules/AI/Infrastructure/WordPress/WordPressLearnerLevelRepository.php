<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\AI\Contract\LearnerLevelRepository;
use CodingPanda\OjPlatform\Modules\AI\Domain\LearnerLevelProfile;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;

final class WordPressLearnerLevelRepository implements LearnerLevelRepository
{
    public function __construct(private readonly ApplicationDatabase $database)
    {
    }

    public function find(int $userId): ?LearnerLevelProfile
    {
        $row = $this->database->row(
            'SELECT * FROM learner_level_profiles WHERE user_id = %d LIMIT 1',
            [$userId]
        );

        return $row === null ? null : LearnerLevelProfile::fromRow($row);
    }

    public function save(LearnerLevelProfile $profile): void
    {
        $data = $profile->toRow();
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        if ($this->find($profile->userId()) === null) {
            $data['created_at'] = $data['updated_at'];
            $this->database->insert('learner_level_profiles', $data);
            return;
        }
        unset($data['user_id']);
        $this->database->update(
            'learner_level_profiles',
            $data,
            ['user_id' => $profile->userId()]
        );
    }

    public function recent(int $limit = 100): array
    {
        $limit = max(1, min(100000, $limit));
        $rows = $this->database->rows(
            'SELECT * FROM learner_level_profiles'
                . ' ORDER BY analyzed_at DESC, user_id ASC LIMIT %d',
            [$limit]
        );

        return array_map([LearnerLevelProfile::class, 'fromRow'], $rows);
    }
}
