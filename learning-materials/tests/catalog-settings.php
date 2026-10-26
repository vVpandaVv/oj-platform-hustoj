<?php
declare(strict_types=1);
foreach (array('Material', 'Material_Settings', 'Material_Catalog', 'Preview_Link') as $class) require_once dirname(__DIR__) . '/src/Materials/' . $class . '.php';
function __(string $text, string $domain): string { return $text; }
use LearningMaterials\Materials\Material;
use LearningMaterials\Materials\Material_Settings;
use LearningMaterials\Materials\Material_Catalog;
use LearningMaterials\Materials\Preview_Link;

$checks = 0;
function check(bool $passed, string $name): void { global $checks; $checks++; if (! $passed) throw new RuntimeException($name); }
function item(int $id, array $extra = array()): Material {
    return Material::from_row(array_merge(array('id' => $id, 'title' => '자료 ' . $id, 'extension' => 'pdf',
        'created_at' => '2026-10-01 00:00:00', 'publication_status' => 'published'), $extra));
}
$now = strtotime('2026-10-15 03:00:00 UTC');
$timezone = new DateTimeZone('Asia/Seoul');
$settings = Material_Settings::from_input(array('category' => '정보', 'tags' => 'AI, 윤리, AI', 'publication_status' => 'published',
    'is_pinned' => '1', 'publish_from' => '2026-10-15T12:00', 'publish_until' => '2026-10-16T12:00'), $timezone);
check($settings->from === '2026-10-15 03:00:00' && $settings->until === '2026-10-16 03:00:00', 'timezone conversion');
check($settings->tags === array('AI', '윤리') && $settings->pinned, 'tags and pin');
check(Material_Settings::local_date($settings->from, $timezone) === '2026-10-15T12:00', 'local roundtrip');
check(Material_Settings::from_input(array(), $timezone)->status === 'draft', 'new materials default to draft');
$configured = item(1, $settings->row());
check($configured->is_available($now) && ! $configured->is_available($now - 1), 'start inclusive');
check(! $configured->is_available(strtotime('2026-10-16 03:00:00 UTC')), 'end exclusive');
foreach (array(array('publication_status' => 'invalid'), array('tags' => implode(',', range(1, 11))),
    array('category' => str_repeat('가', 121)), array('tags' => str_repeat('나', 41)), array('publish_from' => '2026-02-30T12:00'),
    array('publish_from' => '2026-10-15T12:00', 'publish_until' => '2026-10-15T11:00'), array('publication_status' => array('published'))) as $invalid) {
    try { Material_Settings::from_input($invalid, $timezone); check(false, 'invalid settings accepted'); }
    catch (DomainException $expected) { check(true, 'invalid settings rejected'); }
}
$materials = array($configured, item(2, array('title' => 'Secret', 'category' => '비공개 분류', 'tags_json' => '["비공개태그"]')),
    item(3, array('publication_status' => 'draft')), item(4, array('publication_status' => 'archived')),
    item(5, array('publish_from' => '2026-10-16 00:00:00')), item(6, array('publish_until' => '2026-10-15 03:00:00')),
    item(7, array('title' => 'Excel Practice', 'description' => '탐색 설명', 'extension' => 'xlsx', 'category' => '정보', 'tags_json' => '["연습"]')));
$map = array(1 => array('own', 'secret'), 2 => array('secret'), 7 => array('own'));
$allowed = static fn (array $groups): bool => $groups === array() || in_array('own', $groups, true);
$result = Material_Catalog::page($materials, $map, array(), $allowed, false, $now, 1);
check($result['total'] === 2 && $result['pages'] === 2 && $result['items'][0]->id === 1, 'permission-first pagination and pinned order');
check($result['categories'] === array('정보') && ! in_array('비공개태그', $result['tags'], true) && $result['groups'] === array('own'), 'facets do not leak');
$second = Material_Catalog::page($materials, $map, array('lmt_page' => 999), $allowed, false, $now, 1);
check($second['page'] === 2 && $second['items'][0]->id === 7, 'page clamp');
foreach (array(array('q' => 'excel'), array('q' => '탐색'), array('type' => 'spreadsheet'), array('tag' => '연습'), array('category' => '정보', 'type' => 'spreadsheet')) as $query) {
    $filtered = Material_Catalog::page($materials, $map, $query, $allowed, false, $now);
    check($filtered['total'] === 1 && $filtered['items'][0]->id === 7, 'search/filter');
}
$secret = Material_Catalog::page($materials, $map, array('group' => 'secret'), $allowed, false, $now);
check($secret['total'] === 0, 'unauthorized group filter denied');
$admin = Material_Catalog::page($materials, $map, array('status' => 'draft'), static fn ($groups) => true, true, $now);
check($admin['total'] === 1 && $admin['items'][0]->id === 3, 'console draft filter');
$publicOverride = Material_Catalog::page($materials, $map, array('status' => 'draft'), $allowed, false, $now);
check($publicOverride['total'] === 2, 'public cannot request hidden states');
$signature = Preview_Link::signature($configured, array('own'), $now + 600, 'key');
$archived = item(1, array_merge($settings->row(), array('publication_status' => 'archived')));
check(! Preview_Link::valid($archived, array('own'), $now + 600, $signature, 'key', $now), 'status change revokes signed link');
$managerSignature = Preview_Link::signature($configured, array('own'), $now + 600, 'key', true);
check(! Preview_Link::valid($configured, array('own'), $now + 600, $signature, 'key', $now, true), 'manager flag cannot be forged');
check(Preview_Link::valid($configured, array('own'), $now + 600, $managerSignature, 'key', $now, true), 'manager preview signed separately');
echo "$checks catalog/publication/settings checks passed.\n";
