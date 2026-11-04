<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

interface Repository {
    public function locked(string $key, callable $operation): mixed;
    public function get(string $code): ?array;
    public function create(array $room): void;
    public function put(array $room): void;
    public function delete(string $code): void;
    public function recent(int $user, bool $admin = false): array;
    public function save_result(array $result): void;
    public function result(string $id): ?array;
    public function records(int $user, bool $admin, int $page,string $game=Room_Rules::BINARY,string $mode='all'): array;
}
