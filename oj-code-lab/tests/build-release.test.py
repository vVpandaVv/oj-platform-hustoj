"""Exercise redistribution, stable gate and immutable-artifact boundaries."""
from pathlib import Path
import copy, hashlib, importlib.util, json, re, shutil, sys, tempfile, unittest, zipfile
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
import build_release as release


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        tmp = release.ROOT.parent / '_notes/tmp'
        tmp.mkdir(parents=True, exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(prefix='ocl-package-test-', dir=tmp)
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.root = self.base / 'oj-code-lab'
        self.root.mkdir()
        for rel in ['oj-code-lab.php', 'plugin-package.php', 'README.md', 'CHANGELOG.md',
                    'src/Core/Plugin.php', 'resources/sample-datasets/penguins.csv',
                    'resources/sample-datasets/titanic.csv', 'docs/public-datasets.json',
                    'docs/licenses/palmerpenguins-CC0-1.0.md', 'docs/release-gates.json',
                    'docs/license-templates/gpl-2.0.txt']:
            target = self.root / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(release.ROOT / rel, target)
        for name in release.PUBLIC_DOCS - {'public-datasets.json'}:
            (self.root / 'docs' / name).write_text('Documentation fixture', encoding='utf-8')
        for relative in release.VENDOR_FILES:
            target = self.root / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(release.ROOT / relative, target)
        (self.root / 'docs/OPERATIONS_PRIVATE.md').write_text('PRIVATE_OPERATIONS_MARKER', encoding='utf-8')
        self.result = {'version': '1.0.0-rc.4', 'scope': 'package boundary fixture'}
        # GPL-only cases must not inherit the repository's optional permission.
        for path in self.root.rglob('*.php'):
            text = path.read_text(encoding='utf-8')
            path.write_text(re.sub(r'^ \* Additional permission:.*\n', '', text, flags=re.M), encoding='utf-8')
        gates_path = self.root / 'docs/release-gates.json'
        gates = json.loads(gates_path.read_text(encoding='utf-8'))
        if isinstance(gates.get('code_license'), dict):
            gates['code_license'].pop('additional_permission', None)
        self.save_gates(gates)

    def policy(self, change):
        path = self.root / 'docs/public-datasets.json'
        value = json.loads(path.read_text(encoding='utf-8'))
        change(value)
        path.write_text(json.dumps(value), encoding='utf-8')

    def build(self, name='output'):
        return release.build(self.root, self.base / name, self.result)

    def save_gates(self, gates):
        (self.root / 'docs/release-gates.json').write_text(json.dumps(gates), encoding='utf-8')

    def copy_license_fixture(self):
        shutil.copyfile(self.root / 'docs/license-templates/gpl-2.0.txt', self.root / 'LICENSE.txt')

    def stable_fixture(self):
        self.result['version'] = '1.0.0'
        spdx = 'GPL-2.0-or-later'
        reviewed = release.CODE_LICENSES[spdx]
        gates = {'current': '1.0.0', 'target': '1.0.0', 'decision': 'GO',
                 'license_decision': 'approved_by_owner',
                 'code_license': {'spdx': spdx, 'text_sha256': reviewed['text_sha256'],
                                  'source_url': reviewed['source_url'],
                                  'approval_reference': 'Isolated test fixture, not production owner approval'},
                 'gates': [{'id': 'R' + str(i), 'status': 'passed', 'evidence': 'test fixture',
                            'verified_version': '1.0.0'} for i in range(1, 8)]}
        self.save_gates(gates)
        self.copy_license_fixture()
        (self.root / 'readme.txt').write_text(
            'Stable tag: 1.0.0\nLicense: ' + spdx + '\nLicense URI: ' + reviewed['license_uri'] + '\n',
            encoding='utf-8')
        main = (self.root / 'oj-code-lab.php').read_text(encoding='utf-8')
        main = re.sub(r'^ \* Version: .+$', ' * Version: 1.0.0', main, flags=re.M)
        main = re.sub(r'^ \* License(?: URI)?: .+\n', '', main, flags=re.M)
        main = main.replace(' */', ' * License: ' + spdx + '\n * License URI: ' + reviewed['license_uri'] + '\n */', 1)
        (self.root / 'oj-code-lab.php').write_text(main, encoding='utf-8')
        return gates

    def permission_fixture(self):
        gates = self.stable_fixture()
        identifier = 'OJ-Code-Lab-Platform-Linking-Permission-1.0'
        reviewed = release.ADDITIONAL_PERMISSIONS[identifier]
        shutil.copyfile(release.ROOT / reviewed['file'], self.root / reviewed['file'])
        for relative, text in [('resources/js/example.js', '(function () {})();\n'),
                               ('resources/css/example.css', '.example { color: black; }\n')]:
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text, encoding='utf-8')
        selected = release.package_files(self.root, release.reviewed_datasets(self.root))
        source = [(path, relative) for path, relative in selected if relative.suffix in {'.php', '.js', '.css'}
                  and relative.as_posix() not in release.VENDOR_FILES]
        spdx = ' * SPDX-License-Identifier: GPL-2.0-or-later\n'
        reference = ' * ' + release.PERMISSION_REFERENCE + '\n'
        for path, _ in source:
            text = path.read_text(encoding='utf-8')
            if reference not in text:
                if spdx in text:
                    text = text.replace(spdx, spdx + reference, 1)
                else:
                    notice = '/*\n' + spdx + reference + ' */\n'
                    text = text.replace('<?php\n', '<?php\n' + notice, 1) if path.suffix == '.php' else notice + text
                path.write_text(text, encoding='utf-8')
        gates['code_license']['additional_permission'] = {
            'id': identifier, 'file': reviewed['file'], 'sha256': reviewed['sha256'],
            'covered_files': sorted(relative.as_posix() for _, relative in source)}
        self.save_gates(gates)
        return gates

    def test_only_reviewed_csv_and_public_documents_ship(self):
        result = self.build()
        with zipfile.ZipFile(result['archive']) as archive:
            names = archive.namelist()
            self.assertEqual([n for n in names if n.endswith('.csv')], ['oj-code-lab/resources/sample-datasets/penguins.csv'])
            self.assertIn('oj-code-lab/docs/licenses/palmerpenguins-CC0-1.0.md', names)
            self.assertNotIn('oj-code-lab/PLATFORM-LINKING-EXCEPTION.txt', names)
            self.assertFalse(any('OPERATIONS' in n or 'release-gates' in n or 'license-templates' in n for n in names))
        self.assertTrue((self.root / 'resources/sample-datasets/titanic.csv').is_file())
        self.assertFalse(result['release_ready'])

    def test_reproducible_zip(self):
        self.assertEqual(self.build('a')['sha256'], self.build('b')['sha256'])

    def test_existing_release_cannot_be_overwritten(self):
        result = self.build()
        path = Path(result['archive'])
        before = path.read_bytes()
        with self.assertRaisesRegex(RuntimeError, 'already exist'):
            self.build()
        self.assertEqual(before, path.read_bytes())

    def test_changed_csv_is_rejected(self):
        (self.root / 'resources/sample-datasets/penguins.csv').write_text('tampered', encoding='utf-8')
        with self.assertRaisesRegex(RuntimeError, 'checksum changed'):
            self.build()

    def test_missing_or_changed_notice_is_rejected(self):
        (self.root / 'docs/licenses/palmerpenguins-CC0-1.0.md').unlink()
        with self.assertRaisesRegex(RuntimeError, 'license notice'):
            self.build()

    def test_unapproved_dataset_is_rejected(self):
        self.policy(lambda value: value['datasets'][0].update(redistribution='pending'))
        with self.assertRaisesRegex(RuntimeError, 'evidence is incomplete'):
            self.build()

    def test_traversal_and_duplicate_are_rejected(self):
        self.policy(lambda value: value['datasets'].append(copy.deepcopy(value['datasets'][0])))
        with self.assertRaisesRegex(RuntimeError, 'duplicate'):
            self.build()
        self.policy(lambda value: value['datasets'][0].update(file='../penguins.csv'))
        with self.assertRaisesRegex(RuntimeError, 'Invalid'):
            self.build()

    def test_stable_pending_is_rejected(self):
        self.result['version'] = '1.0.0'
        with self.assertRaisesRegex(RuntimeError, 'Stable release is blocked'):
            self.build()

    def test_duplicate_gate_cannot_pass(self):
        gates = self.stable_fixture()
        gates['gates'][-1]['id'] = 'R1'
        (self.root / 'docs/release-gates.json').write_text(json.dumps(gates), encoding='utf-8')
        with self.assertRaisesRegex(RuntimeError, 'seven evidenced gates'):
            self.build()

    def test_license_and_stable_tag_are_required(self):
        self.stable_fixture()
        (self.root / 'LICENSE.txt').unlink()
        with self.assertRaisesRegex(RuntimeError, 'LICENSE.txt'):
            self.build()
        self.copy_license_fixture()
        readme = self.root / 'readme.txt'
        readme.write_text(readme.read_text(encoding='utf-8').replace('Stable tag: 1.0.0', 'Stable tag: 0.0.1'), encoding='utf-8')
        with self.assertRaisesRegex(RuntimeError, 'stable tag differs'):
            self.build()

    def test_stable_approved_official_license_is_accepted(self):
        self.stable_fixture()
        result = self.build()
        self.assertTrue(result['release_ready'])
        with zipfile.ZipFile(result['archive']) as archive:
            content = archive.read('oj-code-lab/LICENSE.txt')
            self.assertEqual(hashlib.sha256(content).hexdigest(),
                             release.CODE_LICENSES['GPL-2.0-or-later']['text_sha256'])
            self.assertFalse(any('license-templates' in name for name in archive.namelist()))

    def test_owner_deferral_cannot_be_overridden_by_passed_gates(self):
        gates = self.stable_fixture()
        gates['license_decision'] = 'deferred_by_owner'
        self.save_gates(gates)
        with self.assertRaisesRegex(RuntimeError, 'approved_by_owner'):
            self.build()

    def test_placeholder_or_tampered_license_text_is_rejected(self):
        for text in ('PENDING', 'License selection is not decided', 'GNU GENERAL PUBLIC LICENSE\nVersion 2, June 1991\n'):
            with self.subTest(text=text):
                self.stable_fixture()
                (self.root / 'LICENSE.txt').write_text(text, encoding='utf-8')
                with self.assertRaisesRegex(RuntimeError, 'official text exactly'):
                    self.build()

    def test_tampered_license_and_updated_declared_hash_are_rejected(self):
        gates = self.stable_fixture()
        tampered = (self.root / 'LICENSE.txt').read_bytes() + b'Extra terms: no redistribution\n'
        (self.root / 'LICENSE.txt').write_bytes(tampered)
        gates['code_license']['text_sha256'] = hashlib.sha256(tampered).hexdigest()
        self.save_gates(gates)
        with self.assertRaisesRegex(RuntimeError, 'official text hash or source differs'):
            self.build()

    def test_owner_approval_record_must_be_complete(self):
        for field in ('spdx', 'text_sha256', 'source_url', 'approval_reference'):
            with self.subTest(field=field):
                gates = self.stable_fixture()
                gates['code_license'].pop(field)
                self.save_gates(gates)
                with self.assertRaisesRegex(RuntimeError, 'Stable release is blocked'):
                    self.build()
        gates = self.stable_fixture()
        gates.pop('code_license')
        self.save_gates(gates)
        with self.assertRaisesRegex(RuntimeError, 'approval record is missing'):
            self.build()

    def test_placeholder_approval_reference_is_rejected(self):
        for reference in ('', ' ', 'TODO', 'pending'):
            with self.subTest(reference=reference):
                gates = self.stable_fixture()
                gates['code_license']['approval_reference'] = reference
                self.save_gates(gates)
                with self.assertRaisesRegex(RuntimeError, 'approval_reference'):
                    self.build()

    def test_unreviewed_license_and_changed_source_are_rejected(self):
        for field, value in (('spdx', 'MIT'), ('source_url', 'https://example.invalid/gpl.txt')):
            with self.subTest(field=field):
                gates = self.stable_fixture()
                gates['code_license'][field] = value
                self.save_gates(gates)
                with self.assertRaisesRegex(RuntimeError, 'Stable release is blocked'):
                    self.build()

    def test_plugin_and_readme_license_and_uri_must_match(self):
        for filename in ('oj-code-lab.php', 'readme.txt'):
            for original, replacement, error in (
                    ('License: GPL-2.0-or-later', 'License: PENDING', 'approved SPDX'),
                    (release.CODE_LICENSES['GPL-2.0-or-later']['license_uri'],
                     'https://example.invalid/license', 'License URI')):
                with self.subTest(filename=filename, original=original):
                    self.stable_fixture()
                    path = self.root / filename
                    path.write_text(path.read_text(encoding='utf-8').replace(original, replacement), encoding='utf-8')
                    with self.assertRaisesRegex(RuntimeError, error):
                        self.build()

    def test_license_declaration_outside_plugin_header_is_rejected(self):
        self.stable_fixture()
        path = self.root / 'oj-code-lab.php'
        main = path.read_text(encoding='utf-8')
        main = main.replace(' * License: GPL-2.0-or-later\n', '')
        main += '\n/*\n * License: GPL-2.0-or-later\n */\n'
        path.write_text(main, encoding='utf-8')
        with self.assertRaisesRegex(RuntimeError, 'approved SPDX'):
            self.build()

    def test_gate_evidence_must_match_release_version(self):
        for verified_version in (None, '1.0.0-rc.4', '0.9.0'):
            with self.subTest(verified_version=verified_version):
                gates = self.stable_fixture()
                gates['gates'][2]['verified_version'] = verified_version
                self.save_gates(gates)
                with self.assertRaisesRegex(RuntimeError, 'exact version'):
                    self.build()

    def test_stable_header_current_and_target_must_match(self):
        for field in ('current', 'target'):
            with self.subTest(field=field):
                gates = self.stable_fixture()
                gates[field] = '1.0.0-rc.4'
                self.save_gates(gates)
                with self.assertRaisesRegex(RuntimeError, 'exact version'):
                    self.build()
        self.stable_fixture()
        path = self.root / 'oj-code-lab.php'
        path.write_text(path.read_text(encoding='utf-8').replace(' * Version: 1.0.0', ' * Version: 1.0.0-rc.4'), encoding='utf-8')
        with self.assertRaisesRegex(RuntimeError, 'plugin header version differs'):
            self.build()

    def test_missing_public_document_is_rejected(self):
        (self.root / 'docs/PRIVACY.md').unlink()
        with self.assertRaisesRegex(RuntimeError, 'documentation is missing'):
            self.build()


    def test_reviewed_additional_permission_ships_with_exact_manifest_scope(self):
        gates = self.permission_fixture()
        expected = gates['code_license']['additional_permission']
        result = self.build()
        manifest = json.loads(Path(result['archive']).with_suffix('.manifest.json').read_text(encoding='utf-8'))
        self.assertEqual(manifest['additional_permission'], expected)
        with zipfile.ZipFile(result['archive']) as archive:
            notice = archive.read('oj-code-lab/' + expected['file'])
            self.assertEqual(hashlib.sha256(notice).hexdigest(), expected['sha256'])
            self.assertEqual(hashlib.sha256(archive.read('oj-code-lab/LICENSE.txt')).hexdigest(),
                             release.CODE_LICENSES['GPL-2.0-or-later']['text_sha256'])

    def test_missing_additional_permission_text_is_rejected(self):
        gates = self.permission_fixture()
        (self.root / gates['code_license']['additional_permission']['file']).unlink()
        with self.assertRaisesRegex(RuntimeError, 'text is missing or changed'):
            self.build()

    def test_modified_permission_text_cannot_be_authorized_by_rehashing_metadata(self):
        gates = self.permission_fixture()
        record = gates['code_license']['additional_permission']
        notice = self.root / record['file']
        notice.write_bytes(notice.read_bytes() + b'Unreviewed expanded rights\n')
        with self.assertRaisesRegex(RuntimeError, 'text is missing or changed'):
            self.build()
        record['sha256'] = hashlib.sha256(notice.read_bytes()).hexdigest()
        self.save_gates(gates)
        with self.assertRaisesRegex(RuntimeError, 'reviewed registry'):
            self.build()

    def test_permission_file_and_markers_require_declaration_even_for_rc(self):
        gates = self.permission_fixture()
        gates['code_license'].pop('additional_permission')
        self.save_gates(gates)
        self.result['version'] = '1.0.0-rc.4'
        with self.assertRaisesRegex(RuntimeError, 'approval metadata is missing'):
            self.build()
        (self.root / 'PLATFORM-LINKING-EXCEPTION.txt').unlink()
        with self.assertRaisesRegex(RuntimeError, 'approval metadata is missing'):
            self.build()

    def test_undeclared_permission_text_without_markers_is_rejected(self):
        self.stable_fixture()
        shutil.copyfile(release.ROOT / 'PLATFORM-LINKING-EXCEPTION.txt', self.root / 'PLATFORM-LINKING-EXCEPTION.txt')
        with self.assertRaisesRegex(RuntimeError, 'approval metadata is missing'):
            self.build()

    def test_permission_identifier_path_hash_and_base_license_are_bound(self):
        cases = [('id', 'unreviewed', 'identifier is not reviewed'),
                 ('id', ['invalid'], 'identifier is not reviewed'),
                 ('file', '../PLATFORM-LINKING-EXCEPTION.txt', 'reviewed registry'),
                 ('sha256', '0' * 64, 'reviewed registry')]
        for field, value, message in cases:
            with self.subTest(field=field):
                gates = self.permission_fixture()
                gates['code_license']['additional_permission'][field] = value
                self.save_gates(gates)
                with self.assertRaisesRegex(RuntimeError, message):
                    self.build()
        gates = self.permission_fixture()
        gates['code_license']['spdx'] = 'MIT'
        self.save_gates(gates)
        self.result['version'] = '1.0.0-rc.4'
        with self.assertRaisesRegex(RuntimeError, 'reviewed registry'):
            self.build()

    def test_permission_coverage_cannot_omit_duplicate_or_include_other_files(self):
        for change in (lambda names: names[:-1], lambda names: names + [names[0]],
                       lambda names: names + ['resources/sample-datasets/penguins.csv']):
            gates = self.permission_fixture()
            record = gates['code_license']['additional_permission']
            record['covered_files'] = change(record['covered_files'])
            self.save_gates(gates)
            with self.assertRaisesRegex(RuntimeError, 'covered_files must exactly list'):
                self.build()

    def test_new_runtime_file_must_be_covered_by_permission_record(self):
        self.permission_fixture()
        (self.root / 'resources/js/new.js').write_text('void 0;\n', encoding='utf-8')
        with self.assertRaisesRegex(RuntimeError, 'covered_files must exactly list'):
            self.build()

    def test_source_permission_markers_must_be_present_exact_and_unique(self):
        self.permission_fixture()
        path = self.root / 'resources/js/example.js'
        original = path.read_text(encoding='utf-8')
        reference = ' * ' + release.PERMISSION_REFERENCE + '\n'
        for changed in (original.replace(reference, ''), original.replace(reference, reference * 2),
                        original.replace('SPDX-License-Identifier: GPL-2.0-or-later', 'SPDX-License-Identifier: MIT'),
                        original.replace(reference, reference + ' * Additional permission: see OTHER.txt.\n'),
                        original.replace(reference, reference + ' * SPDX-License-Identifier: MIT\n')):
            path.write_text(changed, encoding='utf-8')
            with self.assertRaisesRegex(RuntimeError, 'source notice is missing, changed or duplicated'):
                self.build()

    def test_permission_text_cannot_be_excluded_from_package_allowlist(self):
        self.permission_fixture()
        original = release.ROOT_FILES
        try:
            release.ROOT_FILES = original - {'PLATFORM-LINKING-EXCEPTION.txt'}
            with self.assertRaisesRegex(RuntimeError, 'text is excluded from the package'):
                self.build()
        finally:
            release.ROOT_FILES = original

    def test_reviewed_vendor_and_licenses_ship_unchanged_without_relicensing(self):
        self.permission_fixture()
        result = self.build()
        with zipfile.ZipFile(result['archive']) as archive:
            for relative, expected in release.VENDOR_FILES.items():
                content = archive.read('oj-code-lab/' + relative)
                self.assertEqual(hashlib.sha256(content).hexdigest(), expected)
                self.assertNotIn(release.PERMISSION_REFERENCE.encode(), content)
        manifest = json.loads(Path(result['archive']).with_suffix('.manifest.json').read_text(encoding='utf-8'))
        self.assertEqual(manifest['vendor_components'], release.VENDOR_COMPONENTS)
        self.assertFalse(set(manifest['additional_permission']['covered_files']) & set(release.VENDOR_FILES))

    def test_missing_or_modified_vendor_runtime_and_notices_are_rejected(self):
        for relative in release.VENDOR_FILES:
            path = self.root / relative
            original = path.read_bytes()
            with self.subTest(relative=relative, mutation='changed'):
                path.write_bytes(original + b'\nModified\n')
                with self.assertRaisesRegex(RuntimeError, 'vendor checksum changed'):
                    self.build()
            with self.subTest(relative=relative, mutation='missing'):
                path.unlink()
                with self.assertRaisesRegex(RuntimeError, 'vendor file or license is missing'):
                    self.build()
            path.write_bytes(original)

    def test_unknown_vendor_code_cannot_bypass_permission_validation(self):
        self.permission_fixture()
        (self.root / 'resources/vendor/markdown-it/extra.js').write_text('alert(1)', encoding='utf-8')
        with self.assertRaisesRegex(RuntimeError, 'Unreviewed vendor runtime'):
            self.build()

    def test_vendor_cannot_be_claimed_by_project_additional_permission(self):
        gates = self.permission_fixture()
        gates['code_license']['additional_permission']['covered_files'].append('resources/vendor/markdown-it/markdown-it.js')
        gates['code_license']['additional_permission']['covered_files'].sort()
        self.save_gates(gates)
        with self.assertRaisesRegex(RuntimeError, 'covered_files must exactly list'):
            self.build()


if __name__ == '__main__':
    unittest.main(testRunner=unittest.TextTestRunner(stream=sys.stdout, verbosity=2))
