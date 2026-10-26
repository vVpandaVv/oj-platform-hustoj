<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiJobService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\DifficultyScore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class GenerateProblemAiActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_ai_generate';

    public function __construct(
        private readonly ProblemAiJobService $aiJobs,
        private readonly ActionAccessGuard $guard,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $operation = $request->bodyString('ai_operation');
            if (in_array($operation, ['title_only', 'full_draft'], true)) {
                $problemIdValue = $request->bodyInt('problem_id');
                $difficultyValue = $request->bodyInt('difficulty_score');
                $result = $this->aiJobs->enqueueAuthoring(
                    $actor->userId(),
                    $problemIdValue > 0 ? new ProblemId($problemIdValue) : null,
                    $operation,
                    $request->bodyString('brief'),
                    $difficultyValue > 0
                        ? new DifficultyScore($difficultyValue)
                        : null,
                    $this->stringList($request->body()['topic_keys'] ?? [])
                );
            } elseif (in_array($operation, ['testcase_file', 'generator_bundle'], true)) {
                $result = $this->aiJobs->enqueueTestcases(
                    $actor->userId(),
                    new ProblemId($request->bodyInt('problem_id')),
                    $request->bodyInt('pair_count', 3),
                    $request->bodyString('guidance')
                );
            } else {
                throw new DomainException('지원하지 않는 문제 AI 작업입니다.');
            }

            return $this->json(
                202,
                [
                    'ok' => true,
                    'job_id' => $result->jobId(),
                    'created' => $result->wasCreated(),
                    'status_url' => '/oj-console/jobs/' . $result->jobId(),
                    'message' => 'AI 작업을 등록했습니다.',
                ]
            );
        } catch (DomainException | InvalidArgumentException $error) {
            return $this->json(
                422,
                ['ok' => false, 'message' => $error->getMessage()]
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->json(
                500,
                [
                    'ok' => false,
                    'message' => 'AI 작업을 등록하지 못했습니다. 잠시 뒤 다시 시도해 주세요.',
                ]
            );
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): HttpResponse
    {
        try {
            $encoded = json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            $encoded = '{"ok":false,"message":"응답을 만들지 못했습니다."}';
            $status = 500;
        }

        return new HttpResponse(
            $status,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $encoded
        );
    }
}
