#!/usr/bin/env python3
"""Exercise update decisions with synthetic PHP services and blocked OS actions."""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest
import zipfile


REPOSITORY = Path(__file__).resolve().parents[2]
UPDATER = REPOSITORY / "_release-kit/install/update-ubuntu24.sh"
INITIALIZER = REPOSITORY / "_release-kit/install/update-platform.php"

PHP_FIXTURE = r'''<?php
declare(strict_types=1);
namespace {
    $GLOBALS['update_trace'] = [];
    function update_trace(string $event): void { $GLOBALS['update_trace'][] = $event; }
    register_shutdown_function(static function (): void {
        echo "\nTRACE:" . json_encode($GLOBALS['update_trace']) . "\n";
    });
    if (getenv('CASE_NO_CLI') !== '1') { define('WP_CLI', true); }
    define('ABSPATH', __DIR__ . '/');
    define('OJ_PLATFORM_FILE', __FILE__);
    define('OJ_PLATFORM_VERSION', '2.5.2');
    define('OJ_PLATFORM_MIN_WP_VERSION', '6.6');
    define('OJ_PLATFORM_MIN_PHP_VERSION', '8.3');
    function is_plugin_active(string $file): bool {
        if ($file !== 'oj-platform/oj-platform.php') { throw new \RuntimeException('Unexpected plugin'); }
        return getenv('CASE_INACTIVE') !== '1';
    }
    final class WP_CLI {
        public static function success(string $message): void { echo 'SUCCESS:' . $message . "\n"; }
        public static function error(string $message): never {
            fwrite(STDERR, 'ERROR:' . $message . "\n"); exit(1);
        }
    }
    final class UpdateFixtureContainer {
        private ?\CodingPanda\OjPlatform\Kernel\Setup\SetupCoordinator $setup = null;
        public function get(string $id): object {
            return match ($id) {
                \CodingPanda\OjPlatform\Kernel\Boot\Kernel::class => new \CodingPanda\OjPlatform\Kernel\Boot\Kernel(),
                \CodingPanda\OjPlatform\Kernel\Setup\SetupCoordinator::class => $this->setup ??= new \CodingPanda\OjPlatform\Kernel\Setup\SetupCoordinator(),
                \CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRewriteManager::class => new \CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRewriteManager(),
                \CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobScheduler::class => new \CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobScheduler(),
                default => throw new \RuntimeException('Updater requested an unrelated service'),
            };
        }
    }
}
namespace CodingPanda\OjPlatform\Kernel\Environment {
    final class PluginEnvironment {
        public static function fromPluginFile(mixed ...$values): self { return new self(); }
    }
}
namespace CodingPanda\OjPlatform\Kernel\WordPress {
    final class WordPressHookRegistrar {}
    final class WordPressErrorReporter {}
}
namespace CodingPanda\OjPlatform\Kernel\Composition {
    final class CompositionRoot {
        public function __construct(mixed ...$values) {}
        public function build(): \UpdateFixtureContainer { return new \UpdateFixtureContainer(); }
    }
}
namespace CodingPanda\OjPlatform\Kernel\Boot {
    final class Kernel { public function boot(): void { \update_trace('kernel.boot'); } }
}
namespace CodingPanda\OjPlatform\Kernel\Setup {
    enum SetupMode: string {
        case FreshInstall = 'fresh_install'; case Resume = 'resume';
        case AlreadyInstalled = 'already_installed'; case MigrationTarget = 'migration_target';
        case NameCollision = 'name_collision';
    }
    final class UpdateFixtureSnapshot {
        public function __construct(private readonly SetupMode $mode) {}
        public function installationId(): ?string { return getenv('CASE_NO_ID') === '1' ? null : 'synthetic-existing-install'; }
        public function mode(): SetupMode { return $this->mode; }
        public function isRuntimeReady(): bool { return getenv('CASE_NOT_READY') !== '1'; }
        public function allowsRuntime(): bool { return $this->mode === SetupMode::AlreadyInstalled; }
    }
    final class SetupCoordinator {
        private bool $ran = false;
        public function inspect(): UpdateFixtureSnapshot {
            \update_trace('setup.inspect');
            $mode = SetupMode::from(getenv('CASE_MODE') ?: 'already_installed');
            if ($this->ran && getenv('CASE_INCOMPLETE') !== '1') { $mode = SetupMode::AlreadyInstalled; }
            return new UpdateFixtureSnapshot($mode);
        }
        public function run(): void {
            \update_trace('setup.run');
            if (getenv('CASE_THROW') === 'schema') { throw new \RuntimeException('SyntheticPrivatePassword9'); }
            $this->ran = true;
        }
    }
}
namespace CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress {
    final class WordPressRewriteManager {
        public function synchronizeNow(): void {
            \update_trace('routes.synchronize');
            if (getenv('CASE_THROW') === 'routes') { throw new \RuntimeException('SyntheticPrivatePassword9'); }
        }
    }
}
namespace CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress {
    final class WordPressJobScheduler {
        public function ensureScheduled(): void {
            \update_trace('jobs.ensure');
            if (getenv('CASE_THROW') === 'jobs') { throw new \RuntimeException('SyntheticPrivatePassword9'); }
        }
    }
}
namespace {
    $args = [$argv[2]];
    include $argv[1];
}
'''


class UbuntuUpdaterTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        git_bash = Path(r"C:\Program Files\Git\bin\bash.exe")
        cls.bash = os.environ.get("OJ_INSTALLER_TEST_BASH") or (str(git_bash) if git_bash.is_file() else shutil.which("bash"))
        windows_php = Path(r"D:\php\php.exe")
        cls.php = os.environ.get("PHP_BINARY") or (str(windows_php) if windows_php.is_file() else shutil.which("php"))
        cls.temporary_parent = REPOSITORY / "_notes/tmp"
        cls.temporary_parent.mkdir(parents=True, exist_ok=True)

    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="ubuntu-updater-test-", dir=self.temporary_parent)
        self.addCleanup(self.temporary.cleanup)
        self.work = Path(self.temporary.name)

    def shell(self, body: str, **values: str) -> subprocess.CompletedProcess[str]:
        if not self.bash:
            self.skipTest("Bash or Git Bash is required")
        environment = os.environ.copy()
        environment.update(TEST_UPDATER=UPDATER.as_posix(), TEST_DIRECTORY=self.work.as_posix(),
                           TEST_PYTHON=Path(os.sys.executable).as_posix(), TEST_PHP=self.php or "")
        environment.update(values)
        script = r'''
set -Eeuo pipefail
export PATH="/usr/bin:/bin:$PATH"
# Match the Linux CLI's line endings when the fixture uses native Windows Python.
python3() { "$TEST_PYTHON" "$@" | sed 's/\r$//'; }
forbidden() { printf 'FORBIDDEN UPDATE ACTION\n' >&2; exit 97; }
apt-get() { forbidden; }
systemctl() { forbidden; }
runuser() { forbidden; }
curl() { forbidden; }
sudo() { forbidden; }
rm() { forbidden; }
install() { forbidden; }
chmod() { forbidden; }
chown() { forbidden; }
tar() { forbidden; }
gzip() { forbidden; }
mariadb() { forbidden; }
mv() { forbidden; }
rsync() { forbidden; }
rmdir() { forbidden; }
source "$TEST_UPDATER"
PHP=$TEST_PHP
wp_root() { forbidden; }
''' + body
        return subprocess.run([self.bash, "--noprofile", "--norc", "-c", script], cwd=REPOSITORY,
                              env=environment, capture_output=True, text=True, encoding="utf-8",
                              errors="replace", check=False, timeout=20)

    def php_case(self, operation: str = "check", **values: str) -> tuple[subprocess.CompletedProcess[str], list[str]]:
        if not self.php:
            self.skipTest("PHP 8.3 is required")
        fixture = self.work / "update-services.php"
        fixture.write_text(PHP_FIXTURE, encoding="utf-8", newline="\n")
        environment = os.environ.copy()
        environment.update(values)
        result = subprocess.run([self.php, str(fixture), str(INITIALIZER), operation], cwd=REPOSITORY,
                                env=environment, capture_output=True, text=True, encoding="utf-8",
                                errors="replace", timeout=10, check=False)
        match = re.search(r"^TRACE:(\[.*\])$", result.stdout, re.MULTILINE)
        self.assertIsNotNone(match, "Synthetic PHP fixture did not finish")
        return result, json.loads(match.group(1))

    def assert_safe_failure(self, result: subprocess.CompletedProcess[str]) -> None:
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertNotIn("FORBIDDEN UPDATE ACTION", result.stderr)

    def test_source_is_inert_and_shell_syntax_is_valid(self) -> None:
        result = self.shell("printf 'SOURCE_ONLY_OK\\n'\n")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), "SOURCE_ONLY_OK")
        for path in (UPDATER, REPOSITORY / "update.sh"):
            with self.subTest(path=path.name):
                result = subprocess.run([self.bash, "-n", path.as_posix()], cwd=REPOSITORY,
                                        capture_output=True, text=True, encoding="utf-8", check=False, timeout=10)
                self.assertEqual(result.returncode, 0, result.stderr)

    def test_update_version_decisions_do_not_modify_an_equal_release(self) -> None:
        if not self.php:
            self.skipTest("PHP is required")
        for target, expected in (("2.5.1", "same"), ("2.5.2", "upgrade")):
            with self.subTest(target=target):
                result = self.shell('update_check_versions 2.5.1 "$CASE_TARGET" 8.3.9 8.3 6.8 6.6\n', CASE_TARGET=target)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout.strip(), expected)
        self.assert_safe_failure(self.shell("update_check_versions 2.5.2 2.5.1 8.3.9 8.3 6.8 6.6\n"))

    def test_requirements_are_checked_before_an_upgrade(self) -> None:
        if not self.php:
            self.skipTest("PHP is required")
        for arguments in ("2.5.1 2.5.2 8.2.9 8.3 6.8 6.6", "2.5.1 2.5.2 8.3.9 8.3 6.5 6.6"):
            with self.subTest(arguments=arguments):
                self.assert_safe_failure(self.shell("update_check_versions " + arguments + "\n"))

    def test_maintenance_removal_only_touches_the_updaters_own_file(self) -> None:
        web_root = self.work / "synthetic web"
        web_root.mkdir()
        maintenance = web_root / ".maintenance"
        maintenance.write_text("<?php /* another operation */ $upgrading = 1;\n", encoding="utf-8")
        result = self.shell(r'''
WEB_ROOT=$CASE_WEB_ROOT
update_remove_maintenance
''', CASE_WEB_ROOT=web_root.as_posix())
        self.assert_safe_failure(result)
        self.assertEqual(maintenance.read_text(encoding="utf-8"), "<?php /* another operation */ $upgrading = 1;\n")
        result = self.shell(r'''
WEB_ROOT=$CASE_WEB_ROOT
rm() {
    [[ ${@: -1} == "$WEB_ROOT/.maintenance" ]] || forbidden
    command rm "$@"
}
update_maintenance_text > "$WEB_ROOT/.maintenance"
update_owns_maintenance
update_remove_maintenance
[[ ! -e $WEB_ROOT/.maintenance ]]
''', CASE_WEB_ROOT=web_root.as_posix())
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(maintenance.exists())

    def test_only_mutating_phases_are_classified_for_preserved_recovery(self) -> None:
        result = self.shell(r'''
for U_PHASE in applying applied verified; do update_phase_is_applying; done
for U_PHASE in preparing prepared complete unknown; do
    if update_phase_is_applying; then exit 95; fi
done
''')
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_cleanup_preserves_recovery_after_changes_and_releases_preparation(self) -> None:
        for phase in ("preparing", "applying", "applied", "verified"):
            with self.subTest(phase=phase):
                web_root = self.work / (phase + " web")
                state = self.work / (phase + " state")
                backup = self.work / (phase + " backup")
                for directory in (web_root, state, backup):
                    directory.mkdir()
                (state / "pending.env").write_text("synthetic pending state\n", encoding="utf-8")
                (backup / "original-backup.txt").write_text("preserve original backup\n", encoding="utf-8")
                calls = self.work / (phase + " calls")
                result = self.shell(r'''
WEB_ROOT=$CASE_WEB_ROOT
U_STATE=$CASE_STATE
U_BACKUP=$CASE_BACKUP
U_PENDING_VALID=1
U_PHASE=$CASE_PHASE
U_TIMER_WAS_ACTIVE=1
U_HANDOFF=
systemctl() { printf '%s\n' "$*" >> "$CASE_CALLS"; }
rm() {
    local candidate
    for candidate in "$@"; do
        [[ $candidate == -f || $candidate == -- ]] && continue
        [[ $candidate == "$WEB_ROOT/.maintenance" || $candidate == "$U_STATE/pending.env" ]] || forbidden
    done
    command rm "$@"
}
if [[ $U_PHASE != verified ]]; then update_set_maintenance; fi
update_cleanup 28
''', CASE_WEB_ROOT=web_root.as_posix(), CASE_STATE=state.as_posix(), CASE_BACKUP=backup.as_posix(),
                    CASE_PHASE=phase, CASE_CALLS=calls.as_posix())
                self.assertEqual(result.returncode, 28, result.stderr)
                self.assertNotIn("FORBIDDEN UPDATE ACTION", result.stderr)
                self.assertEqual((backup / "original-backup.txt").read_text(encoding="utf-8"), "preserve original backup\n")
                expected_action = "start" if phase == "preparing" else "stop"
                self.assertEqual(calls.read_text(encoding="utf-8").splitlines(), [expected_action + " oj-wordpress-cron.timer"])
                self.assertEqual((state / "pending.env").exists(), phase != "preparing")
                self.assertEqual((web_root / ".maintenance").exists(), phase != "preparing")

    def test_equal_release_checks_the_site_without_backups_or_file_replacement(self) -> None:
        if not self.php:
            self.skipTest("PHP is required")
        web_root = self.work / "existing site"
        plugin = web_root / "wp-content/plugins/oj-platform"
        plugin.mkdir(parents=True)
        (web_root / "wp-config.php").write_text("<?php /* synthetic */\n", encoding="utf-8")
        state = self.work / "updater state"
        state.mkdir()
        wp_cli = self.work / "fake-wp.php"
        wp_cli.write_text("<?php\n", encoding="utf-8")
        package = self.work / "oj-platform.zip"
        with zipfile.ZipFile(package, "w") as bundle:
            bundle.writestr("oj-platform/oj-platform.php", "<?php\n/**\n * Plugin Name: OJ Platform\n * Version: 2.5.1\n * Requires PHP: 8.3\n * Requires at least: 6.6\n */\n")
        Path(str(package) + ".sha256").write_text(hashlib.sha256(package.read_bytes()).hexdigest() + "\n", encoding="utf-8")
        calls = self.work / "no-op-calls.txt"
        result = self.shell(r'''
WEB_ROOT=$(cd -- "$CASE_WEB_ROOT" && pwd -P)
U_PLUGIN_DIR=$WEB_ROOT/wp-content/plugins/oj-platform
U_STATE=$(cd -- "$CASE_STATE" && pwd -P)
U_REQUESTED_ZIP=$CASE_PACKAGE
WP=$CASE_WP_CLI
assert_web_root() { :; }
update_assert_private_directory() { [[ $1 == "$U_STATE" ]] || forbidden; }
realpath() {
    if [[ ${@: -1} == /var/lib ]]; then printf '/var/lib\n'; else command realpath "$@"; fi
}
update_prepare_handoff() { U_HANDOFF=$TEST_DIRECTORY/synthetic-handoff; printf 'handoff\n' >> "$CASE_CALLS"; }
wp_site() {
    case "$*" in
        '--skip-plugins --skip-themes core is-installed'|'--skip-plugins --skip-themes plugin is-active oj-platform') : ;;
        '--skip-plugins --skip-themes plugin get oj-platform --field=version') printf '2.5.1\n' ;;
        '--skip-plugins --skip-themes core version') printf '6.8\n' ;;
        '--skip-plugins --skip-themes option get siteurl') printf 'http://example.test\n' ;;
        'eval-file '*'/update-platform.php check --use-include') printf 'check\n' >> "$CASE_CALLS" ;;
        *) forbidden ;;
    esac
}
update_stages
''', CASE_WEB_ROOT=web_root.as_posix(), CASE_STATE=state.as_posix(), CASE_PACKAGE=package.as_posix(),
            CASE_WP_CLI=wp_cli.as_posix(), CASE_CALLS=calls.as_posix())
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("already up to date", result.stdout)
        self.assertEqual(calls.read_text(encoding="utf-8").splitlines(), ["handoff", "check"])
        self.assertEqual(list(state.iterdir()), [])
        self.assertFalse((web_root / ".maintenance").exists())

    def test_preparation_cleanup_keeps_pending_state_if_restoration_fails(self) -> None:
        for failure in ("timer", "foreign-maintenance"):
            with self.subTest(failure=failure):
                web_root = self.work / (failure + " web")
                state = self.work / (failure + " state")
                web_root.mkdir()
                state.mkdir()
                pending = state / "pending.env"
                pending.write_text("preserve this retry state\n", encoding="utf-8")
                result = self.shell(r'''
WEB_ROOT=$CASE_WEB_ROOT
U_STATE=$CASE_STATE
U_PENDING_VALID=1
U_PHASE=preparing
U_TIMER_WAS_ACTIVE=1
U_HANDOFF=
U_BACKUP=$TEST_DIRECTORY/synthetic-backup
systemctl() { [[ $* == 'start oj-wordpress-cron.timer' ]] || forbidden; return 23; }
rm() {
    [[ ${@: -1} == "$WEB_ROOT/.maintenance" || ${@: -1} == "$U_STATE/pending.env" ]] || forbidden
    command rm "$@"
}
if [[ $CASE_FAILURE == timer ]]; then update_set_maintenance;
else printf '<?php /* another operation */\n' > "$WEB_ROOT/.maintenance"; fi
update_cleanup 28
''', CASE_WEB_ROOT=web_root.as_posix(), CASE_STATE=state.as_posix(), CASE_FAILURE=failure)
                self.assertEqual(result.returncode, 28, result.stderr)
                self.assertNotIn("FORBIDDEN UPDATE ACTION", result.stderr)
                self.assertEqual(pending.read_text(encoding="utf-8"), "preserve this retry state\n")
                self.assertIn("pending update retained", result.stderr)
                if failure == "foreign-maintenance":
                    self.assertEqual((web_root / ".maintenance").read_text(encoding="utf-8"), "<?php /* another operation */\n")

    def test_failed_pending_state_publication_does_not_replace_previous_state(self) -> None:
        state = self.work / "pending state"
        state.mkdir()
        pending = state / "pending.env"
        pending.write_text("PREVIOUS_SAFE_PENDING\n", encoding="utf-8")
        moved = self.work / "attempted-move.txt"
        result = self.shell(r'''
U_STATE=$CASE_STATE
U_CACHED_ZIP=$U_STATE/oj-platform.zip
U_SHA=$CASE_SHA
U_BACKUP=$TEST_DIRECTORY/synthetic-backup
U_OLD_VERSION=2.5.1
U_NEW_VERSION=2.5.2
U_TIMER_WAS_ACTIVE=1
U_PHASE=applying
U_SITE_URL=http://example.test
U_PENDING_VALID=0
chmod() { :; }
mv() { printf 'publication attempted\n' > "$CASE_MOVED"; return 23; }
rm() {
    local candidate
    for candidate in "$@"; do
        [[ $candidate == -f || $candidate == -- ]] && continue
        [[ $candidate == "$U_STATE/.pending."* ]] || forbidden
    done
    command rm "$@"
}
if update_save_pending; then exit 95; fi
[[ $U_PENDING_VALID == 0 ]]
printf 'SAVE_FAILURE_OBSERVED\n'
''', CASE_STATE=state.as_posix(), CASE_SHA="a" * 64, CASE_MOVED=moved.as_posix())
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), "SAVE_FAILURE_OBSERVED")
        self.assertTrue(moved.exists())
        self.assertEqual(pending.read_text(encoding="utf-8"), "PREVIOUS_SAFE_PENDING\n")
        self.assertEqual(list(state.iterdir()), [pending])

    def test_update_stages_do_not_inherit_the_installation_lock(self) -> None:
        source = UPDATER.read_text(encoding="utf-8")
        call = re.search(r"^\s*(update_stages 9>&-)\s*$", source, re.MULTILINE)
        self.assertIsNotNone(call, "Update stages must close the inherited installation lock")
        result = self.shell(r'''
exec 9>"$TEST_DIRECTORY/owner-lock.txt"
update_stages() {
    if "$BASH" --noprofile --norc -c '(: <&9) 2>/dev/null'; then exit 96; fi
}
''' + call.group(1) + r'''
printf 'owner-lock-still-open\n' >&9
exec 9>&-
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((self.work / "owner-lock.txt").read_text(encoding="utf-8"), "owner-lock-still-open\n")

    def test_timer_stop_failure_prevents_waiting_or_continuing(self) -> None:
        calls = self.work / "timer-calls.txt"
        result = self.shell(r'''
systemctl() {
    printf '%s\n' "$*" >> "$CASE_CALLS"
    case "$*" in
        'show --property=LoadState --value oj-wordpress-cron.timer') printf 'loaded\n' ;;
        'stop oj-wordpress-cron.timer') return 23 ;;
        *) forbidden ;;
    esac
}
sleep() { forbidden; }
update_stop_timer
''', CASE_CALLS=calls.as_posix())
        self.assertEqual(result.returncode, 23, result.stderr)
        self.assertEqual(calls.read_text(encoding="utf-8").splitlines(), [
            "show --property=LoadState --value oj-wordpress-cron.timer", "stop oj-wordpress-cron.timer",
        ])

    def test_existing_scheduled_work_is_waited_for_without_killing_it(self) -> None:
        for always_running in (False, True):
            with self.subTest(always_running=always_running):
                calls = self.work / ("running-timer-calls.txt" if always_running else "finishing-timer-calls.txt")
                queried = self.work / ("running-query.txt" if always_running else "finishing-query.txt")
                result = self.shell(r'''
systemctl() {
    printf '%s\n' "$*" >> "$CASE_CALLS"
    case "$*" in
        'show --property=LoadState --value '*) printf 'loaded\n' ;;
        'stop oj-wordpress-cron.timer') : ;;
        'show --property=ActiveState --value oj-wordpress-cron.service')
            if [[ $CASE_ALWAYS_RUNNING == 1 || ! -e $CASE_QUERIED ]]; then
                : > "$CASE_QUERIED"
                printf 'activating\n'
            else printf 'inactive\n'; fi ;;
        *) forbidden ;;
    esac
}
sleep() { printf 'wait\n' >> "$CASE_CALLS"; }
update_stop_timer
''', CASE_CALLS=calls.as_posix(), CASE_QUERIED=queried.as_posix(), CASE_ALWAYS_RUNNING="1" if always_running else "0")
                self.assertEqual(result.returncode, 1 if always_running else 0, result.stderr)
                lines = calls.read_text(encoding="utf-8").splitlines()
                self.assertEqual(lines.count("wait"), 60 if always_running else 1)
                self.assertNotIn("stop oj-wordpress-cron.service", lines)
                self.assertNotIn("FORBIDDEN UPDATE ACTION", result.stderr)

    def test_interrupted_replacement_can_restore_missing_or_inactive_plugin(self) -> None:
        if not self.php:
            self.skipTest("PHP is required")
        cases = [(phase, presence, True) for phase in ("applying", "applied", "verified") for presence in ("missing", "inactive")]
        cases += [("preparing", "missing", False), ("new", "missing", False), ("new", "inactive", False)]
        for index, (phase, presence, should_install) in enumerate(cases):
            with self.subTest(phase=phase, presence=presence):
                fixture = self.work / str(index)
                web_root = fixture / "existing site"
                plugins = web_root / "wp-content/plugins"
                plugins.mkdir(parents=True)
                if presence != "missing":
                    (plugins / "oj-platform").mkdir()
                (web_root / "wp-config.php").write_text("<?php /* synthetic */\n", encoding="utf-8")
                state = fixture / "updater state"
                state.mkdir()
                if phase != "new":
                    (state / "pending.env").write_text("synthetic pending state\n", encoding="utf-8")
                wp_cli = fixture / "fake-wp.php"
                wp_cli.write_text("<?php\n", encoding="utf-8")
                package = state / "oj-platform.zip"
                with zipfile.ZipFile(package, "w") as bundle:
                    bundle.writestr("oj-platform/oj-platform.php", "<?php\n/**\n * Plugin Name: OJ Platform\n * Version: 2.5.2\n * Requires PHP: 8.3\n * Requires at least: 6.6\n */\n")
                package_sha = hashlib.sha256(package.read_bytes()).hexdigest()
                Path(str(package) + ".sha256").write_text(package_sha + "\n", encoding="utf-8")
                installed = fixture / "replacement-reached.txt"
                result = self.shell(r'''
WEB_ROOT=$(cd -- "$CASE_WEB_ROOT" && pwd -P)
U_PLUGIN_DIR=$WEB_ROOT/wp-content/plugins/oj-platform
U_STATE=$(cd -- "$CASE_STATE" && pwd -P)
U_REQUESTED_ZIP=
WP=$CASE_WP_CLI
assert_web_root() { :; }
update_assert_private_directory() { [[ $1 == "$U_STATE" ]] || forbidden; }
realpath() {
    if [[ ${@: -1} == /var/lib ]]; then printf '/var/lib\n'; else command realpath "$@"; fi
}
update_load_pending() {
    U_PHASE=$CASE_PHASE
    U_PENDING_VALID=1
    U_CACHED_ZIP=$U_STATE/oj-platform.zip
    U_SHA=$CASE_SHA
    U_OLD_VERSION=2.5.1
    U_NEW_VERSION=2.5.2
    U_TIMER_WAS_ACTIVE=1
    U_BACKUP=$TEST_DIRECTORY/synthetic-backup
    U_SITE_URL=http://example.test
}
update_prepare_handoff() { U_HANDOFF=$TEST_DIRECTORY/synthetic-handoff; }
update_set_maintenance() { :; }
update_stop_timer() { :; }
update_save_pending() { :; }
update_back_up() { forbidden; }
wp_site() {
    case "$*" in
        '--skip-plugins --skip-themes core is-installed') : ;;
        '--skip-plugins --skip-themes plugin is-active oj-platform') return 1 ;;
        '--skip-plugins --skip-themes plugin get oj-platform --field=version')
            [[ $CASE_PRESENCE != missing ]] || return 1
            printf '2.5.2\n' ;;
        '--skip-plugins --skip-themes core version') printf '6.8\n' ;;
        '--skip-plugins --skip-themes plugin install '*)
            printf 'CACHED_RELEASE_REPLACEMENT\n' > "$CASE_INSTALLED"
            exit 0 ;;
        *) forbidden ;;
    esac
}
update_stages
''', CASE_WEB_ROOT=web_root.as_posix(), CASE_STATE=state.as_posix(), CASE_WP_CLI=wp_cli.as_posix(),
                    CASE_PHASE=phase, CASE_PRESENCE=presence, CASE_SHA=package_sha, CASE_INSTALLED=installed.as_posix())
                self.assertEqual(result.returncode, 0 if should_install else 1, result.stderr)
                self.assertEqual(installed.exists(), should_install)
                self.assertNotIn("FORBIDDEN UPDATE ACTION", result.stderr)

    def test_check_only_never_applies_schema_or_changes_runtime(self) -> None:
        for mode in ("already_installed", "resume"):
            with self.subTest(mode=mode):
                result, trace = self.php_case("check", CASE_MODE=mode)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(trace, ["kernel.boot", "setup.inspect"])

    def test_apply_runs_only_pending_schema_and_then_updates_routes_and_jobs(self) -> None:
        for mode, middle in (("already_installed", []), ("resume", ["setup.run"])):
            with self.subTest(mode=mode):
                result, trace = self.php_case("apply", CASE_MODE=mode)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(trace, ["kernel.boot", "setup.inspect", *middle, "setup.inspect", "routes.synchronize", "jobs.ensure"])

    def test_initializer_rejects_fresh_migration_collision_and_unready_sites(self) -> None:
        cases = [{"CASE_MODE": mode} for mode in ("fresh_install", "migration_target", "name_collision")]
        cases.extend([{"CASE_NO_ID": "1"}, {"CASE_NOT_READY": "1"}])
        for values in cases:
            with self.subTest(values=values):
                result, trace = self.php_case("apply", **values)
                self.assert_safe_failure(result)
                self.assertIn("OJ_PLATFORM_UPDATE_FAILED_INSPECTION", result.stderr)
                self.assertEqual(trace, ["kernel.boot", "setup.inspect"])

    def test_initializer_rechecks_schema_before_routes_and_masks_exceptions(self) -> None:
        result, trace = self.php_case("apply", CASE_MODE="resume", CASE_INCOMPLETE="1")
        self.assert_safe_failure(result)
        self.assertIn("OJ_PLATFORM_UPDATE_FAILED_SCHEMA", result.stderr)
        self.assertEqual(trace, ["kernel.boot", "setup.inspect", "setup.run", "setup.inspect"])
        for stage in ("schema", "routes", "jobs"):
            with self.subTest(stage=stage):
                result, _trace = self.php_case("apply", CASE_MODE="resume", CASE_THROW=stage)
                self.assert_safe_failure(result)
                self.assertIn("OJ_PLATFORM_UPDATE_FAILED_" + stage.upper(), result.stderr)
                self.assertNotIn("SyntheticPrivatePassword9", result.stdout + result.stderr)

    def test_initializer_requires_wp_cli_active_platform_and_known_operation(self) -> None:
        for operation, values, expected in (
            ("check", {"CASE_NO_CLI": "1"}, "OJ_PLATFORM_UPDATE_REQUIRES_WP_CLI"),
            ("check", {"CASE_INACTIVE": "1"}, "OJ_PLATFORM_UPDATE_FAILED_PLATFORM"),
            ("unknown", {}, "OJ_PLATFORM_UPDATE_FAILED_INPUT"),
        ):
            with self.subTest(operation=operation, values=values):
                result, trace = self.php_case(operation, **values)
                self.assert_safe_failure(result)
                self.assertIn(expected, result.stderr)
                self.assertEqual(trace, [])


if __name__ == "__main__":
    unittest.main(verbosity=2)
