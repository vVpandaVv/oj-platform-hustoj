<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Materials/Material_Storage.php';
function __(string $text, string $domain): string { return $text; }
use LearningMaterials\Materials\Material_Storage;

$path = dirname(__DIR__, 2) . '/_notes/tmp/lmt-format-' . bin2hex(random_bytes(8));
$inspect = new ReflectionMethod(Material_Storage::class, 'inspect');
$storage = new Material_Storage();
$cases = array(
    array('notes.txt', '학습자료 본문', true),
    array('table.csv', "name,score\nstudent,90\n", true),
    array('image.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jT1kAAAAASUVORK5CYII='), true),
    array('fake.png', 'plain text pretending to be an image', false),
    array('attack.php.txt', 'plain text', false),
    array('page.html', '<script>alert(1)</script>', false),
    array('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>', false),
    array('archive.zip', 'plain text', false),
);
try {
    foreach ($cases as [$name, $contents, $expected]) {
        file_put_contents($path, $contents);
        try {
            $inspect->invoke($storage, array('name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contents)));
            $accepted = true;
        } catch (DomainException $error) { $accepted = false; }
        if ($accepted !== $expected) throw new RuntimeException('Unexpected file validation: ' . $name);
    }
} finally {
    if (is_file($path)) unlink($path);
}
echo "File formats: text/CSV/image acceptance, MIME mismatch and unsafe extensions passed.\n";
