<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Application\PasswordResetService;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use InvalidArgumentException;
use Throwable;

final class PasswordResetActionHandler implements ActionHandler
{
    private const ACTION = 'oj_user_password_reset';

    public function __construct(
        private readonly PasswordResetService $passwords,
        private readonly ActionGuard $guard,
        private readonly ConsoleResponder $pages,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $actor = $this->guard->actor(
                self::ACTION,
                $request,
                'oj_edit_users'
            );
            $customPassword = trim($request->bodyString('custom_password'));
            $results = $this->passwords->reset(
                $actor->userId(),
                $request->bodyIntList('user_ids'),
                $customPassword !== '' ? $customPassword : null,
                $request->bodyInt('password_length'),
                $request->bodyBool('confirm_weak_password')
            );

            $rows = '';
            $succeededCount = 0;
            foreach ($results as $result) {
                if ($result->succeeded()) {
                    ++$succeededCount;
                }
                $rows .= '<tr><td>' . $result->userId() . '</td><td>'
                    . ($result->succeeded() ? '완료' : '제외') . '</td><td>'
                    . ($result->temporaryPassword() !== null
                        ? $this->passwordField($result->userId(), $result->temporaryPassword())
                        : $this->escape($this->resultLabel($result->resultCode())))
                    . '</td></tr>';
            }
            $failedCount = count($results) - $succeededCount;

            $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">사용자</p>'
                . '<h1>비밀번호 초기화 결과</h1>'
                . '<p>임시 비밀번호는 이 화면에서 한 번만 확인할 수 있습니다.</p></div></header>'
                . $this->summaryNotice($succeededCount, $failedCount)
                . '<div class="ojp-notice ojp-notice--warning"><strong>화면을 닫기 전 전달하세요.</strong>'
                . '<p>분실한 임시 비밀번호는 복구할 수 없으며 다시 초기화해야 합니다.</p></div>'
                . '<div class="ojp-table-wrap"><table class="ojp-table">'
                . '<thead><tr><th>사용자 번호</th><th>결과</th><th>임시 비밀번호 또는 사유</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table></div>'
                . '<div class="ojp-action-bar"><a class="ojp-button ojp-button--primary"'
                . ' href="/oj-console/users">사용자 목록으로 이동</a></div>';

            $response = $this->pages->respond(
                '비밀번호 초기화 결과',
                $content,
                'users.index',
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
                '<div class="ojp-error-state"><p>' . $this->escape($error->getMessage()) . '</p>'
                . '<a class="ojp-button" href="/oj-console/users">사용자 목록으로 이동</a></div>',
                'users.index',
                $request,
                400
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->pages->respond(
                '비밀번호 초기화 실패',
                '<div class="ojp-error-state"><p>대상과 권한을 다시 확인해 주세요.</p>'
                . '<a class="ojp-button" href="/oj-console/users">사용자 목록으로 이동</a></div>',
                'users.index',
                $request,
                400
            );
        }
    }

    private function passwordField(int $userId, string $password): string
    {
        return (new \CodingPanda\OjPlatform\Ui\Component\PasswordFieldView())->result(
            'ojp-temporary-password-' . $userId,
            '사용자 ' . $userId . '의 임시 비밀번호',
            $password
        );
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

    private function resultLabel(string $code): string
    {
        return match ($code) {
            'self_reset_forbidden' => '본인 계정 제외',
            'target_not_allowed' => '보호 대상 또는 이용 불가 계정',
            default => '초기화 실패',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
