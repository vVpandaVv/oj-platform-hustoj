<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/render-code-ordering.php';
$fixture = codeOrderingUiFixture();
$document = new DOMDocument();
@$document->loadHTML('<?xml encoding="UTF-8">' . $fixture['html']);
$xpath = new DOMXPath($document);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) { throw new RuntimeException($message); }
};
$value = static fn (string $name): string => (string) $xpath->evaluate(
    'string(//input[@name="' . $name . '"]/@value)'
);
$assert($value('submission_mode') === 'code_ordering', '서버에서 코드 순서 배열 모드를 고정해야 합니다.');
$assert($value('source') === '', '완성 소스를 학생 폼에 전달하면 안 됩니다.');
$assert($value('language_id') === '6', '배열 스키마의 Python 언어를 사용해야 합니다.');
$assert($value('template_hash') === $fixture['hash'], '현재 배열 버전 해시를 제출해야 합니다.');
$assert($value('class_id') === '77' && $value('nonce') === 'fixture-token', '기존 맥락과 보안 토큰을 보존해야 합니다.');
$assert(preg_match('/^[a-f0-9]{64}$/D', $value('request_key')) === 1, '제출 멱등 키를 유지해야 합니다.');
$assert($xpath->query('//textarea')->length === 0, '조각 본문을 편집 가능한 입력으로 만들면 안 됩니다.');
$assert($xpath->query('//script')->length === 0, '코드 조각의 HTML을 안전하게 표시해야 합니다.');
$assert(! str_contains($fixture['html'], 'PRIVATE_STARTER_ORDER') && ! str_contains($fixture['html'], 'private_front'), '원본 순서와 숨김 코드를 노출하면 안 됩니다.');
$assert(! str_contains($fixture['html'], 'language_key') && ! str_contains($fixture['html'], '"blocks"'), '서버 스키마를 브라우저 JSON으로 전달하면 안 됩니다.');
$ids = [];
foreach ($xpath->query('//input[@name="ordering_block_ids[]"]') as $input) {
    $ids[] = $input->getAttribute('value');
}
$assert(count($ids) === count($fixture['ids']) && count(array_unique($ids)) === count($ids), '모든 조각을 한 번씩 제공해야 합니다.');
$displayedIds = $ids;
$expectedIds = $fixture['ids'];
sort($displayedIds);
sort($expectedIds);
$assert($displayedIds === $expectedIds, '공개 조각은 원본과 같은 ID 집합을 순서 정보 없이 제공해야 합니다.');
$assert($xpath->query('//*[@data-oj-ordering-move]')->length === count($ids) * 2, '모든 조각에 키보드와 모바일 이동 버튼이 필요합니다.');
$assert(str_contains($fixture['html'], '    total += number'), '고정된 원본 들여쓰기를 보존해야 합니다.');
$assert($xpath->query('//button[@data-oj-ordering-submit and @disabled]')->length === 1, '배열 도구 준비 전 제출을 차단해야 합니다.');
$assert(str_contains($fixture['html'], $fixture['hash'] . '_0_-1_77_0'), '초안은 문제 버전과 학습 맥락별로 분리해야 합니다.');
$assert(str_contains($fixture['html'], 'oj_submission_ordering_0_4096_'), '사용자 없는 도구 초안은 별도 영역에 저장해야 합니다.');
$actorFixture = codeOrderingUiFixture(123);
$assert(str_contains($actorFixture['html'], 'oj_submission_ordering_123_4096_'), '공용 브라우저의 다른 학생 초안과 분리해야 합니다.');
echo '코드 순서 배열 학생 폼 검사 통과: ' . $checks . "개\n";
