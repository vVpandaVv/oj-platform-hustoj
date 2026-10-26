#!/usr/bin/env python3
"""Prepare a reviewed source-only snapshot; dry-run unless --write is supplied.

Only Python's standard library is used. No Git history, package archives,
operational content, automatic overwrite, or automatic deletion is supported.
This allowlist exporter does not replace a manual secrets and privacy review.
"""

from __future__ import annotations

import argparse
from collections import Counter
from dataclasses import dataclass
from datetime import date
import fnmatch
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import stat
import subprocess
import sys
from typing import Any


class ExportError(Exception):
    """A source or destination cannot be exported safely."""


@dataclass(frozen=True)
class SourceFile:
    path: str
    size: int
    sha256: str
    executable: bool


def relative_path(value: str) -> str:
    if not isinstance(value, str) or not value or "\\" in value or ":" in value:
        raise ExportError("Invalid relative path in configuration or Git index.")
    parts = value.split("/")
    if value.startswith("/") or any(part in {"", ".", ".."} for part in parts):
        raise ExportError("Absolute paths and path traversal are not allowed.")
    return PurePosixPath(value).as_posix()


def is_reparse(path: Path) -> bool:
    info = path.lstat()
    return stat.S_ISLNK(info.st_mode) or bool(
        getattr(info, "st_file_attributes", 0)
        & getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0x400)
    )


def within(path: Path, parent: Path) -> bool:
    try:
        path.relative_to(parent)
        return True
    except ValueError:
        return False


def no_links(root: Path, path: Path, *, allow_missing: bool = False) -> None:
    if not within(path, root):
        raise ExportError("A path leaves the source repository.")
    current = root
    if is_reparse(current):
        raise ExportError("The repository root cannot be a symbolic link or junction.")
    for part in path.relative_to(root).parts:
        current = current / part
        try:
            if is_reparse(current):
                raise ExportError("Symbolic links and junctions cannot be exported.")
        except FileNotFoundError:
            if allow_missing:
                return
            raise ExportError("A required source path is missing.") from None


def git(root: Path, *arguments: str) -> bytes:
    try:
        result = subprocess.run(
            ["git", "-C", str(root), *arguments],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
    except OSError:
        raise ExportError("Git is required to enumerate the source snapshot.") from None
    if result.returncode:
        raise ExportError("Git could not read the repository index or base commit.")
    return result.stdout


def load_policy(root: Path) -> dict[str, Any]:
    path = root / "_release-kit" / "public-source.json"
    no_links(root, path)
    try:
        policy = json.loads(path.read_text(encoding="utf-8"))
        if policy.get("schemaVersion") != 1 or policy.get("mode") != "source-only":
            raise ExportError("Only schema version 1 and source-only mode are supported.")
        date.fromisoformat(policy["snapshotDate"])
        path_lists = ("includeRoots", "includeFiles", "packageRoots", "excludePaths", "excludePrefixes")
        for key in path_lists:
            if not isinstance(policy[key], list) or not policy[key]:
                raise ExportError("Include and package root lists cannot be empty.")
            for entry in policy[key]:
                relative_path(entry)
        for key in ("includeGlobs", "excludeGlobs"):
            for entry in policy[key]:
                relative_path(entry)
        if any(any(symbol in str(PurePosixPath(pattern).parent) for symbol in "*?[")
               for pattern in policy["includeGlobs"]):
            raise ExportError("Include globs may only match file names in a fixed directory.")
        for rule in policy.get("restrictedDocumentation", []):
            relative_path(rule["prefix"])
            for pattern in rule["allowNames"]:
                relative_path(pattern)
        for key in (
            "defaultOutput", "sourceCatalog", "generatedCatalog",
            "generatedContentReadme", "generatedAiDocumentationIndex", "manifest",
        ):
            relative_path(policy[key])
        generated = [policy[key].casefold() for key in (
            "generatedCatalog", "generatedContentReadme", "generatedAiDocumentationIndex", "manifest",
        )]
        if len(set(generated)) != len(generated):
            raise ExportError("Generated snapshot paths must be distinct.")
        if not 0 < policy["largeFileWarningBytes"] < policy["maximumFileBytes"]:
            raise ExportError("Invalid file size limits.")
        if policy["maximumFileBytes"] > 100 * 1024 * 1024:
            raise ExportError("The maximum file size cannot exceed 100 MiB.")
        return policy
    except (OSError, UnicodeError, ValueError, TypeError, KeyError):
        raise ExportError("The public source policy is invalid or unreadable.") from None


def matches(path: str, pattern: str) -> bool:
    # fnmatch's '**/' does not match the root level, so test that case explicitly.
    return fnmatch.fnmatchcase(path, pattern) or (
        pattern.startswith("**/") and fnmatch.fnmatchcase(path, pattern[3:])
    )


def included(path: str, policy: dict[str, Any]) -> bool:
    return (
        path in policy["includeFiles"]
        or any(path.startswith(prefix + "/") for prefix in policy["includeRoots"])
        or any(
            PurePosixPath(path).parent == PurePosixPath(pattern).parent
            and fnmatch.fnmatchcase(PurePosixPath(path).name, PurePosixPath(pattern).name)
            for pattern in policy["includeGlobs"]
        )
    )


def excluded(path: str, policy: dict[str, Any]) -> str | None:
    parts = PurePosixPath(path).parts
    lowered = tuple(part.lower() for part in parts)
    for rule in policy.get("restrictedDocumentation", []):
        prefix = rule["prefix"] + "/"
        if path.startswith(prefix):
            relative = path[len(prefix):]
            if "/" in relative or not any(fnmatch.fnmatchcase(relative, pattern) for pattern in rule["allowNames"]):
                return "private-development-or-operational-record"
    if path.casefold() in {name.casefold() for name in policy["excludePaths"]}:
        return "operational-document"
    if any(path.casefold() == prefix.casefold() or path.casefold().startswith(prefix.casefold() + "/")
           for prefix in policy["excludePrefixes"]):
        return "operational-document"
    if any(part in policy["excludeDirectoryNames"] for part in lowered[:-1]):
        return "private-cache-or-runtime-directory"
    if lowered[-1] in policy["excludeDirectoryNames"]:
        return "private-cache-or-runtime-directory"
    for prefix in policy["packageRoots"]:
        if path.startswith(prefix + "/"):
            suffix = path[len(prefix) + 1:].split("/")
            if len(suffix) > 1 and suffix[0].lower() in policy["excludePackageDirectoryNames"]:
                return "package-generated-or-composer-directory"
    name = lowered[-1]
    if "docs" in lowered[:-1] and PurePosixPath(name).suffix in {
        ".md", ".html", ".htm", ".json", ".txt",
    } and any(matches(name, pattern.lower()) for pattern in policy["excludeOperationalDocumentNamePatterns"]):
        return "operational-document"
    if name in policy["excludeFileNames"]:
        return "operational-or-sensitive-file"
    if PurePosixPath(name).suffix in policy["excludeExtensions"]:
        return "archive-data-key-log-or-artifact-extension"
    is_example = name in policy["exampleFileNames"] or any(
        name.endswith(suffix) for suffix in policy["exampleFileSuffixes"]
    )
    if not is_example and any(matches(path.lower(), pattern.lower()) for pattern in policy["excludeGlobs"]):
        return "operational-or-sensitive-pattern"
    # Nested _notes are private by default. Only the explicitly selected editor
    # development sources and the single top-level harness may cross this rule.
    if "_notes" in lowered:
        if "_notes" in lowered[1:]:
            return "private-notes"
        if path == "_notes/agent-harness.md":
            return None
        editor = "_notes/readcontents-server-import-2026-09-12/editor-source/"
        if not path.startswith(editor):
            return "private-notes"
    if name.startswith("~$") or name.endswith("~"):
        return "editor-temporary-file"
    return None


def digest_file(path: Path) -> tuple[int, str]:
    total = 0
    hasher = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            total += len(chunk)
            hasher.update(chunk)
    return total, hasher.hexdigest()


def validate_required(root: Path, names: set[str], policy: dict[str, Any]) -> None:
    for name in policy["includeFiles"]:
        path = root / Path(name)
        no_links(root, path)
        if not path.is_file() or name not in names:
            raise ExportError("A required include file is missing from the Git file inventory.")
    for name in policy["includeRoots"]:
        path = root / Path(name)
        no_links(root, path)
        if not path.is_dir() or not any(candidate.startswith(name + "/") for candidate in names):
            raise ExportError("A required include directory has no exportable source files.")
    for pattern in policy["includeGlobs"]:
        if not any(matches(name, pattern) for name in names):
            raise ExportError("A required include pattern has no exportable source files.")
    catalog = root / Path(policy["sourceCatalog"])
    no_links(root, catalog)
    if not catalog.is_file():
        raise ExportError("The original content catalog is required for snapshot provenance.")


def collect(root: Path, policy: dict[str, Any]) -> tuple[list[SourceFile], Counter[str]]:
    raw = git(root, "ls-files", "--cached", "--others", "--exclude-standard", "-z")
    try:
        names = sorted({relative_path(item.decode("utf-8")) for item in raw.split(b"\0") if item})
    except UnicodeError:
        raise ExportError("Git file names must use valid UTF-8.") from None
    selected: list[SourceFile] = []
    rejected: Counter[str] = Counter()
    folded: set[str] = set()
    for name in names:
        if not included(name, policy):
            rejected["outside-allowlist"] += 1
            continue
        reason = excluded(name, policy)
        if reason:
            rejected[reason] += 1
            continue
        path = root / Path(name)
        # Deleted tracked files are intentionally absent from a working-tree
        # snapshot; dangling links are not treated as harmless missing files.
        no_links(root, path, allow_missing=True)
        if not path.exists() and not path.is_symlink():
            rejected["missing-working-tree-file"] += 1
            continue
        no_links(root, path)
        info = path.stat()
        if not stat.S_ISREG(info.st_mode):
            raise ExportError("Only regular source files may be exported.")
        if name.casefold() in folded:
            raise ExportError("Case-insensitive destination file name collision.")
        folded.add(name.casefold())
        if info.st_size > policy["maximumFileBytes"]:
            raise ExportError("An included source file exceeds the 100 MiB limit.")
        size, checksum = digest_file(path)
        if size != info.st_size:
            raise ExportError("A source file changed while the snapshot was being inspected.")
        selected.append(SourceFile(name, size, checksum, bool(info.st_mode & stat.S_IXUSR)))
    validate_required(root, {entry.path for entry in selected}, policy)
    return selected, rejected


def destination(root: Path, value: str) -> Path:
    requested = Path(value)
    path = Path(os.path.abspath(requested if requested.is_absolute() else root / requested))
    if not within(path, root) or path == root:
        raise ExportError("Output must be a new or empty directory inside the repository.")
    if any(part.lower() in {".git", ".svn", ".hg"} for part in path.relative_to(root).parts):
        raise ExportError("Output cannot be inside version control metadata.")
    no_links(root, path, allow_missing=True)
    if path.exists() and (not path.is_dir() or any(path.iterdir())):
        raise ExportError("Output already exists and is not empty; nothing was overwritten or deleted.")
    return path


def generated_files(policy: dict[str, Any]) -> dict[str, bytes]:
    catalog = json.dumps({"schemaVersion": 1, "collections": []}, ensure_ascii=False, indent=2) + "\n"
    readme = "# Content catalog\n\nManage collections in catalog.json.\n"
    generated = {
        policy["generatedCatalog"]: catalog.encode("utf-8"),
        policy["generatedContentReadme"]: readme.encode("utf-8"),
        policy["generatedAiDocumentationIndex"]: (
            "<!doctype html>\n<html lang=\"en\"><meta charset=\"utf-8\">"
            "<title>Public architecture documentation</title><body>"
            "<h1>Public architecture documentation</h1>"
            "<p>Architecture and contract design documents.</p>"
            "<ul><li><a href=\"design-procedure.html\">Design procedure</a></li>"
            "<li><a href=\"code-consistency.html\">Code consistency</a></li>"
            "<li><a href=\"a-ds-00-scope-baseline.html\">Scope baseline</a></li></ul>"
            "<p>See the repository README and SOURCE-SNAPSHOT.json for the publication scope.</p>"
            "</body></html>\n"
        ).encode("utf-8"),
    }
    release_plugins = policy.get("releaseLayoutPlugins", [])
    if release_plugins:
        for plugin in release_plugins:
            relative_path(plugin)
            if "/" in plugin:
                raise ExportError("Release layout requires a plugin identifier.")
            generated[f"_releases/{plugin}/.gitkeep"] = b""
        generated["_releases/README.md"] = (
            "# Distribution ZIPs\n\n"
            "Path: `<plugin>/<version>/<plugin>-<version>.zip`.\n"
        ).encode("utf-8")
        registry_path = Path(__file__).parent / "plugins.json"
        registry = json.loads(registry_path.read_text(encoding="utf-8"))
        registry["plugins"] = [item for item in registry["plugins"] if item["id"] in release_plugins]
        generated["_release-kit/plugins.json"] = (
            json.dumps(registry, ensure_ascii=False, indent=2) + "\n"
        ).encode("utf-8")
    return generated


def manifest(
    root: Path, policy: dict[str, Any], sources: list[SourceFile],
    generated: dict[str, bytes], rejected: Counter[str], base_commit: str,
) -> bytes:
    source_catalog_size, source_catalog_hash = digest_file(root / Path(policy["sourceCatalog"]))
    entries = [
        {"path": item.path, "size": item.size, "sha256": item.sha256, "origin": "working-tree"}
        for item in sources
    ]
    entries.extend(
        {"path": name, "size": len(data), "sha256": hashlib.sha256(data).hexdigest(), "origin": "generated"}
        for name, data in generated.items()
    )
    entries.sort(key=lambda item: item["path"])
    data = {
        "schemaVersion": 1,
        "snapshotDate": policy["snapshotDate"],
        "mode": "source-only",
        "sourceBaseCommit": base_commit,
        "workingTreeIncluded": True,
        "sourceInventory": "git ls-files --cached --others --exclude-standard -z; existing regular files only",
        "ignoredTrackedFilesIncludedWhenAllowlisted": True,
        "ignoredUntrackedFilesIncluded": False,
        "gitHistoryIncluded": False,
        "content": {
            "originalCatalog": policy["sourceCatalog"],
            "originalCatalogSize": source_catalog_size,
            "originalCatalogSha256": source_catalog_hash,
            "originalCatalogIncluded": False,
            "originalContentFilesIncluded": False,
            "privateContentIncluded": False,
            "replacementCatalog": policy["generatedCatalog"],
            "replacementCollectionCount": 0,
            "note": "Source-only export replaces the original catalog and excludes its content collections; no external content paths are followed.",
        },
        "policy": policy,
        "excludedCounts": dict(sorted(rejected.items())),
        "fileCount": len(entries),
        "totalBytes": sum(item["size"] for item in entries),
        "manifestSelfExcludedFromFileHashes": True,
        "reviewRequired": "Allowlist and path checks are not a secrets, privacy or license certification; review the manifest and selected files before publication.",
        "files": entries,
    }
    return (json.dumps(data, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def make_directory(root: Path, path: Path) -> None:
    no_links(root, path, allow_missing=True)
    path.mkdir(parents=True, exist_ok=True)
    no_links(root, path)
    if not path.is_dir():
        raise ExportError("A destination parent is not a regular directory.")


def copy_snapshot(
    root: Path, output: Path, sources: list[SourceFile],
    generated: dict[str, bytes], manifest_path: str, manifest_data: bytes,
    base_commit: str,
) -> None:
    destination(root, str(output))
    if git(root, "rev-parse", "HEAD").decode("ascii").strip() != base_commit:
        raise ExportError("The source base commit changed after inspection.")
    make_directory(root, output)
    for entry in sources:
        source = root / Path(entry.path)
        target = output / Path(entry.path)
        no_links(root, source)
        make_directory(root, target.parent)
        hasher = hashlib.sha256()
        count = 0
        # xb never overwrites an existing destination, including a link.
        with source.open("rb") as source_stream, target.open("xb") as target_stream:
            for chunk in iter(lambda: source_stream.read(1024 * 1024), b""):
                target_stream.write(chunk)
                hasher.update(chunk)
                count += len(chunk)
        if count != entry.size or hasher.hexdigest() != entry.sha256:
            raise ExportError("Source changed during copying; partial output was retained without deletion.")
        if entry.executable and os.name != "nt":
            target.chmod(target.stat().st_mode | stat.S_IXUSR)
    for name, data in {**generated, manifest_path: manifest_data}.items():
        target = output / Path(name)
        make_directory(root, target.parent)
        with target.open("xb") as stream:
            stream.write(data)
    for entry in sources:
        no_links(root, output / Path(entry.path))
        if digest_file(output / Path(entry.path)) != (entry.size, entry.sha256):
            raise ExportError("Copied file verification failed; partial output was retained.")
    for name, data in {**generated, manifest_path: manifest_data}.items():
        if digest_file(output / Path(name)) != (len(data), hashlib.sha256(data).hexdigest()):
            raise ExportError("Generated file verification failed; partial output was retained.")


def main(arguments: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", help="New or empty output directory inside the source repository.")
    parser.add_argument("--write", action="store_true", help="Write after validation; otherwise inspect without creating any files.")
    args = parser.parse_args(arguments)
    root = Path(os.path.abspath(Path(__file__).parent.parent))
    try:
        no_links(root, root)
        git_root = Path(os.path.abspath(git(root, "rev-parse", "--show-toplevel").decode("utf-8").strip()))
        if git_root != root:
            raise ExportError("The exporter must run from its original repository root.")
        policy = load_policy(root)
        output = destination(root, args.output or policy["defaultOutput"])
        sources, rejected = collect(root, policy)
        generated = generated_files(policy)
        reserved = {policy["manifest"], *generated}
        selected_names = {entry.path for entry in sources}
        selected_folded = {name.casefold() for name in selected_names}
        if len(reserved) != len(generated) + 1 or any(name.casefold() in selected_folded for name in reserved):
            raise ExportError("Generated snapshot paths collide with selected source files.")
        if any(within(root / Path(entry.path), output) for entry in sources):
            raise ExportError("Output overlaps selected source files.")
        base_commit = git(root, "rev-parse", "HEAD").decode("ascii").strip()
        manifest_data = manifest(root, policy, sources, generated, rejected, base_commit)
        if args.write:
            copy_snapshot(root, output, sources, generated, policy["manifest"], manifest_data, base_commit)
        total_bytes = sum(entry.size for entry in sources) + sum(map(len, generated.values())) + len(manifest_data)
        large = [entry for entry in sources if entry.size > policy["largeFileWarningBytes"]]
        print(json.dumps({
            "mode": "written" if args.write else "dry-run",
            "sourceFiles": len(sources),
            "generatedFiles": len(generated) + 1,
            "totalFiles": len(sources) + len(generated) + 1,
            "totalBytes": total_bytes,
            "excludedFiles": sum(rejected.values()),
            "largeFileCount": len(large),
        }, ensure_ascii=False))
        for entry in large[:20]:
            print(json.dumps({"largeFile": entry.path, "size": entry.size}, ensure_ascii=False))
        if len(large) > 20:
            print(json.dumps({"additionalLargeFiles": len(large) - 20}))
        return 0
    except (ExportError, OSError, UnicodeError) as error:
        # Do not echo file contents, Git output, settings or credentials.
        message = str(error) if isinstance(error, ExportError) else "File access failed; no existing files were overwritten or deleted."
        print("Export refused: " + message, file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
