<?php
declare(strict_types=1);

/** Benign page bodies only; platform assets, signed config and all APIs are real implementations. */
function page_category_fixture_document(string $path): ?string {
    $pages = array(
        '/oj-addons/learning-materials/1' => '학습자료 열람',
        '/oj-addons/edu-games/games/ai-model-lab' => 'ML 실습',
        '/oj-addons/edu-games/games/binary-conversion' => '교육게임',
        '/board/thread/1' => '질문과 토론',
    );
    if (! isset($pages[$path])) { return null; }
    $body = '<main class="ojp-main"><h1>' . esc_html($pages[$path]) . '</h1>'
        . '<p>실제 분류·승인·수집·통계를 연결하는 검증용 페이지 본문입니다.</p>'
        . '<nav><a href="/oj-addons/learning-materials/1?class_id=1">학습자료</a> '
        . '<a href="/oj-addons/edu-games/games/ai-model-lab?class_id=1">ML 실습</a> '
        . '<a href="/oj-addons/edu-games/games/binary-conversion?class_id=1">교육게임</a> '
        . '<a href="/board/thread/1?class_id=1">질문과 토론</a></nav>';
    if (str_contains($path, 'ai-model-lab')) {
        $body .= '<iframe id="category-learning-frame" title="내부 실습 화면" srcdoc="&lt;button id=&quot;frame-action&quot;&gt;실습 화면에 초점&lt;/button&gt;"></iframe>';
    }
    return platform_fixture_renderer()->render($pages[$path], $body . '</main>', 'ojp-app', get_current_user_id());
}
