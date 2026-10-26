<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Application;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobStepJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\RecoverableJobStepJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyMigrationGateway;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationStage;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationWorkResult;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use DomainException;
use Throwable;

final class PlatformMigrationJobHandler implements JobHandler
{
    public function __construct(
        private readonly MigrationCatalog $catalog,
        private readonly LegacyMigrationGateway $gateway,
        private readonly MigrationCommandService $commands,
        private readonly MigrationStateService $states,
        private readonly JobStepJournal $steps,
        private readonly Authorization $permissions,
        private readonly ErrorReporter $errors
    ) {
    }

    public function jobType(): string
    {
        return 'platform_migration';
    }

    public function handle(JobWorkItem $item): JobHandlerResult
    {
        $claim = $item->claim();
        if (! $this->permissions->can($claim->createdByUserId(), 'oj_manage_migration')) {
            return JobHandlerResult::needsAttention(
                'migration.permission_changed',
                '현재 권한으로 데이터 이전 작업을 계속할 수 없습니다.'
            );
        }

        try {
            $payload = $item->payload();
            if (! is_array($payload) || ! is_array($payload['plan'] ?? null)) {
                throw new DomainException('저장된 데이터 이전 계획이 올바르지 않습니다.');
            }
            $plan = MigrationPlan::fromArray(
                $payload['plan'],
                fn (string $key): MigrationTable => $this->catalog->find($key)
            );
            if ($claim->targetVersion() === null
                || ! hash_equals($plan->hash(), $claim->targetVersion())
                || ! $this->states->current()->isApprovedFor($plan)) {
                throw new DomainException('현재 승인된 데이터 이전 계획과 작업 계획이 다릅니다.');
            }

            $checkpoints = [];
            $validations = [];
            $resumedTables = 0;
            foreach ($plan->tables() as $order => $table) {
                $stepKey = $this->stepKey($plan->stage(), $table);
                $inputHash = hash(
                    'sha256',
                    $plan->hash() . ':' . $table->key()
                );
                if ($this->steps instanceof RecoverableJobStepJournal) {
                    $checkpoint = $this->steps->completedCheckpoint(
                        $claim,
                        $stepKey,
                        $inputHash
                    );
                    if ($checkpoint !== null) {
                        $checkpoints[$table->key()] = $checkpoint;
                        $validations[$table->key()] = ['resumed' => true];
                        ++$resumedTables;
                        continue;
                    }
                }

                $this->steps->start(
                    $claim,
                    $stepKey,
                    $order + 1,
                    $inputHash,
                    [
                        'table_key' => $table->key(),
                        'plan_hash' => $plan->hash(),
                    ]
                );
                try {
                    $result = $this->gateway->execute($table, $plan);
                } catch (Throwable $error) {
                    $this->errors->report($error);
                    try {
                        $this->steps->fail($claim, $stepKey, [
                            'result_code' => 'migration.execution_failed',
                            'retryable' => true,
                        ]);
                    } catch (Throwable $journalError) {
                        $this->errors->report($journalError);
                    }
                    $failure = new MigrationWorkResult(
                        'migration.execution_failed',
                        '데이터 이전 테이블 작업을 완료하지 못했습니다.',
                        [
                            'plan_hash' => $plan->hash(),
                            'table_key' => $table->key(),
                        ],
                        ['retryable' => true],
                        true
                    );
                    try {
                        $this->commands->recordResult($plan, $failure);
                    } catch (Throwable $stateError) {
                        $this->errors->report($stateError);
                    }

                    return JobHandlerResult::needsAttention(
                        $failure->resultCode(),
                        $failure->message(),
                        [
                            'plan_hash' => $plan->hash(),
                            'table_key' => $table->key(),
                        ]
                    );
                }
                if ($result->needsAttention()) {
                    $this->steps->fail($claim, $stepKey, $result->validation());
                    $this->commands->recordResult($plan, $result);

                    return JobHandlerResult::needsAttention(
                        $result->resultCode(),
                        $result->message(),
                        [
                            'plan_hash' => $plan->hash(),
                            'table_key' => $table->key(),
                        ]
                    );
                }
                if ($this->steps instanceof RecoverableJobStepJournal) {
                    $this->steps->completeWithCheckpoint(
                        $claim,
                        $stepKey,
                        $result->checkpoint(),
                        $result->validation()
                    );
                } else {
                    $this->steps->complete(
                        $claim,
                        $stepKey,
                        $result->validation()
                    );
                }
                $checkpoints[$table->key()] = $result->checkpoint();
                $validations[$table->key()] = $result->validation();
            }

            $result = new MigrationWorkResult(
                $this->resultCode($plan->stage()),
                $this->successMessage($plan->stage()),
                [
                    'plan_hash' => $plan->hash(),
                    'table_count' => count($plan->tables()),
                ],
                [
                    'checkpoint_count' => count($checkpoints),
                    'validation_count' => count($validations),
                    'resumed_table_count' => $resumedTables,
                ]
            );
            $state = $this->commands->recordResult($plan, $result);

            return JobHandlerResult::succeeded(
                $result->resultCode(),
                $result->message(),
                [
                    'plan_hash' => $plan->hash(),
                    'wave' => $plan->wave()->value,
                    'stage' => $plan->stage()->value,
                    'observation_days' => $state->observationDayCount(),
                    'resumed_table_count' => $resumedTables,
                ]
            );
        } catch (DomainException $error) {
            return JobHandlerResult::needsAttention(
                'migration.state_changed',
                $error->getMessage()
            );
        } catch (Throwable $error) {
            $this->errors->report($error);
            return JobHandlerResult::needsAttention(
                'migration.execution_failed',
                '데이터 이전 작업을 완료하지 못했습니다. 시스템 기록의 추적 ID를 확인해 주세요.'
            );
        }
    }

    private function stepKey(MigrationStage $stage, MigrationTable $table): string
    {
        return 's' . $stage->value . '.' . substr(
            hash('sha256', $table->key()),
            0,
            16
        );
    }

    private function resultCode(MigrationStage $stage): string
    {
        return match ($stage) {
            MigrationStage::TargetSchema => 'migration.target_schema_ready',
            MigrationStage::PreCopy => 'migration.pre_copy_complete',
            MigrationStage::FinalCopy => 'migration.final_copy_complete',
            MigrationStage::Validation => 'migration.validation_passed',
            MigrationStage::Cutover => 'migration.cutover_complete',
            MigrationStage::Resume => 'migration.resume_checks_passed',
            MigrationStage::Observation => 'migration.observation_passed',
            MigrationStage::JudgeCompatibility => 'migration.judge_compatible',
            MigrationStage::Cleanup => 'migration.cleanup_report_ready',
            default => throw new DomainException('Jobs에서 실행할 수 없는 데이터 이전 단계입니다.'),
        };
    }

    private function successMessage(MigrationStage $stage): string
    {
        return match ($stage) {
            MigrationStage::TargetSchema => '목표 구조 준비를 완료했습니다.',
            MigrationStage::PreCopy => '사전 복사를 완료했습니다.',
            MigrationStage::FinalCopy => '쓰기 동결 뒤 최종 복사를 완료했습니다.',
            MigrationStage::Validation => '최종 데이터 검증을 통과했습니다.',
            MigrationStage::Cutover => '현재 파동의 모듈 전환을 완료했습니다.',
            MigrationStage::Resume => '제한 점검과 쓰기 재개 검사를 통과했습니다.',
            MigrationStage::Observation => '오늘의 관찰 검사를 통과했습니다.',
            MigrationStage::JudgeCompatibility => 'Judge 호환 검증을 통과했습니다.',
            MigrationStage::Cleanup => '삭제하지 않는 정리 후보 보고서를 만들었습니다.',
            default => throw new DomainException('Jobs에서 실행할 수 없는 데이터 이전 단계입니다.'),
        };
    }
}

