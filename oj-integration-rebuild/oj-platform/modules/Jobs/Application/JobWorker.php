<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Application;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQueue;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobResultJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDefinition;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Jobs\Registry\JobHandlerRegistry;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SystemOperationPolicy;
use Throwable;

final class JobWorker
{
    private const MAX_ITEMS = 25;
    private const MAX_SECONDS = 20;
    private const LEASE_SECONDS = 300;

    public function __construct(
        private readonly JobQueue $queue,
        private readonly JobHandlerRegistry $handlers,
        private readonly DefinitionRegistry $definitions,
        private readonly SystemOperationPolicy $operations,
        private readonly FormDraftStore $payloads,
        private readonly JobResultJournal $results,
        private readonly ErrorReporter $errors
    ) {
    }

    /** True permits immediate continuation; storage failures defer to recovery. */
    public function run(): bool
    {
        $startedAt = microtime(true);
        $workerId = 'wordpress-' . bin2hex(random_bytes(8));
        for ($processed = 0; $processed < self::MAX_ITEMS; ++$processed) {
            if (microtime(true) - $startedAt >= self::MAX_SECONDS) {
                return true;
            }
            try {
                $claim = $this->queue->claim($workerId, self::LEASE_SECONDS);
            } catch (Throwable $error) {
                $this->errors->report($error);
                return false;
            }
            if ($claim === null) {
                return true;
            }

            try {
                $result = $this->results->find($claim);
            } catch (Throwable $error) {
                $this->errors->report($error);
                return false;
            }
            try {
                if ($result === null) {
                    $definition = $this->definitions->find($claim->jobType());
                    $handler = $this->handlers->find($claim->jobType());
                    if (! $definition instanceof JobDefinition || $handler === null) {
                        $result = JobHandlerResult::needsAttention(
                            'handler_not_registered',
                            '작업 실행기를 찾을 수 없습니다.'
                        );
                    } elseif (! $this->operations->allowsJob(
                        $definition->owner()->value(),
                        $claim->jobType()
                    )) {
                        $result = $claim->attempt() < $claim->maxAttempts()
                            ? JobHandlerResult::retry(
                                'module_write_frozen',
                                '데이터 이전 쓰기 동결이 해제되면 작업을 다시 시도합니다.',
                                300
                            )
                            : JobHandlerResult::needsAttention(
                                'module_write_frozen',
                                '데이터 이전 쓰기 동결로 작업 실행을 멈췄습니다.'
                            );
                    } else {
                        $draft = $this->payloads->find(
                            $claim->payloadReference(),
                            $claim->createdByUserId()
                        );
                        if (
                            $draft === null
                            || $draft->draftType() !== $claim->jobType()
                            || $draft->targetType() !== $claim->targetType()
                            || $draft->targetKey() !== $claim->targetKey()
                        ) {
                            $result = JobHandlerResult::needsAttention(
                                'payload_unavailable',
                                '작업 입력이 만료되었거나 일치하지 않습니다.'
                            );
                        } else {
                            $result = $handler->handle(new JobWorkItem(
                                $claim,
                                $draft->payload()
                            ));
                        }
                    }
                }
            } catch (Throwable $error) {
                $this->errors->report($error);
                $result = $claim->attempt() < $claim->maxAttempts()
                    ? JobHandlerResult::retry(
                        'handler_temporary_failure',
                        '작업을 다시 시도합니다.',
                        min(900, 30 * (2 ** max(0, $claim->attempt() - 1)))
                    )
                    : JobHandlerResult::failed(
                        'handler_failed',
                        '작업을 완료하지 못했습니다.'
                    );
            }

            try {
                if ($result->outcome() === 'succeeded') {
                    $this->results->save($claim, $result);
                }
                $this->queue->finish($claim, $result);
                if ($result->outcome() === 'succeeded') {
                    $this->payloads->discard(
                        $claim->payloadReference(),
                        $claim->createdByUserId()
                    );
                }
            } catch (Throwable $error) {
                $this->errors->report($error);
                return false;
            }
        }
        return true;
    }
}
