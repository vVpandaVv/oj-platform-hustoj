<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraft;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;
use InvalidArgumentException;

final class ProblemAiDraftService
{
    public function __construct(
        private readonly FormDraftStore $drafts,
        private readonly ProblemGateway $problems,
        private readonly ProblemMetadataRepository $metadata,
        private readonly ProblemTopicDictionary $topics,
        private readonly ProblemAiInputFactory $inputs,
        private readonly ProblemAiOutputValidator $outputs
    ) {
    }

    /**
     * @return null|array{
     *     reference:string,
     *     difficulty_score:int,
     *     topic_keys:list<string>,
     *     confidence:float,
     *     rationale:string,
     *     expires_at:int
     * }
     */
    public function metadata(
        string $reference,
        int $ownerUserId,
        ProblemId $problemId
    ): ?array {
        $draft = $this->load($reference, $ownerUserId);
        if ($draft === null) {
            return null;
        }
        $this->assertTarget(
            $draft,
            'problem_metadata',
            'problem',
            (string) $problemId->value()
        );
        $this->assertCurrentProblemSnapshot($draft, $problemId);
        $this->assertDictionaryVersion($draft);
        $validated = $this->outputs->metadata($draft->payload());

        return $validated + [
            'reference' => $draft->reference()->value(),
            'expires_at' => $draft->expiresAt(),
        ];
    }

    /**
     * @return null|array{
     *     reference:string,
     *     mode:string,
     *     fields:array<string,string>,
     *     expires_at:int
     * }
     */
    public function authoring(
        string $reference,
        int $ownerUserId,
        ?ProblemId $problemId
    ): ?array {
        $draft = $this->load($reference, $ownerUserId);
        if ($draft === null) {
            return null;
        }
        if ($problemId === null) {
            if (
                $draft->draftType() !== 'problem_authoring'
                || $draft->targetType() !== 'problem_authoring'
                || preg_match('/^new-[a-f0-9]{32}$/', $draft->targetKey()) !== 1
            ) {
                throw new DomainException('새 문제에 적용할 수 없는 AI 초안입니다.');
            }
        } else {
            $this->assertTarget(
                $draft,
                'problem_authoring',
                'problem_authoring',
                (string) $problemId->value()
            );
            $this->assertCurrentProblemSnapshot($draft, $problemId);
        }
        $this->assertDictionaryVersion($draft);
        $payload = $draft->payload();
        $mode = is_string($payload['mode'] ?? null)
            ? $payload['mode']
            : '';
        $fields = is_array($payload['fields'] ?? null)
            ? $payload['fields']
            : [];

        return [
            'reference' => $draft->reference()->value(),
            'mode' => $mode,
            'fields' => $this->outputs->authoring($fields, $mode),
            'expires_at' => $draft->expiresAt(),
        ];
    }

    /**
     * @return null|array{
     *     reference:string,
     *     files:list<array{name:string,contents:string,bytes:int,sha256:string}>,
     *     rationale:string,
     *     expires_at:int
     * }
     */
    public function testcases(
        string $reference,
        int $ownerUserId,
        ProblemId $problemId
    ): ?array {
        $draft = $this->load($reference, $ownerUserId);
        if ($draft === null) {
            return null;
        }
        $this->assertTarget(
            $draft,
            'problem_testcase',
            'problem',
            (string) $problemId->value()
        );
        $this->assertCurrentProblemSnapshot($draft, $problemId);
        $validated = $this->outputs->testcases($draft->payload());
        $files = array_map(
            static fn (array $file): array => $file + [
                'bytes' => strlen($file['contents']),
                'sha256' => hash('sha256', $file['contents']),
            ],
            $validated['files']
        );

        return [
            'reference' => $draft->reference()->value(),
            'files' => $files,
            'rationale' => $validated['rationale'],
            'expires_at' => $draft->expiresAt(),
        ];
    }

    /**
     * @param list<string> $references
     * @return array{
     *     generation_method:string,
     *     difficulty_source:string,
     *     references:list<string>
     * }
     */
    public function inspectProblemSave(
        array $references,
        int $ownerUserId,
        ?ProblemId $problemId
    ): array {
        $references = array_values(array_unique(array_filter(
            array_map('trim', $references),
            static fn (string $reference): bool => $reference !== ''
        )));
        if (count($references) > 2) {
            throw new DomainException('한 번에 적용할 수 있는 AI 초안 수를 초과했습니다.');
        }
        $metadataApplied = false;
        foreach ($references as $reference) {
            $draft = $this->required($reference, $ownerUserId);
            if ($draft->draftType() === 'problem_metadata') {
                if ($problemId === null) {
                    throw new DomainException('새 문제에는 메타데이터 초안을 직접 적용할 수 없습니다.');
                }
                $this->metadata($reference, $ownerUserId, $problemId);
                $metadataApplied = true;
                continue;
            }
            if ($draft->draftType() === 'problem_authoring') {
                $this->authoring($reference, $ownerUserId, $problemId);
                continue;
            }

            throw new DomainException('문제 저장에 적용할 수 없는 AI 초안입니다.');
        }

        return [
            'generation_method' => $references === [] ? 'manual' : 'ai_reviewed',
            'difficulty_source' => $metadataApplied ? 'ai' : 'manual',
            'references' => $references,
        ];
    }

    public function discard(string $reference, int $ownerUserId): void
    {
        $this->drafts->discard($this->reference($reference), $ownerUserId);
    }

    private function load(string $reference, int $ownerUserId): ?FormDraft
    {
        if (trim($reference) === '') {
            return null;
        }

        return $this->required($reference, $ownerUserId);
    }

    private function required(string $reference, int $ownerUserId): FormDraft
    {
        $draft = $this->drafts->find(
            $this->reference($reference),
            $ownerUserId
        );
        if ($draft === null) {
            throw new DomainException('AI 초안을 찾을 수 없거나 보관 시간이 지났습니다.');
        }

        return $draft;
    }

    private function reference(string $reference): FormDraftReference
    {
        try {
            return new FormDraftReference(trim($reference));
        } catch (InvalidArgumentException $error) {
            throw new DomainException('AI 초안 참조가 올바르지 않습니다.', 0, $error);
        }
    }

    private function assertTarget(
        FormDraft $draft,
        string $draftType,
        string $targetType,
        string $targetKey
    ): void {
        if (
            $draft->draftType() !== $draftType
            || $draft->targetType() !== $targetType
            || $draft->targetKey() !== $targetKey
        ) {
            throw new DomainException('현재 문제에 적용할 수 없는 AI 초안입니다.');
        }
    }

    private function assertCurrentProblemSnapshot(
        FormDraft $draft,
        ProblemId $problemId
    ): void {
        $problem = $this->problems->find($problemId);
        if ($problem === null) {
            throw new DomainException('AI 초안의 대상 문제를 찾을 수 없습니다.');
        }
        $expected = $draft->payload()['problem_snapshot_hash'] ?? null;
        $actual = $this->inputs->hash($this->inputs->problem(
            $problem,
            $this->metadata->find($problemId)
        ));
        if (
            ! is_string($expected)
            || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1
            || ! hash_equals($expected, $actual)
        ) {
            throw new DomainException(
                'AI 초안 생성 후 문제 내용이 변경되었습니다. 현재 내용으로 다시 생성해 주세요.'
            );
        }
    }

    private function assertDictionaryVersion(FormDraft $draft): void
    {
        $version = $draft->payload()['dictionary_version'] ?? null;
        if (! is_string($version) || $version !== $this->topics->version()) {
            throw new DomainException(
                '문제 주제 사전이 변경되었습니다. 현재 주제로 AI 초안을 다시 생성해 주세요.'
            );
        }
    }
}
