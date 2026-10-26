<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Materials/Google_Link.php';
require_once dirname(__DIR__) . '/src/Materials/Material.php';
function __(string $text, string $domain): string { return $text; }

use LearningMaterials\Materials\Google_Link;
use LearningMaterials\Materials\Material;

$valid = array(
    'https://docs.google.com/document/d/test_123/edit?usp=sharing' => 'https://docs.google.com/document/d/test_123/preview',
    'https://docs.google.com/document/u/0/d/test_123/edit' => 'https://docs.google.com/document/d/test_123/preview',
    'https://docs.google.com/spreadsheets/d/test_123/edit#gid=42' => 'https://docs.google.com/spreadsheets/d/test_123/preview?gid=42',
    'https://docs.google.com/presentation/d/test_123/edit' => 'https://docs.google.com/presentation/d/test_123/embed',
    'https://docs.google.com/document/d/e/published_123/pub' => 'https://docs.google.com/document/d/e/published_123/pub?embedded=true',
    'https://docs.google.com/spreadsheets/d/e/published_123/pubhtml?gid=42' => 'https://docs.google.com/spreadsheets/d/e/published_123/pubhtml?gid=42',
    'https://docs.google.com/presentation/d/e/published_123/pub' => 'https://docs.google.com/presentation/d/e/published_123/embed',
    'https://drive.google.com/file/d/file_123/view?resourcekey=0-key' => 'https://drive.google.com/file/d/file_123/preview?resourcekey=0-key',
    'https://drive.google.com/open?id=file_123' => 'https://drive.google.com/file/d/file_123/preview',
);
foreach ($valid as $input => $expected) {
    $parsed = Google_Link::parse($input);
    if ($parsed['viewer_url'] !== $expected || Google_Link::parse($parsed['source_url'])['viewer_url'] !== $expected) {
        throw new RuntimeException('Incorrect or non-round-trippable link: ' . $input);
    }
}
foreach (array('javascript:alert(1)', 'http://docs.google.com/document/d/abc/edit',
    'https://docs.google.com.evil.test/document/d/abc/edit', 'https://docs.google.com@evil.test/document/d/abc/edit',
    'https://user@docs.google.com/document/d/abc/edit', 'https://docs.google.com:444/document/d/abc/edit',
    'https://drive.google.com/drive/folders/abc', 'https://docs.google.com/forms/d/abc/edit',
    'https://evil.test/', 'https://drive.google.com/open?id[]=abc') as $input) {
    try {
        Google_Link::parse($input);
        throw new RuntimeException('Unsafe link accepted: ' . $input);
    } catch (DomainException $expected) {}
}
$legacy = Material::from_row(array('extension' => 'pdf'));
if ($legacy->is_google_link() || ! $legacy->is_viewable_inline()) throw new RuntimeException('Legacy file compatibility');
foreach (array('ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx') as $extension) {
    $material = Material::from_row(array('extension' => $extension));
    if (! $material->uses_google_viewer() || $material->is_viewable_inline() || $material->content_type() === 'application/octet-stream') {
        throw new RuntimeException('Office file routing failed: ' . $extension);
    }
}
foreach (array('pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'webp') as $extension) {
    $material = Material::from_row(array('extension' => $extension));
    if (! $material->is_viewable_inline() || $material->uses_google_viewer()) throw new RuntimeException('Native preview routing failed');
}
$link = Material::from_row(array('extension' => 'google', 'source_url' => 'https://docs.google.com/document/d/test_123/edit'));
if (! $link->is_google_link() || ! $link->uses_google_viewer() || $link->is_viewable_inline()) throw new RuntimeException('Link routing failed');
echo "Google links: 9 canonical/round-trip cases, 10 unsafe links, file routes and legacy compatibility passed.\n";
