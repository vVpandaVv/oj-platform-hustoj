<?php
declare(strict_types=1);
namespace OjPrimm\Application;

use DomainException;
use OjPrimm\Generation\LessonSchema;
use OjPrimm\Storage\PracticeStore;
use OjPrimm\Storage\Repository;
use Throwable;

final class PracticeService
{
    public const MAX_AGE_SECONDS = 86400;
    public function __construct(private readonly Repository $records, private readonly PracticeStore $runs,
        private readonly PlatformAccess $platform, private readonly PracticeExecutor $executor) {}
    public function run(int $id, array $input): array
    {
        $activity = $this->authorized($id); $actor = $this->platform->actorId();
        $source = LessonSchema::text($input['source'] ?? '', 24000, '연습 코드');
        $material = $this->material($activity);
        $hash = hash('sha256', json_encode([$source, $material], JSON_THROW_ON_ERROR));
        $key = $input['request_key'] ?? '';
        if (! is_string($key) || preg_match('/^[a-zA-Z0-9_.:-]{8,160}$/D', $key) !== 1) { throw new DomainException('연습 요청 키를 확인해 주세요.'); }
        $ticket = $input['ticket'] ?? null;
        if ($ticket !== null && $ticket !== '') {
            if ((! is_int($ticket) && ! is_string($ticket)) || preg_match('/^[1-9][0-9]{0,17}$/D', (string) $ticket) !== 1) { throw new DomainException('연습 확인 번호가 올바르지 않습니다.'); }
            $run = $this->runs->find((int) $ticket) ?? throw new DomainException('연습 요청을 찾을 수 없습니다.');
        } else {
            $fingerprint = hash('sha256', json_encode([$actor, $id, $hash, $key], JSON_THROW_ON_ERROR));
            $run = $this->runs->createOrFind($id, $actor, $fingerprint, ['source' => $source, 'source_hash' => hash('sha256', $source),
                'material_hash' => $hash, 'request_key' => $key, 'executions' => [], 'results' => [], 'all_passed' => false]);
        }
        if ($run['activity_id'] !== $id || $run['user_id'] !== $actor || ! hash_equals($run['material_hash'], $hash)) { throw new DomainException('이 연습은 현재 학습자·수업·코드에 해당하지 않습니다. 코드가 바뀌면 새 검증을 시작해 주세요.'); }
        if ($run['status'] === 'completed') { return $this->publicRun($run); }
        $this->assertLifetime($run);
        $lease = bin2hex(random_bytes(24));
        if (! $this->runs->claim($run['id'], $lease)) { return $this->publicRun($this->runs->find($run['id']) ?? $run); }
        try {
            $run = $this->runs->find($run['id']) ?? throw new DomainException('연습을 찾을 수 없습니다.');
            $this->assertLifetime($run);
            foreach ($material['tests'] as $index => $test) {
                if (isset($run['results'][$index])) { continue; }
                $result = $this->executor->execute($actor, $material, $source, $index,
                    'practice:' . $run['id'] . ':' . $index . ':' . $hash, $run['executions'][$index] ?? null);
                if ($this->platform instanceof ActorRevalidationAccess) { $this->platform->refreshActor(); }
                if ($this->platform->actorId() !== $actor) { throw new DomainException('연습을 시작한 학습자 계정이 변경되었습니다. 다시 로그인해 주세요.'); }
                $this->authorized($id);
                if (! is_string($result['ticket'] ?? null)) { throw new DomainException('플랫폼 연습 응답을 확인하지 못했습니다.'); }
                $run['executions'][$index] = $result['ticket'];
                if (($result['status'] ?? '') === 'completed') {
                    $case = ['case_id' => 'modify:' . $index, 'phase' => 'modify', 'language' => $material['language'],
                        'source' => $source, 'input' => $test['input'], 'expected_output' => $test['expected_output']];
                    $verified = LessonVerification::compare($result, $case);
                    $run['results'][$index] = ['index' => $index + 1, 'verdict' => $verified['verdict'],
                        'actual_output' => $verified['actual_output'], 'diagnostics' => $verified['diagnostics']];
                } elseif (($result['status'] ?? '') !== 'pending') { throw new DomainException('연습 실행 상태가 올바르지 않습니다.'); }
                break;
            }
            $done = count($run['results']) === count($material['tests']);
            $run['all_passed'] = $done && array_filter($run['results'], static fn (array $item): bool => $item['verdict'] !== 'accepted') === [];
            $run['checked_at'] = $done ? gmdate('Y-m-d H:i:s') : null;
            return $this->publicRun($this->runs->save($run['id'], $lease, $this->state($run), $done ? 'completed' : 'pending'));
        } catch (Throwable $error) {
            // An infrastructure error keeps its source/tickets for explicit polling, never a false correctness result.
            $this->runs->save($run['id'], $lease, $this->state($run), 'pending'); throw $error;
        }
    }
    public function latest(int $id): ?array
    {
        $this->authorized($id); $run = $this->runs->latest($id, $this->platform->actorId()); return $run === null ? null : $this->publicRun($run);
    }
    private function authorized(int $id): array
    {
        $this->platform->assertRole('learn');
        $activity = $this->records->findActivity($id) ?? throw new DomainException('수업 자료가 없습니다.');
        if (($activity['status'] ?? 'active') !== 'active') { throw new DomainException('현재 공개된 활동에서만 연습할 수 있습니다.'); }
        $actor = $this->platform->actorId();
        $this->platform->authorize($activity['context_type'], $activity['context_id'], 'participate', $actor);
        $attempt = $this->records->findAttempt($id, $actor);
        if ($attempt === null || ! in_array($attempt['current_stage'], ['modify', 'make'], true)) { throw new DomainException('탐구를 마친 뒤 수정 단계에서 연습 실행을 사용할 수 있습니다.'); }
        $problem = $this->platform->problem($activity['target_problem_id'], $activity['context_type'], $activity['context_id'])['problem'];
        if (! hash_equals($activity['target_hash'], $problem['content_hash'])) { throw new DomainException('목표 문제가 변경되었습니다. 새 활동에서 연습해 주세요.'); }
        if (! isset($activity['lesson']) || ($activity['lesson']['verification']['status'] ?? '') !== 'verified') { throw new DomainException('OJ로 검증된 AI 수업에서만 수정 연습 실행을 사용할 수 있습니다.'); }
        return $activity;
    }
    private function material(array $activity): array
    {
        return ['activity_key' => 'primm:' . $activity['id'], 'revision' => LessonVerification::lessonHash($activity['lesson']),
            'context_type' => $activity['context_type'], 'context_id' => $activity['context_id'],
            'language' => $activity['lesson']['language'], 'tests' => $activity['lesson']['stages'][3]['tests']];
    }
    private function state(array $run): array { return array_diff_key($run, array_flip(['id', 'activity_id', 'user_id', 'status', 'lease_token', 'created_at', 'updated_at'])); }
    private function publicRun(array $run): array
    {
        return ['id' => $run['id'], 'ticket' => (string) $run['id'], 'status' => $run['status'],
            'results' => array_values($run['results']), 'all_passed' => $run['all_passed'],
            'source_hash' => $run['source_hash'], 'checked_at' => $run['checked_at'] ?? null,
            'expired' => $run['status'] !== 'completed' && $this->expired($run)];
    }
    private function expired(array $run): bool
    {
        $value = $run['created_at'] ?? null;
        if (! is_string($value)) { return true; }
        $created = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $created === false || $created->format('Y-m-d H:i:s') !== $value
            || time() - $created->getTimestamp() >= self::MAX_AGE_SECONDS || $created->getTimestamp() > time() + 300;
    }
    private function assertLifetime(array $run): void
    {
        if ($this->expired($run)) { throw new DomainException('연습 요청의 유효 시간 24시간이 지났습니다. 새 연습 실행을 시작해 주세요.'); }
    }
}
