<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\Ranking\Application\RankingService;
use CodingPanda\OjPlatform\Modules\Ranking\Contract\RankingSnapshotCache;
use CodingPanda\OjPlatform\Modules\Ranking\Infrastructure\Judge\MysqliJudgeUserActivityProvider;
use CodingPanda\OjPlatform\Modules\Ranking\Infrastructure\WordPress\WordPressRankingSnapshotCache;
use CodingPanda\OjPlatform\Modules\Ranking\Module;
use CodingPanda\OjPlatform\Modules\Ranking\Presentation\RankingPageHandler;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;

final class RankingComposition
{
    public function assemble(
        AccountShellBundle $accountShell,
        JudgeConnectionSettingsProvider $judgeConnectionSettings,
        JudgeConnector $judgeConnector,
        RouteUrlProvider $urls
    ): RankingBundle {
        $cache = new WordPressRankingSnapshotCache();
        $activityProvider = new MysqliJudgeUserActivityProvider(
            $judgeConnectionSettings,
            $judgeConnector
        );
        $service = new RankingService(
            $activityProvider,
            $accountShell->users(),
            $accountShell->preferenceBatch(),
            $cache
        );
        $handler = new RankingPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $accountShell->preferences(),
            $service,
            $urls,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            new PaginationView()
        );
        $accountShell->routeHandlers()->register('ranking.index', $handler);

        return new RankingBundle(
            new Module(),
            [
                RankingSnapshotCache::class => $cache,
                RankingService::class => $service,
                RankingPageHandler::class => $handler,
            ]
        );
    }
}
