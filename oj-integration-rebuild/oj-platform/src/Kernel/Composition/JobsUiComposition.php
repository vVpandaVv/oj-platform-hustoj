<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobAccessPolicy;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobAccessRuleRegistry;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobControlService;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobControl;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobController;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQuery;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWakeup;
use CodingPanda\OjPlatform\Modules\Jobs\Presentation\Action\CancelJobActionHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Presentation\Action\JobStatusActionHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Presentation\Action\RetryFailedJobActionHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Presentation\Console\JobDetailsPageHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Presentation\Console\JobExportPageHandler;

final class JobsUiComposition
{
    public function assemble(
        ErrorReporter $errorReporter,
        JobQuery $jobs,
        JobControl $control,
        FormDraftStore $payloads,
        JobAccessRuleRegistry $accessRules,
        AccountShellBundle $accountShell,
        ?JobWakeup $wakeup = null
    ): JobsUiBundle {
        $access = new JobAccessPolicy($accessRules);
        $controlService = new JobControlService(
            $jobs,
            $control,
            $access,
            $payloads,
            $errorReporter,
            $wakeup
        );
        $accountShell->routeHandlers()->register(
            'console.jobs.show',
            new JobDetailsPageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $jobs,
                $access,
                $accountShell->actionUrls(),
                $accountShell->actionSecurity()
            )
        );
        $accountShell->routeHandlers()->register(
            'console.jobs.export',
            new JobExportPageHandler(
                $accountShell->currentAccounts(),
                $jobs,
                $access
            )
        );
        $accountShell->actionHandlers()->register(
            'oj_job_status',
            new JobStatusActionHandler(
                $jobs,
                $access,
                $accountShell->actionGuard(),
                $errorReporter
            )
        );
        $accountShell->actionHandlers()->register(
            'oj_job_cancel',
            new CancelJobActionHandler(
                $controlService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $accountShell->actionHandlers()->register(
            'oj_job_retry_failed',
            new RetryFailedJobActionHandler(
                $controlService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );

        return new JobsUiBundle($controlService, [
            JobQuery::class => $jobs,
            JobControl::class => $control,
            JobController::class => $controlService,
            JobAccessPolicy::class => $access,
            JobControlService::class => $controlService,
        ]);
    }
}
