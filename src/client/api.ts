import { http } from "@pterodactyl/sdk";

export interface Mod {
  id: string;
  mod_name: string;
  package_name: string;
  mod_id: string;
  checksum: string;
  workshop_mod: boolean;
  optional_mod: boolean;
  workshop_url: string | null;
}
export interface Metadata {
  mod_count: number;
  workshop_count: number;
  map_name: string;
  exported_at: string | null;
  mods: Mod[];
}
export interface Preset {
  id: string;
  name: string;
  game: "ets2" | "ats";
  revision: number;
  metadata: Metadata;
  hashes: Record<string, string>;
  imported_at: string;
  updated_at: string;
}
export interface Snapshot {
  id: string;
  created_at: string;
  revision: number;
  kind: string;
}
export interface Overview {
  activation: { game: string; target: string };
  presets: Preset[];
  active: {
    state: "missing" | "incomplete" | "invalid" | "external" | "preset";
    preset_id: string | null;
    source_preset_id?: string | null;
    metadata: Metadata | null;
    hashes: Record<string, string> | null;
    message?: string;
  };
  pending: { id: string; kind: string; status: string; created_at: string }[];
  limits: { sii: number; dat: number };
}
export interface Activation {
  id: number;
  egg_id: number;
  game: "ets2" | "ats";
  path_template: string;
  enabled: boolean;
}
export interface QuickData {
  presets: { id: string; name: string; revision: number; mod_count: number }[];
  pending: boolean;
  limits: { sii: number; dat: number };
}
export const quick = async (id: string, signal?: AbortSignal) =>
  (await http.get<QuickData>(`${base(id)}/quick`, { signal })).data;
export async function applyActive(id: string, files: File[]) {
  const body = new FormData();
  files.forEach((file) => body.append("files[]", file));
  return (await http.post(`${base(id)}/active/packages`, body, { timeout: 300000 })).data;
}
export interface AdminData {
  activations: Activation[];
  eggs: { id: number; name: string }[];
  placeholders: string[];
}
export const base = (id: string) => `/api/client/servers/${id}/extensions/ets2-ats-mod-manager`;
export const adminBase = "/api/admin/extensions/ets2-ats-mod-manager";
export const presence = async (id: string, signal?: AbortSignal) =>
  (await http.get<{ enabled: boolean }>(`${base(id)}/presence`, { signal })).data;
export const overview = async (id: string, signal?: AbortSignal) =>
  (await http.get<Overview>(base(id), { signal })).data;
export const detail = async (id: string, preset: string, signal?: AbortSignal) =>
  (
    await http.get<{ preset: Preset; metadata: Metadata; snapshots: Snapshot[] }>(
      `${base(id)}/presets/${preset}`,
      { signal },
    )
  ).data;
export const admin = async () => (await http.get<AdminData>(adminBase)).data;
export async function upload(id: string, name: string, files: File[]) {
  const body = new FormData();
  body.append("name", name);
  files.forEach((file) => body.append("files[]", file));
  return (await http.post<{ preset: Preset }>(`${base(id)}/presets`, body, { timeout: 300000 }))
    .data;
}
export async function download(id: string, preset: string) {
  const response = await http.get<Blob>(`${base(id)}/presets/${preset}/download`, {
    responseType: "blob",
    timeout: 300000,
  });
  const url = URL.createObjectURL(response.data);
  const a = document.createElement("a");
  a.href = url;
  a.download = `server-packages-${preset}.zip`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export const date = (value: string | null | undefined) =>
  value
    ? new Date(value).toLocaleString("en-GB", { dateStyle: "medium", timeStyle: "short" })
    : "–";
