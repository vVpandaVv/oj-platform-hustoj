<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$hashes = [
    'jodit.min.js' => '87027ab4ac5798e8ff200c9cbc9c113c0675806c96f8d21ada2e3983806796be',
    'jodit.min.css' => 'b322483c9e54044689c68c761399e5ea332528ccf09f4bdaffb87fe79e5dadd8',
    'purify.min.js' => 'cd37898f9782adf54820c413191b5691803f0f137c799d6edcfd38523b4c2c31',
];
foreach ($hashes as $file => $hash) {
    $path = $root . '/resources/features/richtext/vendor/' . $file;
    if (! is_file($path) || hash_file('sha256', $path) !== $hash) {
        throw new RuntimeException('Common editor vendor integrity failure: ' . $file);
    }
}
foreach (['LICENSE.txt', 'DOMPurify-LICENSE.txt'] as $file) {
    if (! is_file($root . '/resources/features/richtext/vendor/' . $file)) {
        throw new RuntimeException('Missing editor license: ' . $file);
    }
}
$adapter = (string) file_get_contents($root . '/resources/features/richtext/js/editor.js');
$common = (string) file_get_contents($root . '/resources/ui/js/oj-ui.js');
foreach (['sourceEditor: \'area\'', 'sourceEditorCDNUrlsJS: []', 'beautifyHTML: false', 'window.DOMPurify.sanitize', 'pendingUploads', 'editor.getMode()'] as $contract) {
    if (! str_contains($adapter, $contract)) {
        throw new RuntimeException('Missing common editor contract: ' . $contract);
    }
}
if (str_contains($common, 'runTableCommand') || str_contains($common, "execCommand('insertHTML'")) {
    throw new RuntimeException('Legacy rich text editing engine must not coexist with the common library.');
}
echo "Common editor: local assets, licenses, pinned hashes and single-engine contracts passed.\n";
