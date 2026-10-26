<?php
/**
 * Content publication status.
 *
 * @package ContentsEditor\Content
 */

declare(strict_types=1);

namespace ContentsEditor\Content;

enum ContentStatus: string
{
    case DRAFT = 'draft';
    case REVIEW = 'review';
    case APPROVED = 'approved';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';
}
