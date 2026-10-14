import { useSuspenseQuery } from "@tanstack/react-query";
import { useServerPermission } from "@pterodactyl/sdk";
import { presence } from "./api";

const presenceOptions = (id: string | undefined) => ({
  queryKey: ["ets2-ats-mm", id, "presence"],
  queryFn: ({ signal }: { signal: AbortSignal }) =>
    id ? presence(id, signal) : Promise.resolve({ enabled: false }),
  staleTime: 15000,
  refetchInterval: 30000,
  retry: false as const,
});

/** ScreenGate uses its pending fallback while this query suspends, rather than a 404. */
export function useManagerVisibility(id: string | undefined): boolean {
  const allowed = useServerPermission(["file.read", "file.read-content"], false);
  const { data } = useSuspenseQuery(presenceOptions(id));
  return allowed && data.enabled;
}
