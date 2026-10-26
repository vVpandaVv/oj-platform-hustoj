<?php
declare(strict_types=1);

namespace OjCodeArena\Engine;

/**
 * JSON-file storage for tests and tools/dev-harness (not for production):
 * one file, guarded by flock, so several php -S workers can share it.
 */
final class File_Store implements Store {
    private string $file;
    /** @var resource|null */
    private $lock = null;
    /** @var array<string,mixed>|null */
    private ?array $data = null;

    public function __construct(string $file) {
        $this->file = $file;
    }

    private function load(): array {
        if (null !== $this->data) {
            return $this->data;
        }
        $raw = is_file($this->file) ? (string) file_get_contents($this->file) : '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : array('live' => array(), 'events' => array(), 'presence' => array(), 'seq' => 0);
    }

    private function mutate(callable $fn): void {
        $this->locked('__file', function () use ($fn): void {
            $data = $this->load();
            $fn($data);
            file_put_contents($this->file, json_encode($data));
        });
    }

    public function row(string $key): ?array {
        return $this->load()['live'][$key] ?? null;
    }

    public function head(string $key): ?array {
        return $this->row($key);
    }

    public function write(string $key, array $fields, bool $insert, array $events, int $now): void {
        $this->mutate(function (array &$data) use ($key, $fields, $insert, $events, $now): void {
            $data['live'][$key] = ($insert ? array() : ($data['live'][$key] ?? array())) + array('arena' => $key);
            $data['live'][$key] = $fields + $data['live'][$key];
            foreach ($events as list($scope, $payload)) {
                $data['events'][] = array('id' => ++$data['seq'], 'arena' => $key, 'scope' => $scope, 'payload' => $payload, 'at' => $now);
            }
        });
    }

    public function max_event_id(string $key): int {
        $max = 0;
        foreach ($this->load()['events'] as $e) {
            if ($e['arena'] === $key) {
                $max = max($max, (int) $e['id']);
            }
        }
        return $max;
    }

    public function events_after(string $key, int $cursor, int $limit): array {
        $out = array();
        foreach ($this->load()['events'] as $e) {
            if ($e['arena'] === $key && $e['id'] > $cursor) {
                $out[] = array('id' => (int) $e['id'], 'scope' => $e['scope'], 'payload' => $e['payload']);
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }

    public function seen(string $key, int $uid, string $role, int $now): void {
        $this->mutate(static function (array &$data) use ($key, $uid, $role, $now): void {
            $data['presence'][$key . '|' . $uid . '|' . $role] = array('arena' => $key, 'uid' => $uid, 'role' => $role, 'seen' => $now);
        });
    }

    public function presence(string $key): array {
        $out = array();
        foreach ($this->load()['presence'] as $p) {
            if ($p['arena'] === $key && 'learner' === $p['role']) {
                $out[(string) $p['uid']] = (int) $p['seen'];
            }
        }
        return $out;
    }

    public function recent_free_arena(int $uid, int $since): ?string {
        $best = null;
        $data = $this->load();
        foreach ($data['presence'] as $p) {
            $row = $data['live'][$p['arena']] ?? null;
            if ($p['uid'] === $uid && 'learner' === $p['role'] && $p['seen'] > $since && $row && 'free' === $row['mode'] && ! $row['closed']
                && (! $best || $p['seen'] > $best['seen'])) {
                $best = $p;
            }
        }
        return $best ? $best['arena'] : null;
    }

    public function free_rooms(): array {
        $out = array();
        foreach ($this->load()['live'] as $row) {
            if ('free' === $row['mode'] && ! $row['closed']) {
                $out[] = (string) $row['summary'];
            }
        }
        return $out;
    }

    public function last_room_created_by(int $uid): int {
        $max = 0;
        foreach ($this->load()['live'] as $row) {
            if ((int) $row['host_uid'] === $uid) {
                $max = max($max, (int) $row['created']);
            }
        }
        return $max;
    }

    public function count_open(string $mode): int {
        $n = 0;
        foreach ($this->load()['live'] as $row) {
            $n += $row['mode'] === $mode && ! $row['closed'] ? 1 : 0;
        }
        return $n;
    }

    public function count_players(int $since): int {
        $ids = array();
        foreach ($this->load()['presence'] as $p) {
            if ('learner' === $p['role'] && $p['seen'] > $since) {
                $ids[$p['uid']] = true;
            }
        }
        return count($ids);
    }

    public function prune(int $now): void {
        $this->mutate(static function (array &$data) use ($now): void {
            $data['events'] = array_values(array_filter($data['events'], static fn (array $e): bool => $e['at'] >= $now - 180000));
        });
    }

    public function locked(string $key, callable $fn): void {
        if ('__file' === $key && null !== $this->lock) {
            $fn(); // already holding the file lock
            return;
        }
        $handle = fopen($this->file . '.lock', 'c');
        flock($handle, LOCK_EX);
        $this->lock = $handle;
        $this->data = null;
        try {
            if ('__file' !== $key) {
                $this->data = null;
            }
            $fn();
        } finally {
            $this->data = null;
            $this->lock = null;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
