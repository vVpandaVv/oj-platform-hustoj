<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use DomainException;
use InvalidArgumentException;

final class ProblemTestcaseGenerateJobHandler implements JobHandler
{
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemMetadataRepository $metadata,
        private readonly ProblemAccessPolicy $access,
        private readonly ProblemAiInputFactory $inputs,
        private readonly ProblemAiOutputValidator $outputs,
        private readonly ProblemAiJobSupport $support,
        private readonly FormDraftStore $drafts
    ) {
    }

    public function jobType(): string
    {
        return 'problem_testcase_generate';
    }

    public function handle(JobWorkItem $item): JobHandlerResult
    {
        try {
            $context = $this->support->context(
                $item,
                $this->jobType(),
                ProblemAiInputFactory::TESTCASE_CONTRACT_VERSION
            );
            $rawProblemId = $context['payload']['problem_id'] ?? null;
            if (! is_int($rawProblemId) || $rawProblemId < 1) {
                throw new DomainException('AI 테스트케이스 대상이 올바르지 않습니다.');
            }
            $problemId = new ProblemId($rawProblemId);
            $problem = $this->problems->find($problemId);
            if (
                $problem === null
                || ! $this->access->canRunTestcaseAi(
                    $item->claim()->createdByUserId(),
                    $problemId
                )
            ) {
                return JobHandlerResult::needsAttention(
                    'problem.ai_permission_changed',
                    '현재 권한으로 테스트케이스 초안을 만들 수 없습니다.'
                );
            }
            $currentInput = $context['input'];
            $currentInput['problem'] = $this->inputs->problem(
                $problem,
                $this->metadata->find($problemId)
            );
            if (! hash_equals($context['input_hash'], $this->inputs->hash($currentInput))) {
                return JobHandlerResult::needsAttention(
                    'problem.ai_input_changed',
                    '문제 원문이 작업 등록 이후 변경되었습니다.'
                );
            }
            $response = $this->support->execute(
                $context['assignment'],
                ProblemAiInputFactory::TESTCASE_CONTRACT_VERSION,
                $context['input']
            );
            $validated = $this->outputs->testcases($response->output());
            $draftPayload = $validated + [
                'problem_input_hash' => $context['input_hash'],
                'problem_snapshot_hash' => $this->inputs->hash(
                    $currentInput['problem']
                ),
                'profile_key' => $response->profileKey(),
                'provider_key' => $response->providerKey(),
                'model_key' => $response->modelKey(),
            ];
            $draftReference = $this->drafts->store(
                'problem_testcase',
                $item->claim()->createdByUserId(),
                'problem',
                (string) $problemId->value(),
                $draftPayload
            );

            return JobHandlerResult::succeeded(
                'problem.testcase_draft_ready',
                '테스트케이스 초안을 만들었습니다.',
                [
                    'problem_id' => $problemId->value(),
                    'draft_reference' => $draftReference->value(),
                    'file_count' => count($validated['files']),
                ]
            );
        } catch (AiProviderException $error) {
            return $error->isTransient()
                ? JobHandlerResult::retry(
                    'problem.ai_provider_transient',
                    $error->getMessage(),
                    60
                )
                : JobHandlerResult::needsAttention(
                    'problem.ai_provider_failed',
                    $error->getMessage()
                );
        } catch (DomainException | InvalidArgumentException $error) {
            return JobHandlerResult::needsAttention(
                'problem.ai_contract_failed',
                $error->getMessage()
            );
        }
    }
}
