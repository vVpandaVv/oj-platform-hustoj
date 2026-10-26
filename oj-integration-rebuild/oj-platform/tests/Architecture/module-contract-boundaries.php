<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__, 2);
$moduleRoot = $pluginRoot . '/modules';
$errors = [];
$referenceCount = 0;
$moduleCount = 0;

if (! is_dir($moduleRoot)) {
    fwrite(STDERR, "모듈 경계 검사 실패\n- modules 폴더가 없습니다.\n");
    exit(1);
}

$moduleDirectories = array_values(array_filter(
    scandir($moduleRoot) ?: [],
    static fn (string $entry): bool => $entry !== '.'
        && $entry !== '..'
        && is_dir($moduleRoot . '/' . $entry)
));
sort($moduleDirectories);
$moduleCount = count($moduleDirectories);

foreach ($moduleDirectories as $ownerModule) {
    $ownerRoot = $moduleRoot . '/' . $ownerModule;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $ownerRoot,
            FilesystemIterator::SKIP_DOTS
        )
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        foreach (token_get_all($contents) as $token) {
            if (! is_array($token)) {
                continue;
            }

            $qualifiedNameTokens = [
                T_NAME_QUALIFIED,
                T_NAME_FULLY_QUALIFIED,
                T_NAME_RELATIVE,
            ];
            if (! in_array($token[0], $qualifiedNameTokens, true)) {
                continue;
            }

            $name = ltrim($token[1], '\\');
            if (
                preg_match(
                    '/^CodingPanda\\\\OjPlatform\\\\Modules\\\\'
                    . '([^\\\\]+)\\\\([^\\\\]+)(?:\\\\|$)/',
                    $name,
                    $matches
                ) !== 1
            ) {
                continue;
            }

            ++$referenceCount;
            $targetModule = $matches[1];
            $targetLayer = $matches[2];
            if (
                $targetModule === $ownerModule
                || $targetLayer === 'Contract'
            ) {
                continue;
            }

            $relativePath = str_replace(
                '\\',
                '/',
                substr($file->getPathname(), strlen($pluginRoot) + 1)
            );
            $errors[] = sprintf(
                '%s:%d에서 %s 모듈의 비공개 계층 %s을 참조합니다.',
                $relativePath,
                $token[2],
                $targetModule,
                $targetLayer
            );
        }
    }
}

if ($errors !== []) {
    fwrite(
        STDERR,
        "모듈 경계 검사 실패\n- "
        . implode("\n- ", array_values(array_unique($errors)))
        . "\n"
    );
    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "모듈 Contract 경계 검사 통과: 모듈 %d개, 타입 참조 %d개, 비 Contract 교차 참조 0개\n",
        $moduleCount,
        $referenceCount
    )
);
