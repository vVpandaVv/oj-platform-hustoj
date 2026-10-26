# Bundled Runtime Dependencies

Chart.js 4.5.1, UMD build. MIT license in `chartjs/LICENSE.md`.
Downloaded from `https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js`.
SHA-256: `48444a82d4edcb5bec0f1965faacdde18d9c17db3063d042abada2f705c9f54a`.
The browser loads the local copy, not a remote CDN during class.

ML generation is offline tooling, not a web runtime dependency. Exact versions
and dataset split IDs are embedded in `digit_recognition_v2.php`. Python wheels
and compiler binaries belong in the workspace's `_notes/tmp` tooling directory,
not in the plugin package.
