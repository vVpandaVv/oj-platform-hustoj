<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\ContestRequestFactory;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class SaveContestActionHandler implements ActionHandler
{
    private const ACTION = 'oj_contest_save';

    public function __construct(
        private readonly ContestService $contests,
        private readonly ContestRequestFactory $requests,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorId = 0;
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorId = $actor->userId();
            $contestId = $this->contests->save(
                $actorId,
                $actor->userLogin(),
                $this->requests->saveCommand($request)
            );

            return $this->responses->redirect(
                '/oj-console/contests/' . $contestId . '/edit',
                new UiMessage(
                    'contests.saved',
                    MessageTone::Success,
                    '대회 저장 완료',
                    '대회 문제와 채점·공개 정책을 함께 저장했습니다.'
                ),
                $actorId
            );
        } catch (DomainException $error) {
            return $this->failed($request, $error->getMessage(), $actorId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failed(
                $request,
                '대회를 저장하지 못했습니다.',
                $actorId
            );
        }
    }

    private function failed(
        HttpRequest $request,
        string $message,
        int $actorId
    ): HttpResponse {
        $contestId = $request->bodyInt('contest_id');

        return $this->responses->redirect(
            $contestId > 0
                ? '/oj-console/contests/' . $contestId . '/edit'
                : '/oj-console/contests/new',
            new UiMessage(
                'contests.save_failed',
                MessageTone::Danger,
                '대회 저장 실패',
                $message
            ),
            $actorId
        );
    }
}
