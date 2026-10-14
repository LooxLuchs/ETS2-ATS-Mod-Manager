import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
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
import { queryKeys, refreshPackages } from "../queries";
import ModCountNotice from "../ModCountNotice";
import ModRow from "../ModRow";
import { permissions, operationTimeout } from "../constants";
import { stagePackageFiles } from "../packageFiles";

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
  const canWrite = useServerPermission(permissions.write, false);
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
  const applying = useRef(false);
  const query = useQuery({
    queryKey: queryKeys.overview(id),
    queryFn: ({ signal }) => overview(id, signal),
    retry: false,
  });
  const data = query.data;
  const selectedId = selected === "active" ? data?.active.preset_id : selected;
  const presetQuery = useQuery({
    queryKey: queryKeys.detail(id, selectedId ?? null),
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
  const visibleRows = useRef(rows);
  useLayoutEffect(() => {
    visibleRows.current = rows;
  }, [rows]);
  const dirty = !!currentDraft;
  const blocked = busy || !!data?.pending.length;
  const canEdit =
    canWrite && !!metadata && (selected === "active" ? !!data?.active.hashes : !!preset);
  const removed = !!currentDraft && rows.length < currentDraft.originalIds.length;
  const currentIsActive = preset && preset.id === data?.active.preset_id;

  useLayoutEffect(() => {
    if (!positions.current.size) return;
    const elements = list.current?.querySelectorAll<HTMLLIElement>("li[data-mod-id]");
    if (!elements) return;
    const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
    if (reducedMotion) {
      positions.current.clear();
      return;
    }
    const animations: { element: HTMLLIElement; delta: number }[] = [];
    // Read all affected positions before performing any animation writes.
    elements.forEach((element) => {
      const previous = positions.current.get(element.dataset.modId!);
      if (previous === undefined) return;
      const top = element.getBoundingClientRect().top;
      const delta = previous - top;
      if (delta && typeof element.animate === "function") animations.push({ element, delta });
    });
    positions.current.clear();
    animations.forEach(({ element, delta }) => {
      element.getAnimations().forEach((animation) => animation.cancel());
      element.animate([{ transform: `translateY(${delta}px)` }, { transform: "translateY(0)" }], {
        duration: 140,
        easing: "ease-out",
      });
    });
  }, [rows]);

  useEffect(() => {
    setSelected("active");
  }, [id]);
  useEffect(() => {
    setDraft(null);
    setRemoveId(undefined);
    setError("");
  }, [selected, id]);
  const refresh = (scope: "all" | "library" = "all") => refreshPackages(cache, id, scope);
  async function act(
    action: () => Promise<unknown>,
    message = "",
    refreshAfter: boolean | "library" = true,
    onSuccess?: () => void,
  ) {
    if (applying.current) return;
    applying.current = true;
    setBusy(true);
    setError("");
    try {
      await action();
      if (message) setNotice(message);
      if (refreshAfter) await refresh(refreshAfter === "library" ? "library" : "all");
      onSuccess?.();
    } catch (e) {
      setError(httpErrorToHuman(e));
      if (refreshAfter) {
        try {
          await refresh();
        } catch (refreshError) {
          setError(httpErrorToHuman(refreshError));
        }
      }
    } finally {
      applying.current = false;
      setBusy(false);
    }
  }
  const updateOrder = useCallback(
    (update: (mods: Mod[]) => Mod[]) => {
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
    },
    [canEdit, blocked, metadata, viewKey, selected, data, preset],
  );
  const move = useCallback(
    (modId: string, to: number) => {
      const from = visibleRows.current.findIndex((mod) => mod.id === modId);
      if (from < 0 || to < 0 || to >= visibleRows.current.length || from === to) return;
      if (!window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) {
        const elements = list.current?.querySelectorAll<HTMLLIElement>("li[data-mod-id]");
        for (let index = Math.min(from, to); elements && index <= Math.max(from, to); index++) {
          const element = elements[index];
          positions.current.set(element.dataset.modId!, element.getBoundingClientRect().top);
        }
      }
      updateOrder((mods) => {
        const from = mods.findIndex((mod) => mod.id === modId);
        if (from < 0 || to < 0 || to >= mods.length || from === to) return mods;
        const [mod] = mods.splice(from, 1);
        mods.splice(to, 0, mod);
        return mods;
      });
    },
    [updateOrder],
  );
  const { draggedId, start: startSort } = usePointerSort({
    list,
    key: viewKey,
    enabled: canEdit && !blocked,
    rows,
    before: currentDraft,
    restore: setDraft,
    move,
  });
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
              { timeout: operationTimeout },
            )
          : http.put(
              `${base(id)}/presets/${currentDraft.presetId}/order`,
              { ...body, revision: currentDraft.revision },
              { timeout: operationTimeout },
            ),
      selected === "active" ? messages.activeUpdated : messages.presetEdited,
      currentDraft.hashes ? true : "library",
      () => setDraft(null),
    );
  }
  function addFiles(incoming: FileList | null) {
    if (!incoming || !data) return;
    try {
      const next = Array.from(incoming);
      stagePackageFiles([], next, data.limits, canArchive);
      setFiles((previous) => stagePackageFiles(previous, next, data.limits, canArchive));
      setError("");
    } catch (e) {
      setError((e as Error).message);
    }
  }
  async function importActive() {
    if (!name.trim()) return;
    await act(
      async () => {
        const result = await http.post<{ preset: Preset }>(
          `${base(id)}/import`,
          { name: name.trim(), hashes: importHashes },
          { timeout: operationTimeout },
        );
        setImportOpen(false);
        setSelected(result.data.preset.id);
      },
      messages.presetSaved,
      "library",
    );
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
                              { timeout: operationTimeout },
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
                  <ModCountNotice count={rows.length} limits={data.limits} />
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
                      <ModRow
                        key={mod.id}
                        mod={mod}
                        index={index}
                        count={rows.length}
                        canEdit={canEdit}
                        canDelete={canDelete}
                        blocked={blocked}
                        dragging={draggedId === mod.id}
                        move={move}
                        remove={setRemoveId}
                        start={startSort}
                      />
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
              void act(
                async () => {
                  const result = await upload(id, name, files);
                  setUploadOpen(false);
                  setSelected(result.preset.id);
                },
                "Preset uploaded.",
                "library",
              );
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
                    aria-label={`Remove ${file.name} from upload`}
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
                void act(
                  async () => {
                    await http.delete(`${base(id)}/presets/${preset!.id}`, {
                      data: { revision: preset!.revision },
                    });
                    setConfirmDelete(false);
                    setSelected("active");
                  },
                  "Preset deleted.",
                  "library",
                )
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
              void act(
                async () => {
                  await http.put(`${base(id)}/presets/${preset!.id}/name`, {
                    name,
                    revision: preset!.revision,
                  });
                  setRenameOpen(false);
                },
                "Preset renamed.",
                "library",
              );
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
                void act(
                  async () => {
                    await http.post(
                      `${base(id)}/presets/${preset!.id}/restore/${restoreId}`,
                      { revision: preset!.revision },
                      { timeout: operationTimeout },
                    );
                    setRestoreId(undefined);
                  },
                  "Backup restored. Activate the preset to update the active package.",
                  "library",
                )
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
