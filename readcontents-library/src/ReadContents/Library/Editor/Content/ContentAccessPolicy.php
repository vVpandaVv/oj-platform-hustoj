<?php
/**
 * Shared publication and public visibility policy.
 *
 * @package ReadContentsLibrary\Content
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\Content;

use Closure;

final class ContentAccessPolicy
{
    /** @var Closure(string): ?ContentNode */
    private readonly Closure $findNode;

    /**
     * @param callable(string): ?ContentNode $find_node
     */
    public function __construct(callable $find_node)
    {
        $this->findNode = Closure::fromCallable($find_node);
    }

    public function isPubliclyReadable(?ContentNode $node): bool
    {
        if (! $node || ContentStatus::PUBLISHED !== $node->status) {
            return false;
        }

        $seen = [$node->id => true];
        $parent_id = $node->parentId;

        while ($parent_id) {
            if (isset($seen[$parent_id])) {
                return false;
            }

            $seen[$parent_id] = true;
            $parent = ($this->findNode)($parent_id);

            if (! $parent || ContentStatus::PUBLISHED !== $parent->status) {
                return false;
            }

            $parent_id = $parent->parentId;
        }

        return true;
    }

    public function creationRequiresPublish(mixed $requested_status): bool
    {
        return ContentStatus::PUBLISHED === $this->readStatus($requested_status);
    }

    public function statusChangeRequiresPublish(ContentNode $node, mixed $requested_status): bool
    {
        $next_status = $this->readStatus($requested_status);

        if (! $next_status || $next_status === $node->status) {
            return false;
        }

        return ContentStatus::PUBLISHED === $node->status
            || ContentStatus::PUBLISHED === $next_status;
    }

    /**
     * @param array<int, ContentNode> $nodes
     */
    public function bulkStatusChangeRequiresPublish(array $nodes, mixed $requested_status): bool
    {
        $next_status = $this->readStatus($requested_status);

        if (! $next_status) {
            return false;
        }

        if (ContentStatus::PUBLISHED === $next_status) {
            return true;
        }

        foreach ($nodes as $node) {
            if (ContentStatus::PUBLISHED === $node->status && $next_status !== $node->status) {
                return true;
            }
        }

        return false;
    }

    public function removalRequiresPublish(?ContentNode $node): bool
    {
        return $node && ContentStatus::PUBLISHED === $node->status;
    }

    private function readStatus(mixed $value): ?ContentStatus
    {
        if (! is_string($value) || '' === trim($value)) {
            return null;
        }

        return ContentStatus::tryFrom(trim($value));
    }
}
