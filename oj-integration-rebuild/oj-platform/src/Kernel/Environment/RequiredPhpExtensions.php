<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Environment;

final class RequiredPhpExtensions
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'openssl' => 'OpenSSL',
        'mysqli' => 'mysqli',
        'dom' => 'DOM',
        'xmlwriter' => 'XMLWriter',
        'zip' => 'ZipArchive',
        'fileinfo' => 'Fileinfo',
    ];

    /** @return array<string, string> */
    public static function labels(): array
    {
        return self::LABELS;
    }

    /** @return list<string> */
    public static function missing(): array
    {
        return array_values(array_filter(
            array_keys(self::LABELS),
            static fn (string $extension): bool => ! extension_loaded($extension)
        ));
    }
}
