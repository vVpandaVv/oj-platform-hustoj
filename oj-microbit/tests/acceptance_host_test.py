"""Offline command/metadata mocks only: these tests do not certify a live host."""
import copy
from contextlib import contextmanager, ExitStack
import importlib.util
import io
import json
import os
from pathlib import Path, PurePosixPath
from types import SimpleNamespace
import unittest
from unittest.mock import patch, Mock

SPEC = importlib.util.spec_from_file_location("microbit_host_check", Path(__file__).resolve().parents[1] / "scripts/acceptance/host_check.py")
host = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(host)
ID, IMAGE, NETWORK, ENDPOINT = "a" * 64, "b" * 64, "c" * 64, "d" * 64
UNIT = "/usr/lib/systemd/system/docker.service"


def raw_container():
    return {"id": ID, "name": "/hustoj-web", "imageId": "sha256:" + IMAGE, "image": "hustoj/web:stable", "running": True,
            "restarting": False, "startedAt": "2026-10-28T01:00:00.123456789Z", "restartCount": 0, "networkMode": "bridge",
            "mounts": [{"Type": "volume", "Destination": "/var/lib/mysql", "RW": True, "Source": "/SECRET_MOUNT_SOURCE", "Name": "SECRET_VOLUME_NAME"}],
            "networks": {"hustoj-default": {"NetworkID": NETWORK, "EndpointID": ENDPOINT, "IPAddress": "PRIVATE_IP"}},
            "Env": ["PASSWORD=SECRET_ENV"]}


@contextmanager
def mocked_host(active=True, container=None, rootless=False, changing_daemon=False):
    commands = []
    system_calls = 0

    def run(args):
        nonlocal system_calls
        commands.append(args)
        if args[0] == "systemctl":
            system_calls += 1
            pid = 222 if changing_daemon and system_calls >= 4 else 111
            return f"ActiveState={'active' if active else 'inactive'}\nMainPID={pid}\nExecMainStartTimestampMonotonic=100000\nFragmentPath={UNIT}"
        if args[:2] == ["docker", "info"]:
            return json.dumps({"id": "engine-id", "serverVersion": "28.5.1", "dockerRootDir": "/var/lib/docker", "securityOptions": ["name=rootless"] if rootless else ["name=seccomp"], "cpus": 4, "memoryBytes": 8000000000})
        if args[:3] == ["docker", "container", "inspect"]:
            return json.dumps(container or raw_container())
        raise AssertionError("Unexpected command boundary")

    def text(path, *args, **kwargs):
        value = path.as_posix()
        if value.startswith("/proc/") and value.endswith("/status"):
            return "Name:\tdockerd\nUid:\t0\t0\t0\t0\n"
        if value == "/proc/meminfo":
            return "MemTotal: 8000000 kB\nMemAvailable: 4000000 kB\nSwapTotal: 0 kB\nSwapFree: 0 kB\n"
        raise AssertionError("Unexpected file boundary")

    with ExitStack() as stack:
        stack.enter_context(patch.object(host, "run", side_effect=run))
        stack.enter_context(patch.object(host, "validate_host", return_value={"os": "ubuntu", "version": "24.04", "architecture": "amd64", "wordpressRoot": "/home/judge/src/web", "phpVersion": "8.3.25"}))
        stack.enter_context(patch.object(host.Path, "read_text", autospec=True, side_effect=text))
        stack.enter_context(patch.object(host, "protected_file", return_value=b"a" * 32))
        stack.enter_context(patch.object(host, "configuration_hashes", return_value={UNIT: "e" * 64, "/etc/docker/daemon.json": None}))
        stack.enter_context(patch.object(host, "filesystem", return_value={"totalBytes": 16000000000, "availableBytes": 8000000000}))
        stack.enter_context(patch.object(host.os, "cpu_count", return_value=4))
        yield commands


class HostEvidenceTests(unittest.TestCase):
    def collect(self, label="before", **options):
        with mocked_host(**options):
            return host.snapshot(label, Path("/home/judge/src/web"), ["hustoj-web"])

    def test_snapshot_only_uses_specific_readonly_commands_and_redacts_private_mount_data(self):
        with mocked_host() as commands:
            report = host.snapshot("before", Path("/home/judge/src/web"), ["hustoj-web"])
            host.validate_snapshot(report)
        self.assertEqual(report["scope"], "infrastructure_metadata_only_not_live_acceptance")
        self.assertEqual(report["containers"][0]["id"], ID)
        self.assertNotIn("SECRET", json.dumps(report))
        self.assertNotIn("PRIVATE_IP", json.dumps(report))
        for args in commands:
            self.assertIn(args[0], ("docker", "systemctl"))
            if args[0] == "docker":
                self.assertTrue(args[1] == "info" or args[1:3] == ["container", "inspect"])
                self.assertNotIn(".Config.Env", " ".join(args))
        inspected = [args for args in commands if args[:3] == ["docker", "container", "inspect"]]
        self.assertEqual(len(inspected), 1)
        self.assertEqual(inspected[0][-1], "hustoj-web")

    def test_inactive_daemon_never_sends_a_socket_activating_docker_request(self):
        with mocked_host(active=False) as commands:
            with self.assertRaisesRegex(host.CheckError, "already_be_active"):
                host.snapshot("before", Path("/home/judge/src/web"), ["hustoj-web"])
        self.assertFalse(any(args[0] == "docker" for args in commands))

    def test_partial_id_unknown_identity_and_stopped_container_fail_closed(self):
        with mocked_host():
            with self.assertRaisesRegex(host.CheckError, "partial_container"):
                host.container_metadata(ID[:12])
        for mutation in [{"id": "unknown"}, {"running": False}, {"imageId": "missing"}]:
            value = raw_container(); value.update(mutation)
            with mocked_host(container=value):
                with self.assertRaises(host.CheckError):
                    host.container_metadata("hustoj-web")

    def test_host_network_with_no_endpoint_is_explicitly_identified(self):
        value = raw_container(); value["networkMode"] = "host"
        value["networks"] = {"host": {"NetworkID": NETWORK, "EndpointID": ""}}
        report = self.collect(container=value)
        host.validate_snapshot(report)
        self.assertEqual(report["containers"][0]["networkMode"], "host")

    def test_rootless_or_restarted_daemon_cannot_produce_a_snapshot(self):
        for options in [{"rootless": True}, {"changing_daemon": True}]:
            with self.assertRaises(host.CheckError):
                self.collect(**options)

    def test_comparison_matches_only_recorded_metadata_not_live_acceptance(self):
        before, after = self.collect(), self.collect("after")
        after["resources"]["MemAvailableKiB"] = 2000000
        result = host.compare(before, after)
        self.assertTrue(result["matched"])
        self.assertIn("not_live_acceptance", result["scope"])

    def test_identity_start_restart_image_mount_network_and_configuration_changes_are_detected(self):
        before = self.collect()
        for section, mutation in [
            ("containers", lambda r: r["containers"][0].update({"id": "f" * 64})),
            ("containers", lambda r: r["containers"][0].update({"startedAt": "2026-10-28T02:00:00Z"})),
            ("containers", lambda r: r["containers"][0].update({"restartCount": 1})),
            ("containers", lambda r: r["containers"][0].update({"imageId": "sha256:" + "f" * 64})),
            ("containers", lambda r: r["containers"][0]["mounts"][0].update({"sourceHash": "f" * 64})),
            ("containers", lambda r: r["containers"][0]["networks"][0].update({"endpointId": "f" * 64})),
            ("daemon", lambda r: r["daemon"].update({"pid": 222})),
            ("configurationHashes", lambda r: r["configurationHashes"].update({"/etc/docker/daemon.json": "f" * 64})),
        ]:
            after = copy.deepcopy(before); after["label"] = "after"; mutation(after)
            result = host.compare(before, after)
            self.assertFalse(result["matched"])
            self.assertIn(section, result["changedSections"])

    def test_incomplete_cross_host_and_wrong_label_reports_are_refused(self):
        before = self.collect()
        for mutation in [lambda r: r.pop("daemon"), lambda r: r.update({"hostIdentityHash": "f" * 64}),
                         lambda r: r.update({"label": "before"}), lambda r: r["containers"][0].pop("mounts"),
                         lambda r: r["resources"].update({"Env": "SECRET"})]:
            after = copy.deepcopy(before); after["label"] = "after"; mutation(after)
            with self.assertRaises((host.CheckError, KeyError)):
                host.compare(before, after)

    def test_report_creation_uses_exclusive_no_follow_and_private_mode(self):
        stream = Mock(); stream.__enter__ = Mock(return_value=stream); stream.__exit__ = Mock(return_value=False)
        with patch.object(host, "report_directory", return_value=7), patch.object(host.os, "open", return_value=8) as opened, \
             patch.object(host.os, "fdopen", return_value=stream), patch.object(host.os, "fsync"), patch.object(host.os, "close"), \
             patch.object(host.os, "O_NOFOLLOW", 0x20000, create=True):
            host.write_report(Path("/root/private/before.json"), {"safe": True})
        args, kwargs = opened.call_args
        self.assertTrue(args[1] & os.O_EXCL)
        self.assertTrue(args[1] & 0x20000)
        self.assertEqual(args[2], 0o600)
        self.assertEqual(kwargs["dir_fd"], 7)

    def test_existing_file_or_symlink_is_never_overwritten(self):
        with patch.object(host, "report_directory", return_value=7), patch.object(host.os, "open", side_effect=FileExistsError), \
             patch.object(host.os, "close"), patch.object(host.os, "O_NOFOLLOW", 0x20000, create=True):
            with self.assertRaises(FileExistsError):
                host.write_report(Path("/root/private/before.json"), {})

    def test_directory_walker_rejects_symlinks_and_non_root_writable_ancestors(self):
        with patch.object(host.os, "O_DIRECTORY", 0x10000, create=True), patch.object(host.os, "O_NOFOLLOW", 0x20000, create=True), \
             patch.object(host.os, "open", side_effect=[3, OSError("symlink")]), patch.object(host.os, "close"):
            with self.assertRaises(OSError):
                host.report_directory(PurePosixPath("/root/private"), False)
        with patch.object(host.os, "O_DIRECTORY", 0x10000, create=True), patch.object(host.os, "O_NOFOLLOW", 0x20000, create=True), \
             patch.object(host.os, "open", side_effect=[3, 4]), patch.object(host.os, "close"), \
             patch.object(host.os, "fstat", return_value=SimpleNamespace(st_uid=1000, st_mode=0o777)):
            with self.assertRaisesRegex(host.CheckError, "unsafe_report_directory"):
                host.report_directory(PurePosixPath("/root/private"), False)

    def test_input_reports_require_root_private_regular_single_link_files(self):
        report = self.collect()
        for change in [{"st_uid": 1000}, {"st_mode": 0o100644}, {"st_nlink": 2}]:
            attributes = {"st_uid": 0, "st_mode": 0o100600, "st_nlink": 1, "st_size": 100}
            attributes.update(change)
            stream = Mock(); stream.__enter__ = Mock(return_value=stream); stream.__exit__ = Mock(return_value=False)
            stream.read.return_value = json.dumps(report).encode("utf-8")
            with patch.object(host, "report_directory", return_value=7), patch.object(host.os, "open", return_value=8), \
                 patch.object(host.os, "fdopen", return_value=stream), patch.object(host.os, "close"), \
                 patch.object(host.os, "fstat", return_value=SimpleNamespace(**attributes)), \
                 patch.object(host.os, "O_NOFOLLOW", 0x20000, create=True):
                with self.assertRaisesRegex(host.CheckError, "unsafe_input_report"):
                    host.read_report(Path("/root/private/before.json"))


if __name__ == "__main__":
    unittest.main()
