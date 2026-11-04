"""Private, read-only Ubuntu/rootful-Docker before/after evidence; not live acceptance."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
from datetime import datetime, timezone

TOOL = "oj-microbit-host-check"
MAX_REPORT = 1024 * 1024
ENV = {"PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin", "LC_ALL": "C", "DOCKER_HOST": "unix:///var/run/docker.sock"}
INFO_FORMAT = '{"id":{{json .ID}},"serverVersion":{{json .ServerVersion}},"dockerRootDir":{{json .DockerRootDir}},"securityOptions":{{json .SecurityOptions}},"cpus":{{json .NCPU}},"memoryBytes":{{json .MemTotal}}}'
CONTAINER_FORMAT = '{"id":{{json .Id}},"name":{{json .Name}},"imageId":{{json .Image}},"image":{{json .Config.Image}},"running":{{json .State.Running}},"restarting":{{json .State.Restarting}},"startedAt":{{json .State.StartedAt}},"restartCount":{{json .RestartCount}},"networkMode":{{json .HostConfig.NetworkMode}},"mounts":{{json .Mounts}},"networks":{{json .NetworkSettings.Networks}}}'


class CheckError(Exception):
    """Only a constant safe error code reaches the terminal."""


def require(condition: bool, code: str) -> None:
    if not condition:
        raise CheckError(code)


def run(arguments: list[str]) -> str:
    try:
        result = subprocess.run(arguments, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, timeout=15, env=ENV, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise CheckError("host_command_unavailable") from None
    require(result.returncode == 0 and len(result.stdout) <= MAX_REPORT, "host_command_failed")
    try:
        return result.stdout.decode("utf-8").strip()
    except UnicodeError:
        raise CheckError("host_command_invalid_output") from None


def safe_text(value: object, maximum: int = 4096) -> str:
    require(isinstance(value, str) and 0 < len(value) <= maximum
            and not re.search(r"[\x00-\x1f\x7f]", value), "invalid_metadata")
    return value


def number(value: object, minimum: int = 0) -> int:
    require(type(value) is int and minimum <= value <= 2**63 - 1, "invalid_metadata")
    return value


def digest(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def json_object(value: str) -> dict:
    try:
        result = json.loads(value)
    except (ValueError, TypeError):
        raise CheckError("invalid_json_metadata") from None
    require(isinstance(result, dict), "invalid_json_metadata")
    return result


def protected_file(path: Path, maximum: int = MAX_REPORT, private: bool = False) -> bytes:
    """No symlink, special file, hardlink or unbounded content is accepted."""
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, "rb") as stream:
            info = os.fstat(stream.fileno())
            require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
                    and info.st_size <= maximum and not info.st_mode & (0o077 if private else 0o022), "unsafe_input_file")
            result = stream.read(maximum + 1)
            require(len(result) <= maximum, "input_file_too_large")
            return result
    except (OSError, ValueError):
        raise CheckError("input_file_unavailable") from None


def report_directory(path: Path, create: bool) -> int:
    """Walk root-owned ancestors using directory FDs, refusing symlinks/races."""
    require(path.is_absolute() and not any(part in (".", "..") for part in path.parts)
            and not re.search(r"[\x00-\x1f\x7f]", str(path)), "unsafe_report_path")
    current = os.open("/", os.O_RDONLY | os.O_DIRECTORY)
    try:
        for part in path.parts[1:]:
            try:
                child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=current)
            except FileNotFoundError:
                require(create, "report_directory_missing")
                os.mkdir(part, 0o700, dir_fd=current)
                child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=current)
            info = os.fstat(child)
            if info.st_uid != 0 or info.st_mode & 0o022:
                os.close(child)
                raise CheckError("unsafe_report_directory")
            os.close(current)
            current = child
        require(not os.fstat(current).st_mode & 0o077, "report_directory_must_be_private")
        return current
    except BaseException:
        os.close(current)
        raise


def write_report(path: Path, data: dict) -> None:
    require(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\.json", path.name) is not None, "unsafe_report_filename")
    parent = report_directory(path.parent, True)
    try:
        # O_EXCL also rejects a pre-existing or dangling symbolic link.
        fd = os.open(path.name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
        with os.fdopen(fd, "wb") as stream:
            stream.write((json.dumps(data, ensure_ascii=False, sort_keys=True, indent=2) + "\n").encode("utf-8"))
            stream.flush()
            os.fsync(stream.fileno())
    finally:
        os.close(parent)


def read_report(path: Path) -> dict:
    parent = report_directory(path.parent, False)
    try:
        fd = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=parent)
        with os.fdopen(fd, "rb") as stream:
            info = os.fstat(stream.fileno())
            require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
                    and not info.st_mode & 0o077 and info.st_size <= MAX_REPORT, "unsafe_input_report")
            content = stream.read(MAX_REPORT + 1)
            require(len(content) <= MAX_REPORT, "input_report_too_large")
            data = json_object(content.decode("utf-8"))
    finally:
        os.close(parent)
    require(data.get("tool") == TOOL and data.get("formatVersion") == 1 and data.get("kind") == "snapshot", "invalid_snapshot")
    return data


def docker_service() -> dict:
    # This check precedes every Docker API contact; an inactive socket-activated
    # daemon must never be awakened just by collecting evidence.
    values = run(["systemctl", "show", "docker.service", "--property=ActiveState", "--property=MainPID", "--property=ExecMainStartTimestampMonotonic", "--property=FragmentPath"])
    data = dict(line.split("=", 1) for line in values.splitlines() if "=" in line)
    require(data.get("ActiveState") == "active", "docker_daemon_must_already_be_active")
    require(re.fullmatch(r"[1-9][0-9]*", data.get("MainPID", "")) is not None, "docker_daemon_unidentified")
    require(re.fullmatch(r"[0-9]+", data.get("ExecMainStartTimestampMonotonic", "")) is not None, "docker_daemon_unidentified")
    return {"pid": int(data["MainPID"]), "startMonotonic": data["ExecMainStartTimestampMonotonic"], "unitFile": safe_text(data.get("FragmentPath"))}


def docker(arguments: list[str]) -> str:
    docker_service()
    return run(["docker", *arguments])


def container_metadata(reference: str) -> dict:
    require(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,127}", reference) is not None, "invalid_container_reference")
    raw = json_object(docker(["container", "inspect", "--format", CONTAINER_FORMAT, reference]))
    identifier = safe_text(raw.get("id"), 64)
    require(re.fullmatch(r"[a-f0-9]{64}", identifier) is not None, "container_unidentified")
    name = safe_text(raw.get("name"), 129)
    require(reference == identifier or name == "/" + reference, "partial_container_id_refused")
    require(raw.get("running") is True and raw.get("restarting") is False, "hustoj_container_not_running")
    image_id = safe_text(raw.get("imageId"), 71)
    require(re.fullmatch(r"sha256:[a-f0-9]{64}", image_id) is not None, "container_image_unidentified")
    image = safe_text(raw.get("image"), 256)
    require(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.:/@-]*", image) is not None, "container_image_unidentified")
    mounts = raw.get("mounts")
    networks = raw.get("networks")
    require(isinstance(mounts, list) and len(mounts) <= 64 and isinstance(networks, dict) and len(networks) <= 32, "invalid_container_metadata")
    safe_mounts = []
    for mount in mounts:
        require(isinstance(mount, dict) and mount.get("Type") in ("bind", "volume", "tmpfs") and type(mount.get("RW")) is bool, "invalid_mount_metadata")
        destination = safe_text(mount.get("Destination"))
        require(destination.startswith("/"), "invalid_mount_metadata")
        source = mount.get("Source", "")
        volume = mount.get("Name", "")
        require(isinstance(source, str) and isinstance(volume, str) and len(source) <= 4096 and len(volume) <= 255, "invalid_mount_metadata")
        safe_mounts.append({"type": mount["Type"], "destination": destination, "readWrite": mount["RW"], "sourceHash": digest(source), "volumeNameHash": digest(volume)})
    network_mode = safe_text(raw.get("networkMode"), 255)
    safe_networks = []
    for name, network in networks.items():
        require(isinstance(network, dict), "invalid_network_metadata")
        network_id = safe_text(network.get("NetworkID"), 64)
        endpoint = network.get("EndpointID", "")
        require(isinstance(endpoint, str) and (re.fullmatch(r"[a-f0-9]{64}", endpoint) is not None
                or endpoint == "" and network_mode in ("host", "none") and name == network_mode), "network_unidentified")
        require(re.fullmatch(r"[a-f0-9]{64}", network_id) is not None, "network_unidentified")
        safe_networks.append({"name": safe_text(name, 255), "id": network_id, "endpointId": endpoint})
    return {"reference": reference, "id": identifier, "name": name, "imageId": image_id, "image": image,
            "running": True, "startedAt": safe_text(raw.get("startedAt"), 64), "restartCount": number(raw.get("restartCount")),
            "networkMode": network_mode, "mounts": sorted(safe_mounts, key=lambda item: (item["destination"], item["type"])), "networks": sorted(safe_networks, key=lambda item: item["name"])}


def configuration_hashes(unit: str) -> dict:
    paths = {Path("/etc/docker/daemon.json"), Path("/etc/default/docker"), Path("/etc/apt/sources.list"), Path(unit)}
    for directory, pattern in [("/etc/systemd/system/docker.service.d", "*.conf"), ("/etc/systemd/system/docker.socket.d", "*.conf"),
                               ("/etc/apt/sources.list.d", "*.list"), ("/etc/apt/sources.list.d", "*.sources"), ("/etc/apt/keyrings", "*"), ("/usr/share/keyrings", "*docker*")]:
        entries = list(Path(directory).glob(pattern))
        require(len(entries) <= 128, "configuration_inventory_too_large")
        paths.update(entries)
    return {str(path): hashlib.sha256(protected_file(path)).hexdigest() if path.exists() or path.is_symlink() else None for path in sorted(paths)}


def filesystem(path: Path) -> dict:
    while not path.exists():
        require(path.parent != path, "filesystem_unavailable")
        path = path.parent
    info = os.statvfs(path)
    return {"totalBytes": info.f_blocks * info.f_frsize, "availableBytes": info.f_bavail * info.f_frsize}


def validate_host(wp_root: Path) -> dict:
    require(os.geteuid() == 0 and sys.platform.startswith("linux"), "ubuntu_root_required")
    # Ubuntu's /etc/os-release is normally a symlink; use its known vendor file.
    release = dict(line.split("=", 1) for line in protected_file(Path("/usr/lib/os-release")).decode("utf-8").splitlines() if "=" in line)
    require(release.get("ID", "").strip('"') == "ubuntu" and release.get("VERSION_ID", "").strip('"') == "24.04"
            and run(["dpkg", "--print-architecture"]) == "amd64", "unsupported_host")
    require(wp_root.is_absolute() and not re.search(r"[\x00-\x1f\x7f]", str(wp_root)), "invalid_wordpress_path")
    for name in ("wp-load.php", "wp-config.php"):
        info = (wp_root / name).lstat()
        require(stat.S_ISREG(info.st_mode), "native_wordpress_required")
    php = run(["/usr/bin/php8.3", "-n", "--version"])
    require(php.startswith("PHP 8.3."), "php_83_required")
    return {"os": "ubuntu", "version": "24.04", "architecture": "amd64", "wordpressRoot": str(wp_root.resolve()), "phpVersion": php.splitlines()[0].split()[1]}


def snapshot(label: str, wp_root: Path, references: list[str]) -> dict:
    platform = validate_host(wp_root)
    require(1 <= len(references) <= 8 and len(set(references)) == len(references), "explicit_hustoj_containers_required")
    service = docker_service()
    uid_line = next((line for line in Path(f"/proc/{service['pid']}/status").read_text().splitlines() if line.startswith("Uid:")), "")
    require(len(uid_line.split()) == 5 and set(uid_line.split()[1:]) == {"0"}, "rootful_docker_required")
    info = json_object(docker(["info", "--format", INFO_FORMAT]))
    require(isinstance(info.get("securityOptions"), list) and not any("rootless" in str(item) for item in info["securityOptions"]), "rootful_docker_required")
    daemon = {"id": safe_text(info.get("id"), 128), "version": safe_text(info.get("serverVersion"), 64), "dataRoot": safe_text(info.get("dockerRootDir")), **service}
    require(daemon["dataRoot"].startswith("/"), "docker_data_root_unidentified")
    containers = [container_metadata(reference) for reference in references]
    require(len({item["id"] for item in containers}) == len(containers), "duplicate_container_identity")
    meminfo = {}
    for line in Path("/proc/meminfo").read_text().splitlines():
        key, _, value = line.partition(":")
        if key in ("MemTotal", "MemAvailable", "SwapTotal", "SwapFree"):
            require(re.fullmatch(r"\s*[0-9]+ kB", value) is not None, "invalid_memory_metadata")
            meminfo[key + "KiB"] = int(value.split()[0])
    require(len(meminfo) == 4 and os.cpu_count() is not None, "invalid_memory_metadata")
    machine = protected_file(Path("/etc/machine-id"), 128).decode("ascii").strip()
    require(re.fullmatch(r"[a-f0-9]{32}", machine) is not None, "host_identity_unavailable")
    require(docker_service() == service, "docker_daemon_changed_during_snapshot")
    return {"tool": TOOL, "formatVersion": 1, "kind": "snapshot", "label": label, "observedAt": datetime.now(timezone.utc).isoformat(),
            "scope": "infrastructure_metadata_only_not_live_acceptance", "hostIdentityHash": digest(machine), "platform": platform,
            "daemon": daemon, "configurationHashes": configuration_hashes(service["unitFile"]), "containers": sorted(containers, key=lambda item: item["id"]),
            "resources": {"cpuCount": os.cpu_count(), **meminfo, "installationFilesystem": filesystem(Path("/opt/oj-microbit")), "dockerFilesystem": filesystem(Path(daemon["dataRoot"]))}}


def compare(before: dict, after: dict) -> dict:
    require(before.get("label") == "before" and after.get("label") == "after", "snapshot_labels_must_be_before_after")
    for data in (before, after):
        validate_snapshot(data)
    require(before["hostIdentityHash"] == after["hostIdentityHash"], "different_hosts_refused")
    changed = [key for key in ("platform", "daemon", "configurationHashes", "containers") if before[key] != after[key]]
    return {"tool": TOOL, "formatVersion": 1, "kind": "comparison", "matched": not changed, "changedSections": changed,
            "scope": "infrastructure_metadata_only_not_live_acceptance", "resourcesBefore": before["resources"], "resourcesAfter": after["resources"]}


def validate_snapshot(data: dict) -> None:
    require(data.get("tool") == TOOL and data.get("kind") == "snapshot" and data.get("formatVersion") == 1
            and data.get("scope") == "infrastructure_metadata_only_not_live_acceptance", "invalid_snapshot")
    require(re.fullmatch(r"[a-f0-9]{64}", safe_text(data.get("hostIdentityHash"), 64)) is not None, "invalid_host_identity")
    platform = data.get("platform")
    require(isinstance(platform, dict) and platform.get("os") == "ubuntu" and platform.get("version") == "24.04"
            and platform.get("architecture") == "amd64", "invalid_snapshot_platform")
    safe_text(platform.get("wordpressRoot"))
    require(safe_text(platform.get("phpVersion"), 64).startswith("8.3."), "invalid_snapshot_platform")
    daemon = data.get("daemon")
    require(isinstance(daemon, dict), "incomplete_snapshot")
    for key in ("id", "version", "dataRoot", "startMonotonic", "unitFile"):
        safe_text(daemon.get(key))
    require(daemon["dataRoot"].startswith("/") and daemon["unitFile"].startswith("/")
            and re.fullmatch(r"[0-9]+", daemon["startMonotonic"]) is not None, "invalid_daemon_snapshot")
    number(daemon.get("pid"), 1)
    hashes = data.get("configurationHashes")
    require(isinstance(hashes, dict) and 1 <= len(hashes) <= 1024 and hashes.get(daemon["unitFile"]) is not None, "incomplete_config_snapshot")
    for name, value in hashes.items():
        require(safe_text(name).startswith("/") and (value is None or isinstance(value, str)
                and re.fullmatch(r"[a-f0-9]{64}", value) is not None), "invalid_config_snapshot")
    containers = data.get("containers")
    require(isinstance(containers, list) and 1 <= len(containers) <= 8, "incomplete_snapshot")
    seen = set()
    for item in containers:
        require(isinstance(item, dict) and re.fullmatch(r"[a-f0-9]{64}", safe_text(item.get("id"), 64)) is not None, "invalid_container_snapshot")
        require(item["id"] not in seen and item.get("running") is True, "invalid_container_snapshot")
        seen.add(item["id"])
        for key in ("reference", "name", "image", "networkMode"):
            safe_text(item.get(key))
        require(re.fullmatch(r"sha256:[a-f0-9]{64}", safe_text(item.get("imageId"), 71)) is not None, "invalid_container_snapshot")
        datetime.fromisoformat(safe_text(item.get("startedAt"), 64).replace("Z", "+00:00"))
        number(item.get("restartCount"))
        mounts, networks = item.get("mounts"), item.get("networks")
        require(isinstance(mounts, list) and len(mounts) <= 64 and isinstance(networks, list) and len(networks) <= 32, "incomplete_container_snapshot")
        for mount in mounts:
            require(isinstance(mount, dict) and mount.get("type") in ("bind", "volume", "tmpfs") and type(mount.get("readWrite")) is bool, "invalid_mount_snapshot")
            safe_text(mount.get("destination"))
            for key in ("sourceHash", "volumeNameHash"):
                require(re.fullmatch(r"[a-f0-9]{64}", safe_text(mount.get(key), 64)) is not None, "invalid_mount_snapshot")
        for network in networks:
            require(isinstance(network, dict), "invalid_network_snapshot")
            safe_text(network.get("name"), 255)
            require(re.fullmatch(r"[a-f0-9]{64}", safe_text(network.get("id"), 64)) is not None, "invalid_network_snapshot")
            endpoint = network.get("endpointId")
            require(isinstance(endpoint, str) and (re.fullmatch(r"[a-f0-9]{64}", endpoint) is not None
                    or endpoint == "" and item["networkMode"] in ("host", "none") and network["name"] == item["networkMode"]), "invalid_network_snapshot")
    resources = data.get("resources")
    require(isinstance(resources, dict) and set(resources) == {"cpuCount", "MemTotalKiB", "MemAvailableKiB", "SwapTotalKiB", "SwapFreeKiB", "installationFilesystem", "dockerFilesystem"}, "invalid_resource_snapshot")
    for key in ("cpuCount", "MemTotalKiB", "MemAvailableKiB", "SwapTotalKiB", "SwapFreeKiB"):
        number(resources[key])
    for key in ("installationFilesystem", "dockerFilesystem"):
        require(isinstance(resources[key], dict) and set(resources[key]) == {"totalBytes", "availableBytes"}, "invalid_resource_snapshot")
        number(resources[key]["totalBytes"], 1)
        number(resources[key]["availableBytes"])
        require(resources[key]["availableBytes"] <= resources[key]["totalBytes"], "invalid_resource_snapshot")


def main(arguments: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Read-only host/container snapshots; never installs or changes Docker/HUSTOJ.")
    commands = parser.add_subparsers(dest="command", required=True)
    collect = commands.add_parser("snapshot")
    collect.add_argument("--label", choices=("before", "after"), required=True)
    collect.add_argument("--wp-root", type=Path, default=Path("/home/judge/src/web"))
    collect.add_argument("--hustoj-container", action="append", default=[])
    collect.add_argument("--output", type=Path, required=True)
    comparison = commands.add_parser("compare")
    comparison.add_argument("--before", type=Path, required=True)
    comparison.add_argument("--after", type=Path, required=True)
    comparison.add_argument("--output", type=Path)
    args = parser.parse_args(arguments)
    try:
        require(hasattr(os, "geteuid") and os.geteuid() == 0 and sys.platform.startswith("linux"), "ubuntu_root_required")
        if args.command == "snapshot":
            result = snapshot(args.label, args.wp_root, args.hustoj_container)
            validate_snapshot(result)
            write_report(args.output, result)
            print("SNAPSHOT_WRITTEN: infrastructure evidence only; live acceptance remains unverified.")
            return 0
        result = compare(read_report(args.before), read_report(args.after))
        if args.output:
            write_report(args.output, result)
        print("MATCH: recorded infrastructure metadata unchanged." if result["matched"] else "CHANGED: recorded infrastructure metadata differs; investigate before proceeding.")
        return 0 if result["matched"] else 1
    except Exception as error:
        code = str(error) if isinstance(error, CheckError) and re.fullmatch(r"[a-z][a-z0-9_]{0,80}", str(error)) else "inspection_or_private_report_failed"
        print("HOST_CHECK_FAILED: " + code, file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
