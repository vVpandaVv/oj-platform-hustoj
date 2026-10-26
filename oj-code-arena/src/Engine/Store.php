<?php
declare(strict_types=1);

namespace OjCodeArena\Engine;

/**
 * Storage behind the built-in mode. Wpdb_Store is the real one (MySQL);
 * File_Store backs the tests and the dev harness.
 *
 * A "row" is: arena, mode, state (json), problems (json), summary (json),
 * next_tick, closed, host_uid, created, updated.
 */
interface Store {
    /** @return array<string,mixed>|null */
    public function row(string $key): ?array;

    /** Like row() without the (large) problems column — read on every poll. */
    public function head(string $key): ?array;

    /**
     * Inserts or updates one arena row plus its new events.
     *
     * @param array<string,mixed> $fields
     * @param list<array{0:string,1:string}> $events [scope, json payload]
     */
    public function write(string $key, array $fields, bool $insert, array $events, int $now): void;

    public function max_event_id(string $key): int;

    /** @return list<array{id:int, scope:string, payload:string}> */
    public function events_after(string $key, int $cursor, int $limit): array;

    public function seen(string $key, int $uid, string $role, int $now): void;

    /** @return array<string,int> learner uid → last poll (ms) */
    public function presence(string $key): array;

    /** Arena key of the free room this learner polled within $since, if any. */
    public function recent_free_arena(int $uid, int $since): ?string;

    /** @return list<string> summaries (json) of open free rooms */
    public function free_rooms(): array;

    public function last_room_created_by(int $uid): int;

    public function count_open(string $mode): int;

    public function count_players(int $since): int;

    public function prune(int $now): void;

    /** Runs $fn while holding the arena's lock. */
    public function locked(string $key, callable $fn): void;
}
