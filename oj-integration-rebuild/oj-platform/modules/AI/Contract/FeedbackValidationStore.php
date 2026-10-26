<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\AI\Contract;
interface FeedbackValidationStore
{
    public function reserve(array $record): bool;
    public function finish(array $record): void;
    public function records(string $candidate): array;
    public function count(string $candidate): int;
    public function find(string $candidate, string $id): ?array;
    public function page(string $candidate, int $offset, int $limit): array;
}
