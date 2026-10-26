# Common Rich Text Editor

The platform uses one locally bundled Jodit adapter for problem description,
input, output, hint, board/notice bodies, and agreement documents.

- Jodit 4.18.1, MIT: https://registry.npmjs.org/jodit/-/jodit-4.18.1.tgz
  Included files are the unchanged `es2021/jodit.min.js`, `es2021/jodit.min.css`
  and `LICENSE.txt` from the official package.
- DOMPurify 3.4.17, Apache-2.0 OR MPL-2.0:
  https://registry.npmjs.org/dompurify/-/dompurify-3.4.17.tgz
  Included files are unchanged `dist/purify.min.js` and `LICENSE` (renamed
  `DOMPurify-LICENSE.txt`).

No editor runtime, language pack, stylesheet, HTML beautifier or source editor
is downloaded from a CDN. Korean translations are included in the Jodit bundle.
Source editing uses its local textarea engine; no Ace dependency is introduced.
The editor is loaded only when a shared rich text field exists on the page.

DOMPurify and the editor's clean-HTML rules sanitize editing/paste/source input.
WordPress KSES remains the authoritative server-side sanitizer. No schema,
addon contract, stored field name, or existing upload endpoint is changed.
Comments remain plain text; post and notice bodies use the common editor.

Run `tests/Visual/common-editor.cjs` with Playwright and Node to verify desktop
and mobile behavior. `tests/Architecture/common-editor.php` pins vendor hashes.
