<?php
/**
 * Content hierarchy node type.
 *
 * @package ReadContentsLibrary\Content
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\Content;

enum ContentNodeType: string
{
    case TOPIC = 'topic';
    case CHAPTER = 'chapter';
    case SECTION = 'section';
    case LESSON = 'lesson';
}
