<?php
declare(strict_types=1);

namespace EduGames\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

final class Typing_Evaluations_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('edu-games/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'iconsUrl' => EDU_GAMES_URL . 'assets/shell/icons.svg',
            'studentUrl' => home_url('/oj-addons/edu-games/games/code-typing'),
            'timezone' => function_exists('wp_timezone_string') ? (string) wp_timezone_string() : 'UTC',
        );

        return Page_Response::console(__('타자 그룹 평가', 'edu-games'), self::render($config));
    }

    /** Static shell only; the registered REST endpoints own authorization and data. */
    public static function render(array $config): string {
        ob_start();
        ?>
        <section class="edu-typing-evaluations" data-typing-evaluations data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <?php echo Page_Response::header(__('학습 활동', 'edu-games'), __('타자 그룹 평가', 'edu-games'), ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
            <noscript><p class="ojp-notice ojp-notice--warning">평가 화면을 불러오려면 JavaScript가 필요합니다.</p></noscript>
            <p class="ete-message" data-field="message" role="status" aria-live="polite" aria-atomic="true"></p>

            <section class="ete-section" data-view="list" aria-labelledby="ete-list-title">
                <div class="ete-heading">
                    <h2 id="ete-list-title" tabindex="-1">평가 목록</h2>
                    <div class="ete-actions">
                        <button type="button" class="ojp-button ojp-button--plain" data-action="refresh-list" data-icon="refresh-cw" title="평가 목록 새로고침" aria-label="평가 목록 새로고침"></button>
                        <button type="button" class="ojp-button ojp-button--primary" data-action="new">새 평가</button>
                    </div>
                </div>
                <div data-field="evaluations" aria-busy="true"><p class="ete-state" role="status">평가 목록을 불러오는 중입니다.</p></div>
            </section>

            <section class="ete-section" data-view="create" aria-labelledby="ete-create-title" hidden>
                <div class="ete-heading"><h2 id="ete-create-title" tabindex="-1">새 평가 <span class="ojp-status-badge ojp-status-badge--neutral">초안</span></h2></div>
                <form data-field="create-form">
                    <fieldset class="ete-form-controls" data-field="create-controls">
                        <legend class="ete-sr-only">평가 설정</legend>
                        <div class="ete-settings">
                            <div class="ojp-field ete-title-field"><label for="ete-title">평가명</label><input class="ojp-input" id="ete-title" name="title" type="text" required></div>
                            <div class="ojp-field"><label for="ete-language">언어</label><select class="ojp-select" id="ete-language" name="language" required disabled><option value="">불러오는 중</option></select></div>
                            <div class="ojp-field"><label for="ete-duration">평가 시간</label><select class="ojp-select" id="ete-duration" name="durationSeconds"><option value="60">1분</option><option value="180">3분</option><option value="300">5분</option><option value="600">10분</option></select></div>
                            <div class="ojp-field"><label for="ete-accuracy">최소 정확도 (%)</label><input class="ojp-input" id="ete-accuracy" name="minAccuracy" type="number" min="70" max="100" step="1" value="95" required></div>
                            <div class="ojp-field"><label for="ete-attempts">최대 응시 횟수</label><select class="ojp-select" id="ete-attempts" name="maxAttempts"><option value="1">1회</option><option value="2">2회</option><option value="3">3회</option></select></div>
                            <div class="ojp-field"><label for="ete-line-ending">줄 끝 입력</label><select class="ojp-select" id="ete-line-ending" name="lineEnding"><option value="enter">Enter만</option><option value="space-enter">Space·Enter 허용</option></select></div>
                        </div>
                        <div class="ete-selection-grid">
                            <fieldset class="ete-picker">
                                <legend>대상 학급·그룹 <span class="ete-count" data-field="context-count">0개 선택</span></legend>
                                <div class="ete-check-list" data-field="contexts" aria-busy="true"></div>
                                <p class="ete-message" data-field="contexts-message" role="status"></p>
                                <button type="button" class="ojp-button ojp-button--tinted ojp-button--small" data-action="more-contexts" hidden>학급·그룹 더 보기</button>
                            </fieldset>
                            <fieldset class="ete-picker">
                                <legend>평가 지문 <span class="ete-count" data-field="sample-count">0개 선택</span></legend>
                                <div class="ete-check-list" data-field="samples" aria-busy="true"></div>
                                <p class="ete-message" data-field="samples-message" role="status"></p>
                                <div class="ete-order" data-field="sample-order"></div>
                            </fieldset>
                        </div>
                        <div class="ete-actions ete-form-actions">
                            <button type="submit" class="ojp-button ojp-button--primary" data-field="create-submit" disabled>초안 저장</button>
                            <button type="button" class="ojp-button ojp-button--plain" data-action="cancel-create">취소</button>
                        </div>
                    </fieldset>
                    <p class="ete-message" data-field="create-message" role="status" aria-live="polite"></p>
                </form>
            </section>

            <section class="ete-section" data-view="results" aria-labelledby="ete-results-title" hidden>
                <div class="ete-heading">
                    <div class="ete-result-heading"><button type="button" class="ojp-button ojp-button--plain ete-icon-button" data-action="back" data-icon="arrow-left" title="평가 목록" aria-label="평가 목록"></button><h2 id="ete-results-title" data-field="results-title" tabindex="-1">평가 결과</h2><span data-field="evaluation-status"></span></div>
                    <div class="ete-actions">
                        <button type="button" class="ojp-button ojp-button--tinted" data-action="change-status" data-field="status-button" disabled>평가 시작</button>
                        <button type="button" class="ojp-button ojp-button--plain ete-icon-button" data-action="refresh-results" data-icon="refresh-cw" title="결과 새로고침" aria-label="결과 새로고침"></button>
                        <button type="button" class="ojp-button ojp-button--tinted" data-action="export" data-field="export-button" disabled>전체 CSV</button>
                    </div>
                </div>
                <p class="ete-meta" data-field="evaluation-meta"></p>
                <div class="ete-sharebar" aria-label="학생 참여 링크">
                    <button type="button" class="ojp-button ojp-button--primary" data-action="copy-student-link" data-field="share-button" disabled><svg class="ete-icon" aria-hidden="true"><use href="<?php echo esc_url($config['iconsUrl']); ?>#copy"></use></svg>참여 링크 복사</button>
                    <a class="ojp-button ojp-button--plain" data-field="student-link" target="_blank" rel="noopener" hidden><svg class="ete-icon" aria-hidden="true"><use href="<?php echo esc_url($config['iconsUrl']); ?>#external-link"></use></svg>참여 페이지</a>
                    <span class="ete-message" data-field="share-status" role="status" aria-live="polite"></span>
                    <input class="ojp-input ete-share-url" data-field="share-url" type="text" readonly aria-label="학생 참여 주소" hidden>
                </div>
                <div class="ete-summary" data-field="summary" role="group" aria-label="전체 평가 현황"></div>
                <div class="ete-result-tools" aria-label="결과 조회 조건">
                    <label class="ete-search"><span class="ete-sr-only">학생 검색</span><svg class="ete-icon" aria-hidden="true"><use href="<?php echo esc_url($config['iconsUrl']); ?>#search"></use></svg><input class="ojp-input" type="search" data-result-search maxlength="100" placeholder="학생 이름 · ID 검색"></label>
                    <label><span class="ete-sr-only">대상 그룹</span><select class="ojp-select" data-result-group><option value="">전체 그룹</option></select></label>
                    <label><span class="ete-sr-only">결과 판정</span><select class="ojp-select" data-result-quality><option value="all">모든 판정</option><option value="qualified">기준 충족</option><option value="below">기준 미달</option><option value="review">전송 확인</option></select></label>
                    <label class="ete-mobile-sort"><span class="ete-sr-only">정렬</span><select class="ojp-select" data-result-order><option value="name:asc">이름순</option><option value="name:desc">이름 역순</option><option value="cpm:desc">CPM 높은 순</option><option value="cpm:asc">CPM 낮은 순</option><option value="accuracy:desc">정확도 높은 순</option><option value="accuracy:asc">정확도 낮은 순</option><option value="status:asc">응시 상태순</option><option value="status:desc">응시 상태 역순</option></select></label>
                    <button type="button" class="ojp-button ojp-button--plain ete-icon-button" data-action="reset-filters" data-icon="x" title="조회 조건 초기화" aria-label="조회 조건 초기화"></button>
                    <span class="ete-result-stamp" data-field="result-stamp" role="status" aria-live="polite"></span>
                </div>
                <div data-field="results" aria-busy="false"></div>
                <nav class="ete-pagination" data-field="pagination" aria-label="참여자 페이지" hidden></nav>
                <dialog class="ete-attempts" data-field="attempts-section" aria-labelledby="ete-attempts-title">
                    <div class="ete-heading"><h3 id="ete-attempts-title" data-field="attempts-title" tabindex="-1">응시 기록</h3><button type="button" class="ojp-button ojp-button--plain ete-icon-button" data-action="close-attempts" data-icon="x" title="응시 기록 닫기" aria-label="응시 기록 닫기"></button></div>
                    <div data-field="attempts" aria-busy="false"></div>
                </dialog>
            </section>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}
