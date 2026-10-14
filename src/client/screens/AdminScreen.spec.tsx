import { afterEach, beforeEach, expect, test, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { createExtensionTestHost } from "@pterodactyl/sdk/testing";
import { http } from "@pterodactyl/sdk";
import AdminScreen from "./AdminScreen";
import { admin } from "../api";
vi.mock("../api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../api")>()),
  admin: vi.fn(),
}));
let host: ReturnType<typeof createExtensionTestHost>;
beforeEach(() => {
  vi.mocked(admin).mockResolvedValue({
    activations: [],
    eggs: [{ id: 42, name: "ETS2 Dedicated" }],
    placeholders: ["{{env.P_SERVER_UUID}}", "{{env.P_SERVER_NAME}}"],
  });
  vi.spyOn(http, "request").mockResolvedValue({ data: {} });
  host = createExtensionTestHost({ extensionId: "ets2-ats-mod-manager" });
});
afterEach(() => {
  cleanup();
  host?.dispose();
  vi.restoreAllMocks();
  vi.clearAllMocks();
});
test("admin can create a per-egg activation with the correct default path", async () => {
  render(<AdminScreen />, { wrapper: host.Wrapper });
  fireEvent.click(await screen.findByRole("button", { name: "Create egg activation" }));
  await screen.findByRole("option", { name: "ETS2 Dedicated" });
  fireEvent.change(screen.getByLabelText("Egg"), { target: { value: "42" } });
  expect((screen.getByLabelText("Package directory") as HTMLInputElement).value).toBe(
    "/.local/share/Euro Truck Simulator 2",
  );
  fireEvent.click(screen.getByRole("button", { name: "Save" }));
  await waitFor(() =>
    expect(http.request).toHaveBeenCalledWith(
      expect.objectContaining({
        method: "POST",
        data: expect.objectContaining({ egg_id: 42, game: "ets2", enabled: true }),
      }),
    ),
  );
});
test("path placeholder inserts at the current selection", async () => {
  render(<AdminScreen />, { wrapper: host.Wrapper });
  fireEvent.click(await screen.findByRole("button", { name: "Create egg activation" }));
  const field = screen.getByLabelText("Package directory") as HTMLInputElement;
  fireEvent.change(field, { target: { value: "/packages/server" } });
  field.setSelectionRange(10, 16);
  fireEvent.click(await screen.findByRole("button", { name: "{{env.P_SERVER_UUID}}" }));
  expect(field.value).toBe("/packages/{{env.P_SERVER_UUID}}");
});
test("ATS choice selects its own default directory", async () => {
  render(<AdminScreen />, { wrapper: host.Wrapper });
  fireEvent.click(await screen.findByRole("button", { name: "Create egg activation" }));
  fireEvent.change(screen.getByLabelText("Game"), { target: { value: "ats" } });
  expect((screen.getByLabelText("Package directory") as HTMLInputElement).value).toBe(
    "/.local/share/American Truck Simulator",
  );
});
