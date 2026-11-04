<?php
declare(strict_types=1);
namespace OjMicrobit\Storage;

use DomainException;
use InvalidArgumentException;
use OjMicrobit\Application\Repository;
use OjMicrobit\Domain\ExerciseSchema;
use OjMicrobit\Support\OperationError;
use RuntimeException;

final class WpdbRepository implements Repository
{
    public function __construct(private readonly object $db) {}
    private function table(string $name): string { return '`' . str_replace('`', '``', $this->db->prefix . 'oj_microbit_' . $name) . '`'; }
    private function raw(string $name): string { return $this->db->prefix . 'oj_microbit_' . $name; }
    private function encode(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function decode(string $data): array
    {
        $value = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new RuntimeException('Invalid stored microbit record.'); }
        return $value;
    }
    private function checkedRows(mixed $rows): array
    {
        if (!is_array($rows) || (string) $this->db->last_error !== '') { throw new RuntimeException('Microbit storage query failed.'); }
        return $rows;
    }
    private function hydrated(array $row): array
    {
        $data = $this->decode($row['data_json']);
        $audience = ExerciseSchema::audience($data['audience'] ?? ['mode' => 'site', 'classIds' => []]);
        if ($audience['mode'] !== $row['audience_mode']) { throw new RuntimeException('Microbit stored audience mode is inconsistent.'); }
        return array_replace($data, ['id' => (int) $row['id'], 'ownerId' => (int) $row['owner_id'],
            'revision' => (int) $row['revision'], 'status' => $row['status'], 'updatedAt' => $row['updated_at'],
            'audience' => $audience]);
    }
    private function page(int $beforeId, int $limit): void
    {
        if ($beforeId < 0 || $limit < 1 || $limit > 51) { throw new InvalidArgumentException('목록 커서와 조회 개수를 확인해 주세요.'); }
    }
    private function exerciseMetadata(array $row): array
    {
        return ['id' => (int) $row['id'], 'ownerId' => (int) $row['owner_id'], 'title' => $row['title'],
            'status' => $row['status'], 'revision' => (int) $row['revision'], 'updatedAt' => $row['updated_at'],
            'audience' => $row['audience_mode'] === 'groups' ? ['mode' => 'groups', 'groupIds' => $row['group_ids'] ?? []] : ['mode' => $row['audience_mode'], 'classIds' => $row['class_ids'] ?? []]];
    }
    private function audienceRows(array $rows): array
    {
        $parts = []; $maximum = 0;
        foreach (['classes' => ['exercise_classes', 'class_id'], 'groups' => ['exercise_groups', 'group_id']] as $mode => [$table, $key]) {
            $ids = [];
            foreach ($rows as $row) { if ($row['audience_mode'] === $mode) { $ids[] = (int) $row['id']; } }
            if ($ids === []) { continue; }
            $bound = count($ids) * 50; $maximum += $bound;
            $slots = implode(',', array_fill(0, count($ids), '%d'));
            $parts[] = 'SELECT exercise_id,target_id,scope_type FROM (' . $this->db->prepare('SELECT exercise_id,' . $key . " AS target_id,'" . $mode . "' AS scope_type FROM " . $this->table($table)
                . ' WHERE exercise_id IN (' . $slots . ') ORDER BY exercise_id,' . $key . ' LIMIT ' . ($bound + 1), ...$ids) . ') AS projection_' . $mode;
        }
        if ($parts === []) { return $rows; }
        $links = $this->checkedRows($this->db->get_results(implode(' UNION ALL ', $parts) . ' LIMIT ' . ($maximum + 1), ARRAY_A));
        if (count($links) > $maximum) { throw new RuntimeException('Microbit audience projection exceeds its storage bound.'); }
        $byId = [];
        foreach ($links as $link) { $byId[$link['scope_type']][(int) $link['exercise_id']][] = (int) $link['target_id']; }
        foreach ($rows as &$row) {
            $row['class_ids'] = $byId['classes'][(int) $row['id']] ?? [];
            $row['group_ids'] = $byId['groups'][(int) $row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }
    public function exercises(?int $ownerId, bool $published, int $beforeId = 0, int $limit = 21): array
    {
        $this->page($beforeId, $limit);
        $where = 'archived=0'; $args = [];
        if ($ownerId !== null) { $where .= ' AND owner_id=%d'; $args[] = $ownerId; }
        if ($published) { $where .= " AND status='published'"; }
        if ($beforeId > 0) { $where .= ' AND id<%d'; $args[] = $beforeId; }
        $args[] = $limit;
        $query = 'SELECT id,owner_id,title,status,revision,updated_at,audience_mode FROM ' . $this->table('exercises') . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT %d';
        $rows = $this->checkedRows($this->db->get_results($this->db->prepare($query, ...$args), ARRAY_A));
        return array_map(fn (array $row): array => $this->exerciseMetadata($row), $this->audienceRows($rows));
    }
    public function learnerExercises(array $classIds, int $beforeId = 0, int $limit = 21, array $groupIds = []): array
    {
        $this->page($beforeId, $limit);
        if (!array_is_list($classIds) || count($classIds) > 100 || count(array_unique($classIds, SORT_REGULAR)) !== count($classIds)) { throw new InvalidArgumentException('수업 조회 범위를 확인해 주세요.'); }
        foreach ($classIds as $id) { if (!is_int($id) || $id < 1) { throw new InvalidArgumentException('수업 번호를 확인해 주세요.'); } }
        if (!array_is_list($groupIds) || count($groupIds) > 100 || count(array_unique($groupIds, SORT_REGULAR)) !== count($groupIds)) { throw new InvalidArgumentException('그룹 조회 범위를 확인해 주세요.'); }
        foreach ($groupIds as $id) { if (!is_int($id) || $id < 1) { throw new InvalidArgumentException('그룹 번호를 확인해 주세요.'); } }
        $columns = 'id,owner_id,title,status,revision,updated_at,audience_mode';
        $cursor = $beforeId > 0 ? ' AND id<%d' : '';
        $siteArgs = $beforeId > 0 ? [$beforeId, $limit] : [$limit];
        $site = $this->db->prepare("SELECT id AS exercise_id,'site' AS audience_mode FROM " . $this->table('exercises')
            . " WHERE audience_mode='site' AND status='published' AND archived=0" . $cursor . ' ORDER BY id DESC LIMIT %d', ...$siteArgs);
        if ($classIds === [] && $groupIds === []) {
            $query = $this->db->prepare('SELECT ' . $columns . ' FROM ' . $this->table('exercises')
                . " WHERE audience_mode='site' AND status='published' AND archived=0" . $cursor . ' ORDER BY id DESC LIMIT %d', ...$siteArgs);
        } else {
            // Each branch reads at most one page from a covering index. The
            // bounded UNION deduplicates group and legacy lesson assignments before paging.
            $parts = ['SELECT exercise_id,audience_mode FROM (' . $site . ') AS site_candidates'];
            foreach ($classIds as $index => $classId) {
                $args = $beforeId > 0 ? [$classId, $beforeId, $limit] : [$classId, $limit];
                $part = $this->db->prepare("SELECT exercise_id,'classes' AS audience_mode FROM " . $this->table('exercise_classes')
                    . " WHERE class_id=%d AND status='published' AND archived=0" . ($beforeId > 0 ? ' AND exercise_id<%d' : '')
                    . ' ORDER BY exercise_id DESC LIMIT %d', ...$args);
                $parts[] = 'SELECT exercise_id,audience_mode FROM (' . $part . ') AS class_candidates_' . $index;
            }
            foreach ($groupIds as $index => $groupId) {
                $args = $beforeId > 0 ? [$groupId, $beforeId, $limit] : [$groupId, $limit];
                $part = $this->db->prepare("SELECT exercise_id,'groups' AS audience_mode FROM " . $this->table('exercise_groups')
                    . " WHERE group_id=%d AND status='published' AND archived=0" . ($beforeId > 0 ? ' AND exercise_id<%d' : '')
                    . ' ORDER BY exercise_id DESC LIMIT %d', ...$args);
                $parts[] = 'SELECT exercise_id,audience_mode FROM (' . $part . ') AS group_candidates_' . $index;
            }
            $query = 'SELECT ' . implode(',', array_map(static fn (string $column): string => 'e.' . $column, explode(',', $columns)))
                . ' FROM ' . $this->table('exercises') . ' e INNER JOIN (' . implode(' UNION ', $parts) . ') allowed ON allowed.exercise_id=e.id AND allowed.audience_mode=e.audience_mode'
                . " WHERE e.status='published' AND e.archived=0 ORDER BY e.id DESC LIMIT " . $limit;
        }
        $rows = $this->checkedRows($this->db->get_results($query, ARRAY_A));
        return array_map(fn (array $row): array => $this->exerciseMetadata($row), $this->audienceRows($rows));
    }
    public function exercise(int $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM ' . $this->table('exercises') . ' WHERE id=%d AND archived=0', $id), ARRAY_A);
        if ((string) $this->db->last_error !== '') { throw new RuntimeException('Microbit exercise query failed.'); }
        return is_array($row) ? $this->hydrated($row) : null;
    }
    public function exerciseSummary(int $id): ?array
    {
        // One statement keeps audience and revision consistent during a scope
        // change; authorization never combines old content with new links.
        $rows = $this->checkedRows($this->db->get_results($this->db->prepare('SELECT e.id,e.owner_id,e.title,e.status,e.revision,e.updated_at,e.audience_mode,c.class_id,g.group_id FROM '
            . $this->table('exercises') . ' e LEFT JOIN ' . $this->table('exercise_classes') . " c ON c.exercise_id=e.id AND e.audience_mode='classes'"
            . ' LEFT JOIN ' . $this->table('exercise_groups') . " g ON g.exercise_id=e.id AND e.audience_mode='groups'"
            . ' WHERE e.id=%d AND e.archived=0 ORDER BY c.class_id,g.group_id LIMIT 51', $id), ARRAY_A));
        if ($rows === []) { return null; }
        if (count($rows) > 50) { throw new RuntimeException('Microbit audience summary exceeds its storage bound.'); }
        $row = $rows[0]; $row['class_ids'] = [];
        if ($row['audience_mode'] === 'classes') { foreach ($rows as $link) { if ($link['class_id'] !== null) { $row['class_ids'][] = (int) $link['class_id']; } } }
        $row['group_ids'] = [];
        if ($row['audience_mode'] === 'groups') { foreach ($rows as $link) { if ($link['group_id'] !== null) { $row['group_ids'][] = (int) $link['group_id']; } } }
        return $this->exerciseMetadata($row);
    }
    public function saveExercise(array $data, int $ownerId, ?int $id, int $revision): array
    {
        $audience = ExerciseSchema::audience($data['audience'] ?? ['mode' => 'site', 'classIds' => []]);
        $now = gmdate('Y-m-d H:i:s');
        $this->beginAudienceWrite();
        try {
        if ($id === null) {
            if ($revision !== 0) { throw new DomainException('새 과제의 저장 버전이 올바르지 않습니다.'); }
            $ok = $this->db->insert($this->raw('exercises'), ['owner_id' => $ownerId, 'title' => $data['title'], 'status' => $data['status'],
                'revision' => 1, 'archived' => 0, 'audience_mode' => $audience['mode'], 'data_json' => $this->encode($data), 'created_at' => $now, 'updated_at' => $now]);
            if ($ok !== 1) { throw new RuntimeException('Microbit exercise insert failed.'); }
            $id = (int) $this->db->insert_id;
        } else {
            $changed = $this->db->query($this->db->prepare('UPDATE ' . $this->table('exercises')
                . ' SET title=%s,status=%s,audience_mode=%s,data_json=%s,revision=revision+1,updated_at=%s WHERE id=%d AND owner_id=%d AND revision=%d AND archived=0',
                $data['title'], $data['status'], $audience['mode'], $this->encode($data), $now, $id, $ownerId, $revision));
            if ($changed === false) { throw new RuntimeException('Microbit exercise update failed.'); }
            if ($changed !== 1) { throw new OperationError('conflict', 409, '과제가 변경되었습니다. 최신 내용을 다시 불러와 주세요.'); }
        }
        foreach (['classes' => ['exercise_classes', 'class_id', 'classIds'], 'groups' => ['exercise_groups', 'group_id', 'groupIds']] as $mode => [$table, $key, $field]) {
            if ($this->db->query($this->db->prepare('DELETE FROM ' . $this->table($table) . ' WHERE exercise_id=%d', $id)) === false) { throw new RuntimeException('Microbit audience link replacement failed.'); }
            if ($audience['mode'] !== $mode) { continue; }
            $values = []; $args = [];
            foreach ($audience[$field] as $target) { $values[] = '(%d,%d,%s,0)'; array_push($args, $id, $target, $data['status']); }
            if ($this->db->query($this->db->prepare('INSERT INTO ' . $this->table($table) . ' (exercise_id,' . $key . ',status,archived) VALUES ' . implode(',', $values), ...$args)) === false) { throw new RuntimeException('Microbit audience link insert failed.'); }
        }
        $this->commitAudienceWrite();
        } catch (\Throwable $error) { $this->db->query('ROLLBACK'); throw $error; }
        return $this->exercise($id) ?? throw new RuntimeException('Saved exercise not found.');
    }
    private function beginAudienceWrite(): void
    {
        foreach (['exercises', 'exercise_classes', 'exercise_groups'] as $table) {
            $rows = $this->checkedRows($this->db->get_results($this->db->prepare('SHOW TABLE STATUS WHERE Name=%s', $this->raw($table)), ARRAY_A));
            if (count($rows) !== 1 || strcasecmp((string) ($rows[0]['Engine'] ?? ''), 'InnoDB') !== 0) { throw new OperationError('storage_unavailable', 503, '그룹·수업 배포를 원자적으로 저장할 수 없습니다. InnoDB 저장소 상태를 확인해 주세요.'); }
        }
        if ($this->db->query('START TRANSACTION') === false) { throw new RuntimeException('Microbit audience transaction failed.'); }
    }
    private function commitAudienceWrite(): void
    {
        if ($this->db->query('COMMIT') === false) { throw new RuntimeException('Microbit audience commit failed.'); }
    }
    public function archiveExercise(int $id, int $revision): void
    {
        $this->beginAudienceWrite();
        try {
        $changed = $this->db->query($this->db->prepare('UPDATE ' . $this->table('exercises')
            . ' SET archived=1,revision=revision+1,updated_at=%s WHERE id=%d AND revision=%d AND archived=0', gmdate('Y-m-d H:i:s'), $id, $revision));
        if ($changed === false) { throw new RuntimeException('Microbit archive failed.'); }
        if ($changed !== 1) { throw new OperationError('conflict', 409, '과제가 변경되었습니다. 최신 내용을 다시 불러와 주세요.'); }
        foreach (['exercise_classes', 'exercise_groups'] as $table) {
            if ($this->db->query($this->db->prepare('DELETE FROM ' . $this->table($table) . ' WHERE exercise_id=%d', $id)) === false) { throw new RuntimeException('Microbit archived audience link cleanup failed.'); }
        }
        $this->commitAudienceWrite();
        } catch (\Throwable $error) { $this->db->query('ROLLBACK'); throw $error; }
    }
    public function project(int $exerciseId, int $userId): ?array
    {
        $row = $this->db->get_row($this->db->prepare('SELECT revision,data_json FROM ' . $this->table('projects') . ' WHERE exercise_id=%d AND user_id=%d', $exerciseId, $userId), ARRAY_A);
        if ((string) $this->db->last_error !== '') { throw new RuntimeException('Microbit project query failed.'); }
        return is_array($row) ? ['project' => $this->decode($row['data_json']), 'revision' => (int) $row['revision']] : null;
    }
    public function saveProject(int $exerciseId, int $userId, array $project, int $revision): array
    {
        if ($revision === 0) {
            $changed = $this->db->query($this->db->prepare('INSERT IGNORE INTO ' . $this->table('projects')
                . ' (exercise_id,user_id,revision,data_json,updated_at) VALUES (%d,%d,1,%s,%s)', $exerciseId, $userId, $this->encode($project), gmdate('Y-m-d H:i:s')));
        } else {
            $changed = $this->db->query($this->db->prepare('UPDATE ' . $this->table('projects')
                . ' SET data_json=%s,revision=revision+1,updated_at=%s WHERE exercise_id=%d AND user_id=%d AND revision=%d',
                $this->encode($project), gmdate('Y-m-d H:i:s'), $exerciseId, $userId, $revision));
        }
        if ($changed === false) { throw new RuntimeException('Microbit project write failed.'); }
        if ($changed !== 1) { throw new OperationError('conflict', 409, '프로젝트가 다른 창에서 변경되었습니다. 현재 프로젝트를 내려받은 뒤 최신 내용을 불러와 주세요.'); }
        return ['project' => $project, 'revision' => $revision + 1];
    }
    public function saveAttempt(int $exerciseId, int $revision, int $userId, array $project, array $result): int
    {
        $ok = $this->db->insert($this->raw('attempts'), ['exercise_id' => $exerciseId, 'exercise_revision' => $revision, 'assignment_id' => 0, 'user_id' => $userId,
            'project_json' => $this->encode($project), 'result_json' => $this->encode($result), 'created_at' => gmdate('Y-m-d H:i:s')] + $this->summary($result));
        if ($ok !== 1) { throw new RuntimeException('Microbit attempt insert failed.'); }
        return (int) $this->db->insert_id;
    }
    public function attempts(int $exerciseId, ?int $userId, int $beforeId = 0, int $limit = 21): array
    {
        $this->page($beforeId, $limit);
        $sql = 'SELECT id,exercise_id,exercise_revision,user_id,result_status,result_passed,result_score,created_at FROM ' . $this->table('attempts') . ' WHERE exercise_id=%d AND assignment_id=0';
        $args = [$exerciseId];
        if ($userId !== null) { $sql .= ' AND user_id=%d'; $args[] = $userId; }
        if ($beforeId > 0) { $sql .= ' AND id<%d'; $args[] = $beforeId; }
        $args[] = $limit;
        $rows = $this->checkedRows($this->db->get_results($this->db->prepare($sql . ' ORDER BY id DESC LIMIT %d', ...$args), ARRAY_A));
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'exerciseId' => (int) $r['exercise_id'], 'exerciseRevision' => (int) $r['exercise_revision'],
            'userId' => (int) $r['user_id'], 'result' => ['status' => $r['result_status'] === '' ? 'legacy' : $r['result_status'],
                'passed' => $r['result_passed'] === null ? null : (bool) $r['result_passed'], 'score' => $r['result_score'] === null ? null : (float) $r['result_score']],
            'summaryPending' => $r['result_status'] === '', 'createdAt' => $r['created_at']], $rows);
    }
    public function attempt(int $exerciseId, int $attemptId, ?int $userId): ?array
    {
        $sql = 'SELECT id,exercise_id,exercise_revision,user_id,project_json,result_json,created_at FROM ' . $this->table('attempts') . ' WHERE exercise_id=%d AND assignment_id=0 AND id=%d';
        $args = [$exerciseId, $attemptId];
        if ($userId !== null) { $sql .= ' AND user_id=%d'; $args[] = $userId; }
        $row = $this->db->get_row($this->db->prepare($sql, ...$args), ARRAY_A);
        if ((string) $this->db->last_error !== '') { throw new RuntimeException('Microbit attempt query failed.'); }
        if (!is_array($row)) { return null; }
        $result = $this->decode($row['result_json']);
        return ['id' => (int) $row['id'], 'exerciseId' => (int) $row['exercise_id'], 'exerciseRevision' => (int) $row['exercise_revision'],
            'userId' => (int) $row['user_id'], 'project' => $this->decode($row['project_json']), 'result' => $result, 'createdAt' => $row['created_at']];
    }
    private function summary(array $result): array
    {
        $status = $result['status'] ?? null;
        if (!in_array($status, ['graded', 'preview', 'compile_error', 'runtime_error'], true)) {
            $status = is_bool($result['passed'] ?? null) && is_numeric($result['score'] ?? null) ? 'graded' : 'unavailable';
        }
        $score = $result['score'] ?? null;
        $score = is_numeric($score) && is_finite((float) $score) && $score >= 0 && $score <= 100 ? round((float) $score, 2) : null;
        return ['result_status' => $status, 'result_passed' => is_bool($result['passed'] ?? null) ? (int) $result['passed'] : null, 'result_score' => $score];
    }
    private function writeSummary(int $id, array $summary): void
    {
        $passed = $summary['result_passed'] === null ? 'NULL' : (string) $summary['result_passed'];
        $score = $summary['result_score'] === null ? 'NULL' : (string) $summary['result_score'];
        $sql = 'UPDATE ' . $this->table('attempts') . ' SET result_status=%s,result_passed=' . $passed . ',result_score=' . $score . " WHERE id=%d AND result_status=''";
        if ($this->db->query($this->db->prepare($sql, $summary['result_status'], $id)) === false) { throw new RuntimeException('Microbit attempt summary write failed.'); }
    }
    public function backfillAttemptSummaries(int $limit = 8): bool
    {
        if ($limit < 1 || $limit > 20) { throw new InvalidArgumentException('요약 처리 개수가 올바르지 않습니다.'); }
        $rows = $this->checkedRows($this->db->get_results($this->db->prepare('SELECT id FROM ' . $this->table('attempts') . " WHERE result_status='' ORDER BY id DESC LIMIT %d", $limit), ARRAY_A));
        foreach ($rows as $row) {
            $record = $this->db->get_row($this->db->prepare('SELECT result_json FROM ' . $this->table('attempts') . " WHERE id=%d AND result_status=''", $row['id']), ARRAY_A);
            if ((string) $this->db->last_error !== '') { throw new RuntimeException('Microbit legacy result read failed.'); }
            if (!is_array($record)) { continue; }
            try { $summary = $this->summary($this->decode($record['result_json'])); }
            catch (\JsonException|RuntimeException) { $summary = ['result_status' => 'unavailable', 'result_passed' => null, 'result_score' => null]; }
            $this->writeSummary((int) $row['id'], $summary);
            unset($record, $summary);
        }
        return count($rows) === $limit;
    }
    public function withRunLease(int $userId, callable $operation): mixed
    {
        $key = 'oj_microbit_' . substr(hash('sha256', $this->db->prefix . ':' . $userId), 0, 36);
        if ((string) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)', $key)) !== '1') {
            throw new OperationError('busy', 429, '실행이 진행 중입니다. 결과를 기다린 뒤 다시 시도해 주세요.', 2);
        }
        try {
            $window = intdiv(time(), 60) * 60;
            $scope = 'run:' . $userId;
            $ok = $this->db->query($this->db->prepare('INSERT INTO ' . $this->table('limits')
                . ' (scope_key,window_start,hits) VALUES (%s,%d,1) ON DUPLICATE KEY UPDATE hits=IF(window_start=%d,hits+1,1),window_start=%d', $scope, $window, $window, $window));
            if ($ok === false) { throw new RuntimeException('Microbit limit write failed.'); }
            $hits = $this->db->get_var($this->db->prepare('SELECT hits FROM ' . $this->table('limits') . ' WHERE scope_key=%s', $scope));
            if ($hits === null || (string) $this->db->last_error !== '') { throw new RuntimeException('Microbit limit read failed.'); }
            if ((int) $hits > 30) { throw new OperationError('rate_limited', 429, '실행 요청이 많습니다. 잠시 후 다시 시도해 주세요.', 60); }
            return $operation();
        } finally { $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', $key)); }
    }
    public function purge(int $days, int $limit = 50): bool
    {
        if ($days < 0 || $limit < 1 || $limit > 100) { throw new InvalidArgumentException('정리 기간과 처리 개수를 확인해 주세요.'); }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $more = false;
        if ($days > 0) {
            $more = $this->purgeTable('attempts', 'created_at', $cutoff, ['id'], $limit) || $more;
            $more = $this->purgeTable('projects', 'updated_at', $cutoff, ['exercise_id', 'user_id'], $limit) || $more;
            $more = $this->purgeTable('lesson_projects', 'updated_at', $cutoff, ['assignment_id', 'user_id'], $limit) || $more;
            $more = $this->purgeTable('lesson_results', 'updated_at', $cutoff, ['assignment_id', 'user_id'], $limit) || $more;
        }
        return $this->purgeTable('limits', 'window_start', time() - DAY_IN_SECONDS, ['scope_key'], $limit) || $more;
    }
    private function purgeTable(string $name, string $column, string|int $cutoff, array $keys, int $limit): bool
    {
        $cutoffFormat = is_int($cutoff) ? '%d' : '%s';
        $rows = $this->checkedRows($this->db->get_results($this->db->prepare('SELECT ' . implode(',', $keys) . ' FROM ' . $this->table($name)
            . " WHERE {$column}<{$cutoffFormat} ORDER BY {$column}," . implode(',', $keys) . ' LIMIT %d', $cutoff, $limit), ARRAY_A));
        if ($rows === []) { return false; }
        $conditions = []; $args = [$cutoff];
        foreach ($rows as $row) {
            $parts = [];
            foreach ($keys as $key) { $parts[] = $key . '=' . ($key === 'scope_key' ? '%s' : '%d'); $args[] = $row[$key]; }
            $conditions[] = '(' . implode(' AND ', $parts) . ')';
        }
        // Recheck the cutoff: an active project or rate-limit window may change
        // between the covering key read and this small primary-key delete.
        $sql = 'DELETE FROM ' . $this->table($name) . " WHERE {$column}<{$cutoffFormat} AND (" . implode(' OR ', $conditions) . ')';
        if ($this->db->query($this->db->prepare($sql, ...$args)) === false) { throw new RuntimeException('Microbit bounded cleanup failed.'); }
        return count($rows) === $limit;
    }
}
