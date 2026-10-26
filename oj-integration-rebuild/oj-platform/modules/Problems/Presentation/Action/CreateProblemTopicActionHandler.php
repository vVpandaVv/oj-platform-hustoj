<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTopicService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class CreateProblemTopicActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_topic_create';

    public function __construct(
        private readonly ProblemTopicService $topics,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorUserId = $actor->userId();
            $aliases = preg_split(
                '/[\r\n,]+/u',
                $request->bodyString('topic_aliases')
            ) ?: [];
            $kind = $request->bodyString('topic_kind');
            if ($kind === 'category') {
                $this->topics->createCategory(
                    $actorUserId,
                    trim($request->bodyString('topic_key')),
                    trim($request->bodyString('topic_label')),
                    $aliases
                );
            } elseif ($kind === 'detail') {
                $this->topics->createDetail(
                    $actorUserId,
                    trim($request->bodyString('category_key')),
                    trim($request->bodyString('topic_key')),
                    trim($request->bodyString('topic_label')),
                    $aliases
                );
            } else {
                throw new DomainException('추가할 주제 종류가 올바르지 않습니다.');
            }

            return $this->responses->redirect(
                '/oj-console/problems/topics',
                new UiMessage(
                    'problems.topic_created',
                    MessageTone::Success,
                    '주제 추가 완료',
                    '문제 주제 사전에 새 항목을 추가했습니다.'
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->failure($error->getMessage(), $actorUserId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                '문제 주제를 추가하지 못했습니다.',
                $actorUserId
            );
        }
    }

    private function failure(string $message, int $actorUserId): HttpResponse
    {
        return $this->responses->redirect(
            '/oj-console/problems/topics',
            new UiMessage(
                'problems.topic_create_failed',
                MessageTone::Danger,
                '주제 추가 실패',
                $message
            ),
            $actorUserId
        );
    }
}
