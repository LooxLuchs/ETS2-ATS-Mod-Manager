# ETS2-ATS-Mod-Manager

Native Pterodactyl v2 extension for Euro Truck Simulator 2 and American Truck Simulator: server package presets, metadata, Workshop links and editable mod load order.

**Current version: 0.5.1. This project is in early development; 0.5.0 was its first release. Development, documentation and tests were AI-assisted.** See the [main README](https://github.com/LooxLuchs/ETS2-ATS-Mod-Manager#readme) for a step-by-step installation and usage guide. The project is [MIT licensed](https://github.com/LooxLuchs/ETS2-ATS-Mod-Manager/blob/main/LICENSE); the license is included in the packaged extension.

## Installation

Download `ets2-ats-mod-manager-0.5.1.pteroext` from [GitHub Releases](https://github.com/LooxLuchs/ETS2-ATS-Mod-Manager/releases), or from a successful build workflow's artifacts. Run this from the Panel directory:

```sh
php artisan p:extension:install /path/ets2-ats-mod-manager-0.5.1.pteroext --enable --replace
```

When upgrading from a release with the former extension identity, disable and remove that installed extension first, then install this package. Pterodactyl retains its database tables on removal; activation and preset records are renamed automatically when this release is enabled. The hidden preset directory on each server is moved to the new path on its first operation after any interrupted operation has been recovered.

Add `--replace` when updating an existing installation. Reload the Panel afterwards.

Under **Admin → ETS2-ATS-Mod-Manager**, create an activation for each supported egg: select the game, package directory and enabled status. The extension does not change egg configuration or startup arguments. The directory must match the location from which the game server loads its packages.

Typical Linux egg paths:

| Game | Package directory in the server filesystem |
| --- | --- |
| ETS2 | `/.local/share/Euro Truck Simulator 2` |
| ATS | `/.local/share/American Truck Simulator` |

`/` means the server root, normally `/home/container` inside the running container. An entered `/home/container/…` path maps to this root. Wings cannot manage paths outside the server filesystem. The files must be named `server_packages.sii` and `server_packages.dat`.

Validated against Panel commit `8e07ac97ab42d6b65b144ebf81d5f47da4f4fad1` on `2.0-develop` and SDK `2.0.0-beta.4`. Pterodactyl v2 is in development; see [compatibility.json](compatibility.json). MySQL or MariaDB is required. Pterodactyl 1.x and Blueprint are not supported. Custom Buttons does not need to be installed.

## Using the manager

Enabled eggs show **Mod Manager** in server navigation. The manager opens the currently installed package files. It cannot determine whether the running game process has loaded them. The interface, extension errors and help text use English. Uploaded preset and mod names retain their original text.

- **Console → Mod Manager - Quick Switcher:** Located below the Console and statistics. Preset Quick Select and Quick Drop have separate cards. Colors follow the active Panel theme.
- **Preset Quick Select:** Choose a saved preset, then press **Activate**. Selection alone does not change files. The selector and activation button share a compact row.
- **Quick Switcher → Save as preset:** Opens a name dialog and saves the active server files as a new preset. Existing presets and staged Quick Drop files stay unchanged. The new preset appears in Quick Select immediately. The name dialog opens immediately. The current pair is read once from the active directory when saving, so it captures the files installed at confirmation. Staged and stored preset files are still verified before completion. This action needs no server restart.
- **Quick Drop:** Drop or choose `server_packages.sii` and `server_packages.dat` together or one at a time. Check marks show the staged files. Remove individual files or drop a replacement to change the selection. Only **Apply** uploads a complete pair, backs up the previous files and replaces the active package. Saved presets stay unchanged. Failed requests retain the selection for retry. Leaving the Console or reloading discards the local selection. Restart the game server afterwards.
- **Upload preset:** Enter a name and choose both files together or one at a time; alternatively, upload a ZIP. **Save** validates the complete pair. Incomplete uploads are rejected. Staged files stay local until saving and do not survive a reload.
- **ZIP:** Exactly one package pair in the same directory, including a subdirectory. Other safe files are ignored. Duplicate package files, unsafe paths, symlinks, encrypted entries and extraction limits are rejected.
- **Save as preset:** Enter a name in the dialog to save the active pair as a new preset. An already matching preset can also be saved under a new name.
- **Activate:** Replaces both active files, including while the server is running. No power action is performed. Restart the game server to load the package. Two file renames are not an atomic pair replacement; a game process starting during a switch could read an intermediate state.
- **Load order:** Drag a mod by its handle with mouse or touch, or use the arrow buttons. Rows preview their positions during dragging, animate briefly and scroll at viewport edges. Escape or release outside the list restores the draft from before that drag. Highest priority appears first, matching the last `mod_list` entry in the SII.
- **Save:** Disabled until changes exist. Preset changes overwrite that preset with a backup; activate it separately to update the active files. Changes to the active package update only the server files with a backup, even without a matching preset. Saved presets stay unchanged. Restart the game server after active changes.
- **Reset:** Discards unsaved changes. Returning to the original order disables Save again. Package selection and activation stay disabled while a draft is dirty.
- **Remove mod:** Requires explicit confirmation of the experimental operation and `file.delete`. The mod reference and its SII unit are removed; counts and indices are updated. Map data is not regenerated.
- **Restore backup:** Replaces the selected preset with a previous version. The current preset is backed up first. Activate it to update the active package.
- **Download:** Downloads the stored package pair as a ZIP. **Delete preset** removes the library files; active server files are retained.

Metadata includes total mods, Workshop mods, map module from `map_name`, import date and separate export date from `time_stamp`. Dates use English formatting in the browser's local timezone. Truncated mod names are shown as exported. Workshop links use `mod_id`; no Steam API key or metadata lookup is needed.

## Path placeholders

Names and server values match [Custom Buttons v1.0.1](https://github.com/pterodactyl/extensions/blob/v1.0.1/custom-buttons/src/Services/UrlTemplate.php):

| Placeholder | Value |
| --- | --- |
| `{{env.P_SERVER_UUID}}` | Server UUID |
| `{{env.P_SERVER_UUID_SHORT}}` | Short server ID |
| `{{env.P_SERVER_NAME}}` | Server name |
| `{{env.P_SERVER_ID}}` | Internal server ID |
| `{{env.P_SERVER_ALLOCATION_IP}}` | Primary allocation IP |
| `{{env.P_SERVER_ALLOCATION_PORT}}` | Primary allocation port |
| `{{env.P_SERVER_NODE}}` | Node name |
| `{{env.P_SERVER_OWNER}}` | Owner username |

The editor inserts a placeholder at the cursor. Values are encoded as one safe path segment: a space in a server name becomes `%20`. Literal directory names such as `Euro Truck Simulator 2` retain spaces. Values cannot inject subdirectories. Unknown or empty placeholders, traversal and symlink targets are rejected. `/.ets2-ats-mod-manager` is reserved for internal storage.

## Permissions and storage

Backend endpoints independently check server membership and file permissions. Owners and root administrators follow standard Panel permissions. Only root administrators can configure egg activations.

| Action | Subuser permissions |
| --- | --- |
| View metadata and download | `file.read`, `file.read-content` |
| Upload, Quick Drop / Apply, import, rename, edit, activate, restore | Additionally `file.create`, `file.update` |
| ZIP import | Additionally `file.archive` |
| Delete a preset or remove a mod | Additionally `file.delete` |

Presets and backups live in the server filesystem:

```text
/.ets2-ats-mod-manager/
  presets/<preset-uuid>/server_packages.sii
  presets/<preset-uuid>/server_packages.dat
  operations/<operation-uuid>/server_packages.sii
  operations/<operation-uuid>/server_packages.dat
  operations/<operation-uuid>/staged/server_packages.{sii,dat}
  operations/<operation-uuid>/restore/server_packages.{sii,dat}
  operations/<operation-uuid>/displaced/server_packages.{sii,dat}
```

Tables `ext_ets2_ats_mod_manager_activations`, `ext_ets2_ats_mod_manager_presets` and `ext_ets2_ats_mod_manager_operations` hold configuration, metadata, hashes and recovery status. Quick Drop replacements and direct active edits are also backed up under `operations/<operation-uuid>/`. Failed switches can be recovered through their operation status. Backups of successfully saved active files are available in the file manager. Back up both server files and extension tables. Disabling or removing the extension retains data according to the Panel's behavior.

A MySQL/MariaDB session lock serializes extension file operations per server across workers. Overlapping operations wait up to 30 seconds; acquisition and release use the same write connection. New staged files are verified before a switch. Existing files are moved into the operation backup, verified there, and then replaced. This avoids Wings rename-over-existing errors and avoids re-uploading old bytes for a backup. Recovery also supports older copy-based journals. Failures roll back the pair. Interrupted requests appear with **Recover** next time the manager opens; writes stay blocked until recovery. If the egg activation was disabled, an administrator must enable it again for recovery.

Console preset summaries and preset detail views use the last saved database metadata and require no Wings file reads. Selecting a preset therefore does not download its DAT again. Activation, editing, restoration and downloads still verify the package files. Quick Drop files remain in the browser until Apply. Directory creation inspects each ancestor once; inspections are not cached across file operations.

Hashes detect changes through SFTP or the file manager. Externally changed presets are not silently overwritten. Recovery refuses to overwrite later external changes to target files; the owner or administrator must reconcile such files using the operation journal and backups. SFTP and file manager actions do not share the extension lock. Backups are not automatically removed and count against server storage. Preparation, restore copies and displaced files stay inside the operation directory. Only the two final package filenames are installed in the configured game directory. Recovery can read displaced files left beside the active pair by earlier releases, but creates all new working files in the extension directory. Existing files from earlier releases are retained for recovery.

## Upload limits

Under **Admin → Extensions → ETS2-ATS-Mod-Manager**, defaults are 4 MiB SII and 64 MiB DAT. ZIP size and total uncompressed contents are each limited to their sum; at most 128 archive entries. Limits are configurable there.

For these defaults, use approximately `upload_max_filesize=68M`, `post_max_size=72M`, `memory_limit=512M` and a matching web server limit. Larger DAT limits need more PHP memory: multiple file versions are held for verification and backups. Uploads pass through the Panel to Wings. PHP and web server limits also apply.

## Package editing limits

SCS describes `server_packages.dat` as internal map data, exported with the SII using `export_server_packages`. See [SCS Dedicated Server](https://modding.scssoft.com/wiki/Documentation/Tools/Dedicated_Server).

The extension validates complete pairs and SII structure. It cannot prove that arbitrary DAT contents match a given SII. Mod edits preserve the DAT byte-for-byte and do not regenerate it. Edited packages may therefore be incompatible with the game, particularly after removing map mods. Original exports remain available in backups.

No real ETS2/ATS game process or valid game-generated binary pair was available in development. Automated tests validate parser, UI, Panel and file transaction behavior; they do not establish game compatibility of edited packages.

## Development and tests

Based on [Building Extensions](https://docs.pterodactyl.io/v2/extensions/building) and [Testing and Packaging](https://docs.pterodactyl.io/v2/extensions/testing-and-packaging). Requires Node 22.12 or newer, npm 11, PHP 8.3 or newer with `mbstring` and `zip`; Panel integration also requires its Composer extensions, including `intl` and `pdo_mysql`.

From the extension directory:

```sh
# Fetch the pinned Panel, build its SDK test runtime and copy the SDK.
npm run prepare:sdk
# Alternatively: npm run prepare:sdk -- /path/to/pinned/panel
npm ci
npm run typecheck
npm test
npm run test:php
npm run build
```

The SDK is stored in `.cache/sdk`; dependencies contain no absolute developer paths. This SDK is not currently published to the public npm registry.

Integration tests require a separate v2 Panel checkout and a dedicated MySQL/MariaDB database with `test` in its name. Prepare Panel Composer dependencies, run Panel migrations and install the built extension, then run from the extension directory:

```sh
PANEL_PATH=/path/panel DB_DATABASE=scs_manager_test DB_USERNAME=testuser DB_PASSWORD=testpass \
  php /path/panel/vendor/bin/phpunit -c tests/phpunit.xml
```

Tests use real Panel authentication and HTTP middleware with fake Wings file contracts. The 70-mod fixture retains order, Workshop positions and relevant escapes from the sample; identifiers and many checksums are test data. PHP 8.5 database configuration deprecations in the pinned Panel are known.

Check and package from the Panel directory:

```sh
php artisan p:extension:doctor /path/ets2-ats-mod-manager
php artisan p:extension:pack /path/ets2-ats-mod-manager --output=/path/artifacts/ets2-ats-mod-manager-0.5.1.pteroext
php /path/ets2-ats-mod-manager/scripts/finalize-package.php /path/artifacts/ets2-ats-mod-manager-0.5.1.pteroext
```

CI validates frontend, PHP parser, Panel endpoints and packaging against the pinned commit. Before production rollout, use one ETS2 and one ATS test server: import a valid export, switch presets and restart; then verify changed order, experimental removal and recovery. Claim game compatibility only for cases actually tested.

The finalization script adds this README, the changelog and the repository MIT license to the archive and writes a SHA-256 sidecar. GitHub Actions publishes the tested package as a workflow artifact. Matching `v<version>` tags create releases marked **Latest** after validation; ordinary pushes and manual builds do not publish releases.
