<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Settings\Contract\ServerInstallationLink;

final class WordPressServerInstallationLink implements ServerInstallationLink
{
    public function url(): ?string
    {
        return function_exists('current_user_can') && current_user_can('install_plugins')
            ? admin_url('admin.php?page=oj-server-install') : null;
    }
}
