#!/usr/bin/env python3
"""Safe regression checks for the Ubuntu installer; never call its main()."""

from __future__ import annotations

import hashlib
import io
import os
from pathlib import Path
import re
import shutil
import signal
import stat
import struct
import subprocess
import sys
import tarfile
import tempfile
import time
import unittest
import warnings
import zipfile


REPOSITORY = Path(__file__).resolve().parents[2]
INSTALLER = REPOSITORY / "_release-kit" / "install" / "ubuntu24.sh"
MAIN_FILE = "oj-platform/oj-platform.php"
PLUGIN_HEADER = b"<?php\n/*\n * Plugin Name: OJ Platform\n * Version: 2.3.8\n */\n"


class UbuntuInstallerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        candidate = os.environ.get("OJ_INSTALLER_TEST_BASH")
        if not candidate:
            git_bash = Path(r"C:\Program Files\Git\bin\bash.exe")
            candidate = str(git_bash) if git_bash.is_file() else shutil.which("bash")
        if not candidate:
            raise unittest.SkipTest("Bash or Git Bash is required")
        cls.bash = candidate
        cls.temporary_parent = REPOSITORY / "_notes" / "tmp"
        cls.temporary_parent.mkdir(parents=True, exist_ok=True)

    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(
            prefix="ubuntu-installer-test-", dir=self.temporary_parent
        )
        self.addCleanup(self.temporary.cleanup)
        self.work = Path(self.temporary.name)

    def shell(self, body: str, **values: str) -> subprocess.CompletedProcess[str]:
        environment = os.environ.copy()
        environment.update(
            TEST_INSTALLER=INSTALLER.as_posix(),
            TEST_PYTHON=Path(sys.executable).as_posix(),
            TEST_DIRECTORY=self.work.as_posix(),
            CASE_URL="https://oj.example.com/",
            CASE_EMAIL="admin@example.com",
            CASE_ADMIN="ojadmin",
            CASE_TITLE="Example OJ",
        )
        environment.update(values)
        script = r'''
set -Eeuo pipefail
export PATH="/usr/bin:/bin:$PATH"
python3() { "$TEST_PYTHON" "$@"; }
# Any accidental entry into installation is a test failure, never a real action.
apt-get() { printf 'FORBIDDEN INSTALL ACTION\n' >&2; exit 97; }
systemctl() { printf 'FORBIDDEN INSTALL ACTION\n' >&2; exit 97; }
runuser() { printf 'FORBIDDEN INSTALL ACTION\n' >&2; exit 97; }
rm() { printf 'FORBIDDEN INSTALL ACTION\n' >&2; exit 97; }
curl() { printf 'FORBIDDEN INSTALL ACTION\n' >&2; exit 97; }
source "$TEST_INSTALLER"
SITE_URL=$CASE_URL
ADMIN_EMAIL=$CASE_EMAIL
ADMIN_USER=$CASE_ADMIN
SITE_TITLE=$CASE_TITLE
''' + body
        return subprocess.run(
            [self.bash, "--noprofile", "--norc", "-c", script],
            cwd=REPOSITORY,
            env=environment,
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=20,
            check=False,
        )

    def assert_rejected(self, result: subprocess.CompletedProcess[str]) -> None:
        self.assertEqual(result.returncode, 1, result.stderr or "Unsafe input was accepted")
        self.assertNotIn("FORBIDDEN INSTALL ACTION", result.stderr)

    def package(
        self, entries: list[tuple[str | zipfile.ZipInfo, bytes]] | None = None
    ) -> Path:
        target = self.work / "oj-platform.zip"
        raw_names = []
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", UserWarning)
            with zipfile.ZipFile(target, "w") as archive:
                for name, data in entries if entries is not None else [(MAIN_FILE, PLUGIN_HEADER)]:
                    if isinstance(name, str) and ("\\" in name or "\x00" in name):
                        # Windows ZipInfo normalizes separators even in hostile fixture names.
                        normalized = name.replace("\\", "/").replace("\x00", "_")
                        raw_names.append((normalized.encode("utf-8"), name.encode("utf-8")))
                        archive.writestr(normalized, data)
                    else:
                        archive.writestr(name, data)
        if raw_names:
            contents = target.read_bytes()
            for normalized, original in raw_names:
                contents = contents.replace(normalized, original)
            target.write_bytes(contents)
        self.checksum(target)
        return target

    @staticmethod
    def checksum(target: Path) -> None:
        digest = hashlib.sha256(target.read_bytes()).hexdigest()
        Path(str(target) + ".sha256").write_text(
            digest + "  " + target.name + "\n", encoding="utf-8"
        )

    def validate_package(self, target: Path) -> subprocess.CompletedProcess[str]:
        return self.shell(
            'PLUGIN_ZIP=$CASE_ZIP\nvalidate_package\n', CASE_ZIP=target.as_posix()
        )

    @staticmethod
    def judge_conf(**changes: str | None) -> str:
        values = {
            "OJ_HOST_NAME": "localhost", "OJ_DB_NAME": "jol",
            "OJ_USER_NAME": "ojjudge", "OJ_PASSWORD": "SyntheticJudgePassword9",
            "OJ_PORT_NUMBER": "3306", "OJ_SIM_ENABLE": "0",
            "OJ_TURBO_MODE": "0", "OJ_HTTP_JUDGE": "0",
        }
        for key, value in changes.items():
            if value is None:
                values.pop(key, None)
            else:
                values[key] = value
        return "# Synthetic test configuration only\n" + "\n".join(
            "  " + key + " = " + value + "  " for key, value in values.items()
        ) + "\n"

    def credential_program(self) -> str:
        source = INSTALLER.read_text(encoding="utf-8")
        match = re.search(
            r"^load_judge_credentials\(\)\s*\{.*?<<'PY'\n(.*?)^PY$",
            source, flags=re.MULTILINE | re.DOTALL,
        )
        self.assertIsNotNone(match, "Credential reader must remain independently testable")
        return match.group(1)

    def credential_bridge(self) -> Path:
        bridge = self.work / "credential-reader-fixture.py"
        bridge.write_text('''import os, pathlib, sys
from unittest.mock import patch

def synthetic_read(path, *args, **kwargs):
    if path.as_posix() != '/home/judge/etc/judge.conf':
        raise AssertionError('Unexpected filesystem read in credential fixture')
    if os.environ.get('CASE_CONF_MISSING') == '1':
        raise FileNotFoundError('Synthetic missing judge.conf')
    return os.environ['CASE_CONF']

program = sys.stdin.read()
with patch.object(pathlib.Path, 'read_text', synthetic_read):
    exec(compile(program, 'installer-credential-reader', 'exec'), {'__name__': '__main__'})
''', encoding="utf-8")
        return bridge

    def credentials(self, conf: str, *, missing: bool = False) -> subprocess.CompletedProcess[str]:
        environment = os.environ.copy()
        environment.update(CASE_CONF=conf, CASE_CONF_MISSING="1" if missing else "0")
        return subprocess.run(
            [sys.executable, "-B", str(self.credential_bridge())],
            input=self.credential_program(), capture_output=True, text=True,
            encoding="utf-8", errors="replace", timeout=10, check=False, env=environment,
        )

    def test_shell_syntax_and_source_are_inert(self) -> None:
        syntax = subprocess.run(
            [self.bash, "-n", INSTALLER.as_posix()],
            capture_output=True, text=True, encoding="utf-8", errors="replace",
            check=False, timeout=20,
        )
        self.assertEqual(syntax.returncode, 0, syntax.stderr)
        result = self.shell("printf 'source-only-ok\\n'\n")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), "source-only-ok")

    def test_wp_site_uses_web_root_for_children_and_preserves_callers_directory(self) -> None:
        web_root = self.work / "web root"
        caller = self.work / "private caller"
        web_root.mkdir()
        caller.mkdir()
        result = self.shell(r'''
WEB_ROOT=$CASE_WEB_ROOT
cd -- "$CASE_CALLER"
caller_directory=$PWD
expected_web_directory=$(cd -- "$WEB_ROOT" && pwd -P)
runuser() {
    [[ $PWD == "$expected_web_directory" ]] || return 94
    export EXPECTED_WEB_DIRECTORY=$expected_web_directory
    "$BASH" --noprofile --norc -c '[[ $PWD == "$EXPECTED_WEB_DIRECTORY" ]]' || return 95
    printf '%s\0' "$@"
}
wp_site rewrite structure '/%postname%/' --hard
[[ $PWD == "$caller_directory" ]]
''', CASE_WEB_ROOT=web_root.as_posix(), CASE_CALLER=caller.as_posix())
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.split("\0"), [
            "-u", "www-data", "--", "env", "HOME=/var/cache/oj-wp-cli",
            "/usr/bin/php8.3", "/usr/local/bin/wp", "--path=" + web_root.as_posix(),
            "rewrite", "structure", "/%postname%/", "--hard", "",
        ])

    def test_wp_site_missing_web_root_fails_before_starting_user_command(self) -> None:
        result = self.shell(r'''
WEB_ROOT=$CASE_WEB_ROOT
runuser() { : > "$TEST_DIRECTORY/unexpected-runuser"; }
wp_site core version
''', CASE_WEB_ROOT=(self.work / "missing web root").as_posix())
        self.assert_rejected(result)
        self.assertFalse((self.work / "unexpected-runuser").exists())

    def locked_stage_call(self) -> str:
        source = INSTALLER.read_text(encoding="utf-8")
        main = re.search(r"^main\(\) \{\n(.*?)^\}", source, re.MULTILINE | re.DOTALL)
        self.assertIsNotNone(main, "Installer main entry is missing")
        call = re.search(r"^\s*(install_stages 9>&-)\s*$", main.group(1), re.MULTILINE)
        self.assertIsNotNone(call, "Installation stages must close the inherited lock descriptor")
        return call.group(1)

    def test_install_stages_close_child_lock_descriptor_and_restore_owner(self) -> None:
        result = self.shell(r'''
exec 9>"$TEST_DIRECTORY/owner-descriptor.txt"
source "$TEST_INSTALLER"
install_stages() {
    if "$BASH" --noprofile --norc -c '(: <&9) 2>/dev/null'; then
        printf 'Child inherited the installer lock descriptor\n' >&2
        return 96
    fi
}
''' + self.locked_stage_call() + r'''
printf 'owner-descriptor-restored\n' >&9
exec 9>&-
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(
            (self.work / "owner-descriptor.txt").read_text(encoding="utf-8"),
            "owner-descriptor-restored\n",
        )

    @unittest.skipUnless(sys.platform.startswith("linux"), "Linux flock semantics are required")
    def test_lock_blocks_parallel_run_and_releases_while_daemon_lives(self) -> None:
        self.assert_lock_lifetime(stage_exit_code=0)

    @unittest.skipUnless(sys.platform.startswith("linux"), "Linux flock semantics are required")
    def test_failed_stages_release_lock_and_preserve_exit_code(self) -> None:
        self.assert_lock_lifetime(stage_exit_code=23)

    def assert_lock_lifetime(self, *, stage_exit_code: int) -> None:
        import fcntl

        if not shutil.which("flock"):
            self.skipTest("The util-linux flock command is required")
        owner_script = self.work / "synthetic-lock-owner.sh"
        owner_script.write_text(r'''#!/usr/bin/env bash
set -Eeuo pipefail
source "$TEST_INSTALLER"
install_stages() {
    "$BASH" --noprofile --norc -c '
        printf "%s\n" "$BASHPID" > "$TEST_DAEMON_PID"
        : > "$TEST_DAEMON_READY"
        while [[ ! -e $TEST_DAEMON_STOP ]]; do sleep 0.02; done
    ' </dev/null >/dev/null 2>&1 &
    : > "$TEST_STAGE_READY"
    while [[ ! -e $TEST_STAGE_STOP ]]; do sleep 0.02; done
    return "$TEST_STAGE_EXIT"
}
exec 9>"$TEST_LOCK"
flock -n 9
''' + self.locked_stage_call() + "\n", encoding="utf-8")
        paths = {
            key: self.work / filename for key, filename in (
                ("TEST_LOCK", "synthetic-installer.lock"),
                ("TEST_DAEMON_PID", "daemon.pid"),
                ("TEST_DAEMON_READY", "daemon.ready"),
                ("TEST_DAEMON_STOP", "daemon.stop"),
                ("TEST_STAGE_READY", "stage.ready"),
                ("TEST_STAGE_STOP", "stage.stop"),
            )
        }
        environment = os.environ.copy()
        environment.update({key: str(path) for key, path in paths.items()})
        environment["TEST_INSTALLER"] = str(INSTALLER)
        environment["TEST_STAGE_EXIT"] = str(stage_exit_code)
        owner = subprocess.Popen(
            [self.bash, "--noprofile", "--norc", str(owner_script)], env=environment,
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True, encoding="utf-8", start_new_session=True,
        )
        try:
            deadline = time.monotonic() + 5
            while not all(paths[key].exists() for key in ("TEST_STAGE_READY", "TEST_DAEMON_READY")):
                if owner.poll() is not None:
                    output, error = owner.communicate(timeout=1)
                    self.fail("Synthetic owner exited before stages: " + output + error)
                if time.monotonic() >= deadline:
                    self.fail("Synthetic stages did not become ready")
                time.sleep(0.01)
            daemon_pid = int(paths["TEST_DAEMON_PID"].read_text(encoding="utf-8"))
            with paths["TEST_LOCK"].open("a", encoding="utf-8") as contender:
                with self.assertRaises(BlockingIOError):
                    fcntl.flock(contender, fcntl.LOCK_EX | fcntl.LOCK_NB)
                paths["TEST_STAGE_STOP"].touch()
                output, error = owner.communicate(timeout=5)
                self.assertEqual(owner.returncode, stage_exit_code, output + error)
                os.kill(daemon_pid, 0)
                state = Path(f"/proc/{daemon_pid}/stat").read_text(encoding="utf-8")
                self.assertNotEqual(state.rpartition(") ")[2].split()[0], "Z")
                fcntl.flock(contender, fcntl.LOCK_EX | fcntl.LOCK_NB)
                fcntl.flock(contender, fcntl.LOCK_UN)
        finally:
            paths["TEST_STAGE_STOP"].touch()
            paths["TEST_DAEMON_STOP"].touch()
            try:
                os.killpg(owner.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
            owner.communicate(timeout=5)

    def test_argument_parser(self) -> None:
        result = self.shell(
            'parse_args --site-url http://oj.example.com --admin-email other@example.com '
            '--admin-user test_admin --title "A title" --plugin-zip "$TEST_DIRECTORY/release.zip" --resume\n'
            '[[ $SITE_URL == http://oj.example.com && $ADMIN_EMAIL == other@example.com '
            '&& $ADMIN_USER == test_admin && $SITE_TITLE == "A title" && $RESUME == 1 ]]\n'
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        for arguments in ("--unknown", "--site-url", "--plugin-zip"):
            with self.subTest(arguments=arguments):
                self.assert_rejected(self.shell("parse_args " + arguments + "\n"))

    def test_valid_inputs_and_trailing_slash(self) -> None:
        for url, domain in (
            ("https://oj.example.com/", "oj.example.com"),
            ("http://oj.example.com", "oj.example.com"),
            ("http://192.0.2.1/", "192.0.2.1"),
        ):
            with self.subTest(url=url):
                result = self.shell(
                    'validate_inputs\nprintf "%s\\n%s\\n" "$SITE_URL" "$DOMAIN"\n',
                    CASE_URL=url,
                )
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout.splitlines(), [url.rstrip("/"), domain])

    def test_missing_inputs_use_primary_ipv4_and_test_email(self) -> None:
        for email in ("", "teacher@example.com"):
            with self.subTest(explicit_email=bool(email)):
                result = self.shell(
                    'ip() { printf "%s\\n" "1.1.1.1 via 192.0.2.1 dev eth0 src 192.0.2.17 uid 0"; }\n'
                    'hostname() { printf "%s\\n" "198.51.100.7"; }\n'
                    'set_default_inputs\nvalidate_inputs\n'
                    'printf "%s\\n%s\\n%s\\n" "$SITE_URL" "$ADMIN_EMAIL" "$DOMAIN"\n',
                    CASE_URL="", CASE_EMAIL=email,
                )
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(
                    result.stdout.splitlines(),
                    ["http://192.0.2.17", email or "admin@example.test", "192.0.2.17"],
                )

    def test_default_url_falls_back_to_valid_hostname_ipv4(self) -> None:
        for route in (None, "1.1.1.1 dev eth0 src 999.0.0.1 uid 0"):
            with self.subTest(route_available=route is not None):
                route_stub = 'ip() { return 1; }\n' if route is None else (
                    'ip() { printf "%s\\n" "$CASE_ROUTE"; }\n'
                )
                result = self.shell(
                    route_stub
                    + 'hostname() { printf "%s\\n" "2001:db8::7 127.0.0.1 198.51.100.9 10.0.0.9"; }\n'
                    'set_default_inputs\nvalidate_inputs\nprintf "%s\\n" "$SITE_URL"\n',
                    CASE_URL="", CASE_EMAIL="", CASE_ROUTE=route or "",
                )
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout.strip(), "http://198.51.100.9")

    def test_explicit_site_and_email_skip_address_detection(self) -> None:
        result = self.shell(
            'ip() { printf "UNEXPECTED ADDRESS LOOKUP\\n" >&2; exit 97; }\n'
            'hostname() { printf "UNEXPECTED ADDRESS LOOKUP\\n" >&2; exit 97; }\n'
            'set_default_inputs\nvalidate_inputs\n'
            'printf "%s\\n%s\\n" "$SITE_URL" "$ADMIN_EMAIL"\n',
            CASE_URL="https://class.example.com", CASE_EMAIL="teacher@example.com",
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(
            result.stdout.splitlines(), ["https://class.example.com", "teacher@example.com"]
        )
        self.assertNotIn("UNEXPECTED ADDRESS LOOKUP", result.stderr)

    def test_explicit_site_can_omit_email(self) -> None:
        result = self.shell(
            'ip() { printf "UNEXPECTED ADDRESS LOOKUP\\n" >&2; exit 97; }\n'
            'hostname() { printf "UNEXPECTED ADDRESS LOOKUP\\n" >&2; exit 97; }\n'
            'set_default_inputs\nvalidate_inputs\n'
            'printf "%s\\n%s\\n" "$SITE_URL" "$ADMIN_EMAIL"\n',
            CASE_URL="http://192.0.2.39", CASE_EMAIL="",
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(
            result.stdout.splitlines(), ["http://192.0.2.39", "admin@example.test"]
        )
        self.assertNotIn("UNEXPECTED ADDRESS LOOKUP", result.stderr)

    def test_https_without_email_is_rejected_before_noninteractive_prompt(self) -> None:
        result = self.shell(
            'read() { printf "UNEXPECTED INTERACTIVE PROMPT\\n" >&2; exit 97; }\n'
            'set_default_inputs </dev/null\n',
            CASE_URL="https://class.example.com", CASE_EMAIL="",
        )
        self.assert_rejected(result)
        self.assertNotIn("UNEXPECTED INTERACTIVE PROMPT", result.stderr)

    def test_automatic_url_requires_an_usable_ipv4(self) -> None:
        for addresses in ("", "::1 2001:db8::2 127.0.0.1", "999.0.0.1 not-an-ip"):
            with self.subTest(addresses=addresses):
                result = self.shell(
                    'ip() { return 1; }\n'
                    'hostname() { printf "%s\\n" "$CASE_ADDRESSES"; }\n'
                    'set_default_inputs\n',
                    CASE_URL="", CASE_EMAIL="", CASE_ADDRESSES=addresses,
                )
                self.assert_rejected(result)

    def test_reject_invalid_site_urls(self) -> None:
        for url in (
            "ftp://oj.example.com", "https://user:password@oj.example.com",
            "https://oj.example.com/path", "https://oj.example.com?x=1",
            "https://oj.example.com#fragment", "https://oj.example.com:443",
            "http://oj.example.com:80", "https://192.0.2.1",
            "http://[::1]", "https://-bad.example.com", "https://bad_.example.com",
            "https://oj.example.com\n", "https://oj.\nexample.com",
            "https://oj.example.com\r", "https://oj.example.com\t",
            " https://oj.example.com", "https://oj.example.com ",
        ):
            with self.subTest(url=repr(url)):
                self.assert_rejected(self.shell("validate_inputs\n", CASE_URL=url))

    def test_https_scheme_cannot_skip_tls_configuration(self) -> None:
        result = self.shell(
            'validate_inputs\n[[ $SITE_URL == https://* ]]\n',
            CASE_URL="HTTPS://oj.example.com",
        )
        # Rejecting an uppercase spelling is also safe; accepting it must normalize it.
        if result.returncode:
            rejected = self.shell("validate_inputs\n", CASE_URL="HTTPS://oj.example.com")
            self.assertNotEqual(rejected.returncode, 0, "Accepted HTTPS URL bypasses TLS branch")

    def test_reject_invalid_administrator_fields(self) -> None:
        for field, values in (
            ("CASE_EMAIL", ["missing-at.example.com", "a@@example.com", "a b@example.com"]),
            ("CASE_ADMIN", ["ab", "-admin", "a/b", "bad admin", "admin\nname"]),
            ("CASE_TITLE", ["", "   ", "Bad\nTitle", "Bad\tTitle"]),
        ):
            for value in values:
                with self.subTest(field=field, value=repr(value)):
                    self.assert_rejected(self.shell("validate_inputs\n", **{field: value}))

    def test_fixed_web_root_cannot_be_redirected(self) -> None:
        for web_root in (self.work.as_posix(), "/", "/home/judge/src/web/", "/home/judge/src/web/../web"):
            with self.subTest(web_root=web_root):
                self.assert_rejected(self.shell(
                    'WEB_ROOT=$CASE_WEB_ROOT\nassert_web_root\n', CASE_WEB_ROOT=web_root
                ))

    def test_valid_release_zip(self) -> None:
        target = self.package([(MAIN_FILE, PLUGIN_HEADER), ("oj-platform/src/Example.php", b"<?php\n")])
        result = self.validate_package(target)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_missing_and_invalid_checksums_are_rejected(self) -> None:
        target = self.package()
        sidecar = Path(str(target) + ".sha256")
        sidecar.unlink()
        self.assert_rejected(self.validate_package(target))
        for checksum in ("", "not-a-checksum", "0" * 64):
            with self.subTest(checksum=bool(checksum)):
                sidecar.write_text(checksum, encoding="utf-8")
                self.assert_rejected(self.validate_package(target))

    def test_changed_zip_is_rejected(self) -> None:
        target = self.package()
        target.write_bytes(target.read_bytes() + b"changed-after-checksum")
        self.assert_rejected(self.validate_package(target))

    def test_wrong_plugin_and_nonheader_identity_are_rejected(self) -> None:
        for main in (
            b"<?php\n/* Plugin Name: Other Plugin\nVersion: 1.0.0 */\n",
            b"<?php\n/* Plugin Name: Other Plugin\nVersion: 1.0.0 */\n"
            b"$example = 'Plugin Name: OJ Platform';\n",
            b"<?php\n/* Plugin Name: OJ Platform */\n",
        ):
            with self.subTest(main_size=len(main)):
                self.assert_rejected(self.validate_package(self.package([(MAIN_FILE, main)])))
        self.assert_rejected(self.validate_package(self.package([("other-plugin/other.php", PLUGIN_HEADER)])))

    def test_unsafe_zip_entry_paths_are_rejected(self) -> None:
        for name in (
            "oj-platform/../escape.php", "../oj-platform/escape.php",
            "/oj-platform/escape.php", "C:/oj-platform/escape.php",
            "oj-platform\\escape.php", "oj-platform//alias.php",
            "oj-platform/./alias.php", "oj-platform/zero\x00suffix.php",
        ):
            with self.subTest(entry=name):
                target = self.package([(MAIN_FILE, PLUGIN_HEADER), (name, b"untrusted")])
                self.assert_rejected(self.validate_package(target))

    def test_duplicate_and_symlink_zip_entries_are_rejected(self) -> None:
        target = self.package([(MAIN_FILE, PLUGIN_HEADER), (MAIN_FILE, PLUGIN_HEADER)])
        self.assert_rejected(self.validate_package(target))
        link = zipfile.ZipInfo("oj-platform/src/link.php")
        link.create_system = 3
        link.external_attr = (stat.S_IFLNK | 0o777) << 16
        target = self.package([(MAIN_FILE, PLUGIN_HEADER), (link, b"../../outside")])
        self.assert_rejected(self.validate_package(target))

    def test_archive_declared_expansion_limit(self) -> None:
        target = self.package()
        data = bytearray(target.read_bytes())
        central_header = data.index(b"PK\x01\x02")
        struct.pack_into("<I", data, central_header + 24, 513 * 1024 * 1024)
        target.write_bytes(data)
        self.checksum(target)
        self.assert_rejected(self.validate_package(target))

    def test_official_source_archive_extracts_trunk_into_src(self) -> None:
        commit = "a" * 40
        prefix = "hustoj-" + commit
        archive = self.work / "upstream-source.tar.gz"
        output = self.work / "src"
        output.mkdir()
        expected = {
            "web/index.php": b"synthetic web entry\n",
            "core/judge_client/judge_client.cc": b"synthetic judge entry\n",
            "install/install-ubuntu24.04.sh": b"synthetic installer entry\n",
        }
        with tarfile.open(archive, "w:gz") as bundle:
            for relative, contents in expected.items():
                entry = tarfile.TarInfo(prefix + "/trunk/" + relative)
                entry.size = len(contents)
                bundle.addfile(entry, io.BytesIO(contents))
            unrelated = tarfile.TarInfo(prefix + "/README.md")
            unrelated.size = 7
            bundle.addfile(unrelated, io.BytesIO(b"outside"))
        result = self.shell(
            'archive=$CASE_ARCHIVE; output=$CASE_OUTPUT\n'
            'if command -v cygpath >/dev/null; then '
            'archive=$(cygpath -u "$archive"); output=$(cygpath -u "$output"); fi\n'
            'tar xzf "$archive" --strip-components=2 -C "$output" '
            '"hustoj-$CASE_COMMIT/trunk"\n',
            CASE_ARCHIVE=archive.as_posix(), CASE_OUTPUT=output.as_posix(), CASE_COMMIT=commit,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        actual = {p.relative_to(output).as_posix() for p in output.rglob("*") if p.is_file()}
        self.assertEqual(actual, set(expected))
        for relative, contents in expected.items():
            self.assertEqual((output / relative).read_bytes(), contents)

    def test_zip_symlink_on_disk_is_rejected(self) -> None:
        target = self.package()
        link = self.work / "linked.zip"
        try:
            link.symlink_to(target)
        except OSError as error:
            self.skipTest("Creating a filesystem symlink is unavailable: " + str(error))
        self.checksum(link)
        self.assert_rejected(self.validate_package(link))

    def test_checksum_symlink_on_disk_is_rejected(self) -> None:
        target = self.package()
        sidecar = Path(str(target) + ".sha256")
        stored = self.work / "stored.sha256"
        sidecar.rename(stored)
        try:
            sidecar.symlink_to(stored)
        except OSError as error:
            self.skipTest("Creating a filesystem symlink is unavailable: " + str(error))
        self.assert_rejected(self.validate_package(target))

    def test_judge_credentials_preserve_exact_user_password_and_port(self) -> None:
        for changes, host in (
            ({}, "127.0.0.1:3306"),
            ({"OJ_HOST_NAME": "127.0.0.1", "OJ_PORT_NUMBER": "3307"}, "127.0.0.1:3307"),
            ({"OJ_PORT_NUMBER": None}, "127.0.0.1:3306"),
        ):
            with self.subTest(changes=changes):
                result = self.credentials(self.judge_conf(**changes))
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout, "ojjudge\0SyntheticJudgePassword9\0" + host + "\0")
                self.assertEqual(result.stderr, "")

    def test_judge_credentials_reject_missing_remote_or_incompatible_configuration(self) -> None:
        secret = "SyntheticJudgePassword9"
        invalid = [
            {"OJ_HOST_NAME": "database.example.com"}, {"OJ_DB_NAME": "other_database"},
            {"OJ_HOST_NAME": None}, {"OJ_DB_NAME": None}, {"OJ_USER_NAME": None},
            {"OJ_PASSWORD": None}, {"OJ_USER_NAME": ""}, {"OJ_PASSWORD": ""},
            {"OJ_USER_NAME": "invalid-user"}, {"OJ_PASSWORD": secret + "!"},
            {"OJ_HTTP_JUDGE": "1"}, {"OJ_SIM_ENABLE": "1"}, {"OJ_TURBO_MODE": "1"},
            {"OJ_PORT_NUMBER": "0"}, {"OJ_PORT_NUMBER": "65536"},
            {"OJ_PORT_NUMBER": "not-a-port"},
        ]
        for changes in invalid:
            with self.subTest(fields=sorted(changes)):
                result = self.credentials(self.judge_conf(**changes))
                self.assert_rejected(result)
                self.assertEqual(result.stdout, "", "Rejected credentials must produce no partial result")
                self.assertNotIn(secret, result.stderr)
        result = self.credentials(self.judge_conf(), missing=True)
        self.assert_rejected(result)
        self.assertEqual(result.stdout, "")
        self.assertNotIn(secret, result.stderr)

    def test_shell_credential_load_reuses_values_and_rejects_resume_mismatch(self) -> None:
        bridge = self.credential_bridge()
        setup = '''
STATE=$TEST_DIRECTORY/state
mkdir -p "$STATE"
ADMIN_PASSWORD=SyntheticAdministratorPassword9
save_inputs() { :; }
python3() { "$TEST_PYTHON" "$CASE_BRIDGE"; }
WP_DB_USER= WP_DB_PASSWORD= WP_DB_HOST=
'''
        result = self.shell(
            setup + 'load_judge_credentials\nprintf "%s\\0%s\\0%s\\0" "$WP_DB_USER" "$WP_DB_PASSWORD" "$WP_DB_HOST"\n',
            CASE_BRIDGE=bridge.as_posix(), CASE_CONF=self.judge_conf(),
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, "ojjudge\0SyntheticJudgePassword9\0" + "127.0.0.1:3306\0")
        self.assertEqual(result.stderr, "")
        result = self.shell(
            setup + 'WP_DB_USER=ojjudge; WP_DB_PASSWORD=StaleSyntheticPassword; WP_DB_HOST=127.0.0.1:3306\nload_judge_credentials\n',
            CASE_BRIDGE=bridge.as_posix(), CASE_CONF=self.judge_conf(),
        )
        self.assert_rejected(result)
        self.assertNotIn("SyntheticJudgePassword9", result.stderr)
        self.assertNotIn("StaleSyntheticPassword", result.stderr)


if __name__ == "__main__":
    unittest.main(verbosity=2)
