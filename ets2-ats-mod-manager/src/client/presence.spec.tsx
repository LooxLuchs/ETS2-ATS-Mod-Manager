import { Suspense } from "react";
import { afterEach, beforeEach, expect, test, vi } from "vitest";
import { act, cleanup, render, screen } from "@testing-library/react";
import { createExtensionTestHost, createTestServer } from "@pterodactyl/sdk/testing";
import { useManagerVisibility } from "./presence";
import { presence } from "./api";

vi.mock("./api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./api")>()),
  presence: vi.fn(),
}));
let host: ReturnType<typeof createExtensionTestHost>;
let resolvePresence: (value: { enabled: boolean }) => void;
function Gate({ id }: { id: string }) {
  return useManagerVisibility(id) ? <p>Mod Manager page</p> : <p>404</p>;
}
function mount(permissions = ["file.read", "file.read-content"]) {
  const server = createTestServer({ owner: false, permissions });
  host = createExtensionTestHost({ extensionId: "ets2-ats-mod-manager", server });
  render(
    <Suspense fallback={<p>Checking access …</p>}>
      <Gate id={server.attributes.identifier} />
    </Suspense>,
    { wrapper: host.Wrapper },
  );
}
beforeEach(() => {
  vi.mocked(presence).mockReturnValue(
    new Promise((resolve) => {
      resolvePresence = resolve;
    }),
  );
});
afterEach(() => {
  cleanup();
  host?.dispose();
  vi.clearAllMocks();
});

test("hard reload shows pending state rather than a temporary 404", async () => {
  mount();
  expect(await screen.findByText("Checking access …")).toBeTruthy();
  expect(screen.queryByText("404")).toBeNull();
  await act(async () => resolvePresence({ enabled: true }));
  expect(await screen.findByText("Mod Manager page")).toBeTruthy();
  expect(screen.queryByText("404")).toBeNull();
});
test("disabled egg is hidden only after the presence request finishes", async () => {
  mount();
  expect(screen.queryByText("404")).toBeNull();
  await act(async () => resolvePresence({ enabled: false }));
  expect(await screen.findByText("404")).toBeTruthy();
  expect(screen.queryByText("Mod Manager page")).toBeNull();
});
test("pending visibility does not grant a subuser missing file permissions access", async () => {
  mount(["file.read"]);
  await act(async () => resolvePresence({ enabled: true }));
  expect(await screen.findByText("404")).toBeTruthy();
});
