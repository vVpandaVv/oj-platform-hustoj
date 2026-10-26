<?php
declare(strict_types=1);
namespace OjPrimm\Storage;

interface GenerationOperationsStore extends GenerationStore
{
    public function createLimited(array $data, array $limits): array;
    public function reserveAi(int $id, string $lease, array $limits): array;
    public function defer(int $id, string $lease, string $message, string $retryAt, array $details = []): array;
    public function failDetailed(int $id, string $lease, string $message, array $details): void;
    public function expire(int $id, string $message): array;
    public function cancel(int $id, string $message, bool $administrative = false): array;
    public function resume(int $id): array;
    public function overview(?int $actor = null): array;
}
