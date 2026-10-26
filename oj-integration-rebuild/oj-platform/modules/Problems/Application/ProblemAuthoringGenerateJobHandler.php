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

final class ProblemAuthoringGenerateJobHandler implements JobHandler
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
        return 'problem_authoring_generate';
    }

    public function handle(JobWorkItem $item): JobHandlerResult
    {
        try {
            $context = $this->support->context(
                $item,
                $this->jobType(),
                ProblemAiInputFactory::AUTHORING_CONTRACT_VERSION
            );
            $rawProblemId = $context['payload']['problem_id'] ?? null;
            $problemId = is_int($rawProblemId) && $rawProblemId > 0
                ? new ProblemId($rawProblemId)
                : null;
            $currentInput = $context['input'];
            if ($problemId === null) {
                if (! $this->access->canRunNewAuthoringAi(
                    $item->claim()->createdByUserId()
                )) {
                    return JobHandlerResult::needsAttention(
                        'problem.ai_permission_changed',
                        '현재 권한으로 새 문제 초안을 만들 수 없습니다.'
                    );
                }
                $currentInput['existing_problem'] = null;
            } else {
                $problem = $this->problems->find($problemId);
                if (
                    $problem === null
                    || ! $this->access->canRunAuthoringAi(
                        $item->claim()->createdByUserId(),
                        $problemId
                    )
                ) {
                    return JobHandlerResult::needsAttention(
                        'problem.ai_permission_changed',
                        '현재 권한으로 이 문제의 초안을 만들 수 없습니다.'
                    );
                }
                $currentInput['existing_problem'] = $this->inputs->problem(
                    $problem,
                    $this->metadata->find($problemId)
                );
            }
            $currentInput['topic_dictionary'] = $this->inputs->topicDictionary();
            if (! hash_equals($context['input_hash'], $this->inputs->hash($currentInput))) {
                return JobHandlerResult::needsAttention(
                    'problem.ai_input_changed',
                    '문제 원문이나 주제 사전이 작업 등록 이후 변경되었습니다.'
                );
            }
            $mode = is_string($context['input']['mode'] ?? null)
                ? $context['input']['mode']
                : '';
            $response = $this->support->execute(
                $context['assignment'],
                ProblemAiInputFactory::AUTHORING_CONTRACT_VERSION,
                $context['input']
            );
            $draftPayload = [
                'mode' => $mode,
                'fields' => $this->outputs->authoring($response->output(), $mode),
                'problem_input_hash' => $context['input_hash'],
                'problem_snapshot_hash' => is_array(
                    $currentInput['existing_problem'] ?? null
                )
                    ? $this->inputs->hash($currentInput['existing_problem'])
                    : null,
                'dictionary_version' => $this->inputs->topicDictionary()['version'],
                'profile_key' => $response->profileKey(),
                'provider_key' => $response->providerKey(),
                'model_key' => $response->modelKey(),
            ];
            $targetKey = $problemId === null
                ? $item->claim()->targetKey()
                : (string) $problemId->value();
            $draftReference = $this->drafts->store(
                'problem_authoring',
                $item->claim()->createdByUserId(),
                'problem_authoring',
                $targetKey,
                $draftPayload
            );

            return JobHandlerResult::succeeded(
                'problem.authoring_draft_ready',
                '문제 작성 초안을 만들었습니다.',
                [
                    'problem_id' => $problemId?->value(),
                    'draft_reference' => $draftReference->value(),
                    'mode' => $mode,
                    'title' => $draftPayload['fields']['title'],
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
