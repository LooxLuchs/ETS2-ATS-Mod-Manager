# Changelog

## Unreleased

- Stream DAT uploads, ZIP entries, Wings reads, integrity checks, recovery writes and ZIP downloads through bounded buffers and temporary files. Reuse verified source hashes for edits and restores.
- Share ancestor inspections within pair reads, digest checks and rename preflights; inspect again on subsequent operations.
- Return compact preset summaries, filter activation history by hashes in SQL and add indexes for library, pending-operation and snapshot queries.
- Cache validated SII documents within a request, store tokens compactly, use indexed mod IDs and build edited text in one pass.
- Limit packages and order requests to 120 mods; show a notice from 100 mods in Mod Manager and Console quick controls.
- Refresh library-only changes without re-reading the installed DAT; retain full refreshes for file changes and errors.
- Memoize mod rows, measure only affected animation positions and skip repeated stationary drag hit tests.
- Share upload validation, permissions, query keys and timeouts. Read the pinned Panel revision from compatibility.json in local SDK preparation and CI.

## 0.5.2

- Removed the blue game/server-packages label above the Mod Manager heading.

## 0.5.1

- Added previews of Mod Manager and Console Quick Switcher to the repository README.
- Simplified the installation guide to require only the `.pteroext` download.
- Updated version metadata and packaged documentation for the new release.

## 0.5.0 — First release

This is the first GitHub release and an early development version. Development, documentation and automated tests were AI-assisted.

- Native Pterodactyl v2 extension for ETS2 and ATS with per-egg game and path configuration, server placeholders and permission checks.
- Upload complete SII/DAT pairs or a ZIP, keep named presets, activate packages, import the active files, download packages and restore preset backups.
- Display mod and Workshop counts, map module, dates, load order and Workshop links.
- Reorder mods with mouse or touch, save changed drafts and remove mods with an experimental-change confirmation.
- Console Quick Switcher with compact preset activation, save-as-preset and a two-file Quick Drop that applies only on confirmation.
- Verified backups, server locking, stale-file checks and interrupted-operation recovery. All new working files stay inside the extension directory.
- Theme-aware interface and English labels, messages and documentation.
- MIT license, installation and usage guides, and GitHub Actions build/test/package and prerelease publishing.
