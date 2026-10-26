#!/usr/bin/env python3
"""Exercise the progress runner with synthetic children, never an installer."""

from __future__ import annotations

import hashlib
import os
from pathlib import Path
import queue
import signal
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest


REPOSITORY = Path(__file__).resolve().parents[2]
RUNNER = REPOSITORY / "_release-kit" / "install" / "run-with-progress.py"


class InstallProgressTests(unittest.TestCase):
    def setUp(self) -> None:
        parent = REPOSITORY / "_notes" / "tmp"
        parent.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(prefix="install-progress-test-", dir=parent)
        self.addCleanup(self.temporary.cleanup)
        self.work = Path(self.temporary.name)
        self.raw = self.work / "raw.log"
        self.progress = self.work / "progress.log"

    def command(self, code: str, *child_arguments: str, heartbeat: str = "0.1") -> list[str]:
        return [
            sys.executable, "-B", str(RUNNER),
            "--raw-log", str(self.raw),
            "--progress-log", str(self.progress),
            "--heartbeat", heartbeat,
            "--", sys.executable, "-u", "-c", code, *child_arguments,
        ]

    def run_child(self, code: str, *arguments: str) -> subprocess.CompletedProcess[str]:
        environment = os.environ.copy()
        environment.update(PYTHONUTF8="1", PYTHONIOENCODING="utf-8")
        return subprocess.run(
            self.command(code, *arguments),
            cwd=REPOSITORY,
            env=environment,
            capture_output=True,
            encoding="utf-8",
            errors="replace",
            timeout=8,
            check=False,
        )

    def visible(self, result: subprocess.CompletedProcess[str]) -> str:
        progress = self.progress.read_text(encoding="utf-8") if self.progress.exists() else ""
        return result.stdout + result.stderr + progress

    def test_raw_bytes_are_preserved_and_progress_is_readable(self) -> None:
        payload = "\x1b[31m한글 설치 진행\x1b[0m\n\x07".encode("utf-8") + b"\xff\xfe\n"
        error = b"compiler diagnostic\n"
        code = (
            f"import sys; sys.stdout.buffer.write({payload!r}); sys.stdout.buffer.flush(); "
            f"sys.stderr.buffer.write({error!r}); sys.stderr.buffer.flush()"
        )
        result = self.run_child(code)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.raw.read_bytes(), payload + error)
        output = self.visible(result)
        self.assertIn("한글 설치 진행", output)
        self.assertIn("compiler diagnostic", output)
        self.assertNotIn("\x1b", output)
        self.assertNotIn("\x07", output)
        if os.name == "posix":
            self.assertEqual(stat.S_IMODE(self.raw.stat().st_mode), 0o600)
            self.assertEqual(stat.S_IMODE(self.progress.stat().st_mode), 0o600)

    def test_database_and_command_passwords_are_not_displayed(self) -> None:
        secrets = ["PasswordLeak123", "OjConfigLeak456", "PhpConfigLeak789", "SqlLeak123", "CliLeak456"]
        payload = (
            f"password: {secrets[0]}\n"
            f"OJ_PASSWORD={secrets[1]}\n"
            f'static $DB_PASS="{secrets[2]}";\n'
            f"CREATE USER hustoj IDENTIFIED BY '{secrets[3]}';\n"
            f"mysql --password={secrets[4]}\n"
            "ordinary package installed\n"
        ).encode("utf-8")
        result = self.run_child(f"import sys; sys.stdout.buffer.write({payload!r})")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.raw.read_bytes(), payload)
        output = self.visible(result)
        for secret in secrets:
            self.assertNotIn(secret, output)
        self.assertIn("ordinary package installed", output)

    def test_password_split_across_writes_is_not_displayed(self) -> None:
        code = (
            "import sys,time; "
            "sys.stdout.write('password: StreamPrivatePrefix'); sys.stdout.flush(); "
            "time.sleep(0.25); sys.stdout.write('SecretSuffix\\n'); sys.stdout.flush()"
        )
        result = self.run_child(code)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(b"StreamPrivatePrefixSecretSuffix", self.raw.read_bytes())
        self.assertNotIn("StreamPrivatePrefix", self.visible(result))
        self.assertNotIn("SecretSuffix", self.visible(result))

    def test_known_credentials_are_hidden_and_reload_after_change(self) -> None:
        secret_file = self.work / "judge.conf"
        initial, changed = "AlphaValue987", "ChangedValue65432"
        secret_file.write_text(f"OJ_PASSWORD={initial}\n", encoding="utf-8")
        code = (
            "import time; from pathlib import Path; "
            f"print({initial!r},flush=True); time.sleep(0.25); "
            f"Path({str(secret_file)!r}).write_text('OJ_PASSWORD={changed}\\n'); "
            f"print({changed!r},flush=True); print('completed ordinary task',flush=True)"
        )
        command = self.command(code)
        split = command.index("--")
        command[split:split] = ["--secret-file", str(secret_file)]
        environment = os.environ.copy()
        environment.update(PYTHONUTF8="1", PYTHONIOENCODING="utf-8")
        result = subprocess.run(
            command, env=environment, capture_output=True, encoding="utf-8", timeout=8, check=False
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(initial.encode(), self.raw.read_bytes())
        self.assertIn(changed.encode(), self.raw.read_bytes())
        output = self.visible(result)
        self.assertNotIn(initial, output)
        self.assertNotIn(changed, output)
        self.assertIn("completed ordinary task", output)

    def test_silent_child_still_produces_heartbeat(self) -> None:
        result = self.run_child("import time; time.sleep(0.35)")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.raw.read_bytes(), b"")
        output = self.visible(result)
        self.assertRegex(output.lower(), r"elapsed")
        self.assertRegex(output.lower(), r"pid")
        self.assertTrue(self.progress.is_file())

    def test_output_arrives_before_child_exit(self) -> None:
        environment = os.environ.copy()
        environment.update(PYTHONUTF8="1", PYTHONIOENCODING="utf-8")
        process = subprocess.Popen(
            self.command("import time; print('compile started',flush=True); time.sleep(1.2)"),
            env=environment,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
        )
        self.addCleanup(lambda: process.kill() if process.poll() is None else None)
        observed: queue.Queue[str] = queue.Queue()

        def read_until_progress() -> None:
            assert process.stdout is not None
            for line in process.stdout:
                if "compile started" in line:
                    observed.put(line)
                    return

        threading.Thread(target=read_until_progress, daemon=True).start()
        self.assertIn("compile started", observed.get(timeout=3))
        self.assertIsNone(process.poll(), "Progress was buffered until the child had finished")
        process.communicate(timeout=4)
        self.assertEqual(process.returncode, 0)

    def test_heartbeat_does_not_echo_child_arguments(self) -> None:
        secret = "ArgumentOnlyPrivate987"
        result = self.run_child("import time; time.sleep(0.25)", "--password", secret)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn(secret, self.visible(result))

    def test_child_failure_exit_code_is_preserved(self) -> None:
        result = self.run_child("import sys; print('build failed'); sys.exit(7)")
        self.assertEqual(result.returncode, 7, result.stderr)
        self.assertIn("build failed", self.visible(result))
        self.assertRegex(self.visible(result).lower(), r"(?:exit|code|status)[^\n]*7")

    def test_large_output_is_fully_drained_with_a_slow_terminal(self) -> None:
        line = b"compile " + b"x" * 1000 + b"\n"
        marker = b"ALL_OUTPUT_DRAINED_END\n"
        expected = line * 400 + marker
        code = (
            f"import sys; line={line!r}; "
            f"sys.stdout.buffer.write(line*400+{marker!r}); sys.stdout.buffer.flush()"
        )
        environment = os.environ.copy()
        environment.update(PYTHONUTF8="1", PYTHONIOENCODING="utf-8")
        process = subprocess.Popen(
            self.command(code), env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE
        )
        self.addCleanup(lambda: process.kill() if process.poll() is None else None)
        chunks: list[bytes] = []

        def slowly_read_terminal() -> None:
            assert process.stdout is not None
            while True:
                chunk = process.stdout.read(4096)
                if not chunk:
                    return
                chunks.append(chunk)
                time.sleep(0.035)

        started = time.monotonic()
        reader = threading.Thread(target=slowly_read_terminal, daemon=True)
        reader.start()
        process.wait(timeout=12)
        reader.join(timeout=2)
        self.assertFalse(reader.is_alive(), "Terminal did not drain to EOF")
        assert process.stdout is not None and process.stderr is not None
        error = process.stderr.read().decode("utf-8", errors="replace")
        process.stdout.close()
        process.stderr.close()
        self.assertEqual(process.returncode, 0, error)
        self.assertGreater(time.monotonic() - started, 2)
        raw_bytes = self.raw.read_bytes()
        self.assertEqual(len(raw_bytes), len(expected))
        self.assertEqual(hashlib.sha256(raw_bytes).digest(), hashlib.sha256(expected).digest())
        self.assertTrue(marker.rstrip(b"\n") in b"".join(chunks), "Final output marker missing")
        self.assertIn(marker.decode(), self.progress.read_text(encoding="utf-8"))

    def test_invalid_options_do_not_start_the_child(self) -> None:
        marker = self.work / "child-started"
        code = f"from pathlib import Path; Path({str(marker)!r}).write_text('started')"
        command = self.command(code, heartbeat="0")
        result = subprocess.run(command, capture_output=True, timeout=8, check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(marker.exists())

    def test_same_raw_and_progress_path_is_rejected_before_child(self) -> None:
        marker = self.work / "child-started"
        code = f"from pathlib import Path; Path({str(marker)!r}).write_text('started')"
        command = self.command(code)
        command[command.index("--progress-log") + 1] = str(self.raw)
        result = subprocess.run(command, capture_output=True, timeout=8, check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(marker.exists())

    @unittest.skipUnless(os.name == "posix", "POSIX process-group signals are required")
    def test_interrupt_stops_the_child_process(self) -> None:
        marker = self.work / "child.pid"
        code = (
            "import os,time; from pathlib import Path; "
            f"Path({str(marker)!r}).write_text(str(os.getpid())); time.sleep(20)"
        )
        process = subprocess.Popen(self.command(code), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.addCleanup(lambda: process.kill() if process.poll() is None else None)
        deadline = time.monotonic() + 3
        while not marker.exists() and process.poll() is None and time.monotonic() < deadline:
            time.sleep(0.02)
        self.assertTrue(marker.exists(), "Synthetic child did not start")
        child_pid = int(marker.read_text())
        process.send_signal(signal.SIGINT)
        process.communicate(timeout=5)
        deadline = time.monotonic() + 2
        while time.monotonic() < deadline:
            try:
                os.kill(child_pid, 0)
            except ProcessLookupError:
                break
            time.sleep(0.02)
        else:
            self.fail("Child survived the runner interrupt")
        self.assertNotEqual(process.returncode, 0)


if __name__ == "__main__":
    unittest.main(verbosity=2)
