import type { Limits } from "./api";
import { packageFiles } from "./constants";

/** Both upload surfaces validate locally; the backend remains authoritative. */
export function stagePackageFiles(
  previous: File[],
  incoming: File[],
  limits: Limits,
  allowZip = false,
): File[] {
  if (!incoming.length) return previous;
  if (allowZip && incoming.length === 1 && incoming[0].name.toLowerCase().endsWith(".zip")) {
    if (!incoming[0].size || incoming[0].size > limits.sii + limits.dat)
      throw new Error("The ZIP must be non-empty and within the upload limit.");
    return incoming;
  }
  const seen = new Set<string>();
  for (const file of incoming) {
    if (!(packageFiles as readonly string[]).includes(file.name) || seen.has(file.name))
      throw new Error("Select exactly server_packages.sii and server_packages.dat.");
    if (!file.size || file.size > (file.name.endsWith(".sii") ? limits.sii : limits.dat))
      throw new Error("Files must be non-empty and within the upload limits.");
    seen.add(file.name);
  }
  return Array.from(
    new Map(
      [...previous.filter((file) => !file.name.toLowerCase().endsWith(".zip")), ...incoming].map(
        (file) => [file.name, file],
      ),
    ).values(),
  );
}
