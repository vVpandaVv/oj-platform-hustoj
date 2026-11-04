"""Offline wrapper regression; external installer/systemd calls are fixtures."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import shutil
import stat
import tempfile
import types
import unittest
from unittest.mock import patch

PLUGIN = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('microbit_install_cycle', PLUGIN / 'scripts/acceptance/install-cycle.py')
cycle = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(cycle)


class AcceptanceCycleTests(unittest.TestCase):
    def setUp(self):
        self.temporary = PLUGIN.parent / '_notes' / 'tmp'
        self.temporary.mkdir(parents=True, exist_ok=True)
        self.root = Path(tempfile.mkdtemp(prefix='microbit-cycle-', dir=self.temporary))
        self.addCleanup(self.cleanup)
        self.state = {'ActiveState': 'active', 'UnitFileState': 'enabled', 'LoadState': 'loaded',
                      'WorkingDirectory': '/opt/oj-microbit/0.5.1/runner'}

    def cleanup(self):
        self.assertFalse(self.root.is_symlink())
        self.assertEqual(self.root.resolve().parent, self.temporary.resolve())
        self.assertTrue(self.root.name.startswith('microbit-cycle-'))
        shutil.rmtree(self.root)

    def test_plan_never_executes_an_installer_or_reads_target(self):
        output = io.StringIO()
        with patch.object(cycle, 'run_command', side_effect=AssertionError('unexpected execution')), contextlib.redirect_stdout(output):
            self.assertEqual(cycle.main(['plan']), 0)
        self.assertEqual(json.loads(output.getvalue())['verdict'], 'plan_only')
        self.assertFalse(json.loads(output.getvalue())['mutates'])

    def test_explicit_action_requires_the_trial_flag_before_any_command(self):
        output = io.StringIO()
        with patch.object(cycle.os, 'name', 'posix'), patch.object(cycle.os, 'geteuid', return_value=0, create=True), \
                patch.object(cycle, 'run_command', side_effect=AssertionError('unexpected execution')), contextlib.redirect_stdout(output):
            self.assertEqual(cycle.main(['install']), 1)
        self.assertEqual(json.loads(output.getvalue())['error'], 'explicit_trial_service_changes_and_output_required')

    def test_trial_marker_requires_exact_type_and_safe_origin(self):
        wp = self.root / 'wp'; (wp / 'wp-content').mkdir(parents=True)
        marker = wp / 'wp-content/oj-microbit-acceptance.json'
        marker.write_text(json.dumps({'testServer': True, 'marker': 'oj-microbit-acceptance-v1', 'baseUrl': 'https://trial.example/'}), encoding='utf-8')
        original = Path.lstat
        def metadata(path):
            info = original(path)
            if path == marker:
                return types.SimpleNamespace(st_mode=stat.S_IFREG | 0o644, st_nlink=1, st_size=info.st_size)
            return info
        with patch.object(Path, 'lstat', metadata):
            cycle.marker_check(wp)
            for data in [ {'testServer': 'true', 'marker': 'oj-microbit-acceptance-v1', 'baseUrl': 'https://trial.example/'},
                          {'testServer': True, 'marker': 'wrong', 'baseUrl': 'https://trial.example/'},
                          {'testServer': True, 'marker': 'oj-microbit-acceptance-v1', 'baseUrl': 'http://public.example/'},
                          {'testServer': True, 'marker': 'oj-microbit-acceptance-v1', 'baseUrl': 'https://name:private@trial.example/'} ]:
                marker.write_text(json.dumps(data), encoding='utf-8')
                with self.assertRaises(cycle.CheckError):
                    cycle.marker_check(wp)

    def test_report_creation_is_exclusive_and_never_writes_into_wordpress(self):
        wp = self.root / 'wp'; wp.mkdir()
        report = self.root / 'report'
        with patch.object(cycle, 'private_parent'):
            self.assertEqual(cycle.create_report_directory(report, wp), report)
            (report / 'keep.txt').write_text('preserved', encoding='utf-8')
            with self.assertRaises(FileExistsError):
                cycle.create_report_directory(report, wp)
            with self.assertRaises(cycle.CheckError):
                cycle.create_report_directory(wp / 'public-report', wp)
        self.assertEqual((report / 'keep.txt').read_text(encoding='utf-8'), 'preserved')
        self.assertFalse((wp / 'public-report').exists())

    def test_file_protection_rejects_links_readable_secrets_and_wrong_owner(self):
        candidate = self.root / 'env'; candidate.write_text('PRIVATE_TEST_KEY', encoding='utf-8')
        for mode, links, owner in [(stat.S_IFLNK | 0o600, 1, 0), (stat.S_IFREG | 0o644, 1, 0),
                                   (stat.S_IFREG | 0o600, 2, 0), (stat.S_IFREG | 0o600, 1, 9)]:
            info = types.SimpleNamespace(st_mode=mode, st_nlink=links, st_uid=owner, st_gid=0, st_size=16)
            with patch.object(Path, 'lstat', return_value=info), patch.object(cycle.os, 'geteuid', return_value=0, create=True):
                with self.assertRaises(cycle.CheckError):
                    cycle.file_fingerprint(candidate, True)

    def test_dormant_docker_is_refused_without_any_docker_request(self):
        commands = []
        def run(arguments):
            commands.append(arguments)
            return 'inactive\n'
        with self.assertRaises(cycle.CheckError):
            cycle.check_docker_running(run)
        self.assertEqual(len(commands), 1)
        self.assertEqual(commands[0][0], 'systemctl')

    def test_injected_probe_preserves_production_docker_and_runtime_guards(self):
        self.assertLess(cycle.ROLLBACK_BODY.index('check_docker_query_safe || return 1'), cycle.ROLLBACK_BODY.index('"$NODE_BIN"'))
        self.assertLess(cycle.CONNECTION_BODY.index('check_node_runtime'), cycle.CONNECTION_BODY.index('probe_runner "$old_runner" health'))
        self.assertIn('BRIDGE_PATH="$old_runner/install-tools/configure-wordpress.php"', cycle.CONNECTION_BODY)

    def test_writable_or_foreign_previous_helper_cannot_be_run_as_root(self):
        target = Path('/opt/oj-microbit/0.5.1/runner')
        for bad_name in ['runner-check.mjs', 'configure-wordpress.php', 'WordPressConfigurator.php', 'install-tools']:
            def metadata(path):
                regular = path.name.endswith(('.mjs', '.php'))
                return types.SimpleNamespace(st_uid=9 if path.name == bad_name else 0,
                                             st_mode=(stat.S_IFREG | 0o644) if regular else (stat.S_IFDIR | 0o755),
                                             st_nlink=1, st_size=100)
            with patch.object(Path, 'lstat', metadata), self.assertRaises(cycle.CheckError):
                cycle.protected_runtime(target)

    def test_unmanaged_or_inactive_baselines_cannot_be_rollback_targets(self):
        for values in [dict(self.state, ActiveState='inactive'), dict(self.state, WorkingDirectory='/opt/hustoj'),
                       dict(self.state, UnitFileState='static')]:
            with self.assertRaises(cycle.CheckError):
                cycle.service_state(lambda command: '\n'.join(key + '=' + value for key, value in values.items()))

    def test_install_checks_first_run_rerun_and_readonly_check_in_sequence(self):
        calls = []
        def run(arguments, log):
            calls.append(arguments)
            return 0
        result = cycle.exercise_cycle('install', ['--wp-root', '/trial site'], self.root, command=run)
        self.assertEqual(len(calls), 3)
        self.assertEqual(calls[0], calls[1])
        self.assertEqual(calls[2][-1], '--check')
        self.assertIn('/trial site', calls[0])
        self.assertFalse(result['finalReleaseApproved'])
        self.assertTrue(result['manualPending'])

    def test_failed_first_install_cannot_be_reported_as_a_pass(self):
        with self.assertRaisesRegex(cycle.CheckError, 'first_install_failed'):
            cycle.exercise_cycle('install', [], self.root, command=lambda arguments, log: 7)

    def rollback_runner(self, *, changed=False, successful_child=False, fail_marker=True):
        states = iter([self.state, dict(self.state, UnitFileState='disabled') if changed else self.state])
        calls = []
        def run(arguments, log):
            calls.append(arguments)
            if cycle.ROLLBACK_BODY in arguments:
                text = ('acceptance_intentional_canary_failure\n' if fail_marker else '') + 'Restoring the previous runner environment and service configuration.\n'
                log.write_text(text, encoding='utf-8')
                return 0 if successful_child else 85
            log.write_text('Connection verified.\n', encoding='utf-8')
            return 0
        result = cycle.exercise_cycle('rollback', ['--wp-root', '/trial site'], self.root, command=run,
                                      snapshot=lambda command: next(states), fingerprint=lambda *args: ('PRIVATE_TEST_KEY_HASH', 0o600, 0, 0))
        return result, calls

    def test_rollback_requires_the_intended_failure_and_actual_restored_state(self):
        result, calls = self.rollback_runner()
        self.assertEqual(result['checks']['previous_environment_unit_and_service'], 'restored')
        self.assertEqual(len(calls), 3)
        self.assertNotIn('PRIVATE_TEST_KEY', json.dumps(result))
        self.assertFalse(result['finalReleaseApproved'])

    def test_changed_state_after_failure_is_a_failed_recovery(self):
        with self.assertRaisesRegex(cycle.CheckError, 'previous_runner_state_not_restored'):
            self.rollback_runner(changed=True)

    def test_successful_child_or_early_preflight_error_cannot_fake_rollback(self):
        with self.assertRaisesRegex(cycle.CheckError, 'expected_rollback_not_observed'):
            self.rollback_runner(successful_child=True)
        (self.root / 'intentional_failure.private.log').unlink()
        with self.assertRaisesRegex(cycle.CheckError, 'expected_rollback_not_observed'):
            self.rollback_runner(fail_marker=False)


if __name__ == '__main__':
    unittest.main()
