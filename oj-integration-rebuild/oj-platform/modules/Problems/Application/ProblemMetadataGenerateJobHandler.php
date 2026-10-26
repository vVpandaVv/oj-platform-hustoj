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

final class ProblemMetadataGenerateJobHandler implements JobHandler
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
        return 'problem_metadata_generate';
    }

    public function handle(JobWorkItem $item): JobHandlerResult
    {
        try {
            $context = $this->support->context(
                $item,
                $this->jobType(),
                ProblemAiInputFactory::METADATA_CONTRACT_VERSION
            );
            $problemId = $this->problemId($context['payload']['problem_id'] ?? null);
            $problem = $this->problems->find($problemId);
            if (
                $problem === null
                || ! $this->access->canGenerateMetadataAi(
                    $item->claim()->createdByUserId(),
                    $problemId
                )
            ) {
                return JobHandlerResult::needsAttention(
                    'problem.ai_permission_changed',
                    '현재 권한으로 문제 메타데이터를 생성할 수 없습니다.'
                );
            }
            $currentInput = $context['input'];
            $currentInput['problem'] = $this->inputs->problem(
                $problem,
                $this->metadata->find($problemId)
            );
            $currentInput['topic_dictionary'] = $this->inputs->topicDictionary();
            if (! hash_equals($context['input_hash'], $this->inputs->hash($currentInput))) {
                return JobHandlerResult::needsAttention(
                    'problem.ai_input_changed',
                    '문제 원문이나 주제 사전이 작업 등록 이후 변경되었습니다.'
                );
            }
            // Derive the response constraint from the verified snapshot without changing queued input hashes.
            $providerInput = $context['input'];
            // Existing classifications can be wrong; keep them for conflict detection, not model evidence.
            unset(
                $providerInput['problem']['current_metadata'],
                $providerInput['problem']['accepted_count'],
                $providerInput['problem']['submitted_count']
            );
            $providerInput['output_contract'] = $this->inputs->metadataOutputContract(
                $currentInput['topic_dictionary'],
                $item->claim()->attempt() > 1
            );
            $response = $this->support->execute(
                $context['assignment'],
                ProblemAiInputFactory::METADATA_CONTRACT_VERSION,
                $providerInput
            );
            try {
                $draftPayload = $this->outputs->metadata($response->output());
            } catch (ProblemAiTopicValidationException $error) {
                throw $error;
            } catch (DomainException | InvalidArgumentException $error) {
                // Only malformed model output is retriable, never input or access failures.
                return $item->claim()->attempt() < $item->claim()->maxAttempts()
                    ? JobHandlerResult::retry('problem.ai_contract_failed', $error->getMessage(), 5)
                    : JobHandlerResult::needsAttention('problem.ai_contract_failed', $error->getMessage());
            }
            $draftPayload += [
                'problem_input_hash' => $context['input_hash'],
                'problem_snapshot_hash' => $this->inputs->hash(
                    $currentInput['problem']
                ),
                'dictionary_version' => $this->inputs->topicDictionary()['version'],
                'profile_key' => $response->profileKey(),
                'provider_key' => $response->providerKey(),
                'model_key' => $response->modelKey(),
            ];
            $draftReference = $this->drafts->store(
                'problem_metadata',
                $item->claim()->createdByUserId(),
                'problem',
                (string) $problemId->value(),
                $draftPayload
            );

            return JobHandlerResult::succeeded(
                'problem.metadata_draft_ready',
                '문제 메타데이터 초안을 만들었습니다.',
                [
                    'problem_id' => $problemId->value(),
                    'draft_reference' => $draftReference->value(),
                    'difficulty_score' => $draftPayload['difficulty_score'],
                    'topic_keys' => $draftPayload['topic_keys'],
                ]
            );
        } catch (ProblemAiTopicValidationException $error) {
            return JobHandlerResult::needsAttention(
                'problem.ai_topics_invalid',
                $error->getMessage(),
                ['problem_id' => $problemId->value(), 'topic_validation' => $error->issues()]
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

    private function problemId(mixed $value): ProblemId
    {
        if (! is_int($value) || $value < 1) {
            throw new DomainException('문제 AI 작업 대상이 올바르지 않습니다.');
        }

        return new ProblemId($value);
    }
}
