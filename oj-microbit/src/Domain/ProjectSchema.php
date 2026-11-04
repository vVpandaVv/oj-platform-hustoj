<?php

declare(strict_types=1);

namespace OjMicrobit\Domain;

use InvalidArgumentException;
use JsonException;

final class ProjectSchema
{
    public const MAX_BYTES = 262144;
    public const MAX_FILES = 32;
    public const MAX_FILENAME_BYTES = 240;
    // PXT workspace state is neither source nor a dependency. Ignore only these
    // exact official filenames; similarly named paths still undergo validation.
    public const WORKSPACE_FILES = ['_history', '.simstate.json', '.git.json'];
    public const DEPENDENCIES = ['core', 'microphone', 'radio', 'servo'];

    /** Validate source files without discarding blocks or invoking a compiler. */
    public static function normalize(array $project): array
    {
        $name = SchemaValue::string($project['name'] ?? null, 120, 'project.name');
        $incoming = SchemaValue::object($project['files'] ?? null, 'project.files');
        if (count($incoming) > self::MAX_FILES + count(self::WORKSPACE_FILES)) {
            throw new InvalidArgumentException('project.files: 파일 수가 올바르지 않습니다.');
        }
        $files = [];
        $filenames = [];
        $bytes = strlen($name);
        foreach ($incoming as $filename => $content) {
            if (is_string($filename) && in_array($filename, self::WORKSPACE_FILES, true)) { continue; }
            self::filename($filename, 'project.files');
            $portableName = strtolower($filename);
            if (isset($filenames[$portableName])) {
                throw new InvalidArgumentException('project.files: ' . self::displayName($filename) . ' 파일명은 다른 파일과 대소문자만 다릅니다.');
            }
            $filenames[$portableName] = true;
            $files[$filename] = SchemaValue::string($content, self::MAX_BYTES, 'project.files.' . $filename, true, false);
            $bytes += strlen($filename) + strlen($files[$filename]);
            if ($bytes > self::MAX_BYTES) {
                throw new InvalidArgumentException('project.files: 프로젝트는 256KiB 이하여야 합니다.');
            }
        }
        if (count($files) < 2 || count($files) > self::MAX_FILES) { throw new InvalidArgumentException('project.files: 소스 파일 수가 올바르지 않습니다.'); }
        if (!array_key_exists('main.ts', $files) || !array_key_exists('pxt.json', $files)) {
            throw new InvalidArgumentException('project.files: main.ts와 pxt.json이 필요합니다.');
        }
        try {
            $config = json_decode($files['pxt.json'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('project.files.pxt.json: 올바른 JSON이 필요합니다.', 0, $error);
        }
        $config = self::config(SchemaValue::object($config, 'pxt.json'), $files, $name);
        $files['pxt.json'] = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // Recheck after canonical JSON encoding, which can expand escaped characters.
        $bytes = strlen($name);
        foreach ($files as $filename => $content) {
            $bytes += strlen($filename) + strlen($content);
        }
        if ($bytes > self::MAX_BYTES) {
            throw new InvalidArgumentException('project.files: 프로젝트는 256KiB 이하여야 합니다.');
        }
        ksort($files, SORT_STRING);
        return ['name' => $name, 'files' => $files];
    }

    public static function hash(array $project): string
    {
        return hash('sha256', json_encode(self::normalize($project), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }

    private static function filename(mixed $filename, string $path): void
    {
        if (!is_string($filename) || strlen($filename) > self::MAX_FILENAME_BYTES || trim($filename) !== $filename
            || preg_match('/\A[\p{L}\p{N}_][\p{L}\p{N}\p{M} _.\-]*\.(?:ts|py|blocks|json|md|jres|txt)\z/uD', $filename) !== 1
            || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:[ .]|\z)/i', $filename) === 1) {
            throw new InvalidArgumentException($path . ': ' . self::displayName($filename) . ' — 경로나 URL이 없는 안전한 파일명이 필요합니다.');
        }
    }

    private static function displayName(mixed $filename): string
    {
        if (!is_string($filename)) { return '[문자열이 아닌 파일명]'; }
        $short = substr($filename, 0, 160);
        return (string) json_encode($short . (strlen($filename) > 160 ? '…' : ''),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
    }

    private static function config(array $config, array $files, string $projectName): array
    {
        $allowed = ['name', 'version', 'description', 'dependencies', 'files', 'testFiles', 'preferredEditor', 'targetVersions', 'supportedTargets', 'public'];
        foreach ($config as $key => $_value) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('pxt.json.' . $key . ': 이 프로젝트 설정은 지원하지 않습니다.');
            }
        }
        $dependencies = SchemaValue::object($config['dependencies'] ?? null, 'pxt.json.dependencies');
        if (!array_key_exists('core', $dependencies)) {
            throw new InvalidArgumentException('pxt.json.dependencies: core 패키지가 필요합니다.');
        }
        foreach ($dependencies as $dependency => $version) {
            if (!in_array($dependency, self::DEPENDENCIES, true) || $version !== '*') {
                throw new InvalidArgumentException('pxt.json.dependencies: core, microphone, radio, servo 기본 패키지만 사용할 수 있습니다.');
            }
        }
        ksort($dependencies, SORT_STRING);
        $sourceFiles = self::fileList($config['files'] ?? null, $files, 'pxt.json.files');
        if (!in_array('main.ts', $sourceFiles, true)) {
            throw new InvalidArgumentException('pxt.json.files: main.ts를 포함해야 합니다.');
        }
        $result = [
            'name' => SchemaValue::string($config['name'] ?? $projectName, 120, 'pxt.json.name'),
            'description' => SchemaValue::string($config['description'] ?? '', 4000, 'pxt.json.description', true),
            'dependencies' => $dependencies,
            'files' => $sourceFiles,
        ];
        if (array_key_exists('version', $config)) {
            $version = SchemaValue::string($config['version'], 64, 'pxt.json.version');
            if (preg_match('/\A[0-9][A-Za-z0-9.+-]*\z/D', $version) !== 1) {
                throw new InvalidArgumentException('pxt.json.version: 버전 형식이 올바르지 않습니다.');
            }
            $result['version'] = $version;
        }
        if (array_key_exists('testFiles', $config)) {
            $result['testFiles'] = self::fileList($config['testFiles'], $files, 'pxt.json.testFiles');
        }
        if (array_key_exists('preferredEditor', $config)) {
            $result['preferredEditor'] = SchemaValue::oneOf($config['preferredEditor'], ['blocksprj', 'tsprj', 'pyprj'], 'pxt.json.preferredEditor');
        }
        if (array_key_exists('targetVersions', $config)) {
            $versions = SchemaValue::object($config['targetVersions'], 'pxt.json.targetVersions');
            foreach ($versions as $key => $value) {
                if (!in_array($key, ['target', 'targetId', 'pxt', 'targetBuild'], true)
                    || !is_string($value) || preg_match('/\A[A-Za-z0-9_.+-]{1,64}\z/D', $value) !== 1) {
                    throw new InvalidArgumentException('pxt.json.targetVersions: 대상 버전 형식이 올바르지 않습니다.');
                }
            }
            if (isset($versions['targetId']) && $versions['targetId'] !== 'microbit') {
                throw new InvalidArgumentException('pxt.json.targetVersions: microbit 대상만 사용할 수 있습니다.');
            }
            ksort($versions, SORT_STRING);
            $result['targetVersions'] = $versions;
        }
        if (array_key_exists('supportedTargets', $config)) {
            $targets = SchemaValue::list($config['supportedTargets'], 1, 'pxt.json.supportedTargets');
            if ($targets !== ['microbit']) {
                throw new InvalidArgumentException('pxt.json.supportedTargets: microbit 대상만 사용할 수 있습니다.');
            }
            $result['supportedTargets'] = $targets;
        }
        if (array_key_exists('public', $config)) {
            $result['public'] = SchemaValue::boolean($config['public'], 'pxt.json.public');
        }
        return $result;
    }

    private static function fileList(mixed $value, array $files, string $path): array
    {
        $incoming = SchemaValue::list($value, self::MAX_FILES + count(self::WORKSPACE_FILES), $path);
        $result = []; $seen = [];
        foreach ($incoming as $filename) {
            if (is_string($filename) && in_array($filename, self::WORKSPACE_FILES, true)) { continue; }
            self::filename($filename, $path);
            if ($filename === 'pxt.json' || !array_key_exists($filename, $files) || isset($seen[$filename])) {
                throw new InvalidArgumentException($path . ': ' . self::displayName($filename) . ' 파일이 중복되거나 존재하지 않습니다.');
            }
            $seen[$filename] = true;
            $result[] = $filename;
        }
        if (count($result) > self::MAX_FILES) { throw new InvalidArgumentException($path . ': 소스 파일 수가 너무 많습니다.'); }
        return $result;
    }
}
