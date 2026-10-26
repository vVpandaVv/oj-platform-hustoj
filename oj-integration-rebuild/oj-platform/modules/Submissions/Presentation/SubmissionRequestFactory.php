<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionMode;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmitSubmissionCommand;
use DomainException;

final class SubmissionRequestFactory
{
    public function command(
        UserIdentity $actor,
        HttpRequest $request
    ): SubmitSubmissionCommand {
        $mode = SubmissionMode::tryFrom(
            strtolower(trim($request->bodyString('submission_mode', 'code')))
        );
        if ($mode === null) {
            throw new DomainException('코드 작성 방식을 확인해 주세요.');
        }

        return new SubmitSubmissionCommand(
            $actor->userId(),
            $actor->userLogin(),
            $actor->displayName(),
            $request->bodyInt('problem_id'),
            $mode,
            $request->bodyInt('language_id'),
            $request->bodyString('source'),
            strtolower(trim($request->bodyString('template_hash'))),
            $this->completionAnswers($request),
            $request->bodyString('blockly_workspace'),
            strtolower(trim($request->bodyString('blockly_profile', 'standard'))),
            strtolower(trim($request->bodyString('request_key'))),
            (string) ($request->header('x-oj-client-ip') ?? ''),
            max(0, $request->bodyInt('contest_id')),
            $request->bodyInt('contest_problem_number', -1),
            $this->optionalPositiveInt($request, 'class_id'),
            $this->optionalPositiveInt($request, 'group_id'),
            $this->orderingBlockIds($request)
        );
    }

    /**
     * @return array<string, string>
     */
    private function completionAnswers(HttpRequest $request): array
    {
        $raw = $request->body()['completion_answers'] ?? [];
        if (! is_array($raw)) {
            return [];
        }
        $answers = [];
        foreach ($raw as $id => $answer) {
            $id = (string) $id;
            if (
                preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1
                || ! is_scalar($answer)
            ) {
                continue;
            }
            $answers[$id] = (string) $answer;
        }

        return $answers;
    }

    /** @return list<string> */
    private function orderingBlockIds(HttpRequest $request): array
    {
        $raw = $request->body()['ordering_block_ids'] ?? [];
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > 200) {
            throw new DomainException('코드 조각의 순서를 확인해 주세요.');
        }
        foreach ($raw as $id) {
            if (! is_string($id) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) !== 1) {
                throw new DomainException('코드 조각의 순서를 확인해 주세요.');
            }
        }

        return $raw;
    }

    private function optionalPositiveInt(
        HttpRequest $request,
        string $key
    ): ?int {
        $value = $request->bodyInt($key);

        return $value > 0 ? $value : null;
    }

}
