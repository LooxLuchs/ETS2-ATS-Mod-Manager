import type { QueryClient } from "@tanstack/react-query";
import { library, type Overview } from "./api";

export const queryKeys = {
  server: (id?: string) => ["ets2-ats-mm", id] as const,
  overview: (id: string) => [...queryKeys.server(id), "overview"] as const,
  detail: (id: string, preset?: string | null) =>
    preset === undefined
      ? ([...queryKeys.server(id), "detail"] as const)
      : ([...queryKeys.server(id), "detail", preset] as const),
  quick: (id: string) => [...queryKeys.server(id), "quick"] as const,
  presence: (id?: string) => [...queryKeys.server(id), "presence"] as const,
};

export async function refreshPackages(
  cache: QueryClient,
  id: string,
  scope: "all" | "library" = "all",
) {
  const key = queryKeys.overview(id);
  const previous = cache.getQueryData<Overview>(key);
  if (scope === "library" && previous) {
    const result = await library(id, previous.active.hashes);
    // Never merge a response into a different active snapshot after an overlapping refresh.
    let merged = false;
    cache.setQueryData<Overview>(key, (current) => {
      if (!current || current.active.hashes !== previous.active.hashes) return current;
      merged = true;
      const active = { ...current.active, ...result.active_match };
      if (active.state === "preset" || active.state === "external")
        active.state = active.preset_id ? "preset" : "external";
      return {
        ...current,
        presets: result.presets,
        pending: result.pending,
        limits: result.limits,
        active,
      };
    });
    if (!merged) await cache.invalidateQueries({ queryKey: key }, { cancelRefetch: false });
  } else {
    await cache.invalidateQueries({ queryKey: key }, { cancelRefetch: false });
  }
  // Keep the full refresh sequential: overview determines which detail is selected.
  await cache.invalidateQueries({ queryKey: queryKeys.detail(id) }, { cancelRefetch: false });
  await cache.invalidateQueries({ queryKey: queryKeys.quick(id) }, { cancelRefetch: false });
}
