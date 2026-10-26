#!/usr/bin/env python3
"""Build an immutable WordPress ZIP with reviewed redistribution inputs."""
from pathlib import Path
import argparse, hashlib, json, re, sys, zipfile
sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")
from verify import ROOT, verify

ROOT_FILES = {'oj-code-lab.php', 'plugin-package.php', 'uninstall.php', 'README.md',
              'CHANGELOG.md', 'LICENSE.txt', 'PLATFORM-LINKING-EXCEPTION.txt', 'readme.txt'}
PUBLIC_DOCS = {'THIRD_PARTY.md', 'Lucide-Feather-LICENSE.txt', 'PUBLIC_DATASETS.md', 'public-datasets.json',
               'PRIVACY.md', 'SUPPORT.md'}
# New license types require a deliberate compatibility and notice review.
DATA_LICENSES = {'CC0-1.0', 'MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC'}

# Exact upstream files only: vendor code retains its own license and is never
# relabeled with Code Lab's platform linking permission.
VENDOR_FILES = {
    'resources/vendor/markdown-it/markdown-it.js': '391f2aa638e75e6c7859d0a5c5650ef0956722a45998f17e05b83bc655778e1e',
    'resources/vendor/markdown-it/LICENSE.txt': '792c48c5a849a15fdf9e37e8bcf9e6d1dd13b32b46c642a748a0a46a9919d473',
    'resources/vendor/markdown-it/DEPENDENCY-LICENSES.txt': 'ed7515a8afff19d9677dd1b0eba05588fcb7fe696212fee5905b7c14dc662b9d',
}
VENDOR_COMPONENTS = [{
    'name': 'markdown-it', 'version': '15.0.3', 'license': 'MIT',
    'source': 'https://registry.npmjs.org/markdown-it/-/markdown-it-15.0.3.tgz',
    'integrity': 'sha512-+Az7Ojz3iRGu8KYMvJ6xVhvrxP3eE+vEtJX75Ow88XBpcQNV/0lGX5ZynIIBftREo4DC2jsveAMDaeltmm8QvQ==',
    'dependencies': {'entities': '8.1.0 (BSD-2-Clause)', 'linkify-it': '6.1.0 (MIT)',
                     'mdurl': '2.1.0 (MIT)', 'punycode.js': '2.3.1 (MIT)', 'uc.micro': '3.0.0 (MIT)'},
    'files': VENDOR_FILES,
}]

# Reviewed candidates only. Presence here does not license the plugin or grant approval.
# The exact GNU-hosted GPL v2 text was retrieved on 2026-09-09.
CODE_LICENSES = {
    'GPL-2.0-or-later': {
        'text_sha256': 'edaef632cbb643e4e7a221717a6c441a4c1a7c918e6e4d56debc3d8739b233f6',
        'source_url': 'https://ftp.gnu.org/gnu/Licenses/gpl-2.0.txt',
        'license_uri': 'https://www.gnu.org/licenses/old-licenses/gpl-2.0.html',
        'template_path': 'docs/license-templates/gpl-2.0.txt',
    },
}


# Project-specific, reviewed additional permission; not an SPDX-listed exception.
ADDITIONAL_PERMISSIONS = {
    'OJ-Code-Lab-Platform-Linking-Permission-1.0': {
        'file': 'PLATFORM-LINKING-EXCEPTION.txt',
        'sha256': 'ac7d17b40c50a405b3571b8a025c86587709dc169a140b536e129c6cf0056943',
        'spdx': 'GPL-2.0-or-later',
    },
}
PERMISSION_REFERENCE = 'Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.'


def declared_field(text, field, plugin_header=False):
    if plugin_header:
        header = re.match(r'\A<\?php\s*/\*\*(.*?)\*/', text, re.S)
        if not header:
            return None
        text = header.group(1)
        prefix = r'^ \* '
    else:
        prefix = '^'
    values = re.findall(prefix + re.escape(field) + r': ([^\r\n]+)$', text, re.M)
    return values[0].strip() if len(values) == 1 else None


def release_status(root, version):
    gates = json.loads((root / 'docs/release-gates.json').read_text(encoding='utf-8'))
    stable = bool(re.fullmatch(r'\d+\.\d+\.\d+', version))
    if not stable and not re.fullmatch(r'\d+\.\d+\.\d+-(dev|rc)(\.\d+)?', version):
        raise RuntimeError('Unsupported release version format')
    if stable:
        items = gates.get('gates', [])
        expected = {'R' + str(i) for i in range(1, 8)}
        pending = [g.get('id', '?') for g in items
                   if g.get('status') != 'passed' or not str(g.get('evidence', '')).strip()
                   or g.get('verified_version') != version]
        valid = (gates.get('decision') == 'GO' and gates.get('current') == version
                 and gates.get('target') == version and len(items) == 7
                 and {g.get('id') for g in items} == expected and not pending)
        if not valid:
            raise RuntimeError('Stable release is blocked: current, target and all seven evidenced gates must pass for this exact version. Pending: ' + ', '.join(pending))
        if gates.get('license_decision') != 'approved_by_owner':
            raise RuntimeError('Stable release is blocked: code license must be explicitly approved_by_owner')
        code_license = gates.get('code_license')
        if not isinstance(code_license, dict):
            raise RuntimeError('Stable release is blocked: code_license approval record is missing')
        spdx = code_license.get('spdx')
        reviewed = CODE_LICENSES.get(spdx)
        if reviewed is None:
            raise RuntimeError('Stable release is blocked: code license candidate is not reviewed; add its official text and metadata checks before selecting another SPDX license')
        reference = code_license.get('approval_reference')
        if (not isinstance(reference, str) or not reference.strip()
                or reference.strip().lower() in {'pending', 'todo', 'tbd', 'deferred', 'unconfirmed'}):
            raise RuntimeError('Stable release is blocked: concrete owner approval_reference is required')
        if (code_license.get('text_sha256') != reviewed['text_sha256']
                or code_license.get('source_url') != reviewed['source_url']):
            raise RuntimeError('Stable release is blocked: code_license official text hash or source differs')
        for name in ('LICENSE.txt', 'readme.txt'):
            path = root / name
            if not path.is_file() or not path.read_text(encoding='utf-8').strip():
                raise RuntimeError('Stable release is blocked: missing ' + name)
        if hashlib.sha256((root / 'LICENSE.txt').read_bytes()).hexdigest() != reviewed['text_sha256']:
            raise RuntimeError('Stable release is blocked: LICENSE.txt must match the reviewed official text exactly')
        main = (root / 'oj-code-lab.php').read_text(encoding='utf-8')
        readme = (root / 'readme.txt').read_text(encoding='utf-8')
        if (declared_field(main, 'License', True) != spdx
                or declared_field(readme, 'License') != spdx):
            raise RuntimeError('Stable release is blocked: plugin and readme License declarations must match the approved SPDX')
        if (declared_field(main, 'License URI', True) != reviewed['license_uri']
                or declared_field(readme, 'License URI') != reviewed['license_uri']):
            raise RuntimeError('Stable release is blocked: plugin and readme License URI must match the official reviewed URL')
        if declared_field(main, 'Version', True) != version:
            raise RuntimeError('Stable release is blocked: plugin header version differs')
        if declared_field(readme, 'Stable tag') != version:
            raise RuntimeError('Stable release is blocked: readme stable tag differs')
    return stable


def reviewed_datasets(root):
    policy = json.loads((root / 'docs/public-datasets.json').read_text(encoding='utf-8'))
    if policy.get('schema') != 1 or not policy.get('reviewed_at') or not policy.get('datasets'):
        raise RuntimeError('Reviewed dataset policy is missing or empty')
    selected = {}
    for item in policy['datasets']:
        name = item.get('file', '')
        if not re.fullmatch(r'[A-Za-z0-9_-]+\.csv', name) or name in selected:
            raise RuntimeError('Invalid or duplicate reviewed dataset filename: ' + name)
        if (item.get('redistribution') != 'approved' or item.get('license') not in DATA_LICENSES
                or not item.get('source_url', '').startswith('https://') or not item.get('evidence')):
            raise RuntimeError('Dataset redistribution evidence is incomplete: ' + name)
        path = root / 'resources/sample-datasets' / name
        if not path.is_file() or path.is_symlink():
            raise RuntimeError('Reviewed dataset is missing or a symlink: ' + name)
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        if digest != item.get('sha256'):
            raise RuntimeError('Reviewed dataset checksum changed: ' + name)
        notice = item.get('license_file', '')
        if not re.fullmatch(r'docs/licenses/[A-Za-z0-9_.-]+\.md', notice):
            raise RuntimeError('Invalid dataset license notice path: ' + name)
        notice_path = root / notice
        if (not notice_path.is_file() or notice_path.is_symlink()
                or hashlib.sha256(notice_path.read_bytes()).hexdigest() != item.get('license_sha256')):
            raise RuntimeError('Dataset license notice is missing or changed: ' + name)
        selected[name] = item
    return selected


def package_files(root, datasets):
    selected = []
    notices = {item['license_file'] for item in datasets.values()}
    for p in sorted(root.rglob('*')):
        if not p.is_file():
            continue
        rel = p.relative_to(root)
        allowed = ((len(rel.parts) == 1 and rel.name in ROOT_FILES)
                   or (rel.parts[0] == 'src' and p.suffix == '.php')
                   or (rel.parts[0] == 'resources' and p.suffix in {'.js', '.css'})
                   or (len(rel.parts) == 3 and rel.parts[:2] == ('resources', 'sample-datasets') and rel.name in datasets)
                   or (len(rel.parts) == 2 and rel.parts[0] == 'docs' and rel.name in PUBLIC_DOCS)
                   or rel.as_posix() in notices or rel.as_posix() in VENDOR_FILES)
        if allowed:
            if p.is_symlink() or any(parent.is_symlink() for parent in p.parents if parent != root.parent):
                raise RuntimeError('Symlink not allowed: ' + str(p))
            selected.append((p, rel))
    present = {rel.as_posix() for _, rel in selected}
    required = {'docs/' + name for name in PUBLIC_DOCS} | notices
    if not required.issubset(present):
        raise RuntimeError('Required public documentation is missing: ' + ', '.join(sorted(required - present)))
    return selected


def reviewed_vendor_files(root, selected):
    present = {rel.as_posix(): path for path, rel in selected}
    unknown = [name for name in present if name.startswith('resources/vendor/') and name not in VENDOR_FILES]
    if unknown:
        raise RuntimeError('Unreviewed vendor runtime files: ' + ', '.join(sorted(unknown)))
    for name, expected in VENDOR_FILES.items():
        path = present.get(name)
        if path is None or not path.is_file() or path.is_symlink():
            raise RuntimeError('Reviewed vendor file or license is missing from package: ' + name)
        if hashlib.sha256(path.read_bytes()).hexdigest() != expected:
            raise RuntimeError('Reviewed vendor checksum changed: ' + name)
    return VENDOR_COMPONENTS


def reviewed_additional_permission(root, selected):
    gates = json.loads((root / 'docs/release-gates.json').read_text(encoding='utf-8'))
    code_license = gates.get('code_license')
    record = code_license.get('additional_permission') if isinstance(code_license, dict) else None
    source = {rel.as_posix(): path.read_text(encoding='utf-8')
              for path, rel in selected if rel.suffix in {'.php', '.js', '.css'}
              and rel.as_posix() not in VENDOR_FILES}
    referenced = any('Additional permission:' in text for text in source.values())
    present_notices = any((root / item['file']).exists() for item in ADDITIONAL_PERMISSIONS.values())
    if record is None and not referenced and not present_notices:
        return None
    if not isinstance(record, dict):
        raise RuntimeError('Additional permission is referenced or present but approval metadata is missing')
    identifier = record.get('id')
    reviewed = ADDITIONAL_PERMISSIONS.get(identifier) if isinstance(identifier, str) else None
    if reviewed is None:
        raise RuntimeError('Additional permission identifier is not reviewed')
    if (record.get('file') != reviewed['file'] or record.get('sha256') != reviewed['sha256']
            or code_license.get('spdx') != reviewed['spdx']):
        raise RuntimeError('Additional permission file, hash or base SPDX differs from the reviewed registry')
    notice = root / reviewed['file']
    if (not notice.is_file() or notice.is_symlink()
            or hashlib.sha256(notice.read_bytes()).hexdigest() != reviewed['sha256']):
        raise RuntimeError('Additional permission text is missing or changed')
    if reviewed['file'] not in {rel.as_posix() for _, rel in selected}:
        raise RuntimeError('Additional permission text is excluded from the package')
    covered = record.get('covered_files')
    if not isinstance(covered, list) or covered != sorted(source):
        raise RuntimeError('Additional permission covered_files must exactly list all first-party packaged PHP, JS and CSS in sorted order')
    for relative, text in source.items():
        identifiers = re.findall(r'^ \* SPDX-License-Identifier: ([^\r\n]+)$', text, re.M)
        references = re.findall(r'^ \* Additional permission: ([^\r\n]+)$', text, re.M)
        if (identifiers != [reviewed['spdx']]
                or references != [PERMISSION_REFERENCE.removeprefix('Additional permission: ')]):
            raise RuntimeError('Additional permission source notice is missing, changed or duplicated: ' + relative)
    return {'id': record['id'], 'file': reviewed['file'], 'sha256': reviewed['sha256'],
            'covered_files': covered}


def build(root, output_dir, result):
    version = result['version']
    stable = release_status(root, version)
    datasets = reviewed_datasets(root)
    selected = package_files(root, datasets)
    vendors = reviewed_vendor_files(root, selected)
    permission = reviewed_additional_permission(root, selected)
    out = output_dir.resolve()
    out.mkdir(parents=True, exist_ok=True)
    archive = out / ('oj-code-lab-' + version + '.zip')
    targets = [archive, archive.with_suffix('.zip.sha256'), archive.with_suffix('.manifest.json'), archive.with_suffix('.verification.json')]
    if any(p.exists() for p in targets):
        raise RuntimeError('Release outputs already exist; use a new directory or version. Immutable files were not changed.')
    manifest = {'plugin': 'oj-code-lab', 'version': version,
                'channel': 'stable' if stable else ('release-candidate' if '-rc.' in version else 'development'),
                'release_ready': stable, 'dataset_policy': 'reviewed-only',
                'datasets': list(datasets), 'vendor_components': vendors, 'files': []}
    if permission is not None:
        manifest['additional_permission'] = permission
    created = []
    try:
        with zipfile.ZipFile(archive, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
            created.append(archive)
            for p, rel in selected:
                data = p.read_bytes()
                name = 'oj-code-lab/' + rel.as_posix()
                info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.create_system = 3
                info.external_attr = 0o100644 << 16
                z.writestr(info, data, compresslevel=9)
                manifest['files'].append({'path': name, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
        with zipfile.ZipFile(archive) as z:
            if z.testzip() is not None or len(z.namelist()) != len(selected):
                raise RuntimeError('ZIP integrity failed')
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        contents = [digest + '  ' + archive.name + '\n',
                    json.dumps(manifest, ensure_ascii=False, indent=2) + '\n',
                    json.dumps(result, ensure_ascii=False, indent=2) + '\n']
        for path, content in zip(targets[1:], contents):
            with path.open('x', encoding='utf-8', newline='\n') as handle:
                created.append(path)
                handle.write(content)
    except Exception:
        for path in reversed(created):
            path.unlink(missing_ok=True)
        raise
    return {'archive': str(archive), 'sha256': digest, 'files': len(selected),
            'datasets': list(datasets), 'release_ready': stable}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--output-dir', type=Path, required=True)
    parser.add_argument('--php')
    parser.add_argument('--node')
    args = parser.parse_args()
    result = verify(args.php, args.node)
    print(json.dumps(build(ROOT, args.output_dir, result), ensure_ascii=False, indent=2))


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, ValueError, KeyError, OSError) as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(1)
