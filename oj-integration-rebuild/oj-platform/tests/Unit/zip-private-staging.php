<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem {
    function sys_get_temp_dir(): string
    {
        return $GLOBALS['private_zip_temp_root'];
    }

    function mkdir(string $directory, int $permissions = 0777, bool $recursive = false): bool
    {
        $GLOBALS['private_zip_directories'][$directory] = $permissions;
        return \mkdir($directory, $permissions, $recursive);
    }

    function copy(string $source, string $target): bool
    {
        $GLOBALS['private_zip_source_parent'] = dirname($source);
        $archive = new \ZipArchive();
        if ($archive->open($source) !== true) { throw new \RuntimeException('검사할 ZIP을 열지 못했습니다.'); }
        try { $GLOBALS['private_zip_contents'] = $archive->getFromName('1.in'); }
        finally { $archive->close(); }
        return \copy($source, $target);
    }
}

namespace {
    use CodingPanda\OjPlatform\Kernel\Autoloader;
    use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDataRootProvider;
    use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
    use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\ProblemDataWorkspace;
    use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\ZipProblemArchiveManager;

    $pluginRoot = dirname(__DIR__, 2);
    require_once $pluginRoot . '/src/Kernel/Autoloader.php';
    Autoloader::register($pluginRoot);
    if (! class_exists(ZipArchive::class)) {
        throw new RuntimeException('이 ZIP 회귀 검사는 PHP ZIP 확장을 활성화해야 합니다.');
    }
    $fixtureRoot = dirname($pluginRoot, 2) . '/_notes/tmp/zip-private-' . bin2hex(random_bytes(6));
    $tempRoot = $fixtureRoot . '/temp';
    $dataRoot = $fixtureRoot . '/data';
    mkdir($tempRoot, 0700, true);
    mkdir($dataRoot, 0700);
    $GLOBALS['private_zip_temp_root'] = $tempRoot;
    $GLOBALS['private_zip_directories'] = [];
    $privateZipAssertions = 0;
    function privateZipAssert(bool $condition, string $message): void
    {
        ++$GLOBALS['privateZipAssertions'];
        if (! $condition) { throw new RuntimeException($message); }
    }

    try {
        $roots = new class ($dataRoot) implements ProblemDataRootProvider {
            public function __construct(private readonly string $directory) {}
            public function root(): string { return $this->directory; }
            public function problemDirectory(ProblemId $problemId): string { return $this->directory . DIRECTORY_SEPARATOR . $problemId->value(); }
        };
        $workspace = new ProblemDataWorkspace($roots);
        $problemId = new ProblemId(1024);
        $workspace->write($problemId, '1.in', 'private synthetic testcase', null);
        $hash = $workspace->currentHash($problemId, '1.in');
        $archives = new ZipProblemArchiveManager($workspace);
        $result = $archives->create($problemId, 'cases.zip', ['1.in' => $hash]);
        $parent = $GLOBALS['private_zip_source_parent'] ?? '';
        privateZipAssert($parent !== $tempRoot && ($GLOBALS['private_zip_directories'][$parent] ?? null) === 0700, '테스트데이터 ZIP은 공용 임시폴더가 아닌 전용 0700 디렉터리 안에서 생성해야 합니다.');
        privateZipAssert($GLOBALS['private_zip_contents'] === 'private synthetic testcase', 'ZIP의 원래 테스트데이터 내용을 보존해야 합니다.');
        privateZipAssert(is_file($workspace->path($problemId, 'cases.zip')), '완성된 ZIP을 문제 데이터 영역에 설치해야 합니다.');
        privateZipAssert($result->fileCount() === 1, 'ZIP 생성 결과의 파일 수를 보존해야 합니다.');
        privateZipAssert(! is_dir($parent) && (glob($tempRoot . '/*') ?: []) === [], '완료 후 비공개 임시 ZIP과 폴더를 정리해야 합니다.');
        $createRejected = false;
        try { $archives->create($problemId, 'rejected.zip', ['1.in' => str_repeat('f', 64)]); }
        catch (DomainException) { $createRejected = true; }
        privateZipAssert($createRejected && (glob($tempRoot . '/*') ?: []) === [], '소스 변경으로 실패한 ZIP 작업도 비공개 임시 폴더를 정리해야 합니다.');

        $rejectArchive = static function (string $name, array $entries, ?callable $configure = null) use ($workspace, $problemId, $archives): void {
            $archive = new ZipArchive();
            if ($archive->open($workspace->path($problemId, $name), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('합성 ZIP을 만들지 못했습니다.');
            }
            foreach ($entries as $entry => $contents) { $archive->addFromString($entry, $contents); }
            if ($configure !== null) { $configure($archive); }
            $archive->close();
            $rejected = false;
            try { $archives->inspect($problemId, $name); }
            catch (DomainException) { $rejected = true; }
            privateZipAssert($rejected, '안전하지 않은 ZIP은 해제 전에 거부해야 합니다: ' . $name);
        };
        $rejectArchive('traversal.zip', ['../escape.in' => 'synthetic']);
        $rejectArchive('absolute.zip', ['/absolute.in' => 'synthetic']);
        $rejectArchive('nested.zip', ['nested/case.in' => 'synthetic']);
        $rejectArchive('extension.zip', ['uploaded.php' => '<?php echo 1;']);
        $rejectArchive('duplicate.zip', ['Case.in' => 'a', 'case.in' => 'b']);
        $rejectArchive('symlink.zip', ['link.in' => '../target.in'], static function (ZipArchive $archive): void {
            $archive->setExternalAttributesName('link.in', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });
        $rejectArchive('ratio.zip', ['large.in' => str_repeat('0', 4194304)], static function (ZipArchive $archive): void {
            $archive->setCompressionName('large.in', ZipArchive::CM_DEFLATE, 9);
        });
    } finally {
        $removeFixture = static function (string $directory) use (&$removeFixture): void {
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') { continue; }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($path) && ! is_link($path)) { $removeFixture($path); }
                else { unlink($path); }
            }
            rmdir($directory);
        };
        $removeFixture($fixtureRoot);
    }
    fwrite(STDOUT, sprintf("ZIP 비공개 임시 보관 검사 통과: assertion %d개\n", $privateZipAssertions));
}
