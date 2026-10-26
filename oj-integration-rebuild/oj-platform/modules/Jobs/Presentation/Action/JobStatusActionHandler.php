<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobAccessPolicy;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQuery;
use DomainException;
use JsonException;
use Throwable;

final class JobStatusActionHandler implements ActionHandler
{
    private const ACTION = 'oj_job_status';

    public function __construct(
        private readonly JobQuery $jobs,
        private readonly JobAccessPolicy $access,
        private readonly ActionAccessGuard $guard,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $job = $this->jobs->find($request->bodyInt('job_id'));
            if ($job === null || ! $this->access->canView($actor->userId(), $job)) {
                return $this->json(
                    404,
                    ['ok' => false, 'message' => '작업을 찾을 수 없습니다.']
                );
            }

            return $this->json(200, [
                'ok' => true,
                'job_id' => $job->id(),
                'status' => $job->status(),
                'terminal' => $job->isTerminal(),
                'succeeded_count' => $job->succeededCount(),
                'failed_count' => $job->failedCount(),
            ]);
        } catch (DomainException $error) {
            return $this->json(
                403,
                ['ok' => false, 'message' => $error->getMessage()]
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->json(
                500,
                [
                    'ok' => false,
                    'message' => '작업 상태를 확인하지 못했습니다.',
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): HttpResponse
    {
        try {
            $encoded = json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            $status = 500;
            $encoded = '{"ok":false}';
        }

        return new HttpResponse(
            $status,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
            ],
            $encoded
        );
    }
}
