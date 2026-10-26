#!/usr/bin/env python3
"""Safe regression checks for the Ubuntu installer; never call its main()."""

from __future__ import annotations

import hashlib
import io
import os
from pathlib import Path
import re
import shutil
import stat
import struct
import subprocess
import sys
import tarfile
import tempfile
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
