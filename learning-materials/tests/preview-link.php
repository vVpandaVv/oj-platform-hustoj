<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Materials/Material.php';
require_once dirname(__DIR__) . '/src/Materials/Preview_Link.php';

use LearningMaterials\Materials\Material;
use LearningMaterials\Materials\Preview_Link;

function material(string $filename = 'original.pptx', string $updated = '2026-10-15'): Material {
    return new Material(7, '자료', '', $filename, '자료.pptx', 'pptx', 'application/zip', 100, 1, '2026-10-15', $updated);
}

$now = 100000;
$expires = $now + Preview_Link::TTL;
$key = 'test-key';
$file = material();
$groups = array('group-b', 'group-a');
$signature = Preview_Link::signature($file, $groups, $expires, $key);
$cases = array(
    'valid link' => Preview_Link::valid($file, $groups, $expires, $signature, $key, $now),
    'multiple fetches allowed' => Preview_Link::valid($file, $groups, $expires, $signature, $key, $now + 1),
    'group order does not matter' => Preview_Link::valid($file, array_reverse($groups), $expires, $signature, $key, $now),
    'expiry rejected' => ! Preview_Link::valid($file, $groups, $expires, $signature, $key, $expires),
    'future lifetime capped' => ! Preview_Link::valid($file, $groups, $expires, $signature, $key, $now - 1),
    'expiry tampering rejected' => ! Preview_Link::valid($file, $groups, $expires - 1, $signature, $key, $now),
    'bad signature rejected' => ! Preview_Link::valid($file, $groups, $expires, str_repeat('0', 64), $key, $now),
    'array-like signature rejected' => ! Preview_Link::valid($file, $groups, $expires, 'invalid', $key, $now),
    'wrong key rejected' => ! Preview_Link::valid($file, $groups, $expires, $signature, 'other-key', $now),
    'replacement rejected' => ! Preview_Link::valid(material('replacement.pptx'), $groups, $expires, $signature, $key, $now),
    'update rejected' => ! Preview_Link::valid(material('original.pptx', '2026-10-16'), $groups, $expires, $signature, $key, $now),
    'visibility change rejected' => ! Preview_Link::valid($file, array(), $expires, $signature, $key, $now),
);
$other = new Material(8, '자료', '', 'original.pptx', '자료.pptx', 'pptx', 'application/zip', 100, 1, '2026-10-15', '2026-10-15');
$cases['different material rejected'] = ! Preview_Link::valid($other, $groups, $expires, $signature, $key, $now);
foreach ($cases as $name => $passed) {
    if (! $passed) {
        fwrite(STDERR, 'FAIL: ' . $name . PHP_EOL);
        exit(1);
    }
}
echo count($cases) . " preview link checks passed.\n";
