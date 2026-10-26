<?php
/**
 * Raised when a content mutation needs an additional capability.
 *
 * @package ReadContentsLibrary\Rest
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\Rest;

use RuntimeException;

final class ContentPermissionException extends RuntimeException
{
}
