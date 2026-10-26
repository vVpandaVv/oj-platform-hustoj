#!/usr/bin/env bash
set -euo pipefail
site=${1:?WordPress root is required}
work=${2:?Existing evidence directory is required}
metadata_hash=${3:?Expected metadata hash is required}
summary_hash=${4:?Expected summary hash is required}
[[ "$site" == /home/judge/src/web && $(id -u) == 0 ]] || exit 64
[[ "$work" =~ ^/root/ojai-storage-recovery-[a-zA-Z0-9-]+$ && ! -L "$work" && -d "$work" ]] || exit 65
[[ $(realpath -- "$work") == "$work" && $(stat -c %a -- "$work") == 700 ]] || exit 66
[[ "$metadata_hash" =~ ^[a-f0-9]{64}$ && "$summary_hash" =~ ^[a-f0-9]{64}$ ]] || exit 67
[[ $(sha256sum "$work/private-file-metadata.sql" | cut -d' ' -f1) == "$metadata_hash" ]] || exit 68
[[ $(sha256sum "$work/summary.json" | cut -d' ' -f1) == "$summary_hash" ]] || exit 69
cmp --silent "$work/private-file-metadata.sql" "$work/restored-metadata.sql"
for directory in file-backup file-restore; do
  [[ -d "$work/$directory" && ! -L "$work/$directory" && $(stat -c %a "$work/$directory") == 700 ]] || exit 70
  [[ -z $(find "$work/$directory" -mindepth 1 ! -type f -print -quit) ]] || exit 71
  [[ -z $(find "$work/$directory" -type f ! -perm 600 -print -quit) ]] || exit 72
done
manifest() { (cd "$1" && find . -type f -print0 | sort -z | xargs -0 sha256sum); }
cmp --silent <(manifest "$work/file-backup") <(manifest "$work/file-restore")
php -d display_errors=0 -r '
$work = $argv[1];
$summary = json_decode(file_get_contents($work . "/summary.json"), true, 512, JSON_THROW_ON_ERROR);
$backup = glob($work . "/file-backup/*");
$restored = glob($work . "/file-restore/*");
$bytes = array_sum(array_map("filesize", $restored));
if (($summary["file_restore_passed"] ?? false) !== true || ($summary["failures"] ?? null) !== []
    || count($backup) !== $summary["ready_files"] || count($restored) !== $summary["restored_files"]
    || $bytes !== $summary["restored_bytes"]) { exit(73); }
echo "PASS: existing storage evidence reverified; ", count($restored), " files, ", $bytes, " bytes; directory 0700, files 0600.\n";
echo "Scope: ready file recovery only. No new backup, live database query, or source-file change.\n";
' "$work"
echo 'PASS: authenticated evidence verification completed.'
