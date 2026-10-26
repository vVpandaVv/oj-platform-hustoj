<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Application;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDefinition;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQueue;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWakeup;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobTarget;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobExecutionPlan;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobItemPlan;
use DomainException;
use JsonException;
use Throwable;

final class JobManager implements JobDispatcher
{
    /**
     * @param DefinitionRegistry<JobDefinition> $definitions
     */
    public function __construct(
        private readonly DefinitionRegistry $definitions,
        private readonly IdempotencyKeyFactory $idempotencyKeys,
        private readonly FormDraftStore $payloads,
        private readonly JobQueue $queue,
        private readonly ?JobWakeup $wakeup = null
    ) {
    }

    /**
     * @param list<JobTarget> $targets
     * @param array<string, mixed> $inputSummary
     * @param array<string, mixed> $targetSummary
     */
    public function enqueue(
        string $jobType,
        string $executionMode,
        int $actorUserId,
        array $targets,
        array $inputSummary,
        array $targetSummary,
        string $accessPolicyKey,
        string $accessPolicyVersion = '1',
        string $inputVersion = '1'
    ): JobEnqueueResult {
        $definition = $this->definitions->find($jobType);
        if (! $definition instanceof JobDefinition) {
            throw new DomainException('등록되지 않은 Jobs 종류입니다.');
        }
        if ($targets === [] || $actorUserId < 1) {
            throw new DomainException('Jobs 대상과 실행 사용자를 확인해 주세요.');
        }
        $targetKeys = [];
        $targetHashes = [];
        foreach ($targets as $target) {
            if (! $target instanceof JobTarget) {
                throw new DomainException('Jobs 대상 형식이 올바르지 않습니다.');
            }
            $compoundKey = $target->targetType() . ':' . $target->targetKey();
            if (isset($targetHashes[$compoundKey])) {
                throw new DomainException('Jobs 대상은 중복될 수 없습니다.');
            }
            $targetKeys[] = $compoundKey;
            $targetHashes[$compoundKey] = $this->hash($target->payload());
        }
        $idempotencyKey = $this->idempotencyKeys->create(
            $definition,
            $executionMode,
            $targetKeys,
            $inputVersion,
            [
                'actor_user_id' => $actorUserId,
                'input_summary' => $inputSummary,
                'target_hashes' => $targetHashes,
            ]
        );
        $references = [];
        try {
            $items = [];
            foreach ($targets as $target) {
                $reference = $this->payloads->store(
                    $jobType,
                    $actorUserId,
                    $target->targetType(),
                    $target->targetKey(),
                    $target->payload()
                );
                $references[] = $reference;
                $items[] = new JobItemPlan(
                    $target->targetType(),
                    $target->targetKey(),
                    $this->hash($target->payload()),
                    $reference,
                    $definition->maxAttempts(),
                    $target->targetVersion(),
                    $target->targetUpdatedAt()
                );
            }
            $result = $this->queue->enqueue(
                new JobExecutionPlan(
                    $definition->key(),
                    $definition->owner()->value(),
                    $definition->definitionVersion(),
                    $inputVersion,
                    $accessPolicyKey,
                    $accessPolicyVersion,
                    $executionMode,
                    $idempotencyKey,
                    $actorUserId,
                    count($items),
                    $inputSummary,
                    $targetSummary
                ),
                $items
            );
            if (! $result->wasCreated()) {
                $this->discard($references, $actorUserId);
            }
        } catch (Throwable $error) {
            $this->discard($references, $actorUserId);
            throw $error;
        }
        // Wake only after commit, outside payload rollback/cleanup handling.
        try {
            $this->wakeup?->wake();
        } catch (Throwable) {
            // Dispatch failure must not undo a committed job; cron can recover it.
        }
        return $result;
    }

    private function hash(mixed $value): string
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
            throw new DomainException('Jobs 입력을 직렬화할 수 없습니다.', 0, $error);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * @param list<FormDraftReference> $references
     */
    private function discard(array $references, int $ownerUserId): void
    {
        foreach ($references as $reference) {
            try {
                $this->payloads->discard($reference, $ownerUserId);
            } catch (Throwable) {
            }
        }
    }
}
