<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobTarget;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeParticipant;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemNumberChangePlan;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemNumberMap;
use DomainException;
use InvalidArgumentException;
use JsonException;

final class ProblemNumberChangeService
{
    /**
     * @param list<ProblemNumberChangeParticipant> $participants
     */
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemAccessPolicy $access,
        private readonly array $participants,
        private readonly JobDispatcher $jobs
    ) {
        $keys = [];
        foreach ($this->participants as $participant) {
            if (
                ! $participant instanceof ProblemNumberChangeParticipant
                || isset($keys[$participant->key()])
            ) {
                throw new InvalidArgumentException(
                    '문제 번호 변경 참여자가 올바르지 않거나 중복되었습니다.'
                );
            }
            $keys[$participant->key()] = true;
        }
    }

    public function fromRange(
        int $sourceStart,
        int $sourceEnd,
        int $targetStart
    ): ProblemNumberMap {
        try {
            return ProblemNumberMap::range(
                $sourceStart,
                $sourceEnd,
                $targetStart
            );
        } catch (InvalidArgumentException $error) {
            throw new DomainException($error->getMessage(), 0, $error);
        }
    }

    public function fromPairsText(string $text): ProblemNumberMap
    {
        $lines = preg_split('/\R/u', trim($text));
        if (! is_array($lines) || $lines === []) {
            throw new DomainException('현재 번호와 새 번호 쌍을 입력해 주세요.');
        }
        $pairs = [];
        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (
                preg_match('/^([1-9][0-9]*)\s*(?:,|\t|\s)\s*([1-9][0-9]*)$/', $line, $match) !== 1
            ) {
                throw new DomainException(
                    ($lineNumber + 1) . '번째 문제 번호 쌍 형식을 확인해 주세요.'
                );
            }
            $source = (int) $match[1];
            $target = (int) $match[2];
            if (isset($pairs[$source])) {
                throw new DomainException('같은 원본 문제 번호를 두 번 입력할 수 없습니다.');
            }
            $pairs[$source] = $target;
        }
        try {
            return new ProblemNumberMap($pairs);
        } catch (InvalidArgumentException $error) {
            throw new DomainException($error->getMessage(), 0, $error);
        }
    }

    public function preview(
        int $actorUserId,
        ProblemNumberMap $mapping
    ): ProblemNumberChangePlan {
        if (! $this->access->canChangeIds($actorUserId)) {
            throw new DomainException('문제 번호를 변경할 권한이 없습니다.');
        }
        foreach ($mapping->sourceIds() as $source) {
            if ($this->problems->find(new ProblemId($source)) === null) {
                throw new DomainException($source . '번 문제를 찾을 수 없습니다.');
            }
        }
        $sourceSet = array_fill_keys($mapping->sourceIds(), true);
        foreach ($mapping->targetIds() as $target) {
            if (
                ! isset($sourceSet[$target])
                && $this->problems->find(new ProblemId($target)) !== null
            ) {
                throw new DomainException($target . '번 문제가 이미 존재합니다.');
            }
        }

        $next = $this->problems->nextId()->value();
        $base = max(
            $next,
            max($mapping->sourceIds()),
            max($mapping->targetIds())
        ) + 1000;
        $temporaryPairs = [];
        foreach ($mapping->sourceIds() as $offset => $source) {
            $temporaryPairs[$source] = $base + $offset;
        }
        try {
            $temporary = new ProblemNumberMap($temporaryPairs);
        } catch (InvalidArgumentException $error) {
            throw new DomainException('안전한 임시 문제 번호를 만들지 못했습니다.', 0, $error);
        }

        $impact = [];
        foreach ($this->participants as $participant) {
            $impact[$participant->key()] = $participant->inspect($mapping);
            $participant->inspect($temporary);
        }

        return new ProblemNumberChangePlan($mapping, $temporary, $impact);
    }

    public function enqueue(
        int $actorUserId,
        ProblemNumberMap $mapping,
        string $expectedPlanHash
    ): JobEnqueueResult {
        $plan = $this->preview($actorUserId, $mapping);
        if (
            preg_match('/^[a-f0-9]{64}$/', $expectedPlanHash) !== 1
            || ! hash_equals($expectedPlanHash, $plan->hash())
        ) {
            throw new DomainException('영향 정보가 변경되었습니다. 다시 확인해 주세요.');
        }
        $payload = $this->planPayload($plan);

        return $this->jobs->enqueue(
            'problem_number_change',
            'mapping',
            $actorUserId,
            [
                new JobTarget(
                    'problem_number_mapping',
                    $plan->requested()->hash(),
                    $payload,
                    $plan->hash()
                ),
            ],
            [
                'mapping_count' => $mapping->count(),
                'plan_hash' => $plan->hash(),
            ],
            [
                'source_start' => min($mapping->sourceIds()),
                'source_end' => max($mapping->sourceIds()),
                'mapping_count' => $mapping->count(),
            ],
            'problems.change_id',
            '1',
            '1'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function planPayload(ProblemNumberChangePlan $plan): array
    {
        return [
            'requested' => $plan->requested()->pairs(),
            'temporary' => $plan->temporary()->pairs(),
            'impact_counts' => $plan->impactCounts(),
            'plan_hash' => $plan->hash(),
        ];
    }

    public function planFromPayload(mixed $payload): ProblemNumberChangePlan
    {
        if (! is_array($payload)) {
            throw new DomainException('문제 번호 변경 작업 입력이 올바르지 않습니다.');
        }
        try {
            $requested = new ProblemNumberMap(
                $this->integerMap($payload['requested'] ?? null)
            );
            $temporary = new ProblemNumberMap(
                $this->integerMap($payload['temporary'] ?? null)
            );
        } catch (InvalidArgumentException $error) {
            throw new DomainException($error->getMessage(), 0, $error);
        }
        $impact = $payload['impact_counts'] ?? null;
        if (! is_array($impact)) {
            throw new DomainException('문제 번호 변경 영향 입력이 올바르지 않습니다.');
        }
        $normalizedImpact = [];
        foreach ($impact as $participant => $counts) {
            if (! is_string($participant) || ! is_array($counts)) {
                throw new DomainException('문제 번호 변경 영향 입력이 올바르지 않습니다.');
            }
            foreach ($counts as $key => $count) {
                if (! is_string($key) || ! is_int($count) || $count < 0) {
                    throw new DomainException('문제 번호 변경 영향 수가 올바르지 않습니다.');
                }
                $normalizedImpact[$participant][$key] = $count;
            }
        }
        $plan = new ProblemNumberChangePlan(
            $requested,
            $temporary,
            $normalizedImpact
        );
        $planHash = $payload['plan_hash'] ?? null;
        if (! is_string($planHash) || ! hash_equals($plan->hash(), $planHash)) {
            throw new DomainException('문제 번호 변경 계획 해시가 올바르지 않습니다.');
        }

        return $plan;
    }

    public function serializeMapping(ProblemNumberMap $mapping): string
    {
        $pairs = [];
        foreach ($mapping->pairs() as $source => $target) {
            $pairs[] = ['source' => $source, 'target' => $target];
        }
        try {
            return json_encode(
                $pairs,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $error) {
            throw new DomainException('문제 번호 매핑을 직렬화하지 못했습니다.', 0, $error);
        }
    }

    public function deserializeMapping(string $json): ProblemNumberMap
    {
        try {
            $rows = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new DomainException('문제 번호 매핑 입력이 올바르지 않습니다.', 0, $error);
        }
        if (! is_array($rows)) {
            throw new DomainException('문제 번호 매핑 입력이 올바르지 않습니다.');
        }
        $pairs = [];
        foreach ($rows as $row) {
            if (
                ! is_array($row)
                || ! is_int($row['source'] ?? null)
                || ! is_int($row['target'] ?? null)
            ) {
                throw new DomainException('문제 번호 매핑 행이 올바르지 않습니다.');
            }
            $pairs[$row['source']] = $row['target'];
        }
        try {
            return new ProblemNumberMap($pairs);
        } catch (InvalidArgumentException $error) {
            throw new DomainException($error->getMessage(), 0, $error);
        }
    }

    /**
     * @return array<int, int>
     */
    private function integerMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new DomainException('문제 번호 매핑 입력이 올바르지 않습니다.');
        }
        $pairs = [];
        foreach ($value as $source => $target) {
            if (
                (! is_int($source) && ! (is_string($source) && ctype_digit($source)))
                || ! is_int($target)
            ) {
                throw new DomainException('문제 번호 매핑 입력이 올바르지 않습니다.');
            }
            $pairs[(int) $source] = $target;
        }

        return $pairs;
    }
}
