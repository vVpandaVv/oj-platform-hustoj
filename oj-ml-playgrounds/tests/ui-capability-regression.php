<?php

declare(strict_types=1);

function ojMlUiCapabilityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$handler = file_get_contents($root . '/src/Platform/Addon_Page_Handler.php');
$script = file_get_contents($root . '/resources/js/addon.js');
$style = file_get_contents($root . '/resources/css/addon.css');

ojMlUiCapabilityAssert(is_string($handler), 'Page handler source must be readable.');
ojMlUiCapabilityAssert(is_string($script), 'Console UI source must be readable.');
ojMlUiCapabilityAssert(is_string($style), 'Console UI stylesheet must be readable.');
ojMlUiCapabilityAssert(
    str_contains($handler, 'Addon_Capabilities::REVIEW')
        && str_contains($handler, 'Addon_Capabilities::MANAGE')
        && str_contains($handler, "'data-can-review'"),
    'The page wrapper must derive its mutation flag from REVIEW or MANAGE.'
);
ojMlUiCapabilityAssert(
    str_contains($script, "root.dataset.canReview === '1'")
        && str_contains($script, 'resultDetailsRowHtml(row, mayReview)')
        && str_contains($script, 'var canEvaluate = mayReview && row.canEvaluate === true')
        && str_contains($script, 'var canReview = canEvaluate &&')
        && str_contains($script, 'var canPreview = canEvaluate &&')
        && str_contains($script, 'var downloadSpec = canEvaluate &&')
        && str_contains($script, 'var canReinspect = canEvaluate &&')
        && str_contains($script, 'canEvaluate && artifact.originalName'),
    'The results UI must require global REVIEW/MANAGE and row-level Context evaluate before exposing sensitive controls or the original filename.'
);

ojMlUiCapabilityAssert(
    !str_contains($script, 'oj-ml-button')
        && str_contains($script, 'class="ojp-button')
        && str_contains($style, '.oj-ml-app :where(button:not(.ojp-button))[data-variant="primary"]')
        && !preg_match('/\.oj-ml-app button\s*\{[^}]*--oj-ml-primary/', $style)
        && !str_contains($style, '!important'),
    'Links use the platform ojp-button parts and only primary script buttons are filled (D-010).'
);
ojMlUiCapabilityAssert(
    str_contains($script, "published: '게시됨'")
        && str_contains($script, 'assignmentStatusLabel(item)'),
    'Student assignment cards must show localized status labels.'
);
ojMlUiCapabilityAssert(
    str_contains($script, 'function runtimeCodeLabel(runtime)')
        && str_contains($script, "runtime.state === 'ready' && runtime.code === ''")
        && str_contains($script, "if (runtime.code !== '') return 'invalid';")
        && str_contains($script, 'escapeHtml(runtimeCodeLabel(health.runtime || {}))')
        && str_contains($script, 'Runtime 진단 코드:')
        && str_contains($script, "health.ok ? '서버 운영 상태가 정상입니다.'")
        && !str_contains($script, "escapeHtml(runtime.code || 'unknown')"),
    'Only an exact ready-and-empty Runtime error code may display and count as healthy.'
);

fwrite(STDOUT, "OJ ML console UI capability regression passed.\n");
