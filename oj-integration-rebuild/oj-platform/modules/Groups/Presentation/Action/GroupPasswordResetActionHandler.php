<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use InvalidArgumentException;
use Throwable;

final class GroupPasswordResetActionHandler implements ActionHandler
{
    private const ACTION = 'oj_group_reset_member_password';

    public function __construct(
        private readonly GroupService $groups,
        private readonly ActionAccessGuard $guard,
        private readonly ConsoleResponder $pages,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $groupId = $request->bodyInt('group_id');
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $customPassword = trim($request->bodyString('custom_password'));
            $results = $this->groups->resetMemberPasswords(
                $actor->userId(),
                $groupId,
                $request->bodyIntList('target_user_ids'),
                $customPassword !== '' ? $customPassword : null,
                $request->bodyBool('confirm_weak_password')
            );
            $rows = '';
            $succeededCount = 0;
            foreach ($results as $index => $result) {
                if ($result['success']) {
                    ++$succeededCount;
                }
                $rows .= '<tr><td>' . $this->escape($result['user_login'])
                    . '</td><td>'
                    . ($result['success'] ? '완료' : '제외') . '</td><td>'
                    . ($result['temporary_password'] === null
                        ? $this->reason($result['reason'])
                        : (new \CodingPanda\OjPlatform\Ui\Component\PasswordFieldView())->result(
                            'ojp-group-temporary-password-' . $groupId . '-' . $index,
                            $result['user_login'] . '의 임시 비밀번호',
                            $result['temporary_password']
                        ))
                    . '</td></tr>';
            }
            $failedCount = count($results) - $succeededCount;
            $content = '<header class="ojp-page-header"><div>'
                . '<p class="ojp-eyebrow">그룹 관리</p>'
                . '<h1>비밀번호 초기화 결과</h1></div></header>'
                . $this->summaryNotice($succeededCount, $failedCount)
                . '<div class="ojp-notice ojp-notice--warning"><strong>'
                . '임시 비밀번호는 이 화면에서 한 번만 표시됩니다.</strong>'
                . '<p>이메일 주소와 관계없이 직접 초기화되며 다음 로그인에서 '
                . '비밀번호 변경을 요구합니다.</p></div>'
                . '<div class="ojp-table-wrap"><table class="ojp-table">'
                . '<thead><tr><th>로그인 아이디</th><th>결과</th>'
                . '<th>임시 비밀번호 또는 사유</th></tr></thead><tbody>'
                . $rows . '</tbody></table></div>'
                . '<div class="ojp-action-bar"><a class="ojp-button '
                . 'ojp-button--primary" href="/oj-console/groups/' . $groupId
                . '/members">구성원으로 이동</a></div>';

            $response = $this->pages->respond(
                '비밀번호 초기화 결과',
                $content,
                'classes.groups',
                $request
            );
            return new HttpResponse($response->status(), array_replace($response->headers(), [
                'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]), $response->body());
        } catch (InvalidArgumentException $error) {
            return $this->pages->respond(
                '비밀번호 초기화 실패',
                '<div class="ojp-error-state"><h1>초기화하지 못했습니다.</h1><p>'
                    . $this->escape($error->getMessage()) . '</p><a class="ojp-button"'
                    . ' href="/oj-console/groups/' . $groupId
                    . '/members">구성원으로 이동</a></div>',
                'classes.groups',
                $request,
                400
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->pages->respond(
                '비밀번호 초기화 실패',
                '<div class="ojp-error-state"><h1>초기화하지 못했습니다.</h1><p>'
                    . $this->escape($error->getMessage()) . '</p><a class="ojp-button"'
                    . ' href="/oj-console/groups/' . $groupId
                    . '/members">구성원으로 이동</a></div>',
                'classes.groups',
                $request,
                400
            );
        }
    }

    private function summaryNotice(int $succeededCount, int $failedCount): string
    {
        if ($failedCount === 0) {
            $tone = 'success';
            $message = sprintf('%d명 모두 비밀번호 초기화에 성공했습니다.', $succeededCount);
        } elseif ($succeededCount === 0) {
            $tone = 'danger';
            $message = sprintf('%d명 모두 비밀번호 초기화에 실패했습니다.', $failedCount);
        } else {
            $tone = 'warning';
            $message = sprintf(
                '%d명 성공, %d명 실패했습니다. 실패한 대상은 결과를 확인해 주세요.',
                $succeededCount,
                $failedCount
            );
        }

        return '<div class="ojp-notice ojp-notice--' . $tone . '"><strong>'
            . $this->escape($message) . '</strong></div>';
    }

    private function reason(string $reason): string
    {
        return match ($reason) {
            'target_not_allowed' => '보호 대상 또는 이용 불가 계정',
            default => '초기화 실패',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
