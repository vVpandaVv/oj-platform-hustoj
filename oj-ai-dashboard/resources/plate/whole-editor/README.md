# Plate Whole Editor Runtime

<!-- HARNESS: plate-whole-editor-runtime -->

This folder contains the physically merged Plate template editor source tree used by the worksheet module.

The runtime contract keeps these layers together:

- `PlateProvider`
- `EditorShell`
- `Toolbar`
- `FloatingToolbar`
- `SlashMenu`
- `ContextMenu`
- `PortalRoot`

Custom worksheet response blocks, schema extraction, REST persistence, and template import/export are extension layers. They must not replace the whole editor structure with separately assembled Plate packages.
