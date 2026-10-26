<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobStepJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeLock;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeParticipant;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemNumberChangeExecutionResult;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemNumberChangePlan;
use DomainException;
use Throwable;

final class ProblemNumberChangeCoordinator
{
    /**
     * @param list<ProblemNumberChangeParticipant> $participants
     */
    public function __construct(
        private readonly array $participants,
        private readonly ProblemNumberChangeLock $lock,
        private readonly JobStepJournal $steps
    ) {
    }

    public function execute(
        ClaimedJobItem $claim,
        ProblemNumberChangePlan $plan
    ): ProblemNumberChangeExecutionResult {
        $lockedIds = array_values(array_unique(array_merge(
            $plan->requested()->sourceIds(),
            $plan->requested()->targetIds(),
            $plan->temporary()->targetIds()
        )));

        return $this->lock->withExclusiveLock(
            $lockedIds,
            function () use ($claim, $plan): ProblemNumberChangeExecutionResult {
                try {
                    $this->validateCurrentState($claim, $plan);
                } catch (Throwable $error) {
                    try {
                        $this->steps->fail(
                            $claim,
                            'validate',
                            ['reason_code' => 'state_changed']
                        );
                    } catch (Throwable) {
                    }
                    throw $error;
                }
                $staged = [];
                $finalized = [];
                $activeStep = null;
                try {
                    foreach ($this->participants as $index => $participant) {
                        $stepKey = 'stage.' . $participant->key();
                        $activeStep = $stepKey;
                        $this->steps->start(
                            $claim,
                            $stepKey,
                            10 + $index,
                            $plan->hash(),
                            ['participant' => $participant->key()]
                        );
                        $participant->move($plan->temporary());
                        $staged[$participant->key()] = $participant;
                        $this->steps->complete(
                            $claim,
                            $stepKey,
                            ['moved' => true]
                        );
                        $activeStep = null;
                    }
                    $finalStage = $plan->finalStage();
                    foreach ($this->participants as $index => $participant) {
                        $stepKey = 'finalize.' . $participant->key();
                        $activeStep = $stepKey;
                        $this->steps->start(
                            $claim,
                            $stepKey,
                            30 + $index,
                            $plan->hash(),
                            ['participant' => $participant->key()]
                        );
                        $participant->move($finalStage);
                        $finalized[$participant->key()] = $participant;
                        $this->steps->complete(
                            $claim,
                            $stepKey,
                            ['moved' => true]
                        );
                        $activeStep = null;
                    }
                    foreach ($this->participants as $index => $participant) {
                        $stepKey = 'verify.' . $participant->key();
                        $activeStep = $stepKey;
                        $this->steps->start(
                            $claim,
                            $stepKey,
                            50 + $index,
                            $plan->hash()
                        );
                        $participant->verifyMoved($plan->requested());
                        $this->steps->complete(
                            $claim,
                            $stepKey,
                            ['verified' => true]
                        );
                        $activeStep = null;
                    }

                    return ProblemNumberChangeExecutionResult::succeeded();
                } catch (Throwable) {
                    if ($activeStep !== null) {
                        try {
                            $this->steps->fail(
                                $claim,
                                $activeStep,
                                ['reason_code' => 'step_failed']
                            );
                        } catch (Throwable) {
                        }
                    }
                    $compensationFailed = false;
                    foreach (array_reverse($this->participants) as $participant) {
                        $key = $participant->key();
                        if (! isset($staged[$key])) {
                            continue;
                        }
                        $wasFinalized = isset($finalized[$key]);
                        $stepKey = $wasFinalized
                            ? 'finalize.' . $key
                            : 'stage.' . $key;
                        $reverse = $wasFinalized
                            ? $plan->requested()->inverse()
                            : $plan->temporary()->inverse();
                        try {
                            $participant->move($reverse);
                            $participant->verifyMoved($reverse);
                            $this->steps->compensate(
                                $claim,
                                $stepKey,
                                true,
                                ['restored' => true]
                            );
                        } catch (Throwable) {
                            $compensationFailed = true;
                            try {
                                $this->steps->compensate(
                                    $claim,
                                    $stepKey,
                                    false,
                                    ['restored' => false]
                                );
                            } catch (Throwable) {
                            }
                        }
                    }

                    return $compensationFailed
                        ? ProblemNumberChangeExecutionResult::compensationFailed()
                        : ProblemNumberChangeExecutionResult::compensated();
                }
            }
        );
    }

    private function validateCurrentState(
        ClaimedJobItem $claim,
        ProblemNumberChangePlan $plan
    ): void {
        $this->steps->start(
            $claim,
            'validate',
            1,
            $plan->hash(),
            ['mapping_count' => $plan->requested()->count()]
        );
        $impact = [];
        foreach ($this->participants as $participant) {
            $impact[$participant->key()] = $participant->inspect(
                $plan->requested()
            );
            $participant->inspect($plan->temporary());
        }
        if ($this->canonicalImpact($impact) !== $this->canonicalImpact($plan->impactCounts())) {
            throw new DomainException(
                '문제 번호 참조 수가 영향 확인 이후 변경되었습니다.'
            );
        }
        $this->steps->complete(
            $claim,
            'validate',
            ['impact_unchanged' => true]
        );
    }

    /**
     * @param array<string, array<string, int>> $impact
     *
     * @return array<string, array<string, int>>
     */
    private function canonicalImpact(array $impact): array
    {
        ksort($impact, SORT_STRING);
        foreach ($impact as &$counts) {
            ksort($counts, SORT_STRING);
        }
        unset($counts);

        return $impact;
    }
}
