<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobWorker;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQueueReadiness;
use Throwable;

final class WordPressJobScheduler
{
    public const HOOK = 'oj_platform_jobs_tick';

    public function __construct(
        private readonly JobWorker $worker,
        private readonly FormDraftStore $drafts,
        private readonly ErrorReporter $errors,
        private readonly ?WordPressJobWakeup $wakeup = null,
        private readonly ?JobQueueReadiness $queue = null
    ) {
    }

    public function ensureScheduled(): void
    {
        if (
            ! function_exists('wp_next_scheduled')
            || ! function_exists('wp_schedule_single_event')
            || wp_next_scheduled(self::HOOK) !== false
        ) {
            return;
        }
        wp_schedule_single_event(time() + 60, self::HOOK);
    }

    public function run(): void
    {
        $acquired = false;
        $healthy = false;
        try {
            if ($this->wakeup !== null) {
                $acquired = $this->wakeup->acquire();
                if (! $acquired) {
                    return;
                }
            }
            $healthy = $this->worker->run();
            $this->drafts->purgeExpired(100);
        } catch (Throwable $error) {
            $this->errors->report($error);
        } finally {
            if ($acquired) {
                try {
                    $this->wakeup?->release();
                } catch (Throwable $error) {
                    $healthy = false;
                    $this->errors->report($error);
                }
            }
            $this->ensureScheduled();
        }
        // Recheck after unlocking so enqueue at the end of a batch cannot be lost.
        if ($healthy && $this->wakeup !== null && $this->queue !== null) {
            try {
                if ($this->queue->hasReadyItems()) {
                    $this->wakeup->wake();
                }
            } catch (Throwable $error) {
                $this->errors->report($error);
            }
        }
    }

    public function handleWakeup(): void
    {
        try {
            $ticket = $_POST['ticket'] ?? null;
            $allowed = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
                && is_string($ticket)
                && $this->wakeup?->consume($ticket) === true;
            if (! $allowed) {
                if (function_exists('status_header')) {
                    status_header(403);
                }
                return;
            }
            ignore_user_abort(true);
            $this->run();
            if (function_exists('status_header')) {
                status_header(204);
            }
        } catch (Throwable $error) {
            $this->errors->report($error);
            if (function_exists('status_header')) {
                status_header(503);
            }
        }
    }
}
