<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Application;

use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Ops\Options;
use OJML\Playgrounds\Security\Addon_Capabilities;

/** Queues a teacher-authorized recheck; never accepts a file or bypasses inspection. */
final class ReconsiderationService
{
    public function __construct(
        private readonly Repository $repository,
        private readonly AssignmentService $assignments
    ) {
    }

    /** @param array<string,mixed> $input @return array{attempt:array<string,mixed>,created:bool,httpStatus:int} */
    public function reconsider(string $attemptUuid, int $actorId, array $input, string $correlationId): array
    {
        $capability = Addon_Capabilities::wordpress(Addon_Capabilities::MANAGE);
        if ($actorId < 1) {
            throw new Problem('OJ_ML_AUTHENTICATION_REQUIRED', '로그인이 필요합니다.', 401);
        }
        if ($capability === '' || !function_exists('user_can') || !user_can($actorId, $capability)) {
            throw new Problem('OJ_ML_CAPABILITY_REQUIRED', '반려된 제출의 재검토 요청에는 과제 관리 권한이 필요합니다.', 403);
        }
        if (Options::all()['maintenanceMode']) {
            throw new Problem('OJ_ML_MAINTENANCE', 'ML 점검 중에는 재검토를 요청할 수 없습니다.', 503);
        }
        $request = self::normalizeRequest($input);
        // The current class evaluate permission is required on every request, including replay.
        $attempt = $this->assignments->teacherResult($attemptUuid, $actorId, 'evaluate');

        // Status, latest evaluation, artifact retention and duplicate keys are checked together
        // under the repository's attempt row lock. Do not reject a replay on its new status here.
        $queued = $this->repository->queueReconsideration(
            (string) $attempt['uuid'],
            $actorId,
            $request['expectedEvaluationId'],
            $request['reason'],
            $request['idempotencyKey'],
            $correlationId
        );
        $queued['attempt']['context_may_evaluate'] = true;
        return [
            'attempt' => $queued['attempt'],
            'created' => $queued['created'],
            'httpStatus' => $queued['created'] ? 202 : 200,
        ];
    }

    /** @param array<string,mixed> $input @return array{reason:string,expectedEvaluationId:int,idempotencyKey:string} */
    public static function normalizeRequest(array $input): array
    {
        if (array_diff(array_keys($input), ['reason', 'expectedEvaluationId', 'idempotencyKey']) !== []) {
            throw new Problem('OJ_ML_RECONSIDERATION_INPUT_INVALID', '재검토 요청 항목이 올바르지 않습니다.', 400);
        }
        $rawReason = $input['reason'] ?? null;
        if (!is_string($rawReason) || strlen($rawReason) > 1200 || preg_match('//u', $rawReason) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $rawReason) === 1) {
            throw new Problem('OJ_ML_RECONSIDERATION_REASON_INVALID', '재검토 사유를 1~300자로 입력하세요.', 400);
        }
        $reason = trim(sanitize_textarea_field($rawReason));
        if (preg_match('/^.{1,300}$/su', $reason) !== 1) {
            throw new Problem('OJ_ML_RECONSIDERATION_REASON_INVALID', '재검토 사유를 1~300자로 입력하세요.', 400);
        }
        $rawId = $input['expectedEvaluationId'] ?? null;
        $evaluationId = (is_int($rawId) || is_string($rawId))
            ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($evaluationId === false) {
            throw new Problem('OJ_ML_RECONSIDERATION_VERSION_INVALID', '현재 검사 결과를 다시 불러온 뒤 재검토를 요청하세요.', 400);
        }
        $key = is_string($input['idempotencyKey'] ?? null) ? strtolower(trim($input['idempotencyKey'])) : '';
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $key) !== 1) {
            throw new Problem('OJ_ML_RECONSIDERATION_KEY_INVALID', '재검토 요청 식별자가 올바르지 않습니다. 화면을 새로 열어 주세요.', 400);
        }
        return ['reason' => $reason, 'expectedEvaluationId' => $evaluationId, 'idempotencyKey' => $key];
    }
}
