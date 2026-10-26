#!/usr/bin/env bash
set -euo pipefail
umask 077

site=${1:?WordPress root is required}
[[ "$site" == /home/judge/src/web && $(id -u) == 0 ]] || exit 64
plugin="$site/wp-content/plugins/oj-ai-dashboard"
[[ -f "$plugin/oj-ai-dashboard.php" && ! -L "$plugin" ]] || exit 65
mysql=(mysql --protocol=socket --connect-timeout=10 --batch --skip-column-names)
source_db=$("${mysql[@]}" -e "SELECT table_schema FROM information_schema.tables WHERE table_name='oj_ai_worksheets' AND table_schema NOT LIKE 'ojai_recovery_%'")
[[ "$source_db" =~ ^[a-zA-Z0-9_]+$ ]] || { echo 'Expected exactly one source database.'; exit 66; }
stamp=$(date -u +%Y%m%dT%H%M%SZ)
work=$(mktemp -d "/root/ojai-recovery-${stamp}-XXXXXX")
suffix=${work##*-}
restore_db="ojai_recovery_${stamp}_${suffix}"
[[ "$restore_db" =~ ^ojai_recovery_[a-zA-Z0-9_]+$ && "$restore_db" != "$source_db" ]] || exit 67
created=0
cleanup() {
  if [[ "$created" == 1 ]]; then
    "${mysql[@]}" -e "DROP DATABASE \`$restore_db\`" && echo 'Temporary restore database removed.'
  fi
}
trap cleanup EXIT
printf 'UTC: '; date -u '+%Y-%m-%d %H:%M:%S'
printf 'Private recovery directory: %s\n' "$work"
printf 'Source database: %s\n' "$source_db"

# Only add-on tables are restored. Other plugins and platform tables are excluded.
mapfile -t owned_tables < <("${mysql[@]}" -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$source_db' AND LEFT(table_name,6)='oj_ai_' ORDER BY table_name")
[[ ${#owned_tables[@]} -gt 0 ]] || exit 69
for table in "${owned_tables[@]}"; do [[ "$table" =~ ^oj_ai_[a-z0-9_]+$ ]] || exit 69; done
unsafe=$("${mysql[@]}" -e "SELECT
 (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$source_db' AND LEFT(table_name,6)='oj_ai_' AND (table_type<>'BASE TABLE' OR engine<>'InnoDB')) +
 (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$source_db' AND LEFT(event_object_table,6)='oj_ai_') +
 (SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema='$source_db' AND LEFT(table_name,6)='oj_ai_' AND referenced_table_name IS NOT NULL AND (referenced_table_schema<>'$source_db' OR LEFT(referenced_table_name,6)<>'oj_ai_'))")
printf 'Add-on isolation preflight: %s unsupported objects.\n' "$unsafe"
[[ "$unsafe" == 0 ]] || { echo 'Restore isolation preflight needs review; nothing restored.'; exit 68; }
tables=${#owned_tables[@]}
free=$(df -Pk /root | awk 'NR==2 {print $4}')
[[ "$free" -gt 4194304 ]] || { echo 'At least 4 GiB of free space is required.'; exit 70; }

dump_args=(--protocol=socket --single-transaction --skip-lock-tables --skip-add-locks --skip-comments --skip-dump-date --order-by-primary --hex-blob --skip-triggers --routines=false --events=false)
mysqldump "${dump_args[@]}" "$source_db" "${owned_tables[@]}" > "$work/database.sql"
test -s "$work/database.sql"
sha256sum "$work/database.sql"
"${mysql[@]}" -e "CREATE DATABASE \`$restore_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
created=1
"${mysql[@]}" "$restore_db" < "$work/database.sql" > "$work/import.log" 2>&1
restored_tables=$("${mysql[@]}" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$restore_db'")
[[ "$restored_tables" == "$tables" ]] || exit 71
mysqlcheck --protocol=socket --check "$restore_db" > "$work/database-check.log" 2>&1
mysqldump "${dump_args[@]}" "$restore_db" "${owned_tables[@]}" > "$work/restored.sql"
cmp --silent "$work/database.sql" "$work/restored.sql" || { echo 'Restored canonical database dump differs.'; exit 72; }
printf 'Database restore PASS: %s tables; all canonical schema/data bytes identical.\n' "$tables"

tar -czf "$work/plugin-before-update.tar.gz" -C "$site/wp-content/plugins" oj-ai-dashboard
mkdir "$work/plugin-restore"
tar -xzf "$work/plugin-before-update.tar.gz" -C "$work/plugin-restore"
manifest() { (cd "$1" && find . -type f -print0 | sort -z | xargs -0 sha256sum); }
manifest "$plugin" > "$work/plugin-source.sha256"
manifest "$work/plugin-restore/oj-ai-dashboard" > "$work/plugin-restored.sha256"
cmp --silent "$work/plugin-source.sha256" "$work/plugin-restored.sha256" || exit 73
printf 'Plugin archive restore PASS: %s files; SHA256 contents identical.\n' "$(wc -l < "$work/plugin-source.sha256")"
grep -m 1 'Version:' "$plugin/oj-ai-dashboard.php"
sha256sum "$work/plugin-before-update.tar.gz"
stat -c '%a %U:%G %n' "$work" "$work/database.sql" "$work/plugin-before-update.tar.gz"
printf 'PASS. Live database and plugin files were not changed. Private backups retained.\n'
