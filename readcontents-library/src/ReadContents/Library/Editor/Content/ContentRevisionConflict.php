<?php
/**
 * Raised when a lesson document was changed after the editor loaded it.
 *
 * @package ReadContentsLibrary\Content
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\Content;

use RuntimeException;

final class ContentRevisionConflict extends RuntimeException
{
    public function __construct(private readonly int $currentRevision)
    {
        parent::__construct(
            '이 레슨은 다른 창이나 사용자가 먼저 저장했습니다. 최신 내용을 확인한 뒤 다시 저장해 주세요.'
        );
    }

    public function currentRevision(): int
    {
        return $this->currentRevision;
    }
}
