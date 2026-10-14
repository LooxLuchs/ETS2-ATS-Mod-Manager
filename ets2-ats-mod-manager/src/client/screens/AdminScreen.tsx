import { useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Button, Dialog, PageContentBlock, http, httpErrorToHuman } from "@pterodactyl/sdk";
import { admin, adminBase, type Activation } from "../api";

type Draft = Omit<Activation, "id"> & { id?: number };
const empty: Draft = {
  egg_id: 0,
  game: "ets2",
  path_template: "/.local/share/Euro Truck Simulator 2",
  enabled: true,
};
export default function AdminScreen() {
  const cache = useQueryClient();
  const query = useQuery({ queryKey: ["ets2-ats-mm-admin"], queryFn: admin, retry: false });
  const [draft, setDraft] = useState<Draft>();
  const [deleting, setDeleting] = useState<Activation>();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const path = useRef<HTMLInputElement>(null);
  async function save() {
    if (!draft) return;
    setBusy(true);
    setError("");
    try {
      await http.request({
        method: draft.id ? "PUT" : "POST",
        url: `${adminBase}/activations${draft.id ? `/${draft.id}` : ""}`,
        data: draft,
      });
      setDraft(undefined);
      await cache.invalidateQueries({ queryKey: ["ets2-ats-mm-admin"] });
      await cache.invalidateQueries({ queryKey: ["ets2-ats-mm"] });
    } catch (e) {
      setError(httpErrorToHuman(e));
    } finally {
      setBusy(false);
    }
  }
  function insert(placeholder: string) {
    if (!draft) return;
    const start = path.current?.selectionStart ?? draft.path_template.length;
    const end = path.current?.selectionEnd ?? start;
    setDraft({
      ...draft,
      path_template:
        draft.path_template.slice(0, start) + placeholder + draft.path_template.slice(end),
    });
    requestAnimationFrame(() => {
      path.current?.focus();
      path.current?.setSelectionRange(start + placeholder.length, start + placeholder.length);
    });
  }
  return (
    <PageContentBlock title="ETS2-ATS-Mod-Manager">
      <div className="smm-root">
        <header className="smm-header">
          <div>
            <p className="smm-eyebrow">ADMINISTRATION</p>
            <h1>ETS2-ATS-Mod-Manager</h1>
            <p className="smm-muted">
              Enable Mod Manager for ETS2 and ATS eggs and configure each package directory.
            </p>
          </div>
          <Button
            onClick={() => {
              setError("");
              setDraft({ ...empty });
            }}
          >
            Create egg activation
          </Button>
        </header>
        {(error || query.error) && (
          <div className="smm-alert smm-error" role="alert">
            {error || httpErrorToHuman(query.error)}
          </div>
        )}
        {query.isLoading && <p role="status">Loading egg activations …</p>}
        {query.data && (
          <div className="smm-panel">
            <div className="smm-table-wrap">
              <table className="smm-table">
                <thead>
                  <tr>
                    <th>Egg</th>
                    <th>Game</th>
                    <th>Package directory</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {query.data.activations.map((a) => (
                    <tr key={a.id}>
                      <td>{query.data.eggs.find((e) => e.id === a.egg_id)?.name ?? a.egg_id}</td>
                      <td>{a.game.toUpperCase()}</td>
                      <td>
                        <code>{a.path_template}</code>
                      </td>
                      <td>
                        <span className={`smm-badge ${a.enabled ? "smm-live" : ""}`}>
                          {a.enabled ? "Enabled" : "Disabled"}
                        </span>
                      </td>
                      <td>
                        <div className="smm-actions">
                          <Button
                            isSecondary
                            size="small"
                            onClick={() => {
                              setError("");
                              setDraft({ ...a });
                            }}
                          >
                            Edit
                          </Button>
                          <Button.Danger size="small" onClick={() => setDeleting(a)}>
                            Delete
                          </Button.Danger>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {!query.data.activations.length && (
              <p className="smm-muted">
                No egg activations yet. Configure a package directory for each egg.
              </p>
            )}
          </div>
        )}
        <Dialog
          open={!!draft}
          onClose={() => {
            if (!busy) setDraft(undefined);
          }}
          title={draft?.id ? "Edit egg activation" : "Create egg activation"}
          preventExternalClose={busy}
        >
          {draft && (
            <form
              className="smm-root smm-form"
              onSubmit={(e) => {
                e.preventDefault();
                void save();
              }}
            >
              <label>
                Egg
                <select
                  required
                  value={draft.egg_id || ""}
                  onChange={(e) => setDraft({ ...draft, egg_id: Number(e.target.value) })}
                >
                  <option value="">Select an egg …</option>
                  {query.data?.eggs.map((egg) => (
                    <option
                      key={egg.id}
                      value={egg.id}
                      disabled={query.data.activations.some(
                        (a) => a.egg_id === egg.id && a.id !== draft.id,
                      )}
                    >
                      {egg.name}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                Game
                <select
                  value={draft.game}
                  onChange={(e) => {
                    const game = e.target.value as "ets2" | "ats";
                    setDraft({
                      ...draft,
                      game,
                      path_template: draft.id
                        ? draft.path_template
                        : `/.local/share/${game === "ets2" ? "Euro Truck Simulator 2" : "American Truck Simulator"}`,
                    });
                  }}
                >
                  <option value="ets2">Euro Truck Simulator 2</option>
                  <option value="ats">American Truck Simulator</option>
                </select>
              </label>
              <label>
                Package directory
                <input
                  ref={path}
                  required
                  maxLength={2048}
                  value={draft.path_template}
                  onChange={(e) => setDraft({ ...draft, path_template: e.target.value })}
                />
              </label>
              <p className="smm-muted">
                Path within the server filesystem. /home/container/ maps to its root. Both package
                files go directly in this directory.
              </p>
              <div className="smm-placeholder-list">
                {query.data?.placeholders.map((p) => (
                  <button type="button" key={p} onClick={() => insert(p)}>
                    {p}
                  </button>
                ))}
              </div>
              <label className="smm-checkbox">
                <input
                  type="checkbox"
                  checked={draft.enabled}
                  onChange={(e) => setDraft({ ...draft, enabled: e.target.checked })}
                />
                Enable Mod Manager for this egg
              </label>
              {error && (
                <p role="alert" className="smm-error">
                  {error}
                </p>
              )}
              <Button type="submit" isLoading={busy}>
                Save
              </Button>
            </form>
          )}
        </Dialog>
        <Dialog
          open={!!deleting}
          onClose={() => {
            if (!busy) setDeleting(undefined);
          }}
          title="Delete egg activation"
          preventExternalClose={busy}
        >
          <div className="smm-root smm-form">
            <p>Hide Mod Manager for this egg. Saved presets and server files are retained.</p>
            {error && <p role="alert">{error}</p>}
            <Button.Danger
              isLoading={busy}
              onClick={() => {
                setBusy(true);
                void http
                  .delete(`${adminBase}/activations/${deleting!.id}`)
                  .then(async () => {
                    setDeleting(undefined);
                    await cache.invalidateQueries({ queryKey: ["ets2-ats-mm-admin"] });
                    await cache.invalidateQueries({ queryKey: ["ets2-ats-mm"] });
                  })
                  .catch((e) => setError(httpErrorToHuman(e)))
                  .finally(() => setBusy(false));
              }}
            >
              Delete
            </Button.Danger>
          </div>
        </Dialog>
      </div>
    </PageContentBlock>
  );
}
