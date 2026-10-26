<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Message;

enum MessageTone: string
{
    case Neutral = 'neutral';
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
}
