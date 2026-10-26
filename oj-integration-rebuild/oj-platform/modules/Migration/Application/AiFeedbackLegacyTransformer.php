<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Application;

use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;
use DomainException;

final class AiFeedbackLegacyTransformer implements LegacyDomainTransformer
{
    public function __construct(private readonly LegacyTransformValues $values)
    {
    }

    public function supports(string $transformer): bool
    {
        return in_array($transformer, [
            'submission_ai_feedback',
            'learner_level_profiles',
        ], true);
    }

    public function transform(
        MigrationTable $table,
        array $row,
        MigrationPlan $plan
    ): array {
        unset($plan);

        return [match ($table->transformer()) {
            'submission_ai_feedback' => $this->feedback($row),
            'learner_level_profiles' => $this->level($row),
            default => throw new DomainException('AI 피드백 이전 변환기를 찾지 못했습니다.'),
        }];
    }

    /** @param array<string, mixed> $row */
    private function feedback(array $row): array
    {
        $submissionId = $this->values->positive(
            $row['submission_id'] ?? null,
            '제출 번호'
        );
        $content = (string) ($row['content'] ?? '');
        $metadata = $this->values->nullableJson($row['metadata'] ?? null) ?? '{}';
        $model = $this->values->text($row['model'] ?? null) ?? 'legacy';

        return [
            'submission_id' => $submissionId,
            'content' => $content,
            'input_hash' => hash('sha256', $submissionId . ':' . $metadata),
            'source_hash' => hash('sha256', 'legacy-source:' . $submissionId),
            'problem_hash' => hash('sha256', 'legacy-problem:' . $submissionId),
            'result_code' => (int) ($row['result_code'] ?? 0),
            'guide_key' => 'legacy-import',
            'guide_revision' => 1,
            'analysis_status' => $content === '' ? 'failed' : 'ready',
            'level' => null,
            'level_policy_version' => 'legacy-v1',
            'profile_last_submission_id' => 0,
            'assignment_revision' => 0,
            'profile_key' => 'legacy-import',
            'provider_key' => 'legacy',
            'profile_fingerprint' => hash('sha256', 'legacy:' . $model),
            'model_key' => $model,
            'metadata' => $metadata,
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function level(array $row): array
    {
        return [
            'user_id' => $this->values->user($row, '', 'user_id'),
            'analysis_status' => 'ready',
            'level' => $this->values->text($row['level'] ?? null),
            'proven_tier' => max(
                1,
                min(5, (int) round((float) ($row['difficulty_score'] ?? 1)))
            ),
            'proven_difficulty' => $this->values->score(
                $row['difficulty_score'] ?? null
            ),
            'breadth' => $this->values->score($row['diversity_score'] ?? null),
            'recent_challenge' => $this->values->score(
                $row['challenge_score'] ?? null
            ),
            'stability' => $this->values->score($row['stability_score'] ?? null),
            'skill_score' => $this->values->score($row['cs_score'] ?? null),
            'submission_count' => max(0, (int) ($row['submission_count'] ?? 0)),
            'attempted_problem_count' => max(
                0,
                (int) ($row['attempted_count'] ?? 0)
            ),
            'solved_problem_count' => max(0, (int) ($row['solved_count'] ?? 0)),
            'recent_sample_count' => 0,
            'difficulty_coverage' => 0,
            'topic_coverage' => 0,
            'policy_version' => 'legacy-v1',
            'last_submission_id' => max(
                0,
                (int) ($row['last_submission_id'] ?? 0)
            ),
            'analyzed_at' => $this->values->requiredDateTime(
                $row['analyzed_at'] ?? null
            ),
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
        ];
    }
}

