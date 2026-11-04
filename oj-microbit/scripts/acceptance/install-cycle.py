#!/usr/bin/env python3
"""Explicit trial-host installer exercise; never manages HUSTOJ resources."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import signal
import stat
import subprocess
import sys

SERVICE = 'oj-microbit-runner.service'
ROOT = Path('/opt/oj-microbit')
ENV_FILE = Path('/etc/oj-microbit/runner.env')
UNIT_FILE = Path('/etc/systemd/system/oj-microbit-runner.service')
INSTALLER = Path(__file__).resolve().parents[2] / 'runner' / 'install.sh'
SAFE_ENV = {'PATH': '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin',
            'LANG': 'C.UTF-8', 'DOCKER_HOST': 'unix:///var/run/docker.sock'}
FAIL_MARKER = 'acceptance_intentional_canary_failure'
ROLLBACK_BODY = '''set +x; set +a; set -Eeuo pipefail; umask 077
source "$1"; shift
probe_runner() {
  check_docker_query_safe || return 1
  if [[ $2 == canary ]]; then printf 'acceptance_intentional_canary_failure\\n' >&2; return 85; fi
  printf '%s' "$TOKEN" | "$NODE_BIN" "$1/install-tools/runner-check.mjs" "$2" "$URL" "$EXPECTED_RUNTIME"
}
main "$@"
'''
CONNECTION_BODY = '''set +x; set +a; set -Eeuo pipefail; umask 077
source "$1"; shift
old_runner=$1; shift
parse_args "$@"; check_source; check_wordpress_files
EXPLICIT_PORT=false; PORT=''; read_environment
check_node_runtime
BRIDGE_PATH="$old_runner/install-tools/configure-wordpress.php"
healthy=false
for attempt in {1..20}; do
  if probe_runner "$old_runner" health >/dev/null 2>&1; then healthy=true; break; fi
  sleep 1
done
$healthy || exit 1
run_wp_bridge check
'''


class CheckError(Exception):
    pass


def marker_check(wp_root):
    marker = wp_root / 'wp-content' / 'oj-microbit-acceptance.json'
    info = marker.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_mode & 0o022 or info.st_size > 4096:
        raise CheckError('unsafe_trial_marker')
    data = json.loads(marker.read_text(encoding='utf-8'))
    if data.get('testServer') is not True or data.get('marker') != 'oj-microbit-acceptance-v1' or not isinstance(data.get('baseUrl'), str):
        raise CheckError('trial_marker_required')
    from urllib.parse import urlsplit
    target = urlsplit(data['baseUrl'])
    if target.scheme != 'https' and not (target.scheme == 'http' and target.hostname in ['127.0.0.1', 'localhost', '[::1]', '::1']):
        raise CheckError('invalid_trial_origin')
    if not target.hostname or target.username or target.password or target.query or target.fragment:
        raise CheckError('invalid_trial_origin')


def private_parent(path):
    """Existing private parent only; never chmod an operator's directory."""
    path = path.absolute()
    for item in [path, *path.parents]:
        info = item.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o022:
            raise CheckError('unsafe_report_ancestor')
    if stat.S_IMODE(path.stat().st_mode) != 0o700:
        raise CheckError('report_parent_must_be_private')


def create_report_directory(path, wp_root):
    if not path.is_absolute() or path.name in ['', '.', '..']:
        raise CheckError('invalid_report_directory')
    private_parent(path.parent)
    normalized = path.parent.resolve() / path.name
    if normalized == wp_root or wp_root in normalized.parents:
        raise CheckError('report_directory_must_be_outside_web_root')
    normalized.mkdir(mode=0o700)  # exclusive: an existing run is never replaced
    return normalized


def run_command(arguments, log=None, timeout=1800):
    if log is None:
        result = subprocess.run(arguments, env=SAFE_ENV, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                text=True, timeout=15, check=False)
        if result.returncode != 0 or len(result.stdout) > 16384:
            raise CheckError('readonly_service_query_failed')
        return result.stdout
    descriptor = os.open(log, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'wb') as output:
        child = subprocess.Popen(arguments, env=SAFE_ENV, stdout=output, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            return child.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            # Only our newly created process group is signalled. Service/DB
            # state is unknown until the operator examines the retained log.
            for number, grace in [(signal.SIGINT, 30), (signal.SIGTERM, 30), (signal.SIGKILL, 10)]:
                if child.poll() is not None:
                    break
                try:
                    os.killpg(child.pid, number)
                    child.wait(timeout=grace)
                except ProcessLookupError:
                    break
                except subprocess.TimeoutExpired:
                    continue
            raise CheckError('installer_timeout_manual_recovery_required')


def service_state(command=run_command):
    output = command(['systemctl', 'show', SERVICE, '--property=ActiveState', '--property=UnitFileState',
                      '--property=WorkingDirectory', '--property=LoadState'])
    values = dict(line.split('=', 1) for line in output.splitlines() if '=' in line)
    if set(values) != {'ActiveState', 'UnitFileState', 'WorkingDirectory', 'LoadState'}:
        raise CheckError('incomplete_service_state')
    if values['LoadState'] != 'loaded' or values['ActiveState'] != 'active' or values['UnitFileState'] not in ['enabled', 'disabled']:
        raise CheckError('rollback_requires_an_active_existing_runner')
    if re.fullmatch(r'/opt/oj-microbit/[0-9]+\.[0-9]+\.[0-9]+(?:-rc\.[1-9][0-9]*)?/runner', values['WorkingDirectory']) is None:
        raise CheckError('unmanaged_existing_runner')
    protected_runtime(Path(values['WorkingDirectory']))
    return values


def protected_runtime(path):
    # Baseline health executes the old helper as root. Verify every ancestor
    # and that specific helper first; a writable old release is never executed.
    helpers = ['runner-check.mjs', 'configure-wordpress.php', 'WordPressConfigurator.php']
    for item in [*path.parents, path, path / 'install-tools', *[path / 'install-tools' / name for name in helpers]]:
        info = item.lstat()
        regular = item.name in helpers
        if info.st_uid != 0 or info.st_mode & 0o022 or not (stat.S_ISREG(info.st_mode) if regular else stat.S_ISDIR(info.st_mode)):
            raise CheckError('unprotected_previous_runtime')
        if regular and (info.st_nlink != 1 or info.st_size > 1048576):
            raise CheckError('unprotected_previous_runtime')


def file_fingerprint(path, private=False):
    info = path.lstat()
    mode = stat.S_IMODE(info.st_mode)
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != os.geteuid() or info.st_size > 1048576 or mode & 0o022 or (private and mode & 0o077):
        raise CheckError('unsafe_existing_configuration')
    return (hashlib.sha256(path.read_bytes()).hexdigest(), mode, info.st_uid, info.st_gid)


def check_docker_running(command=run_command):
    # Refuse a dormant socket before making a Docker request. No daemon start.
    active = command(['systemctl', 'show', 'docker.service', '--property=ActiveState', '--value']).strip()
    if active != 'active':
        raise CheckError('active_generic_docker_service_required')
    command(['docker', 'info', '--format', '{{.ServerVersion}}'])


def exercise_cycle(mode, installer_args, report_dir, command=run_command, snapshot=service_state,
                   fingerprint=file_fingerprint, env_file=ENV_FILE, unit_file=UNIT_FILE):
    checks = {}
    if mode == 'install':
        for label, extra in [('first_install', []), ('same_version_rerun', []), ('installed_readonly_check', ['--check'])]:
            if command(['bash', str(INSTALLER), *installer_args, *extra], report_dir / (label + '.private.log')) != 0:
                raise CheckError(label + '_failed_manual_review_required')
            checks[label] = 'passed'
    else:
        before = snapshot(command)
        before_env = fingerprint(env_file, True)
        before_unit = fingerprint(unit_file)
        if command(['bash', '-c', CONNECTION_BODY, 'acceptance-check', str(INSTALLER), before['WorkingDirectory'], *installer_args],
                   report_dir / 'baseline_connection.private.log') != 0:
            raise CheckError('baseline_health_or_wordpress_connection_failed')
        exit_code = command(['bash', '-c', ROLLBACK_BODY, 'acceptance-rollback', str(INSTALLER), *installer_args],
                            report_dir / 'intentional_failure.private.log')
        with (report_dir / 'intentional_failure.private.log').open('rb') as log:
            log.seek(max(0, log.seek(0, os.SEEK_END) - 65536))
            log_text = log.read(65536).decode('utf-8', errors='replace')
        if exit_code == 0 or FAIL_MARKER not in log_text or 'Restoring the previous runner environment' not in log_text:
            raise CheckError('expected_rollback_not_observed')
        if before != snapshot(command) or before_env != fingerprint(env_file, True) or before_unit != fingerprint(unit_file):
            raise CheckError('previous_runner_state_not_restored')
        if command(['bash', '-c', CONNECTION_BODY, 'acceptance-check', str(INSTALLER), before['WorkingDirectory'], *installer_args],
                   report_dir / 'restored_connection.private.log') != 0:
            raise CheckError('restored_health_or_wordpress_connection_failed')
        checks = {'intentional_post_activation_failure': 'observed', 'previous_environment_unit_and_service': 'restored',
                  'authenticated_health_and_wordpress_connection': 'passed'}
    return {'tool': 'install-cycle', 'mode': mode, 'verdict': 'checks_passed', 'checks': checks, 'finalReleaseApproved': False,
            'scope': 'Only the selected installer/service cycle was exercised; this is not final 1.0 approval.',
            'manualPending': ['HUSTOJ before/after snapshot comparison', 'database backup restore and upgrade data comparison',
                              'real accounts/class permissions', 'reboot and interrupted installation', 'concurrent classroom/HUSTOJ grading load']}


def main(argv=None):
    parser = argparse.ArgumentParser(description='Trial-host installation/rerun/real rollback probe. Default plan is non-mutating.')
    parser.add_argument('mode', choices=['plan', 'install', 'rollback'], nargs='?', default='plan')
    parser.add_argument('--wp-root', default='/home/judge/src/web')
    parser.add_argument('--wp-user')
    parser.add_argument('--port', type=int)
    parser.add_argument('--output-dir')
    parser.add_argument('--allow-test-service-changes', action='store_true')
    args = parser.parse_args(argv)
    installer_args = ['--wp-root', args.wp_root]
    if args.wp_user:
        if re.fullmatch(r'[a-z_][a-z0-9_-]*\$?', args.wp_user) is None:
            parser.error('invalid WordPress OS account')
        installer_args += ['--wp-user', args.wp_user]
    if args.port is not None:
        if not 1024 <= args.port <= 65535:
            parser.error('port must be 1024..65535')
        installer_args += ['--port', str(args.port)]
    if not PurePosixPath(args.wp_root).is_absolute():
        parser.error('WordPress root must be absolute')
    if args.mode == 'plan':
        print(json.dumps({'verdict': 'plan_only', 'mutates': False,
                          'installCommand': ['bash', str(INSTALLER), *installer_args],
                          'requires': ['trial marker', 'explicit --allow-test-service-changes', 'root', 'existing running Docker'],
                          'rollbackProbe': 'Requires an active previous runner; deliberately fails after service activation.'}))
        return 0
    directory = None
    try:
        if os.name != 'posix' or os.geteuid() != 0:
            raise CheckError('root_on_trial_linux_host_required')
        if not args.allow_test_service_changes or not args.output_dir:
            raise CheckError('explicit_trial_service_changes_and_output_required')
        wp_root = Path(args.wp_root).resolve(strict=True)
        marker_check(wp_root)
        check_docker_running()
        directory = create_report_directory(Path(args.output_dir), wp_root)
        result = exercise_cycle(args.mode, installer_args, directory)
        code = 0
    except Exception as error:
        safe_code = str(error) if isinstance(error, CheckError) else 'preflight_or_private_report_failed'
        result = {'tool': 'install-cycle', 'mode': args.mode, 'verdict': 'failed', 'error': safe_code,
                  'manualRecoveryRequired': True, 'finalReleaseApproved': False}
        code = 1
    if directory is not None:
        descriptor = os.open(directory / 'report.json', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, 'w', encoding='utf-8') as report:
            json.dump(result, report, ensure_ascii=False, indent=2)
            report.write('\n')
    print(json.dumps({'mode': args.mode, 'verdict': result['verdict'], 'error': result.get('error'), 'finalReleaseApproved': False}))
    return code


if __name__ == '__main__':
    sys.exit(main())
