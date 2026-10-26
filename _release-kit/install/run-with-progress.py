#!/usr/bin/env python3
"""Keep the raw install log private and display redacted, live progress."""
from __future__ import annotations

import argparse
import codecs
import math
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
import time


ANSI = re.compile(r"\x1b(?:\[[0-?]*[ -/]*[@-~]|\][^\x07]*(?:\x07|\x1b\\)|[@-Z\\-_])")
SENSITIVE = re.compile(r"password|passwd|db_pass|oj_password|identified\s+by|authorization|secret|token", re.I)
MAX_LINE = 65536


class Redactor:
    def __init__(self, secret_file: Path) -> None:
        self.secret_file = secret_file
        self.fingerprint = None
        self.values: list[str] = []

    def line(self, text: str) -> str:
        text = ANSI.sub("", text)
        text = "".join(char for char in text if char.isprintable() or char == "\t")
        try:
            info = self.secret_file.stat()
            fingerprint = (info.st_mtime_ns, info.st_size)
            if fingerprint != self.fingerprint and not self.secret_file.is_symlink() and info.st_size <= 16384:
                values = []
                for line in self.secret_file.read_text(encoding="utf-8").splitlines():
                    if "=" not in line or line.lstrip().startswith("#"):
                        continue
                    key, value = line.split("=", 1)
                    if SENSITIVE.search(key) and value.strip():
                        values.append(value.strip().strip("\"'"))
                self.values = values
                self.fingerprint = fingerprint
        except (OSError, UnicodeError):
            pass
        if SENSITIVE.search(text) or any(value and value in text for value in self.values):
            return "[HUSTOJ] [credential output hidden]"
        return text


def private_log(path: Path):
    if path.is_symlink():
        raise ValueError("Log must be a regular file")
    flags = os.O_WRONLY | os.O_CREAT | os.O_APPEND | getattr(os, "O_NOFOLLOW", 0)
    descriptor = os.open(path, flags, 0o600)
    try:
        if not stat.S_ISREG(os.fstat(descriptor).st_mode):
            raise ValueError("Log must be a regular file")
        if os.name == "posix":
            os.fchmod(descriptor, 0o600)
        return os.fdopen(descriptor, "wb", buffering=0)
    except BaseException:
        os.close(descriptor)
        raise


def process_snapshot(pid: int) -> str:
    pending = [pid]
    seen = set()
    names = []
    state = "alive"
    try:
        status = Path(f"/proc/{pid}/status").read_text()
        for line in status.splitlines():
            if line.startswith("State:"):
                state = line.partition(":")[2].strip().split()[0]
                break
    except OSError:
        pass
    while pending and len(seen) < 32 and len(names) < 8:
        current = pending.pop(0)
        if current in seen:
            continue
        seen.add(current)
        try:
            name = Path(f"/proc/{current}/comm").read_text().strip()
            name = "".join(char for char in name if char.isalnum() or char in "-_.+")[:30]
            if name and name not in names:
                names.append(name)
            children = Path(f"/proc/{current}/task/{current}/children").read_text().split()
            pending.extend(int(child) for child in children if child.isdigit())
        except OSError:
            continue
    return f"state={state} tasks={','.join(names) or 'process'}"


def stop_process(process: subprocess.Popen, *, force: bool = False) -> None:
    try:
        if os.name == "posix":
            os.killpg(process.pid, signal.SIGKILL if force else signal.SIGTERM)
        elif process.poll() is None:
            if force:
                process.kill()
            else:
                process.terminate()
    except ProcessLookupError:
        pass


def cleanup_process(process: subprocess.Popen) -> None:
    stop_process(process)
    deadline = time.monotonic() + 3
    while time.monotonic() < deadline:
        process.poll()
        if os.name == "posix":
            try:
                os.killpg(process.pid, 0)
            except ProcessLookupError:
                break
        elif process.poll() is not None:
            break
        time.sleep(0.05)
    stop_process(process, force=True)
    process.wait(timeout=5)


def run(args: argparse.Namespace) -> int:
    if args.raw_log.resolve() == args.progress_log.resolve():
        raise ValueError("Separate log files are required")
    if args.raw_log.exists() and args.progress_log.exists() and os.path.samefile(args.raw_log, args.progress_log):
        raise ValueError("Separate log files are required")
    redactor = Redactor(args.secret_file)
    with private_log(args.raw_log) as raw, private_log(args.progress_log) as progress, args.raw_log.open("rb", buffering=0) as tail:
        def emit(line: str) -> None:
            safe = redactor.line(line)
            progress.write((safe + "\n").encode("utf-8"))
            print(safe, flush=True)

        started = time.monotonic()
        tail.seek(os.fstat(raw.fileno()).st_size)
        # The child writes to the regular private log directly. Services it starts
        # can keep that descriptor without holding a pipe open or losing output.
        interrupted = [0]
        previous_handlers = {}
        completed = False

        def interrupt(signum, _frame) -> None:
            force = bool(interrupted[0])
            interrupted[0] = signum
            stop_process(process, force=force)

        process = subprocess.Popen(args.command, stdout=raw, stderr=subprocess.STDOUT,
                                   stdin=subprocess.DEVNULL, bufsize=0, start_new_session=os.name == "posix")
        try:
            for signum in (signal.SIGINT, signal.SIGTERM):
                previous_handlers[signum] = signal.signal(signum, interrupt)
            emit(f"[HUSTOJ] started pid={process.pid}; live output below")
            decoder = codecs.getincrementaldecoder("utf-8")("replace")
            buffer = ""
            dropping = False
            stopped_at = None
            last_output = started
            next_heartbeat = started + args.heartbeat
            end_position = None
            while True:
                status = process.poll()
                if status is not None and end_position is None:
                    # Snapshot after the child exited, then drain all of its
                    # remaining bytes even when the terminal consumes slowly.
                    end_position = os.fstat(raw.fileno()).st_size
                remaining = 4096 if end_position is None else end_position - tail.tell()
                if remaining <= 0:
                    break
                payload = tail.read(min(4096, remaining))
                if payload:
                    last_output = time.monotonic()
                    text = decoder.decode(payload).replace("\r", "\n")
                    for part in text.splitlines(keepends=True):
                        complete = part.endswith("\n")
                        if not dropping:
                            buffer += part.rstrip("\n") if complete else part
                            if len(buffer) > MAX_LINE:
                                emit("[HUSTOJ] long output line retained in the private raw log")
                                buffer = ""
                                dropping = True
                        if complete:
                            if not dropping:
                                emit(buffer)
                            buffer = ""
                            dropping = False
                now = time.monotonic()
                if process.poll() is None and now >= next_heartbeat:
                    emit(f"[HUSTOJ] elapsed={int(now-started)}s pid={process.pid} "
                         f"silent={int(now-last_output)}s {process_snapshot(process.pid)}")
                    next_heartbeat = now + args.heartbeat
                if interrupted[0] and stopped_at is None:
                    stopped_at = now
                if stopped_at is not None and now - stopped_at > 3:
                    stop_process(process, force=True)
                if not payload:
                    if status is not None:
                        break
                    time.sleep(min(0.1, max(0.01, next_heartbeat - now)))
            buffer += decoder.decode(b"", final=True)
            if buffer and not dropping:
                emit(buffer)
            status = process.wait()
            code = 128 + interrupted[0] if interrupted[0] else (128 - status if status < 0 else status)
            completed = True
        finally:
            try:
                if not completed or interrupted[0] or process.poll() is None:
                    cleanup_process(process)
            finally:
                for signum, previous in previous_handlers.items():
                    signal.signal(signum, previous)
        emit(f"[HUSTOJ] finished exit_code={code} elapsed={int(time.monotonic()-started)}s")
        return code


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--raw-log", type=Path, required=True)
    parser.add_argument("--progress-log", type=Path, required=True)
    parser.add_argument("--secret-file", type=Path, default=Path("/home/judge/etc/judge.conf"))
    parser.add_argument("--heartbeat", type=float, default=10)
    parser.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if args.command and args.command[0] == "--":
        args.command.pop(0)
    if not args.command or not math.isfinite(args.heartbeat) or not 0 < args.heartbeat <= 3600:
        parser.error("A command and positive heartbeat (up to 3600 seconds) are required")
    try:
        return run(args)
    except Exception:
        print("[HUSTOJ] log runner failed; inspect the private raw log", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
