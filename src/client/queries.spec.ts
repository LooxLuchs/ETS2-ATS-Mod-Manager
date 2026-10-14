import { QueryClient } from "@tanstack/react-query";
import { expect, test, vi } from "vitest";
import { library, type LibraryOverview, type Overview } from "./api";
import { queryKeys, refreshPackages } from "./queries";

vi.mock("./api", async (original) => ({
  ...(await original<typeof import("./api")>()),
  library: vi.fn(),
}));

test("a late library response cannot overwrite a newer active snapshot or another server", async () => {
  const cache = new QueryClient();
  const before: Overview = {
    activation: { game: "ets2", target: "/packages" },
    presets: [],
    pending: [],
    limits: { sii: 4194304, dat: 67108864 },
    active: {
      state: "external",
      preset_id: null,
      metadata: null,
      hashes: { "server_packages.sii": "a".repeat(64), "server_packages.dat": "b".repeat(64) },
    },
  };
  cache.setQueryData(queryKeys.overview("first"), before);
  cache.setQueryData(queryKeys.overview("second"), before);
  let finish!: (result: LibraryOverview) => void;
  vi.mocked(library).mockReturnValue(
    new Promise((resolve) => {
      finish = resolve;
    }),
  );
  const refresh = refreshPackages(cache, "first", "library");
  const newer = {
    ...before,
    active: {
      ...before.active,
      hashes: { "server_packages.sii": "c".repeat(64), "server_packages.dat": "d".repeat(64) },
    },
  };
  cache.setQueryData(queryKeys.overview("first"), newer);
  finish({
    presets: [],
    pending: [],
    limits: before.limits,
    active_match: { preset_id: "old-preset", source_preset_id: "old-preset" },
  });
  await refresh;
  expect(cache.getQueryData<Overview>(queryKeys.overview("first"))?.active).toEqual(newer.active);
  expect(cache.getQueryState(queryKeys.overview("first"))?.isInvalidated).toBe(true);
  expect(cache.getQueryData(queryKeys.overview("second"))).toEqual(before);
  expect(cache.getQueryState(queryKeys.overview("second"))?.isInvalidated).toBe(false);
  cache.clear();
});
