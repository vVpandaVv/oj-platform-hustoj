<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserImportDraftService;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserImportCandidate;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use DomainException;
use Throwable;

final class PreviewUserImportActionHandler implements ActionHandler
{
    private const ACTION = 'oj_console_bulk_preview_users';

    public function __construct(
        private readonly UserImportDraftService $drafts,
        private readonly ActionGuard $guard,
        private readonly Authorization $permissions,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
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
                'oj_create_users'
            );
            $file = $request->file('user_csv');
            if ($file === null) {
                throw new DomainException('CSV 파일을 선택해야 합니다.');
            }
            $draft = $this->drafts->create(
                $file,
                $actor->userId(),
                $this->permissions->can($actor->userId(), 'oj_manage_roles')
            );
            $preview = $draft->preview();
            $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">CSV Jobs</p>'
                . '<h1>일괄 등록 미리보기</h1>'
                . '<p>실행 전에 제외 행과 예정 역할을 확인합니다.</p></div></header>'
                . '<div class="ojp-metrics">'
                . $this->metric('전체', count($preview->candidates()))
                . $this->metric('등록 가능', $preview->validCount())
                . $this->metric('제외', $preview->invalidCount())
                . '</div>'
                . $this->table($preview->candidates())
                . $this->actions(
                    $draft->reference()?->value(),
                    $preview->validCount()
                );

            return $this->pages->respond(
                '일괄 등록 미리보기',
                $content,
                'users.bulk_import',
                $request
            );
        } catch (DomainException $error) {
            return $this->failure($request, $error->getMessage(), 400);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                $request,
                'CSV 미리보기를 처리하지 못했습니다. 잠시 뒤 다시 시도해 주세요.',
                500
            );
        }
    }

    private function failure(
        HttpRequest $request,
        string $message,
        int $status
    ): HttpResponse {
        return $this->pages->respond(
            '일괄 등록 미리보기 실패',
            '<div class="ojp-error-state"><p>' . $this->escape($message) . '</p>'
            . '<a class="ojp-button" href="/oj-console/users/bulk-import">다시 선택</a></div>',
            'users.bulk_import',
            $request,
            $status
        );
    }

    /**
     * @param list<UserImportCandidate> $candidates
     */
    private function table(array $candidates): string
    {
        $rows = '';
        foreach ($candidates as $candidate) {
            $state = $candidate->isValid()
                ? '<span class="ojp-status-badge ojp-status-badge--success">통과</span>'
                : '<span class="ojp-status-badge ojp-status-badge--warning">'
                    . $this->escape($this->errors($candidate->errors())) . '</span>';
            $rows .= '<tr><td>' . $candidate->rowNumber() . '</td>'
                . '<td>' . $this->escape($candidate->userLogin()) . '</td>'
                . '<td>' . $this->escape($candidate->displayName()) . '</td>'
                . '<td>' . $this->escape($candidate->role()) . '</td>'
                . '<td>' . $state . '</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>행</th><th>로그인 아이디</th><th>표시 이름</th>'
            . '<th>역할</th><th>검증</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    private function actions(?string $reference, int $validCount): string
    {
        if ($reference === null || $validCount < 1) {
            return '<div class="ojp-action-bar"><a class="ojp-button"'
                . ' href="/oj-console/users/bulk-import">CSV 다시 선택</a></div>';
        }

        return '<div class="ojp-action-bar"><a class="ojp-button"'
            . ' href="/oj-console/users/bulk-import">취소</a>'
            . '<form method="post" action="' . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_console_bulk_execute_users')
            . '<input type="hidden" name="draft_reference" value="'
            . $this->escape($reference) . '">'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . $validCount . '명 등록 시작</button></form></div>';
    }

    /**
     * @param list<string> $codes
     */
    private function errors(array $codes): string
    {
        $labels = array_map(
            static fn (string $code): string => match ($code) {
                'invalid_login' => '아이디 형식',
                'invalid_email' => '이메일 형식',
                'invalid_password' => '비밀번호 길이',
                'invalid_display_name' => '표시 이름',
                'invalid_affiliation' => '소속 길이',
                'invalid_birth_date' => '생년 형식',
                'duplicate_login' => 'CSV 아이디 중복',
                'duplicate_email' => 'CSV 이메일 중복',
                'existing_login' => '기존 아이디',
                'existing_email' => '기존 이메일',
                'role_not_allowed' => '역할 권한 없음',
                'unknown_role' => '등록되지 않은 역할',
                default => '확인 필요',
            },
            $codes
        );

        return implode(', ', array_values(array_unique($labels)));
    }

    private function metric(string $label, int $value): string
    {
        return '<div><dt>' . $this->escape($label) . '</dt><dd>'
            . $value . '</dd></div>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
