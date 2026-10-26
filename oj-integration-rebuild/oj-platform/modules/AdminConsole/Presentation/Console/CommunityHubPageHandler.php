<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;

final class CommunityHubPageHandler implements RouteHandler
{
    public function __construct(
        private readonly CurrentAccountProvider $accounts,
        private readonly FeatureFlags $features,
        private readonly Authorization $permissions
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route, $request);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        if (
            $this->features->isEnabled('board')
            && $this->permissions->can($userId, 'oj_manage_board')
        ) {
            return HttpResponse::redirect('/oj-console/board');
        }
        if (
            $this->features->isEnabled('notices')
            && $this->permissions->can($userId, 'oj_manage_notices')
        ) {
            return HttpResponse::redirect('/oj-console/notices');
        }
        if ($this->permissions->can($userId, 'oj_manage_settings')) {
            return HttpResponse::redirect('/oj-console/community/settings');
        }

        return HttpResponse::redirect('/oj-console');
    }
}
