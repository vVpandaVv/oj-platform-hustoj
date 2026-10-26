<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestDataJobService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestDataService;
use CodingPanda\OjPlatform\Modules\Problems\Application\VisualGradingService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\PngReferenceImage;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class ProblemTestDataActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_test_data_action';

    public function __construct(
        private readonly ProblemTestDataService $testData,
        private readonly ProblemTestDataJobService $jobs,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors,
        private readonly ?VisualGradingService $visualGrading = null
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        $problemIdValue = $request->bodyInt('problem_id');
        $path = $problemIdValue > 0
            ? '/oj-console/problems/' . $problemIdValue . '/test-data'
            : '/oj-console/problems';
        $returnTab = $request->bodyString('return_tab');
        if ($problemIdValue > 0 && in_array($returnTab, ['visual', 'spj'], true)) {
            $path .= '?tab=' . $returnTab;
        }
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorUserId = $actor->userId();
            $problemId = new ProblemId($problemIdValue);
            $operation = $request->bodyString('operation');
            if ($operation === 'apply_ai_draft') {
                $result = $this->jobs->enqueueAiDraftApply(
                    $actorUserId,
                    $problemId,
                    $request->bodyString('draft_reference'),
                    $this->selectedNames($request),
                    $request->bodyBool('overwrite_collisions')
                );

                return $this->responses->redirect(
                    '/oj-console/jobs/' . $result->jobId(),
                    new UiMessage(
                        'problems.testcase_apply_queued',
                        MessageTone::Success,
                        '테스트케이스 적용 작업 등록',
                        '현재 파일을 다시 확인한 뒤 선택한 AI 초안을 적용합니다.'
                    ),
                    $actorUserId
                );
            }
            $message = match ($operation) {
                'save' => $this->save($request, $actorUserId, $problemId),
                'upload' => $this->upload($request, $actorUserId, $problemId),
                'rename' => $this->rename($request, $actorUserId, $problemId),
                'delete' => $this->delete($request, $actorUserId, $problemId),
                'create_zip' => $this->createZip($request, $actorUserId, $problemId),
                'extract_zip' => $this->extractZip($request, $actorUserId, $problemId),
                'compile_spj' => $this->compileSpj($request, $actorUserId, $problemId),
                'install_visual_grader' => $this->installVisualGrader($request, $actorUserId, $problemId),
                'upload_reference_image' => $this->uploadReferenceImage($request, $actorUserId, $problemId),
                'arm_capture' => $this->armCapture($actorUserId, $problemId),
                'apply_capture' => $this->applyCapture($request, $actorUserId, $problemId),
                'cancel_capture' => $this->cancelCapture($actorUserId, $problemId),
                'set_tolerance' => $this->setTolerance($request, $actorUserId, $problemId),
                default => throw new DomainException('지원하지 않는 테스트데이터 작업입니다.'),
            };

            return $this->responses->redirect(
                $path,
                new UiMessage(
                    'problems.test_data_completed',
                    MessageTone::Success,
                    '테스트데이터 작업 완료',
                    $message
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                $path,
                new UiMessage(
                    'problems.test_data_rejected',
                    MessageTone::Danger,
                    '테스트데이터 작업 실패',
                    $error->getMessage()
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                $path,
                new UiMessage(
                    'problems.test_data_failed',
                    MessageTone::Danger,
                    '테스트데이터 작업 실패',
                    '파일 작업을 완료하지 못했습니다. 데이터 루트와 파일 권한을 확인해 주세요.'
                ),
                $actorUserId
            );
        }
    }

    private function save(
        HttpRequest $request,
        int $actorUserId,
        ProblemId $problemId
    ): string {
        $expected = trim($request->bodyString('expected_sha256'));
        $this->testData->save(
            $actorUserId,
            $problemId,
            $request->bodyString('file_name'),
            $request->bodyString('contents'),
            $expected === '' ? null : $expected
        );

        return '파일을 저장했습니다.';
    }

    private function upload(
        HttpRequest $request,
        int $actorUserId,
        ProblemId $problemId
    ): string {
        $file = $request->file('test_data_file');
        if ($file === null || ! $file->isSuccessful()) {
            throw new DomainException('업로드할 파일을 선택해 주세요.');
        }
        $this->testData->upload(
            $actorUserId,
            $problemId,
            $file->clientName(),
            $file->temporaryPath()
        );

        return '파일을 업로드했습니다.';
    }

    private function rename(
        HttpRequest $request,
        int $actorUserId,
        ProblemId $problemId
    ): string {
        $this->testData->rename(
            $actorUserId,
            $problemId,
            $request->bodyString('file_name'),
            $request->bodyString('new_file_name'),
            $request->bodyString('expected_sha256')
        );

        return '파일 이름을 변경했습니다.';
    }

    private function delete(
        HttpRequest $request,
        int $actorUserId,
        ProblemId $problemId
    ): string {
        $selected = $this->selectedHashes($request);
        $this->testData->deleteMany($actorUserId, $problemId, $selected);

        return count($selected) . '개 파일을 삭제했습니다.';
    }

    private function createZip(
        HttpRequest $request,
        int $actorUserId,
        ProblemId $problemId
    ): string {
        $result = $this->jobs->enqueueArchiveCreate(
            $actorUserId,
            $problemId,
            $request->bodyString('archive_name'),
            $this->selectedHashes($request)
        );

        return 'ZIP 생성 작업 ' . $result->jobId() . '번을 등록했습니다.';
    }

    private function extractZip(
        HttpRequest $request,
        int $actorUserId,
        ProblemId $problemId
    ): string {
        $result = $this->jobs->enqueueArchiveExtract(
            $actorUserId,
            $problemId,
            $request->bodyString('archive_name'),
            $request->bodyString('archive_sha256'),
            $request->bodyBool('overwrite_collisions')
        );

        return 'ZIP 해제 작업 ' . $result->jobId() . '번을 등록했습니다.';
    }

    private function compileSpj(
        HttpRequest $request,
        int $actorUserId,
        ProblemId $problemId
    ): string {
        $result = $this->jobs->enqueueSpjCompile(
            $actorUserId,
            $problemId,
            $request->bodyString('source_name')
        );

        return 'SPJ 컴파일 작업 ' . $result->jobId() . '번을 등록했습니다.';
    }

    private function installVisualGrader(
        HttpRequest $request,
        int $actorUserId,
        ProblemId $problemId
    ): string {
        $result = $this->visualGradingService()->installGrader(
            $actorUserId,
            $problemId,
            $request->bodyBool('replace_existing')
        );
        $saved = $result->sourceWritten
            ? '이미지 채점기 소스(spj.cc)를 저장했습니다. '
            : '이미지 채점기 소스(spj.cc)가 이미 최신입니다. ';
        if ($result->compileJobId !== null) {
            return $saved . '컴파일 작업 ' . $result->compileJobId . '번을 등록했습니다.'
                . ' 컴파일은 작업 대기열에서 순서대로 처리되어 몇 분 걸릴 수 있으며, 끝나면 이 화면의 "채점기 실행 파일"에 크기가 표시됩니다.'
                . ' 잠시 뒤 새로고침해 확인하고, 계속 없으면 작업 목록에서 실패 사유를 확인해 주세요.';
        }

        return $saved . '컴파일 작업은 등록하지 못했습니다: '
            . (string) $result->compileProblem;
    }

    private function uploadReferenceImage(
        HttpRequest $request,
        int $actorUserId,
        ProblemId $problemId
    ): string {
        $file = $request->file('reference_image');
        if ($file === null || ! $file->isSuccessful()) {
            throw new DomainException('업로드할 정답 PNG 파일을 선택해 주세요.');
        }
        $path = $file->temporaryPath();
        $bytes = is_file($path)
            ? file_get_contents($path, false, null, 0, PngReferenceImage::MAX_BYTES + 1)
            : false;
        if (! is_string($bytes)) {
            throw new DomainException('업로드한 파일을 읽지 못했습니다.');
        }
        $name = $this->visualGradingService()->saveReferenceImage(
            $actorUserId,
            $problemId,
            $request->bodyString('case_name'),
            $request->bodyString('case_input'),
            $bytes,
            $request->bodyBool('replace_existing')
        );

        return $name . '.in / ' . $name . '.out 정답 이미지 테스트를 저장했습니다.';
    }

    private function armCapture(int $actorUserId, ProblemId $problemId): string
    {
        $this->visualGradingService()->armCapture($actorUserId, $problemId);

        return '정답 캡처를 시작했습니다. 10분 안에 모범 답안을 이 문제에 제출해 주세요.';
    }

    private function applyCapture(
        HttpRequest $request,
        int $actorUserId,
        ProblemId $problemId
    ): string {
        $name = $this->visualGradingService()->applyCapture(
            $actorUserId,
            $problemId,
            $request->bodyString('case_name'),
            $request->bodyString('case_input'),
            $request->bodyBool('replace_existing')
        );

        return $name . '.in / ' . $name . '.out 정답 이미지 테스트로 저장했습니다.';
    }

    private function setTolerance(
        HttpRequest $request,
        int $actorUserId,
        ProblemId $problemId
    ): string {
        $tolerance = $request->bodyInt('tolerance', -1);
        $this->visualGradingService()->saveTolerance($actorUserId, $problemId, $tolerance);

        return $tolerance === VisualGradingService::DEFAULT_TOLERANCE
            ? '비교 기준을 기본값(엄격, 색 허용치 ' . $tolerance . ')으로 되돌렸습니다.'
            : '색 허용치를 ' . $tolerance . '로 저장했습니다. 새 제출부터 적용됩니다.';
    }

    private function cancelCapture(int $actorUserId, ProblemId $problemId): string
    {
        $this->visualGradingService()->cancelCapture($actorUserId, $problemId);

        return '정답 캡처를 취소하고 임시 파일을 정리했습니다.';
    }

    private function visualGradingService(): VisualGradingService
    {
        return $this->visualGrading
            ?? throw new DomainException('이미지 채점 기능을 사용할 수 없습니다.');
    }

    /**
     * @return array<string, string>
     */
    private function selectedHashes(HttpRequest $request): array
    {
        $body = $request->body();
        $names = is_array($body['file_names'] ?? null)
            ? $body['file_names']
            : [];
        $hashes = is_array($body['file_hashes'] ?? null)
            ? $body['file_hashes']
            : [];
        $selected = [];
        foreach ($names as $name) {
            if (! is_scalar($name)) {
                continue;
            }
            $fileName = (string) $name;
            $hash = $hashes[$fileName] ?? null;
            if (is_scalar($hash)) {
                $selected[$fileName] = (string) $hash;
            }
        }

        return $selected;
    }

    /**
     * @return list<string>
     */
    private function selectedNames(HttpRequest $request): array
    {
        $body = $request->body();
        $names = is_array($body['file_names'] ?? null)
            ? $body['file_names']
            : [];
        $selected = [];
        foreach ($names as $name) {
            if (is_scalar($name) && trim((string) $name) !== '') {
                $selected[] = trim((string) $name);
            }
        }

        return array_values(array_unique($selected));
    }
}
