<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use DomainException;
use JsonException;
use Throwable;

final class SubmissionPollActionHandler implements ActionHandler
{
    private const ACTION = 'oj_submission_poll';

    public function __construct(
        private readonly SubmissionQueryService $submissions,
        private readonly ActionAccessGuard $guard,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $submissionId = new SubmissionId(
                $request->bodyInt('submission_id')
            );
            $this->guard->throttle(
                'submission_poll',
                $actor->userId() . ':' . $submissionId->value(),
                60,
                60
            );
            $poll = $this->submissions->poll(
                $actor->userId(),
                $actor->userLogin(),
                $submissionId
            );
            if ($poll === null) {
                return $this->json(404, [
                    'ok' => false,
                    'message' => '제출을 찾을 수 없습니다.',
                ]);
            }
            $summary = $poll->summary();
            $result = $this->submissions->result($summary->resultCode());
            $payload = [
                'ok' => true,
                'submission_id' => $summary->submissionId()->value(),
                'result_code' => $result->code(),
                'result_label' => $result->label(),
                'result_tone' => $result->tone(),
                'result_class' => $result->badgeClass(),
                'is_processing' => $result->isProcessing(),
            ];
            $payload['time_ms'] = $summary->timeMilliseconds();
            $payload['memory_kb'] = $summary->memoryKilobytes();
            $payload['judged_at'] = $summary->judgedAt();

            return $this->json(200, $payload);
        } catch (DomainException $error) {
            return $this->json(403, [
                'ok' => false,
                'message' => $error->getMessage(),
            ]);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->json(500, [
                'ok' => false,
                'message' => '채점 결과를 갱신하지 못했습니다.',
            ]);
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
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
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
