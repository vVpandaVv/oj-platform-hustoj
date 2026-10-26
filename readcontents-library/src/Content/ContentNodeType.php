<?php
/**
 * Content hierarchy node type.
 *
 * @package ContentsEditor\Content
 */

declare(strict_types=1);

namespace ContentsEditor\Content;

enum ContentNodeType: string
{
    case TOPIC = 'topic';
    case CHAPTER = 'chapter';
    case SECTION = 'section';
    case LESSON = 'lesson';
}
