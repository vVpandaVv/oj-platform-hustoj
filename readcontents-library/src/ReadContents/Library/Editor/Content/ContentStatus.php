<?php
/**
 * Content publication status.
 *
 * @package ReadContentsLibrary\Content
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\Content;

enum ContentStatus: string
{
    case DRAFT = 'draft';
    case REVIEW = 'review';
    case APPROVED = 'approved';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';
}
