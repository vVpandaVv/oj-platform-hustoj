<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Board\Application\BoardSettingsService;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunitySettings;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use Throwable;

final class CommunitySettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly BoardSettingsService $settings,
        private readonly FeatureFlags $features,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        if (! $this->featureAvailable()) {
            return $this->pages->respond(
                '커뮤니티 설정',
                '<div class="ojp-empty-state"><h1>사용 중인 커뮤니티 기능이 없습니다.</h1>'
                    . '<p>게시판 또는 공지 기능을 켠 뒤 설정할 수 있습니다.</p></div>',
                'community.settings',
                $request,
                404
            );
        }
        $snapshot = $this->settings->snapshot();
        $settings = $this->settings->current();
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">커뮤니티 관리</p>'
            . '<h1>게시판·공지 설정</h1>'
            . '<p>기능별 작성 범위와 보호 첨부 제한을 한곳에서 관리합니다.</p>'
            . '</div></header><form class="ojp-form" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_board_settings')
            . '<input type="hidden" name="expected_revision" value="'
            . $snapshot->revision() . '">'
            . $this->questionSettings($settings)
            . $this->attachmentSettings($settings)
            . $this->rateLimitSettings($settings)
            . '<div class="ojp-command-group"><button class="ojp-button ojp-button--primary" type="submit">'
            . '설정 저장</button></div></form>';

        return $this->pages->respond(
            '커뮤니티 설정',
            $content,
            'community.settings',
            $request
        );
    }

    private function questionSettings(CommunitySettings $settings): string
    {
        return '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>게시와 댓글</h2><p>꺼진 문맥은 사용자 검색·상세·작성에서 함께 제외됩니다.</p>'
            . '</div></div><div class="ojp-check-grid">'
            . $this->check('global_posting', '전체 게시판 글쓰기', $settings->globalPosting())
            . $this->check('board_comments', '게시판 댓글', $settings->boardComments())
            . $this->check('problem_questions', '문제별 질문', $settings->questionsEnabled('problem'))
            . $this->check('class_questions', '수업 질문', $settings->questionsEnabled('class'))
            . $this->check('contest_questions', '대회·평가 질문', $settings->questionsEnabled('contest'))
            . $this->check('group_questions', '그룹 질문', $settings->questionsEnabled('group'))
            . $this->check(
                'notice_comments_default',
                '새 공지의 댓글 기본 허용',
                $settings->noticeCommentsDefault()
            )
            . '</div></section>';
    }

    private function attachmentSettings(CommunitySettings $settings): string
    {
        return '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>보호 첨부파일</h2><p>파일은 공개 미디어 주소 없이 권한 검사 응답으로만 내려받습니다.</p>'
            . '</div></div><div class="ojp-check-grid">'
            . $this->check('attachments', '새 첨부파일 허용', $settings->attachments())
            . $this->check('zip_allowed', 'ZIP 압축 파일 허용', $settings->zipAllowed())
            . '</div><div class="ojp-form-grid"><div class="ojp-field">'
            . '<label for="allowed-extensions">허용 확장자</label>'
            . '<input class="ojp-input" id="allowed-extensions" name="allowed_extensions" value="'
            . $this->escape(implode(', ', $settings->allowedExtensions())) . '"></div>'
            . '<div class="ojp-field"><label for="max-file-mb">파일당 최대 크기(MB)</label>'
            . '<input class="ojp-input" id="max-file-mb" type="number" name="max_file_mb" min="1" max="10" value="'
            . max(1, (int) floor($settings->maxFileBytes() / 1048576)) . '"></div>'
            . '<div class="ojp-field"><label for="max-post-files">게시글 최대 파일</label>'
            . '<input class="ojp-input" id="max-post-files" type="number" name="max_post_files" min="0" max="5" value="'
            . $settings->maxPostFiles() . '"></div>'
            . '<div class="ojp-field"><label for="max-comment-files">댓글 최대 파일</label>'
            . '<input class="ojp-input" id="max-comment-files" type="number" name="max_comment_files" min="0" max="3" value="'
            . $settings->maxCommentFiles() . '"></div></div></section>';
    }

    private function rateLimitSettings(CommunitySettings $settings): string
    {
        return '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>작성 제한</h2><p>10분 동안 사용자 1명이 작성할 수 있는 최대 건수입니다. '
            . '관리자 설정에서도 이 상한을 넘겨 완화할 수 없습니다.</p>'
            . '</div></div><div class="ojp-form-grid">'
            . '<div class="ojp-field"><label for="post-rate-limit">10분당 게시글</label>'
            . '<input class="ojp-input" id="post-rate-limit" type="number" name="post_rate_limit"'
            . ' min="1" max="5" value="' . $settings->postRateLimit() . '"></div>'
            . '<div class="ojp-field"><label for="comment-rate-limit">10분당 댓글</label>'
            . '<input class="ojp-input" id="comment-rate-limit" type="number" name="comment_rate_limit"'
            . ' min="1" max="20" value="' . $settings->commentRateLimit() . '"></div>'
            . '</div></section>';
    }

    private function check(string $name, string $label, bool $checked): string
    {
        return '<label><input type="checkbox" name="' . $this->escape($name)
            . '" value="1"' . ($checked ? ' checked' : '') . '> '
            . $this->escape($label) . '</label>';
    }

    private function featureAvailable(): bool
    {
        try {
            return $this->features->isEnabled('board')
                || $this->features->isEnabled('notices');
        } catch (Throwable) {
            return false;
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
