<?php

declare(strict_types=1);

namespace ReadContents\Library\Addon;

final class AddonRoutes
{
    public const ADDON_ID = 'readcontents-library';
    public const NAMESPACE = 'readcontents_library';

    public const CAPABILITY_VIEW = 'readcontents_library_view';
    public const CAPABILITY_EDIT = 'readcontents_library_edit';
    public const CAPABILITY_PUBLISH = 'readcontents_library_publish';

    public const BASE_PATH = '/oj-addons/' . self::ADDON_ID;
    public const CONSOLE_BASE_PATH = '/oj-console/addons/' . self::ADDON_ID;

    public const SAVE_ACTION = 'rcis_save_lesson';

    public static function topicPath(string $topicId): string
    {
        return self::BASE_PATH . '/' . $topicId;
    }

    public static function topicOverviewPath(string $topicSlug): string
    {
        return self::BASE_PATH . '/topic/' . $topicSlug;
    }

    public static function consoleEditPath(string $topicId): string
    {
        return self::CONSOLE_BASE_PATH . '/' . $topicId;
    }

    private function __construct()
    {
    }
}
