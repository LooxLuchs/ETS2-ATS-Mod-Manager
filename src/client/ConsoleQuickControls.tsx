import { useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Button,
  Dialog,
  http,
  httpErrorToHuman,
  usePanelLocation,
  useServerPermission,
  type SdkServer,
  type SlotComponentProps,
} from "@pterodactyl/sdk";
import { applyActive, base, presence, quick, type Preset } from "./api";
import { messages } from "./messages";

const names = ["server_packages.sii", "server_packages.dat"] as const;

export default function ConsoleQuickControls({ data: server }: SlotComponentProps<SdkServer>) {
  const id = server.attributes.identifier;
  const location = usePanelLocation();
  const onConsole = /^\/server\/[^/]+\/?$/.test(location.pathname);
  const allowed = useServerPermission(
    ["file.read", "file.read-content", "file.create", "file.update"],
    false,
  );
  const enabled = useQuery({
    queryKey: ["ets2-ats-mm", id, "presence"],
    queryFn: ({ signal }) => presence(id, signal),
    enabled: allowed && onConsole,
    staleTime: 15000,
    refetchInterval: 30000,
    retry: false,
  });
  if (!onConsole || !allowed || !enabled.data?.enabled) return null;
  return <QuickControls key={id} id={id} />;
}

function QuickControls({ id }: { id: string }) {
  const cache = useQueryClient();
  const query = useQuery({
    queryKey: ["ets2-ats-mm", id, "quick"],
    queryFn: ({ signal }) => quick(id, signal),
    staleTime: 5000,
    refetchInterval: 30000,
    retry: false,
  });
  const [selected, setSelected] = useState("");
  const [files, setFiles] = useState<File[]>([]);
  const [hover, setHover] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busyMessage, setBusyMessage] = useState("");
  const [saveOpen, setSaveOpen] = useState(false);
  const [saveName, setSaveName] = useState("");
  const input = useRef<HTMLInputElement>(null);
  const depth = useRef(0);
  // A ref also prevents double submissions before React commits the disabled buttons.
  const applying = useRef(false);
  const data = query.data;
  const blocked = busy || !data || data.pending;
  const preset = data?.presets.find((item) => item.id === selected);
  const complete = names.every((name) => files.some((file) => file.name === name));

  function closeSave() {
    if (!busy) setSaveOpen(false);
  }

  function openSave() {
    if (blocked || applying.current) return;
    setSaveName("");
    setError("");
    setSaveOpen(true);
  }

  function stage(incoming: File[]) {
    if (blocked) return;
    const limits = data!.limits;
    const duplicates = new Set<string>();
    for (const file of incoming) {
      if (!(names as readonly string[]).includes(file.name) || duplicates.has(file.name)) {
        setError("Select exactly server_packages.sii and server_packages.dat.");
        return;
      }
      if (!file.size || file.size > (file.name.endsWith(".sii") ? limits.sii : limits.dat)) {
        setError("Files must be non-empty and within the upload limits.");
        return;
      }
      duplicates.add(file.name);
    }
    setError("");
    setNotice("");
    setFiles((previous) =>
      Array.from(new Map([...previous, ...incoming].map((file) => [file.name, file])).values()),
    );
  }

  async function apply(
    action: () => Promise<unknown>,
    clear: () => void,
    message = messages.activeUpdated,
    status = "Updating the active package …",
  ) {
    if (blocked || applying.current) return;
    applying.current = true;
    setBusy(true);
    setBusyMessage(status);
    setError("");
    setNotice("");
    try {
      await action();
      clear();
      setNotice(message);
    } catch (e) {
      setError(httpErrorToHuman(e));
    } finally {
      await cache.invalidateQueries(
        { queryKey: ["ets2-ats-mm", id, "quick"] },
        { cancelRefetch: false },
      );
      await cache.invalidateQueries(
        { queryKey: ["ets2-ats-mm", id, "overview"] },
        { cancelRefetch: false },
      );
      applying.current = false;
      setBusy(false);
    }
  }

  async function saveActive() {
    if (!saveName.trim() || blocked) return;
    await apply(
      async () => {
        const result = await http.post<{ preset: Preset }>(
          `${base(id)}/import-current`,
          { name: saveName.trim() },
          { timeout: 300000 },
        );
        setSelected(result.data.preset.id);
      },
      () => {
        setSaveOpen(false);
      },
      messages.presetSaved,
      "Saving preset …",
    );
  }

  return (
    <section className="smm-root smm-panel smm-quick" aria-label="Mod Manager - Quick Switcher">
      <h2>Mod Manager - Quick Switcher</h2>
      <div className="smm-quick-grid">
        <section className="smm-quick-card smm-quick-presets" aria-label="Preset Quick Select">
          <h3>Preset Quick Select</h3>
          <div className="smm-quick-select-row">
            <label className="smm-quick-preset-field">
              Preset
              <select
                aria-label="Preset"
                value={selected}
                disabled={blocked}
                onChange={(e) => setSelected(e.target.value)}
              >
                <option value="">Select a preset …</option>
                {data?.presets.map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.name} ({item.mod_count} mods)
                  </option>
                ))}
              </select>
            </label>
            <Button
              size="small"
              disabled={blocked || !preset}
              onClick={() => {
                if (preset)
                  void apply(
                    () =>
                      http.post(
                        `${base(id)}/presets/${preset.id}/activate`,
                        { revision: preset.revision },
                        { timeout: 300000 },
                      ),
                    () => setSelected(""),
                  );
              }}
            >
              Activate
            </Button>
          </div>
          <p className="smm-muted">Choose a saved preset, then activate it.</p>
          <div className="smm-quick-save">
            <Button size="small" isSecondary disabled={blocked} onClick={() => void openSave()}>
              Save as preset
            </Button>
            <p className="smm-muted">Save the active server files as a new preset.</p>
          </div>
        </section>
        <section className="smm-quick-card smm-form" aria-label="Package upload">
          <h3>Quick Drop</h3>
          <div
            className={`smm-quick-drop${hover ? " smm-drop-hover" : ""}`}
            role="group"
            aria-label="Quick Drop"
            onDragEnter={(e) => {
              e.preventDefault();
              depth.current++;
              if (!blocked) setHover(true);
            }}
            onDragOver={(e) => {
              e.preventDefault();
              e.dataTransfer.dropEffect = blocked ? "none" : "copy";
            }}
            onDragLeave={(e) => {
              e.preventDefault();
              if (--depth.current <= 0) {
                depth.current = 0;
                setHover(false);
              }
            }}
            onDrop={(e) => {
              e.preventDefault();
              depth.current = 0;
              setHover(false);
              stage(Array.from(e.dataTransfer.files));
            }}
          >
            <p className="smm-muted">Drop both package files together or one at a time.</p>
            <ul>
              {names.map((name) => (
                <li key={name}>
                  <span>
                    {files.some((file) => file.name === name) ? "✓" : "○"} {name}
                  </span>
                  {files.some((file) => file.name === name) && (
                    <button
                      disabled={busy}
                      aria-label={`Remove ${name} from Quick Drop`}
                      onClick={() =>
                        setFiles((current) => current.filter((file) => file.name !== name))
                      }
                    >
                      ×
                    </button>
                  )}
                </li>
              ))}
            </ul>
            <input
              ref={input}
              type="file"
              multiple
              accept=".sii,.dat"
              hidden
              aria-label="Quick Drop files"
              disabled={blocked}
              onChange={(e) => {
                stage(Array.from(e.target.files ?? []));
                e.target.value = "";
              }}
            />
            <Button isSecondary disabled={blocked} onClick={() => input.current?.click()}>
              Choose files
            </Button>
          </div>
          <div className="smm-actions">
            <Button
              disabled={blocked || !complete}
              onClick={() => {
                if (complete)
                  void apply(
                    () => applyActive(id, files),
                    () => setFiles([]),
                  );
              }}
            >
              Apply
            </Button>
            <span className="smm-muted">
              Replaces the active package. Saved presets stay unchanged.
            </span>
          </div>
        </section>
      </div>
      {busy && (
        <p className="smm-muted" role="status">
          {busyMessage}
        </p>
      )}
      {data?.pending && <p className="smm-alert smm-warning">{messages.recoveryRequired}</p>}
      {!saveOpen && (error || query.error) && (
        <p className="smm-alert smm-error" role="alert">
          {error || httpErrorToHuman(query.error)}
        </p>
      )}
      {notice && (
        <p className="smm-alert" role="status">
          {notice}
        </p>
      )}
      <Dialog
        open={saveOpen}
        onClose={closeSave}
        title="Save as preset"
        preventExternalClose={busy}
      >
        <form
          className="smm-root smm-form"
          onSubmit={(e) => {
            e.preventDefault();
            void saveActive();
          }}
        >
          <p className="smm-muted">Save the active server files as a new preset.</p>
          <label>
            Preset name
            <input
              required
              autoFocus
              maxLength={100}
              value={saveName}
              disabled={busy}
              onChange={(e) => setSaveName(e.target.value)}
            />
          </label>
          {data?.pending && <p className="smm-alert smm-warning">{messages.recoveryRequired}</p>}
          {error && (
            <p className="smm-error" role="alert">
              {error}
            </p>
          )}
          <div className="smm-actions">
            <Button isSecondary disabled={busy} onClick={closeSave}>
              Cancel
            </Button>
            <Button type="submit" isLoading={busy} disabled={blocked || !saveName.trim()}>
              Save as preset
            </Button>
          </div>
        </form>
      </Dialog>
    </section>
  );
}
