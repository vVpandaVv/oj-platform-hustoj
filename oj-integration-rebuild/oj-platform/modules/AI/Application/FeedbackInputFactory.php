<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Application;

use CodingPanda\OjPlatform\Modules\AI\Domain\FeedbackGuide;
use CodingPanda\OjPlatform\Modules\AI\Domain\LearnerLevelProfile;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use JsonException;

final class FeedbackInputFactory
{
    public const CONTRACT_VERSION = 'submission-feedback-v1';

    /**
     * @return array<string, mixed>
     */
    public function base(
        AiFeedbackSubmission $submission,
        LearningProblem $problem
    ): array {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'problem' => [
                'problem_id' => $problem->problemId(),
                'title' => $problem->title(),
                'description' => $problem->description(),
                'input' => $problem->input(),
                'output' => $problem->output(),
                'hint' => $problem->hint(),
                'difficulty' => $problem->effectiveDifficulty(),
                'difficulty_source' => $problem->difficultySource(),
                'topic_keys' => $problem->topicKeys(),
            ],
            'submission' => [
                'submission_id' => $submission->submissionId(),
                'language_id' => $submission->languageId(),
                'result_code' => $submission->resultCode(),
                'time_ms' => $submission->timeMilliseconds(),
                'memory_kb' => $submission->memoryKilobytes(),
                'source' => $submission->source(),
                'compile_error' => $this->diagnostic(
                    $submission->compileError()
                ),
                'runtime_error' => $this->diagnostic(
                    $submission->runtimeError()
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    public function complete(
        array $base,
        LearnerLevelProfile $profile,
        FeedbackGuide $guide
    ): array {
        $base['learner_profile'] = [
            'analysis_status' => $profile->analysisStatus(),
            'level' => $profile->level(),
            'proven_tier' => $profile->provenTier(),
            'skill_score' => $profile->metric('skill_score'),
            'policy_version' => $profile->policyVersion(),
        ];
        $base['response_policy'] = [
            'guide_key' => $guide->key(),
            'guide_revision' => $guide->revision(),
            'instructions' => $guide->instructions(),
            'must_not_include' => [
                'personal_identity',
                'complete_answer_code',
                'copy_ready_patch',
            ],
            'output_schema' => [
                'result_understanding' => 'string',
                'step_hints' => 'list<string>',
                'review_question' => 'string',
            ],
        ];

        return $base;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function hash(array $input): string
    {
        try {
            return hash('sha256', json_encode(
                $input,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            ));
        } catch (JsonException) {
            return hash('sha256', serialize($input));
        }
    }

    /** Research uses the existing level result without altering its calculation. */
    public function research(array $base, array $context): array
    {
        unset($base['learner_profile']);
        if (isset($context['learning_materials'])) { $base['reviewed_learning_materials'] = $context['learning_materials']; }
        if ($context['policy'] === 'level_adaptive') {
            $profile = $context['baseline'];
            $base['learner_profile'] = [
                'analysis_status' => $profile['analysis_status'], 'level' => $profile['level'],
                'proven_tier' => $profile['proven_tier'], 'skill_score' => $profile['skill_score'],
                'policy_version' => $profile['policy_version'],
            ];
        }
        $base['response_policy'] = [
            'instructions' => $context['instructions'],
            'must_not_include' => ['personal_identity', 'complete_answer_code', 'copy_ready_patch', 'research_condition', 'learner_level_label'],
            'output_schema' => ['result_understanding' => 'string', 'step_hints' => 'list<string>', 'review_question' => 'string'],
        ];
        return $base;
    }

    private function diagnostic(string $value): string
    {
        $value = trim($value);
        if (strlen($value) <= 8000) {
            return $value;
        }
        $value = substr($value, 0, 8000);
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }

        return $value;
    }
}
