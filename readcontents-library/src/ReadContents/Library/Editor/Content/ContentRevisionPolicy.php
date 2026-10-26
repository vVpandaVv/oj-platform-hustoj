<?php
/**
 * Optimistic revision checks for lesson documents.
 *
 * @package ReadContentsLibrary\Content
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\Content;

use InvalidArgumentException;

final class ContentRevisionPolicy
{
    public static function assertExpected(int $currentRevision, mixed $expectedRevision): void
    {
        // Imports and legacy migrations do not carry an editor revision.
        if (null === $expectedRevision) {
            return;
        }

        if (
            ! is_int($expectedRevision)
            && ! (is_string($expectedRevision) && 1 === preg_match('/^[0-9]+$/', $expectedRevision))
        ) {
            throw new InvalidArgumentException('레슨 문서 revision은 1 이상의 정수여야 합니다.');
        }

        $expectedRevision = (int) $expectedRevision;

        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('레슨 문서 revision은 1 이상의 정수여야 합니다.');
        }

        if ($expectedRevision !== $currentRevision) {
            throw new ContentRevisionConflict($currentRevision);
        }
    }
}
