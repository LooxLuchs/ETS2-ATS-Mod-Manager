import { useSuspenseQuery } from "@tanstack/react-query";
import { useServerPermission } from "@pterodactyl/sdk";
import { presence } from "./api";
import { queryKeys } from "./queries";
import { permissions, presenceStaleTime, refreshInterval } from "./constants";

export const presenceOptions = (id: string | undefined) => ({
  queryKey: queryKeys.presence(id),
  queryFn: ({ signal }: { signal: AbortSignal }) =>
    id ? presence(id, signal) : Promise.resolve({ enabled: false }),
  staleTime: presenceStaleTime,
  refetchInterval: refreshInterval,
  retry: false as const,
});

/** ScreenGate uses its pending fallback while this query suspends, rather than a 404. */
export function useManagerVisibility(id: string | undefined): boolean {
  const allowed = useServerPermission(permissions.read, false);
  const { data } = useSuspenseQuery(presenceOptions(id));
  return allowed && data.enabled;
}
