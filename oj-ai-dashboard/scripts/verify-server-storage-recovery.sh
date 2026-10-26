#!/usr/bin/env bash
set -euo pipefail
umask 077

site=${1:?WordPress root is required}
[[ "$site" == /home/judge/src/web && $(id -u) == 0 ]] || exit 64
[[ -f "$site/wp-config.php" && ! -L "$site" && -n ${OJAI_AUDIT_PHP_BASE64:-} ]] || exit 65
mysql=(mysql --protocol=socket --connect-timeout=10 --batch --skip-column-names)
source_db=$("${mysql[@]}" -e "SELECT table_schema FROM information_schema.tables WHERE table_name='oj_ai_private_files' AND table_schema NOT LIKE 'ojai_recovery_%' AND table_schema NOT LIKE 'ojai_storage_recovery_%'")
[[ "$source_db" =~ ^[a-zA-Z0-9_]+$ ]] || { echo 'FAIL: source_database_ambiguous'; exit 66; }
unsafe=$("${mysql[@]}" -e "SELECT
 (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$source_db' AND table_name='oj_ai_private_files' AND (table_type<>'BASE TABLE' OR engine<>'InnoDB')) +
 (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$source_db' AND event_object_table='oj_ai_private_files') +
 (SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema='$source_db' AND table_name='oj_ai_private_files' AND referenced_table_name IS NOT NULL)")
[[ "$unsafe" == 0 ]] || { echo 'FAIL: unsupported_storage_database_objects'; exit 67; }
stamp=$(date -u +%Y%m%dT%H%M%SZ)
work=$(mktemp -d "/root/ojai-storage-recovery-${stamp}-XXXXXX")
restore_db="ojai_storage_recovery_${stamp}_${work##*-}"
[[ "$restore_db" =~ ^ojai_storage_recovery_[a-zA-Z0-9_]+$ && "$restore_db" != "$source_db" ]] || exit 68
created=0
cleanup() {
  if [[ "$created" == 1 ]]; then
    "${mysql[@]}" -e "DROP DATABASE \`$restore_db\`" || { echo 'FAIL: isolated_database_cleanup_failed'; exit 1; }
    echo 'Temporary storage restore database removed.'
  fi
}
trap cleanup EXIT
printf 'UTC: '; date -u '+%Y-%m-%d %H:%M:%S'
printf 'Private evidence directory: %s\n' "$work"
printf '%s' "$OJAI_AUDIT_PHP_BASE64" | base64 -d > "$work/verify.php"
unset OJAI_AUDIT_PHP_BASE64
php -l "$work/verify.php"
dump_args=(--protocol=socket --single-transaction --skip-lock-tables --skip-add-locks --skip-comments --skip-dump-date --order-by-primary --hex-blob --skip-triggers --routines=false --events=false)
mysqldump "${dump_args[@]}" "$source_db" oj_ai_private_files > "$work/private-file-metadata.sql"
test -s "$work/private-file-metadata.sql"
"${mysql[@]}" -e "CREATE DATABASE \`$restore_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
created=1
"${mysql[@]}" "$restore_db" < "$work/private-file-metadata.sql" > "$work/import.log" 2>&1
mysqldump "${dump_args[@]}" "$restore_db" oj_ai_private_files > "$work/restored-metadata.sql"
cmp --silent "$work/private-file-metadata.sql" "$work/restored-metadata.sql" || { echo 'FAIL: metadata_restore_mismatch'; exit 69; }
echo 'Private file metadata table restore: canonical bytes identical.'
php -d display_errors=0 -d log_errors=1 -d "error_log=$work/php-errors.log" "$work/verify.php" "$site" "$restore_db" "$work"
sha256sum "$work/private-file-metadata.sql" "$work/summary.json"
echo 'Storage recovery audit finished. Live database and original files were not modified.'
