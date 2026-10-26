<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Icon;

enum IconName: string
{
    case Search = 'search';
    case Plus = 'plus';
    case Edit = 'edit';
    case Trash = 'trash';
    case Refresh = 'refresh-cw';
    case Save = 'save';
    case Close = 'x';
    case Menu = 'menu';
    case Check = 'check';
    case Warning = 'triangle-alert';
    case Eye = 'eye';
    case EyeOff = 'eye-off';
    case Download = 'download';
    case Upload = 'upload';
    case ChevronDown = 'chevron-down';
    case ArrowLeft = 'arrow-left';
    case Bookmark = 'bookmark';
    case Clock = 'clock';
    case Memory = 'memory-stick';
    case Database = 'database';
    case Send = 'send';
    case Copy = 'copy';
    case Lightbulb = 'lightbulb';
    case Info = 'info';
    case Chart = 'chart-no-axes-column';
    case Trophy = 'trophy';
    case History = 'history';
    case Rows = 'list';
    case Home = 'home';
    case Book = 'book-open';
    case Learning = 'graduation-cap';
    case Users = 'users';
    case Community = 'message-square';
    case Settings = 'settings';
    case User = 'user';
    case Logout = 'log-out';
    case External = 'external-link';
    case Dashboard = 'layout-dashboard';
    case More = 'ellipsis';
    case ArrowRight = 'arrow-right';
    case Mail = 'mail';
    case Sun = 'sun';
    case Moon = 'moon';
    case Monitor = 'monitor';
    case Puzzle = 'puzzle';
}
