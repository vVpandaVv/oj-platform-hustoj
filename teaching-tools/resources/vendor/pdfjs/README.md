# PDF.js 6.4.299

Local browser assets from the Mozilla `pdfjs-dist` npm package, version 6.4.299.
Source: https://github.com/mozilla/pdf.js
Distribution: https://registry.npmjs.org/pdfjs-dist/-/pdfjs-dist-6.4.299.tgz

The upstream source contents are unmodified. `build/pdf.mjs` and
`build/pdf.worker.mjs` are distributed as `build/pdf.js` and `build/pdf.worker.js`
so servers without an MJS MIME mapping serve executable JavaScript. They remain
ES modules. Other components are CMaps, standard fonts, ICC profiles and WASM decoders.
The main project uses Apache License 2.0; see `LICENSE`.
Supporting assets retain their upstream license files in `cmaps/`,
`standard_fonts/`, `iccs/` and `wasm/`.

Teaching Tools loads these assets from the same site and fetches learning
materials with the current user's credentials. It does not copy PDF files to
another storage location or bypass the material provider's access checks.
