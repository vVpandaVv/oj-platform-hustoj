<?php
declare(strict_types=1);
namespace OjMicrobit\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningItemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningLinkV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningProviderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningQueryV1;
use OjMicrobit\Application\Access;
use OjMicrobit\Application\LessonService;
use OjMicrobit\Application\PracticeService;
use OjMicrobit\Core\Plugin;
use OjMicrobit\Support\OperationError;
use OjMicrobit\Support\PlatformAccess;

/** Metadata-only bridge to native problem and lesson pages; no judge table writes. */
final class NativeLearningProvider implements AddonNativeLearningProviderV1
{
    public function __construct(
        private readonly ?LessonService $lessons = null,
        private readonly ?PracticeService $practice = null,
        private readonly ?Access $access = null
    ) {}

    public function query(AddonNativeLearningQueryV1 $query): AddonNativeLearningPageV1
    {
        try {
            $access = $this->access ?? new PlatformAccess();
            if ($query->actorUserId() !== $access->actorId()) {
                throw new OperationError('access_denied', 403, '요청한 사용자와 로그인 사용자가 다릅니다.');
            }
            $lessons = $this->lessons ?? Plugin::lessons();
            $teacher = in_array($query->surface(), ['problems.teacher', 'class.teacher'], true);
            $classPage = in_array($query->surface(), ['class.student', 'class.teacher'], true);
            $actions = [];
            if ($classPage) {
                $page = $teacher
                    ? $lessons->teacherAssignments($query->contextId(), $query->beforeId(), $query->limit())
                    : $lessons->classAssignments($query->contextId(), $query->beforeId(), $query->limit());
                if ($teacher) {
                    $actions[] = new AddonNativeLearningLinkV1('addon.oj_microbit.teacher_lessons', '마이크로비트 문제 적용·결과', [], ['classId' => (string) $query->contextId(), 'mbView' => 'lesson']);
                }
                $items = array_map(fn (array $record): AddonNativeLearningItemV1 => $this->assignment($record, $teacher), $page['items']);
            } elseif ($query->surface() === 'problems.public') {
                $page = $lessons->publicExercises($query->beforeId(), $query->limit());
                $items = array_map(fn (array $record): AddonNativeLearningItemV1 => $this->problem($record, false), $page['items']);
            } elseif ($query->surface() === 'problems.teacher') {
                $page = ($this->practice ?? Plugin::service())->teacherExercises($query->beforeId(), $query->limit());
                $items = array_map(fn (array $record): AddonNativeLearningItemV1 => $this->problem($record, true), $page['items']);
                $actions[] = new AddonNativeLearningLinkV1('addon.oj_microbit.teacher', '새 마이크로비트 문제', [], ['mbView' => 'new']);
            } else {
                throw new OperationError('access_denied', 403, '지원하지 않는 목록입니다.');
            }
            return new AddonNativeLearningPageV1($items, $page['nextBeforeId'], $actions);
        } catch (OperationError $error) {
            $message = $error->errorCode === 'unauthorized'
                ? '로그인한 뒤 마이크로비트 문제를 확인해 주세요.'
                : '마이크로비트 목록의 권한과 연결 상태를 확인해 주세요.';
            return new AddonNativeLearningPageV1([], null, [], false, $message);
        } catch (\Throwable) {
            return new AddonNativeLearningPageV1([], null, [], false, '마이크로비트 목록을 불러오지 못했습니다. 잠시 후 다시 확인해 주세요.');
        }
    }

    private function problem(array $record, bool $teacher): AddonNativeLearningItemV1
    {
        $route = $teacher ? 'addon.oj_microbit.teacher' : 'addon.oj_microbit.learn';
        $query = ['exerciseId' => (string) $record['id']];
        $status = ($record['status'] ?? '') !== 'published' ? '초안'
            : (($record['audience']['mode'] ?? 'site') === 'site' ? '공개' : '기존 제한 배포');
        $primary = new AddonNativeLearningLinkV1($route, $teacher ? '문제 편집' : '문제 보기', [], $query + ['mbView' => $teacher ? 'edit' : 'detail']);
        $secondary = [];
        if ($teacher) {
            $secondary[] = new AddonNativeLearningLinkV1($route, '일반 제출 내역', [], $query + ['mbView' => 'history']);
            $secondary[] = new AddonNativeLearningLinkV1($route, '학생 화면', [], $query + ['mbView' => 'preview']);
        }
        return new AddonNativeLearningItemV1('oj_microbit:exercise:' . $record['id'], $record['title'], '마이크로비트', $primary, $secondary, $status);
    }

    private function assignment(array $record, bool $teacher): AddonNativeLearningItemV1
    {
        $route = $teacher ? 'addon.oj_microbit.teacher_lessons' : 'addon.oj_microbit.lessons';
        $query = ['assignmentId' => (string) $record['id'], 'exerciseId' => (string) $record['exerciseId']];
        $working = ($record['workAvailable'] ?? false) === true;
        $status = ($record['archived'] ?? false) ? '적용 해제 · 기록 보관' : ($working ? '적용 중' : '공개 중단 · 기록 보관');
        $view = $teacher ? 'results' : ($working ? 'detail' : 'history');
        $primary = new AddonNativeLearningLinkV1($route, $teacher ? '수업 결과' : ($working ? '수업 문제 보기' : '내 수업 제출 내역'), [], $query + ['mbView' => $view]);
        $secondary = [];
        if ($teacher || $working) {
            $secondary[] = new AddonNativeLearningLinkV1($route, $teacher ? '수업 제출 내역' : '내 수업 제출', [], $query + ['mbView' => 'history']);
        }
        if ($teacher) { $secondary[] = new AddonNativeLearningLinkV1($route, '문제 설명', [], $query + ['mbView' => 'detail']); }
        return new AddonNativeLearningItemV1('oj_microbit:assignment:' . $record['id'], $record['title'], '마이크로비트', $primary, $secondary, $status);
    }
}
