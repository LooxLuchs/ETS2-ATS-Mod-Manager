# Validation — 2026-10-14

Extension: `ets2-ats-mod-manager` 0.5.0 (first GitHub release)

- Panel: `pterodactyl/panel`, `2.0-develop`, commit `8e07ac97ab42d6b65b144ebf81d5f47da4f4fad1`.
- SDK: `2.0.0-beta.4`; PHP: `8.5.4`; Node: `22.22.1`; MariaDB: `11.8.6`.
- PHP unit/scenario tests: **23 passed**.
- Frontend SDK-host tests: **39 passed**.
- Panel HTTP integration: **39 passed**, **289 assertions**. Tests use real Panel middleware, MySQL/MariaDB, and fake Wings file contracts.
- TypeScript type check, production Vite build, PHP syntax checks and `p:extension:doctor`: passed.
- Extension package: ZIP integrity and runtime contents checked; SDK caches and node_modules excluded.
- `.pteroext` installed and enabled in the isolated development Panel with `--replace`.
- SHA-256 is recorded in the release's `ets2-ats-mod-manager-0.5.0.sha256`.

The earlier version numbers below refer to internal development snapshots. All of their implemented features are included in the first GitHub release, 0.5.0. Development, documentation and tests were AI-assisted. The release package includes the MIT license.

The pinned Panel emits eight PDO constant deprecations under PHP 8.5; these originate in its database configuration. The SDK test runtime emits a circular-dependency warning. Tests have no assertion failures.

No real Wings node, ETS2/ATS game process or matching game-generated DAT pair was available. Game compatibility of edited SII packages remains unverified. DAT files are preserved byte-for-byte; Mod removal is explicitly experimental.

Retained regression coverage: delayed page visibility without a temporary 404, disabled/unauthorized access, serialized refreshes, downloads without invalidation, and background-worker lock contention during overview, detail, import and download. Genuine lock contention still times out after 30 seconds without bypassing mutual exclusion.

Retained workflow coverage: name dialog and cancellation, immediate drag-and-drop, inactive Save before changes and after undo, unsaved draft retention on errors, permission checks for active removal, stale active hash rejection, active backups and rollback, and unchanged template files after active edits.

New coverage in 1.2.0: successive and combined drops, local staging until Apply, wrong/duplicate/empty/oversized uploads, retry after failure, double-submit prevention, preset revision on quick activation, disabled/read-only access, hidden console tabs, lightweight server-scoped preset summaries, background lock waiting, pending-operation blocking, active-only replacement, backups and rollback without any preset changes. Pointer sorting checks live previews, target midpoint, Escape, pointer cancellation and out-of-list release, including retention of earlier unsaved edits.

Chromium headless browser check against the real SDK test host: 70-row live mouse sorting and release, touch sorting at 390px width, successive Quick Drop files without network writes, Apply-only multipart request, and no horizontal overflow on the mobile Console. This used an HTTP adapter fake, not a live Wings node. No browser page errors occurred.

Validation for 1.2.1: English labels, dialogs, accessibility names, settings and API errors pass the existing SDK/HTTP regressions. TypeScript and PHP syntax checks pass. A scan found no remaining German extension-owned text in source, README or changelog. User-supplied package names and fixture contents are preserved.

Chromium additionally checked separate preset/upload cards, a preset card at most 320px wide and shorter than Quick Drop, selection and activation in one row, light/dark token colors and a live theme change without reloading. Mobile width still has no horizontal overflow. Console placement uses the native `server.console.after` slot; the pinned Panel renders that anchor after Console, details and StatGraphs. Browser checks use the SDK test host and an HTTP adapter fake; they do not render the full live Panel or its core stylesheet.

Validation for 1.3.0: Quick Switcher saving reads the installed pair only on demand, opens a required-name dialog and posts its hashes to the existing import endpoint. Tests cover local name validation, aborting an unfinished read, missing/invalid active files, retained name on errors, duplicate prevention, list refresh and selection of the new preset. Staged Quick Drop files stay unchanged. HTTP integration checks a new preset UUID, unchanged existing library/active files, stale hash rejection, read-only denial and nonmember denial. No new backend endpoint or permission bypass was introduced.

Chromium 1.3.0 check also opens the new name dialog, submits it with Enter, checks the exact import name/hashes, observes the new selected preset and verifies that both staged files remain available for a later Apply. This keyboard submission uses the SDK test host without the full Panel core stylesheet; full live Panel dialog pointer interaction was not tested.

Validation for 1.3.1: original 1.3.0 package reproduced the Apply conflict with a fake that rejects existing rename destinations, matching Wings. Current replacement succeeds, retains verified originals and does not upload backup bytes. Standalone tests cover crashes after moving originals, non-overwriting recovery of legacy copy journals, interrupted recovery and foreign files created in the swap gap. HTTP checks include applying twice, JSON key reorder during matching/restore, metadata-only preset detail, current save with exactly one source DAT read, ACLs, invalid files and linear safe directory traversal.

IO probe with a 1 MiB DAT and the 70-mod SII fixture: 1.3.0 made 4 writes / 2,133,022 payload bytes before failing; 1.3.1 succeeds with 2 writes / 1,066,511 payload bytes. Equal-size old/new pairs halve uploaded file bytes. Four-level directory creation makes 4 list calls instead of 10. Preset detail makes 0 Wings reads/lists. These are fake-contract IO counts, not production timings; no live node was available.

Quick Save no longer snapshots at dialog opening; it captures the currently installed pair at confirmation. Existing snapshot imports retain their stale-hash checks. The new current-save endpoint is create-only and never replaces active or existing preset files.

Wings behavior was checked in source commit `5d9e2c34b6692ae48b1afb1b7bb61694439267b2`, `internal/ufs/fs_unix.go` (Rename rejects an existing destination). Earlier fakes had incorrectly permitted overwrite and have been corrected.

Chromium 1.3.1 checks the immediate Quick Save dialog with no new GET request on opening, the name-only current-save request, the new selected preset, unchanged staged files and subsequent Apply. Existing sorting, touch, mobile and live-theme checks pass. The browser still uses the isolated SDK host and adapter fake, not a live Panel/Wings connection.

Validation for 1.4.0: the public extension identity, package, source directory, provider, namespace, migration, routes, activity identifiers and internal storage use **ETS2-ATS-Mod-Manager** naming. The server navigation and server page use **Mod Manager**. The upgrade migration renamed all three existing database tables successfully in the isolated Panel. An integration regression verifies that the first locked server operation moves the complete former hidden library to the new directory when no interrupted operation is pending, while existing operation journals remain recoverable before that move.

Validation for 1.4.1: staged uploads, restore copies and displaced files now live in `/.ets2-ats-mod-manager/operations/<id>/`. Standalone scenarios verify that every working-file write stays in this directory on success, rollback and failure at each of the four swap renames. Recovery resumes interruptions from both previous displaced filename prefixes without creating working files beside the game packages. The HTTP Apply regression verifies two internal staged writes, unchanged backup upload counts and exactly the two canonical package filenames in the game directory after successive replacements. Existing leftovers are retained, and no live server files were cleaned up.
