# OJ Realtime Hub

`Teaching Tools` and future classroom addons can share this small WebSocket relay. The current integration uses separate `teaching-tools / whiteboard`, `teaching-tools / quickboard`, and `teaching-tools / classroom` namespaces. Connections are admitted only with short-lived HMAC tickets issued by WordPress. Viewers cannot change snapshots or host events. The classroom channel accepts only validated signal events, while quickboard accepts only a bounded board-revision notice; the user ID always comes from the signed ticket.

Do not run this folder directly from the public web root. Use the one-line installer shown in **교실 도구 → 설정**, which copies it to `/opt/oj-realtime-hub`, installs the systemd service, adds the nginx WebSocket location, validates nginx, and starts the service. Running the same command again updates the installation.

Requirements: Ubuntu or Debian, systemd, nginx, and root access. The installer adds Node.js 24 when it is not already available. If the WebSocket connection is unavailable, Teaching Tools automatically uses its WordPress REST polling fallback.

The settings page compares the authenticated installed module and hub versions against the code bundled with the plugin. After uploading a new plugin ZIP, run the displayed `--update` command to preserve the installed configuration while updating server code. This is a bundled-version comparison, not an internet release lookup.

The module advertises application heartbeat support in `ready` and responds to `ping` with `pong`. The browser detects silent connections and reconnects; older modules remain supported without the heartbeat requirement. Whiteboard viewers retain periodic REST recovery even while connected, and use state revisions to avoid replacing newer ink with stale backups.

The legacy standalone `src/setup.js` helper reads its setup code only from standard input. Do not place the code in a command argument or shell history.
