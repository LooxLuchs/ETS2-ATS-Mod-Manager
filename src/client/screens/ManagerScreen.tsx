import { useEffect, useLayoutEffect, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Button,
  Dialog,
  ServerContentBlock,
  http,
  httpErrorToHuman,
  useCurrentServerRequired,
  useServerPermission,
} from "@pterodactyl/sdk";
import { base, date, detail, download, overview, upload, type Mod, type Preset } from "../api";
import { usePointerSort } from "../usePointerSort";
import { messages } from "../messages";

type Draft = {
  key: string;
  mods: Mod[];
  originalIds: string[];
  hashes?: Record<string, string>;
  presetId?: string;
  revision?: number;
};

export default function ManagerScreen() {
  const server = useCurrentServerRequired();
  const id = server.attributes.identifier;
  const cache = useQueryClient();
  const canWrite = useServerPermission(
    ["file.read", "file.read-content", "file.create", "file.update"],
    false,
  );
  const canDelete = useServerPermission("file.delete");
  const canArchive = useServerPermission("file.archive");
  const [selected, setSelected] = useState("active");
  const [draft, setDraft] = useState<Draft | null>(null);
  const [removeId, setRemoveId] = useState<string>();
  const [uploadOpen, setUploadOpen] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const [importHashes, setImportHashes] = useState<Record<string, string>>({});
  const [files, setFiles] = useState<File[]>([]);
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [renameOpen, setRenameOpen] = useState(false);
  const [restoreId, setRestoreId] = useState<string>();
  const list = useRef<HTMLOListElement>(null);
  const positions = useRef(new Map<string, number>());
  const query = useQuery({
    queryKey: ["ets2-ats-mm", id, "overview"],
    queryFn: ({ signal }) => overview(id, signal),
    retry: false,
  });
  const data = query.data;
  const selectedId = selected === "active" ? data?.active.preset_id : selected;
  const presetQuery = useQuery({
    queryKey: ["ets2-ats-mm", id, "detail", selectedId],
    queryFn: ({ signal }) => detail(id, selectedId!, signal),
    enabled: !!selectedId,
    retry: false,
  });
  const preset = presetQuery.data?.preset;
  const metadata = selected === "active" ? data?.active.metadata : presetQuery.data?.metadata;
  const snapshots = presetQuery.data?.snapshots ?? [];
  const viewKey = `${id}:${selected}`;
  const currentDraft = draft?.key === viewKey ? draft : null;
  const rows = currentDraft?.mods ?? metadata?.mods ?? [];
  const dirty = !!currentDraft;
  const blocked = busy || !!data?.pending.length;
  const canEdit =
    canWrite && !!metadata && (selected === "active" ? !!data?.active.hashes : !!preset);
  const removed = !!currentDraft?.originalIds.some(
    (modId) => !rows.some((mod) => mod.id === modId),
  );
  const currentIsActive = preset && preset.id === data?.active.preset_id;
  const { draggedId, start: startSort } = usePointerSort({
    list,
    key: viewKey,
    enabled: canEdit && !blocked,
    rows,
    before: currentDraft,
    restore: setDraft,
    move,
  });

  useLayoutEffect(() => {
    const elements = list.current?.querySelectorAll<HTMLLIElement>("li[data-mod-id]");
    if (!elements) return;
    const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
    elements.forEach((element) => {
      const previous = positions.current.get(element.dataset.modId!);
      const top = element.getBoundingClientRect().top;
      const delta = previous === undefined ? 0 : previous - top;
      if (delta && !reducedMotion && element.animate) {
        element.getAnimations().forEach((animation) => animation.cancel());
        element.animate([{ transform: `translateY(${delta}px)` }, { transform: "translateY(0)" }], {
          duration: 140,
          easing: "ease-out",
        });
      }
    });
    positions.current.clear();
  }, [rows]);

  useEffect(() => {
    setSelected("active");
  }, [id]);
  useEffect(() => {
    setDraft(null);
    setRemoveId(undefined);
    setError("");
  }, [selected, id]);
  const refresh = async () => {
    await cache.invalidateQueries(
      { queryKey: ["ets2-ats-mm", id, "overview"] },
      { cancelRefetch: false },
    );
    await cache.invalidateQueries(
      { queryKey: ["ets2-ats-mm", id, "detail"] },
      { cancelRefetch: false },
    );
  };
  async function act(
    action: () => Promise<unknown>,
    message = "",
    refreshAfter = true,
    onSuccess?: () => void,
  ) {
    setBusy(true);
    setError("");
    try {
      await action();
      if (message) setNotice(message);
      if (refreshAfter) await refresh();
      onSuccess?.();
    } catch (e) {
      setError(httpErrorToHuman(e));
      if (refreshAfter) await refresh();
    } finally {
      setBusy(false);
    }
  }
  function updateOrder(update: (mods: Mod[]) => Mod[]) {
    if (!canEdit || blocked || !metadata) return;
    setDraft((previous) => {
      const baseline: Draft =
        previous?.key === viewKey
          ? previous
          : {
              key: viewKey,
              mods: [...metadata.mods],
              originalIds: metadata.mods.map((mod) => mod.id),
              ...(selected === "active"
                ? { hashes: { ...data!.active.hashes! } }
                : { presetId: preset!.id, revision: preset!.revision }),
            };
      const mods = update([...baseline.mods]);
      if (
        mods.length === baseline.originalIds.length &&
        mods.every((mod, index) => mod.id === baseline.originalIds[index])
      )
        return null;
      return { ...baseline, mods };
    });
  }
  function move(modId: string, to: number) {
    list.current?.querySelectorAll<HTMLLIElement>("li[data-mod-id]").forEach((element) => {
      positions.current.set(element.dataset.modId!, element.getBoundingClientRect().top);
    });
    updateOrder((mods) => {
      const from = mods.findIndex((mod) => mod.id === modId);
      if (from < 0 || to < 0 || to >= mods.length || from === to) return mods;
      const [mod] = mods.splice(from, 1);
      mods.splice(to, 0, mod);
      return mods;
    });
  }
  async function saveChanges() {
    if (!currentDraft || blocked || draggedId) return;
    const body = {
      order: currentDraft.mods.map((mod) => mod.id),
      confirm_experimental_removal: removed,
    };
    await act(
      () =>
        currentDraft.hashes
          ? http.put(
              `${base(id)}/active/order`,
              { ...body, hashes: currentDraft.hashes },
              { timeout: 300000 },
            )
          : http.put(
              `${base(id)}/presets/${currentDraft.presetId}/order`,
              { ...body, revision: currentDraft.revision },
              { timeout: 300000 },
            ),
      selected === "active" ? messages.activeUpdated : messages.presetEdited,
      true,
      () => setDraft(null),
    );
  }
  function addFiles(incoming: FileList | null) {
    if (!incoming) return;
    const next = Array.from(incoming);
    if (next.some((file) => file.name.toLowerCase().endsWith(".zip"))) setFiles(next);
    else
      setFiles((current) =>
        Array.from(
          new Map(
            [...current.filter((file) => !file.name.toLowerCase().endsWith(".zip")), ...next].map(
              (file) => [file.name, file],
            ),
          ).values(),
        ),
      );
  }
  async function importActive() {
    if (!name.trim()) return;
    await act(async () => {
      const result = await http.post<{ preset: Preset }>(
        `${base(id)}/import`,
        { name: name.trim(), hashes: importHashes },
        { timeout: 300000 },
      );
      setImportOpen(false);
      setSelected(result.data.preset.id);
    }, messages.presetSaved);
  }

  return (
    <ServerContentBlock title="Mod Manager">
      <div className="smm-root">
        <header className="smm-header">
          <div>
            <h1>Mod Manager</h1>
            <p className="smm-muted">Manage server packages, presets and mod load order.</p>
          </div>
          <div className="smm-actions">
            <Button isSecondary disabled={busy || dirty} onClick={() => void refresh()}>
              Refresh
            </Button>
            {canWrite && (
              <Button
                disabled={blocked || dirty}
                onClick={() => {
                  setName("");
                  setFiles([]);
                  setUploadOpen(true);
                }}
              >
                Upload preset
              </Button>
            )}
          </div>
        </header>
        {(error || query.error || presetQuery.error) && (
          <div className="smm-alert smm-error" role="alert">
            {error || httpErrorToHuman(query.error || presetQuery.error)}
          </div>
        )}
        {notice && (
          <div className="smm-alert" role="status">
            {notice}
            <button aria-label="Dismiss notice" onClick={() => setNotice("")}>
              ×
            </button>
          </div>
        )}
        {query.isLoading && <p role="status">Loading server packages …</p>}
        {data?.pending.map((operation) => (
          <div key={operation.id} className="smm-alert smm-warning" role="alert">
            <span>
              A package update was interrupted ({operation.status}). Recover it before making
              further changes.
            </span>
            {canWrite && (
              <Button
                isSecondary
                disabled={busy}
                onClick={() =>
                  void act(
                    () => http.post(`${base(id)}/operations/${operation.id}/recover`),
                    "Operation recovered.",
                  )
                }
              >
                Recover
              </Button>
            )}
          </div>
        ))}
        {data && (
          <div className="smm-layout">
            <aside className="smm-presets" aria-label="Server packages">
              <button
                className={`smm-preset ${selected === "active" ? "smm-selected" : ""}`}
                disabled={dirty || busy}
                onClick={() => setSelected("active")}
              >
                <span className="smm-badge smm-live">Active files</span>
                <strong>
                  {data.presets.find((p) => p.id === data.active.preset_id)?.name ??
                    "Active server package"}
                </strong>
                <small>
                  {data.active.state === "external"
                    ? "Custom package or older preset revision"
                    : data.active.state === "preset"
                      ? "Installed in the package directory"
                      : "Missing or invalid"}
                </small>
              </button>
              <h2>
                Presets <span className="smm-muted">{data.presets.length}</span>
              </h2>
              {!data.presets.length && (
                <p className="smm-muted">
                  No presets yet. Upload a package pair or save the active package as a preset.
                </p>
              )}
              {data.presets.map((p) => (
                <button
                  key={p.id}
                  className={`smm-preset ${selected === p.id ? "smm-selected" : ""}`}
                  disabled={dirty || busy}
                  onClick={() => setSelected(p.id)}
                >
                  <strong>{p.name}</strong>
                  <small>
                    {p.metadata.mod_count} mods ({p.metadata.workshop_count} Workshop mods)
                  </small>
                  <small>{p.metadata.map_name}</small>
                  <small>Imported: {date(p.imported_at)}</small>
                  {p.id === data.active.preset_id ? (
                    <span className="smm-badge smm-live">Active</span>
                  ) : p.id === data.active.source_preset_id ? (
                    <span className="smm-badge">Changes not activated</span>
                  ) : null}
                </button>
              ))}
            </aside>
            <main className="smm-panel">
              <div className="smm-header">
                <div>
                  <h2>
                    {selected === "active" ? "Active server package" : (preset?.name ?? "Preset")}
                  </h2>
                  <p className="smm-muted">
                    {selected === "active"
                      ? data.activation.target
                      : `Revision ${preset?.revision ?? "–"} · Imported ${date(preset?.imported_at)}`}
                  </p>
                </div>
                <div className="smm-actions">
                  {selected === "active" && data.active.metadata && canWrite && (
                    <Button
                      isSecondary
                      disabled={blocked || dirty}
                      onClick={() => {
                        setName("");
                        setImportHashes({ ...data.active.hashes! });
                        setError("");
                        setImportOpen(true);
                      }}
                    >
                      Save as preset
                    </Button>
                  )}
                  {preset && (
                    <Button
                      isSecondary
                      disabled={blocked || dirty}
                      onClick={() => void act(() => download(id, preset.id), "", false)}
                    >
                      Download
                    </Button>
                  )}
                  {selected !== "active" && preset && canWrite && (
                    <Button
                      disabled={blocked || dirty}
                      onClick={() =>
                        void act(
                          () =>
                            http.post(
                              `${base(id)}/presets/${preset.id}/activate`,
                              { revision: preset.revision },
                              { timeout: 300000 },
                            ),
                          messages.activeUpdated,
                        )
                      }
                    >
                      Activate
                    </Button>
                  )}
                </div>
              </div>
              {selected === "active" && data.active.message && (
                <div className="smm-alert smm-warning">{data.active.message}</div>
              )}
              {selected === "active" && data.active.state === "missing" && (
                <p>No server package is installed in the configured directory.</p>
              )}
              {metadata && (
                <>
                  <div className="smm-stats">
                    <div>
                      <strong>{rows.length}</strong>
                      <span>
                        {" "}
                        mods ({rows.filter((mod) => mod.workshop_mod).length} Workshop mods)
                      </span>
                    </div>
                    <div>
                      <strong>{metadata.map_name}</strong>
                      <span>Map module</span>
                    </div>
                    <div>
                      <strong>{date(metadata.exported_at)}</strong>
                      <span>Export date</span>
                    </div>
                  </div>
                  <div className="smm-header">
                    <div>
                      <h3>Load order</h3>
                      <p className="smm-muted">
                        Highest priority first
                        {canEdit
                          ? " · Drag the handle or use the arrows"
                          : " · reverse of the SII entry order"}
                      </p>
                    </div>
                    {canEdit && (
                      <div className="smm-actions">
                        {dirty && (
                          <Button
                            isSecondary
                            disabled={busy || !!draggedId}
                            onClick={() => {
                              setDraft(null);
                              setError("");
                            }}
                          >
                            Reset
                          </Button>
                        )}
                        <Button
                          color={dirty ? "primary" : "grey"}
                          disabled={blocked || !dirty || !!draggedId}
                          onClick={() => void saveChanges()}
                        >
                          Save
                        </Button>
                      </div>
                    )}
                  </div>
                  {canEdit && (
                    <div className="smm-draft-status" aria-live="polite">
                      <div
                        className="smm-muted"
                        aria-hidden={dirty}
                        style={{ visibility: dirty ? "hidden" : "visible" }}
                      >
                        Changes are applied only when you save. Cancelling a drag restores your
                        previous order.
                      </div>
                      <div
                        className="smm-alert smm-warning"
                        aria-hidden={!dirty}
                        style={{ visibility: dirty ? "visible" : "hidden" }}
                      >
                        Unsaved changes. Saving backs up the previous package. Map data stays
                        unchanged.
                      </div>
                    </div>
                  )}
                  <ol ref={list} className="smm-mods" aria-label="Mod load order">
                    {rows.map((mod, index) => (
                      <li
                        key={mod.id}
                        data-mod-id={mod.id}
                        className={draggedId === mod.id ? "smm-dragging" : undefined}
                        draggable={false}
                        onDragStart={(e) => e.preventDefault()}
                      >
                        {canEdit && (
                          <span
                            className="smm-drag-handle"
                            aria-hidden="true"
                            onPointerDown={(e) => startSort(e, mod.id)}
                          >
                            ⠿
                          </span>
                        )}
                        <span className="smm-position">{index + 1}</span>
                        <div className="smm-mod-name">
                          <strong>{mod.mod_name || mod.package_name}</strong>
                          <small>{mod.package_name}</small>
                        </div>
                        <div className="smm-tags">
                          {mod.optional_mod && <span className="smm-badge">Optional</span>}
                          {mod.workshop_url && (
                            <a
                              href={mod.workshop_url}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="smm-badge smm-link"
                            >
                              Workshop ↗
                            </a>
                          )}
                        </div>
                        {canEdit && (
                          <div className="smm-row-actions">
                            <button
                              disabled={blocked || index === 0}
                              aria-label={`Move ${mod.mod_name} up`}
                              onClick={() => move(mod.id, index - 1)}
                            >
                              ↑
                            </button>
                            <button
                              disabled={blocked || index === rows.length - 1}
                              aria-label={`Move ${mod.mod_name} down`}
                              onClick={() => move(mod.id, index + 1)}
                            >
                              ↓
                            </button>
                            {canDelete && (
                              <button
                                disabled={blocked}
                                aria-label={`Remove ${mod.mod_name}`}
                                onClick={() => setRemoveId(mod.id)}
                              >
                                ×
                              </button>
                            )}
                          </div>
                        )}
                      </li>
                    ))}
                  </ol>
                  {!rows.length && (
                    <p className="smm-muted">This server package contains no mods.</p>
                  )}
                </>
              )}
              {selected !== "active" && preset && canWrite && (
                <div className="smm-footer">
                  <div className="smm-actions">
                    <Button
                      isSecondary
                      disabled={blocked || dirty}
                      onClick={() => {
                        setName(preset.name);
                        setRenameOpen(true);
                      }}
                    >
                      Rename
                    </Button>
                    {canDelete && (
                      <Button.Danger
                        disabled={blocked || dirty}
                        onClick={() => setConfirmDelete(true)}
                      >
                        Delete preset
                      </Button.Danger>
                    )}
                  </div>
                  {!!snapshots.length && (
                    <label>
                      Restore backup
                      <select
                        aria-label="Restore backup"
                        value=""
                        disabled={blocked || dirty}
                        onChange={(e) => setRestoreId(e.target.value || undefined)}
                      >
                        <option value="">Select a backup …</option>
                        {snapshots.map((s) => (
                          <option key={s.id} value={s.id}>
                            Revision {s.revision} · {date(s.created_at)}
                          </option>
                        ))}
                      </select>
                    </label>
                  )}
                </div>
              )}
              {currentIsActive && (
                <p className="smm-muted smm-footnote">
                  The active files match this preset. Restart the server to load them into the game.
                </p>
              )}
            </main>
          </div>
        )}
        <Dialog
          open={importOpen}
          onClose={() => {
            if (!busy) setImportOpen(false);
          }}
          title="Save as preset"
          preventExternalClose={busy}
        >
          <form
            className="smm-root smm-form"
            onSubmit={(e) => {
              e.preventDefault();
              void importActive();
            }}
          >
            <label>
              Preset name
              <input
                required
                autoFocus
                maxLength={100}
                disabled={busy}
                value={name}
                onChange={(e) => setName(e.target.value)}
              />
            </label>
            {error && (
              <p role="alert" className="smm-error">
                {error}
              </p>
            )}
            <div className="smm-actions">
              <Button isSecondary disabled={busy} onClick={() => setImportOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" isLoading={busy} disabled={!name.trim()}>
                Save as preset
              </Button>
            </div>
          </form>
        </Dialog>
        <Dialog
          open={uploadOpen}
          onClose={() => {
            if (!busy) setUploadOpen(false);
          }}
          title="Upload preset"
          preventExternalClose={busy}
        >
          <form
            className="smm-root smm-form"
            onSubmit={(e) => {
              e.preventDefault();
              void act(async () => {
                const result = await upload(id, name, files);
                setUploadOpen(false);
                setSelected(result.preset.id);
              }, "Preset uploaded.");
            }}
          >
            <label>
              Preset name
              <input
                required
                maxLength={100}
                value={name}
                onChange={(e) => setName(e.target.value)}
              />
            </label>
            <label>
              Server package
              <input
                aria-label="Server package files"
                type="file"
                multiple
                accept={canArchive ? ".sii,.dat,.zip" : ".sii,.dat"}
                onChange={(e) => {
                  addFiles(e.target.files);
                  e.target.value = "";
                }}
              />
            </label>
            <p className="smm-muted">
              Add both files together or one at a time
              {canArchive ? ", or upload a ZIP" : ""}. The package pair is validated when you save.
            </p>
            <ul>
              {files.map((file) => (
                <li key={file.name}>
                  {file.name}{" "}
                  <button
                    type="button"
                    aria-label={`${file.name} aus Upload remove`}
                    onClick={() => setFiles((current) => current.filter((f) => f !== file))}
                  >
                    ×
                  </button>
                </li>
              ))}
            </ul>
            <p className="smm-muted">
              SII up to {(data?.limits.sii ?? 0) / 1048576} MiB · DAT up to{" "}
              {(data?.limits.dat ?? 0) / 1048576} MiB
            </p>
            {error && (
              <p role="alert" className="smm-error">
                {error}
              </p>
            )}
            <Button type="submit" isLoading={busy}>
              Save
            </Button>
          </form>
        </Dialog>
        <Dialog
          open={!!removeId}
          onClose={() => setRemoveId(undefined)}
          title="Remove mod (experimental)"
        >
          <div className="smm-root smm-form">
            <p>
              Removing mods is experimental and may make the server package unusable. Map data is
              not regenerated.
            </p>
            <Button.Danger
              onClick={() => {
                updateOrder((mods) => mods.filter((mod) => mod.id !== removeId));
                setRemoveId(undefined);
              }}
            >
              Remove mod
            </Button.Danger>
          </div>
        </Dialog>
        <Dialog
          open={confirmDelete}
          onClose={() => {
            if (!busy) setConfirmDelete(false);
          }}
          title="Delete preset"
          preventExternalClose={busy}
        >
          <div className="smm-root smm-form">
            <p>Delete this preset from the library. The active server package stays unchanged.</p>
            <Button.Danger
              isLoading={busy}
              onClick={() =>
                void act(async () => {
                  await http.delete(`${base(id)}/presets/${preset!.id}`, {
                    data: { revision: preset!.revision },
                  });
                  setConfirmDelete(false);
                  setSelected("active");
                }, "Preset deleted.")
              }
            >
              Delete
            </Button.Danger>
          </div>
        </Dialog>
        <Dialog
          open={renameOpen}
          onClose={() => {
            if (!busy) setRenameOpen(false);
          }}
          title="Rename preset"
          preventExternalClose={busy}
        >
          <form
            className="smm-root smm-form"
            onSubmit={(e) => {
              e.preventDefault();
              void act(async () => {
                await http.put(`${base(id)}/presets/${preset!.id}/name`, {
                  name,
                  revision: preset!.revision,
                });
                setRenameOpen(false);
              });
            }}
          >
            <label>
              Preset name
              <input
                required
                maxLength={100}
                value={name}
                onChange={(e) => setName(e.target.value)}
              />
            </label>
            {error && <p role="alert">{error}</p>}
            <Button type="submit" isLoading={busy}>
              Save
            </Button>
          </form>
        </Dialog>
        <Dialog
          open={!!restoreId}
          onClose={() => {
            if (!busy) setRestoreId(undefined);
          }}
          title="Restore backup"
          preventExternalClose={busy}
        >
          <div className="smm-root smm-form">
            <p>
              Restore this backup to the preset. The current preset is backed up first. Activate it
              to update the active package.
            </p>
            {error && <p role="alert">{error}</p>}
            <Button
              isLoading={busy}
              onClick={() =>
                void act(async () => {
                  await http.post(
                    `${base(id)}/presets/${preset!.id}/restore/${restoreId}`,
                    { revision: preset!.revision },
                    { timeout: 300000 },
                  );
                  setRestoreId(undefined);
                }, "Backup restored. Activate the preset to update the active package.")
              }
            >
              Restore
            </Button>
          </div>
        </Dialog>
      </div>
    </ServerContentBlock>
  );
}
