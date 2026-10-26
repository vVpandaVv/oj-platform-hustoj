<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobTarget;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Domain\DifficultyScore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use DomainException;

final class ProblemAiJobService
{
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemMetadataRepository $metadata,
        private readonly ProblemTopicDictionary $topics,
        private readonly ProblemAccessPolicy $access,
        private readonly ProblemAiInputFactory $inputs,
        private readonly JobDispatcher $jobs,
        private readonly AiTaskRouter $ai
    ) {
    }

    /**
     * @param list<ProblemId> $problemIds
     */
    public function enqueueMetadata(
        int $actorUserId,
        array $problemIds,
        string $executionMode
    ): JobEnqueueResult {
        if (
            ! in_array($executionMode, ['single', 'selected', 'filtered'], true)
            || $problemIds === []
            || count($problemIds) > 500
        ) {
            throw new DomainException('메타데이터 생성 대상이 올바르지 않습니다.');
        }
        $unique = [];
        foreach ($problemIds as $problemId) {
            if (! $problemId instanceof ProblemId) {
                throw new DomainException('메타데이터 생성 대상이 올바르지 않습니다.');
            }
            $unique[$problemId->value()] = $problemId;
        }
        if (count($unique) !== count($problemIds)) {
            throw new DomainException('메타데이터 생성 대상이 중복되었습니다.');
        }
        $assignment = null;
        $targets = [];
        $dictionary = $this->inputs->topicDictionary();
        foreach ($unique as $problemId) {
            $problem = $this->problems->find($problemId);
            if (
                $problem === null
                || ! $this->access->canGenerateMetadataAi(
                    $actorUserId,
                    $problemId
                )
            ) {
                throw new DomainException('메타데이터를 생성할 수 없는 문제가 포함되어 있습니다.');
            }
            $assignment ??= $this->ai->snapshot('problem_metadata_generate');
            $input = [
                'contract_version' => ProblemAiInputFactory::METADATA_CONTRACT_VERSION,
                'problem' => $this->inputs->problem(
                    $problem,
                    $this->metadata->find($problemId)
                ),
                'topic_dictionary' => $dictionary,
            ];
            $inputHash = $this->inputs->hash($input);
            $targets[] = new JobTarget(
                'problem',
                (string) $problemId->value(),
                [
                    'problem_id' => $problemId->value(),
                    'problem_input_hash' => $inputHash,
                    'input' => $input,
                    'assignment' => $assignment->toArray(),
                ],
                $inputHash
            );
        }
        if ($assignment === null) {
            throw new DomainException('AI 작업 배정을 확인할 수 없습니다.');
        }

        return $this->jobs->enqueue(
            'problem_metadata_generate',
            $executionMode,
            $actorUserId,
            $targets,
            [
                'contract_version' => ProblemAiInputFactory::METADATA_CONTRACT_VERSION,
                'dictionary_version' => $this->topics->version(),
                'assignment_hash' => $assignment->hash(),
            ],
            ['problem_ids' => array_keys($unique)],
            'problems.metadata.generate'
        );
    }

    /**
     * @param list<string> $topicKeys
     */
    public function enqueueAuthoring(
        int $actorUserId,
        ?ProblemId $problemId,
        string $mode,
        string $brief,
        ?DifficultyScore $difficulty,
        array $topicKeys
    ): JobEnqueueResult {
        $brief = trim($brief);
        if (
            ! in_array($mode, ['title_only', 'full_draft'], true)
            || $brief === ''
            || strlen($brief) > 10000
            || count($topicKeys) > 3
        ) {
            throw new DomainException('AI 문제 초안 입력이 올바르지 않습니다.');
        }
        if ($topicKeys !== []) {
            $this->topics->assertSelectableDetails($topicKeys);
        }
        $existing = null;
        if ($problemId === null) {
            if (! $this->access->canRunNewAuthoringAi($actorUserId)) {
                throw new DomainException('새 문제 AI 초안을 만들 권한이 없습니다.');
            }
        } else {
            $existing = $this->problems->find($problemId);
            if (
                $existing === null
                || ! $this->access->canRunAuthoringAi($actorUserId, $problemId)
            ) {
                throw new DomainException('이 문제의 AI 초안을 만들 권한이 없습니다.');
            }
        }
        $assignment = $this->ai->snapshot('problem_authoring_generate');
        $input = [
            'contract_version' => ProblemAiInputFactory::AUTHORING_CONTRACT_VERSION,
            'mode' => $mode,
            'brief' => $brief,
            'requested_difficulty_score' => $difficulty?->value(),
            'requested_topic_keys' => array_values($topicKeys),
            'topic_dictionary' => $this->inputs->topicDictionary(),
            'existing_problem' => $existing === null
                ? null
                : $this->inputs->problem(
                    $existing,
                    $this->metadata->find($existing->id())
                ),
        ];
        $inputHash = $this->inputs->hash($input);
        $targetKey = $problemId === null
            ? 'new-' . substr($inputHash, 0, 32)
            : (string) $problemId->value();

        return $this->jobs->enqueue(
            'problem_authoring_generate',
            'single',
            $actorUserId,
            [new JobTarget(
                'problem_authoring',
                $targetKey,
                [
                    'problem_id' => $problemId?->value(),
                    'problem_input_hash' => $inputHash,
                    'input' => $input,
                    'assignment' => $assignment->toArray(),
                ],
                $inputHash
            )],
            [
                'contract_version' => ProblemAiInputFactory::AUTHORING_CONTRACT_VERSION,
                'mode' => $mode,
                'assignment_hash' => $assignment->hash(),
            ],
            ['problem_id' => $problemId?->value(), 'mode' => $mode],
            'problems.authoring.generate'
        );
    }

    public function enqueueTestcases(
        int $actorUserId,
        ProblemId $problemId,
        int $pairCount,
        string $guidance
    ): JobEnqueueResult {
        $problem = $this->problems->find($problemId);
        if (
            $problem === null
            || ! $this->access->canRunTestcaseAi($actorUserId, $problemId)
            || $pairCount < 1
            || $pairCount > 20
            || strlen($guidance) > 10000
        ) {
            throw new DomainException('AI 테스트케이스 생성 입력이 올바르지 않습니다.');
        }
        $assignment = $this->ai->snapshot('problem_testcase_generate');
        $input = [
            'contract_version' => ProblemAiInputFactory::TESTCASE_CONTRACT_VERSION,
            'problem' => $this->inputs->problem(
                $problem,
                $this->metadata->find($problemId)
            ),
            'requested_pair_count' => $pairCount,
            'guidance' => trim($guidance),
        ];
        $inputHash = $this->inputs->hash($input);

        return $this->jobs->enqueue(
            'problem_testcase_generate',
            'single',
            $actorUserId,
            [new JobTarget(
                'problem',
                (string) $problemId->value(),
                [
                    'problem_id' => $problemId->value(),
                    'problem_input_hash' => $inputHash,
                    'input' => $input,
                    'assignment' => $assignment->toArray(),
                ],
                $inputHash
            )],
            [
                'contract_version' => ProblemAiInputFactory::TESTCASE_CONTRACT_VERSION,
                'assignment_hash' => $assignment->hash(),
            ],
            ['problem_id' => $problemId->value(), 'pair_count' => $pairCount],
            'problems.testcases.generate'
        );
    }
}
