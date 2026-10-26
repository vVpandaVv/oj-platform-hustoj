<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataOutputContract;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use JsonException;
use RuntimeException;

final class ProblemAiInputFactory
{
    public const METADATA_CONTRACT_VERSION = 'problem-metadata-v1';
    public const AUTHORING_CONTRACT_VERSION = 'problem-authoring-v1';
    public const TESTCASE_CONTRACT_VERSION = 'problem-testcase-v1';

    public function __construct(
        private readonly ProblemTopicDictionary $topics
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function problem(
        JudgeProblem $problem,
        ?ProblemMetadata $metadata = null
    ): array {
        return [
            'problem_id' => $problem->id()->value(),
            'title' => $problem->title(),
            'description' => $problem->description(),
            'input' => $problem->input(),
            'output' => $problem->output(),
            'sample_input' => $problem->sampleInput(),
            'sample_output' => $problem->sampleOutput(),
            'hint' => $problem->hint(),
            'source' => $problem->source(),
            'time_limit_seconds' => $problem->timeLimitSeconds(),
            'memory_limit_megabytes' => $problem->memoryLimitMegabytes(),
            'special_judge' => $problem->specialJudge(),
            'accepted_count' => $problem->accepted(),
            'submitted_count' => $problem->submitted(),
            'current_metadata' => $metadata === null
                ? null
                : [
                    'difficulty_score' => $metadata->difficulty()?->value(),
                    'topic_keys' => $metadata->topicKeys(),
                    'generation_method' => $metadata->generationMethod(),
                    'difficulty_source' => $metadata->difficultySource(),
                ],
        ];
    }

    /**
     * @return array{version:string,topics:list<array<string,mixed>>}
     */
    public function topicDictionary(): array
    {
        $topics = [];
        $all = $this->topics->all();
        $categories = [];
        foreach ($all as $topic) {
            if ($topic->isCategory()) {
                $categories[$topic->key()] = true;
            }
        }
        foreach ($all as $topic) {
            if (! $topic->isActive()) {
                continue;
            }
            $topics[] = [
                'key' => $topic->key(),
                'label' => $topic->label(),
                'category_key' => $topic->categoryKey(),
                'selectable' => ! $topic->isCategory() && isset($categories[$topic->categoryKey()]),
            ];
        }

        return [
            'version' => $this->topics->version(),
            'topics' => $topics,
        ];
    }

    /** @param array{version:string,topics:list<array<string,mixed>>} $dictionary
     * @return array{json_schema:array<string,mixed>}
     */
    public function metadataOutputContract(array $dictionary, bool $retry = false): array
    {
        return ProblemMetadataOutputContract::fromDictionary($dictionary, $retry);
    }

    public function hash(mixed $value): string
    {
        try {
            return hash(
                'sha256',
                json_encode(
                    $this->canonicalize($value),
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $error) {
            throw new RuntimeException('문제 AI 입력을 직렬화하지 못했습니다.', 0, $error);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalize($item),
                $value
            );
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
