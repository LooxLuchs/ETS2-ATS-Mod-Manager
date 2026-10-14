import { afterEach, beforeEach, expect, test, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { createExtensionTestHost, createTestServer } from "@pterodactyl/sdk/testing";
import { http } from "@pterodactyl/sdk";
import ConsoleQuickControls from "./ConsoleQuickControls";
import { applyActive, overview, presence, quick, type Overview } from "./api";

vi.mock("./api", async (original) => ({
  ...(await original<typeof import("./api")>()),
  presence: vi.fn(),
  quick: vi.fn(),
  applyActive: vi.fn(),
  overview: vi.fn(),
}));
let host: ReturnType<typeof createExtensionTestHost>;
const sii = new File(["SiiNunit"], "server_packages.sii");
const dat = new File(["\0map"], "server_packages.dat");
const snapshot: Overview = {
  activation: { game: "ets2", target: "/packages" },
  presets: [],
  active: {
    state: "external",
    preset_id: null,
    hashes: { "server_packages.sii": "a".repeat(64), "server_packages.dat": "b".repeat(64) },
    metadata: {
      mod_count: 0,
      workshop_count: 0,
      map_name: "/map/europe.mbd",
      exported_at: null,
      mods: [],
    },
  },
  pending: [],
  limits: { sii: 4194304, dat: 67108864 },
};
function mount(
  permissions = ["file.read", "file.read-content", "file.create", "file.update"],
  path = "/server/quick-test",
) {
  const server = createTestServer({ identifier: "quick-test", owner: false, permissions });
  host = createExtensionTestHost({ extensionId: "ets2-ats-mod-manager", server, path });
  return render(<ConsoleQuickControls data={server} />, { wrapper: host.Wrapper });
}
async function drop(files: File[]) {
  const zone = await screen.findByRole("group", { name: "Quick Drop" });
  await waitFor(() =>
    expect(
      (screen.getByRole("button", { name: "Choose files" }) as HTMLButtonElement).disabled,
    ).toBe(false),
  );
  fireEvent.drop(zone, { dataTransfer: { files } });
}
beforeEach(() => {
  vi.mocked(presence).mockResolvedValue({ enabled: true });
  vi.mocked(quick).mockResolvedValue({
    presets: [{ id: "preset-one", name: "Convoy", revision: 3, mod_count: 70 }],
    pending: false,
    limits: { sii: 4194304, dat: 67108864 },
  });
  vi.mocked(applyActive).mockResolvedValue({ restart_required: true });
  vi.mocked(overview).mockResolvedValue(structuredClone(snapshot));
  vi.spyOn(http, "post").mockResolvedValue({ data: { ok: true } });
});
afterEach(() => {
  cleanup();
  host?.dispose();
  vi.restoreAllMocks();
  vi.clearAllMocks();
});

test("successive file drops stay local until Apply and never write a preset", async () => {
  mount();
  await drop([sii]);
  const apply = screen.getByRole("button", { name: "Apply" }) as HTMLButtonElement;
  expect(apply.disabled).toBe(true);
  expect(applyActive).not.toHaveBeenCalled();
  await drop([dat]);
  expect(apply.disabled).toBe(false);
  expect(applyActive).not.toHaveBeenCalled();
  fireEvent.click(apply);
  await waitFor(() => expect(applyActive).toHaveBeenCalledWith("quick-test", [sii, dat]));
  expect(await screen.findByText(/Active package updated and backed up/)).toBeTruthy();
  expect(apply.disabled).toBe(true);
  expect(http.post).not.toHaveBeenCalled();
});
test("a complete pair can be dropped together or chosen through the file input", async () => {
  mount();
  await drop([dat, sii]);
  expect((screen.getByRole("button", { name: "Apply" }) as HTMLButtonElement).disabled).toBe(false);
  fireEvent.click(
    screen.getByRole("button", { name: "Remove server_packages.dat from Quick Drop" }),
  );
  expect((screen.getByRole("button", { name: "Apply" }) as HTMLButtonElement).disabled).toBe(true);
  fireEvent.change(screen.getByLabelText("Quick Drop files"), { target: { files: [dat] } });
  expect((screen.getByRole("button", { name: "Apply" }) as HTMLButtonElement).disabled).toBe(false);
});
test("unknown names, duplicate files and oversize files cannot be staged", async () => {
  mount();
  await drop([new File(["bad"], "unexpected.sii")]);
  expect(await screen.findByRole("alert")).toHaveProperty(
    "textContent",
    expect.stringContaining("Select exactly server_packages"),
  );
  await drop([sii, sii]);
  await drop([new File([new Uint8Array(4194305)], "server_packages.sii")]);
  expect(screen.getByRole("alert").textContent).toContain("upload limits");
  expect((screen.getByRole("button", { name: "Apply" }) as HTMLButtonElement).disabled).toBe(true);
  expect(applyActive).not.toHaveBeenCalled();
});
test("Apply failures keep the staged pair for retry", async () => {
  vi.mocked(applyActive).mockRejectedValueOnce(new Error("Wings offline"));
  mount();
  await drop([sii, dat]);
  fireEvent.click(screen.getByRole("button", { name: "Apply" }));
  await screen.findByRole("alert");
  await waitFor(() =>
    expect((screen.getByRole("button", { name: "Apply" }) as HTMLButtonElement).disabled).toBe(
      false,
    ),
  );
  expect(
    screen.getByRole("button", { name: "Remove server_packages.sii from Quick Drop" }),
  ).toBeTruthy();
});
test("a running Apply locks both quick controls and prevents duplicate requests", async () => {
  let finish!: (value: unknown) => void;
  vi.mocked(applyActive).mockReturnValue(
    new Promise((resolve) => {
      finish = resolve;
    }),
  );
  mount();
  await drop([sii, dat]);
  fireEvent.change(screen.getByLabelText("Preset"), {
    target: { value: "preset-one" },
  });
  fireEvent.click(screen.getByRole("button", { name: "Apply" }));
  fireEvent.click(screen.getByRole("button", { name: "Apply" }));
  expect(applyActive).toHaveBeenCalledTimes(1);
  expect((screen.getByRole("button", { name: "Activate" }) as HTMLButtonElement).disabled).toBe(
    true,
  );
  await act(async () => finish({}));
  await screen.findByText(/Active package updated/);
});
test("selecting a preset only activates it after pressing its button with the known revision", async () => {
  mount();
  await screen.findByRole("option", { name: "Convoy (70 mods)" });
  fireEvent.change(screen.getByLabelText("Preset"), {
    target: { value: "preset-one" },
  });
  expect(http.post).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole("button", { name: "Activate" }));
  await waitFor(() =>
    expect(http.post).toHaveBeenCalledWith(
      expect.stringContaining("/presets/preset-one/activate"),
      { revision: 3 },
      { timeout: 300000 },
    ),
  );
  expect(applyActive).not.toHaveBeenCalled();
});
test("disabled eggs and read-only subusers do not see quick controls", async () => {
  vi.mocked(presence).mockResolvedValue({ enabled: false });
  const rendered = mount();
  await waitFor(() => expect(presence).toHaveBeenCalled());
  expect(screen.queryByRole("region", { name: "Mod Manager - Quick Switcher" })).toBeNull();
  expect(quick).not.toHaveBeenCalled();
  rendered.unmount();
  host.dispose();
  vi.clearAllMocks();
  mount(["file.read", "file.read-content"]);
  expect(screen.queryByRole("region", { name: "Mod Manager - Quick Switcher" })).toBeNull();
  expect(presence).not.toHaveBeenCalled();
});
test("the hidden console on other server tabs does not fetch or show quick controls", () => {
  mount(undefined, "/server/quick-test/files");
  expect(screen.queryByRole("region", { name: "Mod Manager - Quick Switcher" })).toBeNull();
  expect(quick).not.toHaveBeenCalled();
  expect(presence).not.toHaveBeenCalled();
});

test("save-as-preset asks for a name and saves the installed pair without applying staged files", async () => {
  mount();
  await drop([sii, dat]);
  expect(overview).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole("button", { name: "Save as preset" }));
  const dialog = await screen.findByRole("dialog", { name: "Save as preset" });
  const save = within(dialog).getByRole("button", { name: "Save as preset" });
  expect((save as HTMLButtonElement).disabled).toBe(true);
  expect(http.post).not.toHaveBeenCalled();
  vi.mocked(http.post).mockResolvedValueOnce({ data: { preset: { id: "new-preset" } } });
  vi.mocked(quick).mockResolvedValue({
    presets: [{ id: "new-preset", name: "Weekend", revision: 1, mod_count: 0 }],
    pending: false,
    limits: snapshot.limits,
  });
  fireEvent.change(within(dialog).getByLabelText("Preset name"), {
    target: { value: "  Weekend  " },
  });
  await waitFor(() => expect((save as HTMLButtonElement).disabled).toBe(false));
  fireEvent.click(save);
  await waitFor(() =>
    expect(http.post).toHaveBeenCalledWith(
      expect.stringContaining("/import-current"),
      { name: "Weekend" },
      { timeout: 300000 },
    ),
  );
  await screen.findByText("Preset saved.");
  await screen.findByRole("option", { name: "Weekend (0 mods)" });
  expect((screen.getByLabelText("Preset") as HTMLSelectElement).value).toBe("new-preset");
  expect(
    screen.getByRole("button", { name: "Remove server_packages.dat from Quick Drop" }),
  ).toBeTruthy();
  expect(applyActive).not.toHaveBeenCalled();
  expect(http.post).toHaveBeenCalledTimes(1);
});

test("the name dialog opens immediately without reading files and cancellation keeps staged files", async () => {
  vi.mocked(overview).mockRejectedValue(new Error("No file read should occur before confirmation"));
  mount();
  await drop([sii]);
  fireEvent.click(screen.getByRole("button", { name: "Save as preset" }));
  const dialog = await screen.findByRole("dialog", { name: "Save as preset" });
  fireEvent.change(within(dialog).getByLabelText("Preset name"), { target: { value: "Name" } });
  expect(
    (within(dialog).getByRole("button", { name: "Save as preset" }) as HTMLButtonElement).disabled,
  ).toBe(false);
  expect(overview).not.toHaveBeenCalled();
  fireEvent.click(within(dialog).getByRole("button", { name: "Cancel" }));
  expect(
    screen.getByRole("button", { name: "Remove server_packages.sii from Quick Drop" }),
  ).toBeTruthy();
  expect(http.post).not.toHaveBeenCalled();
});

test("a failed active package read keeps the name in the dialog for retry", async () => {
  vi.mocked(http.post).mockRejectedValueOnce(
    new Error("Missing package file: server_packages.dat"),
  );
  mount();
  await screen.findByRole("option", { name: "Convoy (70 mods)" });
  fireEvent.click(screen.getByRole("button", { name: "Save as preset" }));
  const dialog = await screen.findByRole("dialog", { name: "Save as preset" });
  fireEvent.change(within(dialog).getByLabelText("Preset name"), { target: { value: "Name" } });
  fireEvent.click(within(dialog).getByRole("button", { name: "Save as preset" }));
  await within(dialog).findByRole("alert");
  expect((within(dialog).getByLabelText("Preset name") as HTMLInputElement).value).toBe("Name");
  expect(screen.queryByText("Preset saved.")).toBeNull();
});

test("failed saving retains the name and displays the error in the dialog", async () => {
  vi.mocked(http.post).mockRejectedValueOnce(new Error("The active package has changed."));
  mount();
  await screen.findByRole("option", { name: "Convoy (70 mods)" });
  fireEvent.click(screen.getByRole("button", { name: "Save as preset" }));
  const dialog = await screen.findByRole("dialog", { name: "Save as preset" });
  fireEvent.change(within(dialog).getByLabelText("Preset name"), { target: { value: "Weekend" } });
  const save = within(dialog).getByRole("button", { name: "Save as preset" });
  await waitFor(() => expect((save as HTMLButtonElement).disabled).toBe(false));
  fireEvent.click(save);
  await within(dialog).findByRole("alert");
  expect((within(dialog).getByLabelText("Preset name") as HTMLInputElement).value).toBe("Weekend");
  await waitFor(() => expect((save as HTMLButtonElement).disabled).toBe(false));
  expect(screen.queryByText("Preset saved.")).toBeNull();
});

test("a pending save prevents duplicate imports and cannot be cancelled", async () => {
  let finish!: (value: unknown) => void;
  vi.mocked(http.post).mockReturnValueOnce(
    new Promise((resolve) => {
      finish = resolve;
    }),
  );
  mount();
  await screen.findByRole("option", { name: "Convoy (70 mods)" });
  fireEvent.click(screen.getByRole("button", { name: "Save as preset" }));
  const dialog = await screen.findByRole("dialog", { name: "Save as preset" });
  fireEvent.change(within(dialog).getByLabelText("Preset name"), { target: { value: "Weekend" } });
  const save = within(dialog).getByRole("button", { name: "Save as preset" });
  await waitFor(() => expect((save as HTMLButtonElement).disabled).toBe(false));
  fireEvent.click(save);
  fireEvent.click(save);
  expect(http.post).toHaveBeenCalledTimes(1);
  expect(
    (within(dialog).getByRole("button", { name: "Cancel" }) as HTMLButtonElement).disabled,
  ).toBe(true);
  await act(async () => finish({ data: { preset: { id: "new-preset" } } }));
  await screen.findByText("Preset saved.");
});
test("interrupted server operations disable both Apply and preset activation", async () => {
  vi.mocked(quick).mockResolvedValue({ presets: [], pending: true, limits: { sii: 1, dat: 1 } });
  mount();
  await screen.findByText(/interrupted operation/);
  expect((screen.getByRole("button", { name: "Apply" }) as HTMLButtonElement).disabled).toBe(true);
  expect((screen.getByRole("button", { name: "Activate" }) as HTMLButtonElement).disabled).toBe(
    true,
  );
});
