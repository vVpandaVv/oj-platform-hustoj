<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;

final class WordPressActionUrlProvider implements ActionUrlProvider
{
    public function actionUrl(): string
    {
        return admin_url('admin-post.php');
    }

    public function ajaxUrl(): string
    {
        return admin_url('admin-ajax.php');
    }
}
