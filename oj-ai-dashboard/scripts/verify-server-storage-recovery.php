<?php
/** Private recovery audit. Never loads WordPress or executes wp-config.php. */
declare(strict_types=1);

function audit_check(bool $condition, string $code): void
{
    if (!$condition) {
        throw new RuntimeException($code);
    }
}

function audit_literal(string $token): string
{
    $quote = $token[0] ?? '';
    audit_check(in_array($quote, ["'", '"'], true) && str_ends_with($token, $quote), 'config_literal_invalid');
    $value = substr($token, 1, -1);
    audit_check(!str_contains($value, '\\') && !str_contains($value, '$'), 'config_dynamic_path_requires_review');
    return $value;
}

function audit_config_path(string $source): ?string
{
    $tokens = array_values(array_filter(token_get_all($source), static fn($t): bool =>
        !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $values = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'define') {
            continue;
        }
        $name = $tokens[$index + 2] ?? null;
        if (($tokens[$index + 1] ?? null) !== '(' || !is_array($name) || $name[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        if (!in_array($name[1], ["'OJ_AI_PRIVATE_STORAGE_DIR'", '"OJ_AI_PRIVATE_STORAGE_DIR"'], true)) {
            continue;
        }
        $value = $tokens[$index + 4] ?? null;
        audit_check(($tokens[$index + 3] ?? null) === ',' && is_array($value)
            && $value[0] === T_CONSTANT_ENCAPSED_STRING && ($tokens[$index + 5] ?? null) === ')',
            'config_dynamic_path_requires_review');
        $values[] = audit_literal($value[1]);
    }
    audit_check(count($values) <= 1, 'config_multiple_storage_paths');
    return $values[0] ?? null;
}

function audit_private_root(string $root, string $site): string
{
    $real = realpath($root);
    $web = realpath($site);
    audit_check($real !== false && $web !== false && is_dir($real) && !is_link($root), 'storage_root_unavailable');
    $real = str_replace('\\', '/', $real);
    $web = str_replace('\\', '/', $web);
    audit_check($real !== $web && !str_starts_with($real . '/', $web . '/'), 'storage_root_inside_wordpress');
    audit_check(!str_starts_with($web . '/', $real . '/'), 'storage_root_contains_wordpress');
    return $real;
}

function audit_file_path(string $root, string $key): string
{
    audit_check(strlen($key) <= 512 && preg_match('~^[a-z0-9][a-z0-9._/-]*$~D', $key) === 1, 'storage_key_invalid');
    $path = $root;
    foreach (explode('/', $key) as $part) {
        audit_check(!in_array($part, ['', '.', '..'], true), 'storage_key_traversal');
        $path .= '/' . $part;
        audit_check(!is_link($path), 'storage_key_symlink');
    }
    $real = realpath($path);
    audit_check($real !== false, 'storage_file_missing');
    $real = str_replace('\\', '/', $real);
    audit_check(str_starts_with($real, $root . '/') && is_file($real), 'storage_file_missing');
    return $real;
}

function audit_copy(string $source, string $destination, int $bytes, string $hash): void
{
    audit_check(preg_match('/^[a-f0-9]{64}$/D', $hash) === 1 && $bytes >= 0, 'file_metadata_invalid');
    $input = fopen($source, 'rb');
    audit_check(is_resource($input), 'file_open_failed');
    $output = null;
    try {
        audit_check(flock($input, LOCK_SH), 'file_lock_failed');
        $stat = fstat($input);
        audit_check(is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && $stat['size'] === $bytes, 'file_size_mismatch');
        $output = fopen($destination, 'xb');
        audit_check(is_resource($output), 'backup_exclusive_create_failed');
        audit_check(stream_copy_to_stream($input, $output) === $bytes && fflush($output), 'backup_copy_failed');
        audit_check(hash_equals($hash, (string) hash_file('sha256', $destination)), 'file_hash_mismatch');
    } finally {
        if (is_resource($output)) {
            fclose($output);
        }
        flock($input, LOCK_UN);
        fclose($input);
    }
}

function audit_artifact(string $path, string $mime): string
{
    if ($mime === 'application/pdf') {
        $handle = fopen($path, 'rb');
        audit_check(is_resource($handle), 'pdf_open_failed');
        try {
            audit_check(fread($handle, 5) === '%PDF-', 'pdf_header_invalid');
            audit_check(fseek($handle, max(0, filesize($path) - 1024)) === 0, 'pdf_seek_failed');
            audit_check(str_contains((string) stream_get_contents($handle), '%%EOF'), 'pdf_truncated');
        } finally {
            fclose($handle);
        }
        return 'pdf_header_and_eof_only';
    }
    if (in_array($mime, ['application/zip', 'application/vnd.oj.ai-worksheet+zip'], true)) {
        $zip = new ZipArchive();
        audit_check($zip->open($path, ZipArchive::CHECKCONS) === true, 'zip_consistency_failed');
        try {
            audit_check($zip->numFiles > 0, 'zip_empty');
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entry = $zip->statIndex($i);
                audit_check(is_array($entry), 'zip_entry_invalid');
                $name = $entry['name'];
                audit_check(!str_starts_with($name, '/') && !str_contains($name, '\\')
                    && !str_contains($name, ':') && !in_array('..', explode('/', $name), true), 'zip_entry_traversal');
            }
        } finally {
            $zip->close();
        }
        return 'zip_directory_consistency_only';
    }
    if ($mime === 'text/csv') {
        audit_check(filesize($path) > 0, 'csv_empty');
        return 'csv_bytes_only';
    }
    return 'hash_only';
}

function audit_run(array $args): void
{
    audit_check(count($args) === 4 && PHP_OS_FAMILY === 'Linux', 'audit_arguments_invalid');
    [, $site, $database, $work] = $args;
    audit_check(preg_match('/^ojai_storage_recovery_[a-zA-Z0-9_]+$/D', $database) === 1, 'audit_database_invalid');
    audit_check(realpath($work) === $work && str_starts_with($work, '/root/ojai-storage-recovery-')
        && !is_link($work) && (fileperms($work) & 0777) === 0700, 'audit_directory_invalid');
    $config = file_get_contents($site . '/wp-config.php');
    audit_check(is_string($config), 'config_unavailable');
    $configured = audit_config_path($config);
    // A missing/dynamic config is not guessed from the root SSH user's HOME.
    audit_check(is_string($configured) && str_starts_with($configured, '/'), 'explicit_storage_path_required');
    $root = audit_private_root($configured, $site);
    $db = new mysqli('localhost', 'root', '', $database);
    $result = $db->query("SELECT public_id, storage_key, byte_size, sha256, mime_type FROM oj_ai_private_files WHERE state='ready' AND purged_at_utc IS NULL ORDER BY id");
    audit_check($result instanceof mysqli_result, 'metadata_query_failed');
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    audit_check(count($rows) > 0, 'no_ready_files_to_verify');
    $required = array_sum(array_map(static fn(array $row): int => (int) $row['byte_size'], $rows));
    audit_check($required >= 0 && disk_free_space($work) > $required * 2 + 1073741824, 'backup_disk_headroom_insufficient');
    audit_check(mkdir($work . '/file-backup', 0700) && mkdir($work . '/file-restore', 0700), 'backup_directory_failed');
    $summary = ['ready_files' => count($rows), 'restored_files' => 0, 'restored_bytes' => 0, 'artifact_checks' => [], 'failures' => []];
    foreach ($rows as $row) {
        $id = $row['public_id'];
        try {
            audit_check(preg_match('/^[A-Z0-9]{26}$/D', $id) === 1, 'file_id_invalid');
            $source = audit_file_path($root, $row['storage_key']);
            $bytes = (int) $row['byte_size'];
            $backup = $work . '/file-backup/' . $id;
            $restored = $work . '/file-restore/' . $id;
            audit_copy($source, $backup, $bytes, $row['sha256']);
            audit_copy($backup, $restored, $bytes, $row['sha256']);
            $check = audit_artifact($restored, $row['mime_type']);
            $summary['artifact_checks'][$check] = ($summary['artifact_checks'][$check] ?? 0) + 1;
            ++$summary['restored_files'];
            $summary['restored_bytes'] += $bytes;
        } catch (Throwable $error) {
            $code = $error instanceof RuntimeException && preg_match('/^[a-z_]+$/D', $error->getMessage()) === 1
                ? $error->getMessage() : 'file_audit_failed';
            $summary['failures'][] = ['file_id' => $id, 'code' => $code];
        }
    }
    $summary['file_restore_passed'] = $summary['failures'] === [] && $summary['restored_files'] === $summary['ready_files'];
    $summary['production_approved'] = false;
    $summary['excluded'] = ['non_ready_files', 'web_alias_exposure', 'browser_download_authorization', 'artifact_visual_and_semantic_review', 'runtime_upgrade_rollback'];
    $json = json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    audit_check(file_put_contents($work . '/summary.json', $json, LOCK_EX) !== false, 'summary_write_failed');
    echo $json, PHP_EOL;
    audit_check($summary['file_restore_passed'], 'private_file_recovery_failed');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0');
    try {
        audit_run($argv);
    } catch (Throwable $error) {
        $code = $error instanceof RuntimeException && preg_match('/^[a-z_]+$/D', $error->getMessage()) === 1
            ? $error->getMessage() : 'audit_failed_details_retained_privately';
        fwrite(STDERR, 'FAIL: ' . $code . PHP_EOL);
        exit(1);
    }
}
