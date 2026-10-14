import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { createExtensionTestHost, createTestServer } from "@pterodactyl/sdk/testing";
import { http } from "@pterodactyl/sdk";
import ManagerScreen from "./ManagerScreen";
import {
  overview,
  detail,
  download,
  upload,
  type Metadata,
  type Overview,
  type Preset,
} from "../api";

vi.mock("../api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../api")>()),
  overview: vi.fn(),
  detail: vi.fn(),
  upload: vi.fn(),
  download: vi.fn(),
  presence: vi.fn().mockResolvedValue({ enabled: true }),
}));
const mods = [
  {
    id: "_last",
    mod_name: "Highest priority",
    package_name: "last",
    mod_id: "1787041596",
    checksum: "18446744073709551615",
    workshop_mod: true,
    optional_mod: true,
    workshop_url: "https://steamcommunity.com/sharedfiles/filedetails/?id=1787041596",
  },
  {
    id: "_first",
    mod_name: "Lowest priority",
    package_name: "first",
    mod_id: "5067522809175364042",
    checksum: "1",
    workshop_mod: false,
    optional_mod: false,
    workshop_url: null,
  },
];
const metadata: Metadata = {
  mod_count: 2,
  workshop_count: 1,
  map_name: "/map/europe.mbd",
  exported_at: "2026-10-11T12:00:00Z",
  mods,
};
const preset: Preset = {
  id: "abc",
  name: "Convoy",
  game: "ets2",
  revision: 1,
  metadata,
  hashes: { "server_packages.sii": "a", "server_packages.dat": "b" },
  imported_at: "2026-10-11T12:00:00Z",
  updated_at: "2026-10-11T12:00:00Z",
};
const data: Overview = {
  activation: { game: "ets2", target: "/.local/share/Euro Truck Simulator 2" },
  presets: [preset],
  active: { state: "preset", preset_id: "abc", metadata, hashes: preset.hashes },
  pending: [],
  limits: { sii: 4194304, dat: 67108864 },
};
let host: ReturnType<typeof createExtensionTestHost>;
function mount(
  permissions = [
    "file.read",
    "file.read-content",
    "file.create",
    "file.update",
    "file.delete",
    "file.archive",
  ],
) {
  host = createExtensionTestHost({
    extensionId: "ets2-ats-mod-manager",
    server: createTestServer({ owner: false, permissions }),
  });
  return render(<ManagerScreen />, { wrapper: host.Wrapper });
}
function mockSortGeometry(list: HTMLElement) {
  const rect = (top: number, height: number) => ({
    x: 0,
    y: top,
    left: 0,
    right: 100,
    top,
    bottom: top + height,
    width: 100,
    height,
    toJSON: () => ({}),
  });
  vi.spyOn(list, "getBoundingClientRect").mockReturnValue(rect(0, 100));
  within(list)
    .getAllByRole("listitem")
    .forEach((item) => {
      vi.spyOn(item, "getBoundingClientRect").mockImplementation(() =>
        rect(Array.from(list.children).indexOf(item) * 50, 50),
      );
    });
  if (!document.elementFromPoint)
    Object.defineProperty(document, "elementFromPoint", {
      configurable: true,
      writable: true,
      value: () => null,
    });
  vi.spyOn(document, "elementFromPoint").mockImplementation(
    (_x, y) => list.children[y < 50 ? 0 : 1],
  );
}
beforeEach(() => {
  vi.mocked(overview).mockResolvedValue(structuredClone(data));
  vi.mocked(detail).mockResolvedValue({ preset, metadata, snapshots: [] });
  vi.spyOn(http, "post").mockResolvedValue({ data: { ok: true } });
  vi.spyOn(http, "put").mockResolvedValue({ data: { preset } });
});
afterEach(() => {
  cleanup();
  host?.dispose();
  vi.restoreAllMocks();
  vi.clearAllMocks();
});

describe("server manager", () => {
  test("save starts disabled and drag-and-drop edits active files without an edit mode", async () => {
    mount();
    await screen.findByText("Highest priority");
    const save = screen.getByRole("button", { name: "Save" }) as HTMLButtonElement;
    expect(save.disabled).toBe(true);
    expect(screen.queryByRole("button", { name: "Edit" })).toBeNull();
    const items = within(screen.getByRole("list", { name: "Mod load order" })).getAllByRole(
      "listitem",
    );
    expect(items[0].getAttribute("draggable")).toBe("false");
    mockSortGeometry(screen.getByRole("list", { name: "Mod load order" }));
    fireEvent.pointerDown(items[0].querySelector(".smm-drag-handle")!, {
      button: 0,
      clientX: 25,
      clientY: 20,
    });
    fireEvent.pointerMove(window, { clientX: 25, clientY: 90 });
    await waitFor(() =>
      expect(
        within(screen.getByRole("list", { name: "Mod load order" })).getAllByRole("listitem")[0]
          .textContent,
      ).toContain("Lowest priority"),
    );
    fireEvent.pointerUp(window, { clientX: 25, clientY: 90 });
    expect(save.disabled).toBe(false);
    expect(
      within(screen.getByRole("list", { name: "Mod load order" })).getAllByRole("listitem")[0]
        .textContent,
    ).toContain("Lowest priority");
    expect(http.put).not.toHaveBeenCalled();
    fireEvent.click(save);
    await waitFor(() =>
      expect(http.put).toHaveBeenCalledWith(
        expect.stringContaining("/active/order"),
        {
          order: ["_first", "_last"],
          hashes: preset.hashes,
          confirm_experimental_removal: false,
        },
        expect.anything(),
      ),
    );
    await waitFor(() =>
      expect((screen.getByRole("button", { name: "Save" }) as HTMLButtonElement).disabled).toBe(
        true,
      ),
    );
    expect(http.post).not.toHaveBeenCalled();
  });
  test("moving a mod back to its original position disables save again", async () => {
    mount();
    await screen.findByText("Highest priority");
    fireEvent.click(screen.getByRole("button", { name: "Move Highest priority down" }));
    expect((screen.getByRole("button", { name: "Save" }) as HTMLButtonElement).disabled).toBe(
      false,
    );
    fireEvent.click(screen.getByRole("button", { name: "Move Highest priority up" }));
    expect((screen.getByRole("button", { name: "Save" }) as HTMLButtonElement).disabled).toBe(true);
    expect(http.put).not.toHaveBeenCalled();
  });
  test("pointer sorting waits for the target midpoint and pointer cancellation restores the order", async () => {
    mount();
    await screen.findByText("Highest priority");
    const list = screen.getByRole("list", { name: "Mod load order" });
    mockSortGeometry(list);
    const grip = list.querySelector(".smm-drag-handle")!;
    fireEvent.pointerDown(grip, { button: 0, clientX: 25, clientY: 20 });
    fireEvent.pointerUp(window, { clientX: 25, clientY: 70 });
    expect(within(list).getAllByRole("listitem")[0].textContent).toContain("Highest priority");
    expect((screen.getByRole("button", { name: "Save" }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.pointerDown(grip, { button: 0, clientX: 25, clientY: 20 });
    fireEvent.pointerMove(window, { clientX: 25, clientY: 90 });
    await waitFor(() =>
      expect(within(list).getAllByRole("listitem")[0].textContent).toContain("Lowest priority"),
    );
    fireEvent.pointerCancel(window);
    expect(within(list).getAllByRole("listitem")[0].textContent).toContain("Highest priority");
    expect(http.put).not.toHaveBeenCalled();
  });
  test("drag hover immediately previews the new order and a cancelled drag restores the previous draft", async () => {
    mount();
    await screen.findByText("Highest priority");
    const list = screen.getByRole("list", { name: "Mod load order" });
    let items = within(list).getAllByRole("listitem");
    mockSortGeometry(list);
    fireEvent.pointerDown(items[0].querySelector(".smm-drag-handle")!, {
      button: 0,
      clientX: 25,
      clientY: 20,
    });
    fireEvent.pointerMove(window, { clientX: 25, clientY: 90 });
    await waitFor(() =>
      expect(within(list).getAllByRole("listitem")[0].textContent).toContain("Lowest priority"),
    );
    expect(http.put).not.toHaveBeenCalled();
    fireEvent.keyDown(window, { key: "Escape" });
    expect(within(list).getAllByRole("listitem")[0].textContent).toContain("Highest priority");
    expect((screen.getByRole("button", { name: "Save" }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.click(screen.getByRole("button", { name: "Move Highest priority down" }));
    items = within(list).getAllByRole("listitem");
    fireEvent.pointerDown(items[0].querySelector(".smm-drag-handle")!, {
      button: 0,
      clientX: 25,
      clientY: 20,
    });
    fireEvent.pointerMove(window, { clientX: 25, clientY: 90 });
    await waitFor(() =>
      expect(within(list).getAllByRole("listitem")[0].textContent).toContain("Highest priority"),
    );
    fireEvent.pointerUp(window, { clientX: 150, clientY: 90 });
    expect(within(list).getAllByRole("listitem")[0].textContent).toContain("Lowest priority");
    expect((screen.getByRole("button", { name: "Save" }) as HTMLButtonElement).disabled).toBe(
      false,
    );
  });
  test("reset discards unsaved edits and restores the save state", async () => {
    mount();
    await screen.findByText("Highest priority");
    fireEvent.click(screen.getByRole("button", { name: "Move Highest priority down" }));
    expect((screen.getByRole("button", { name: /^Convoy/ }) as HTMLButtonElement).disabled).toBe(
      true,
    );
    fireEvent.click(screen.getByRole("button", { name: "Reset" }));
    expect((screen.getByRole("button", { name: "Save" }) as HTMLButtonElement).disabled).toBe(true);
    expect(
      within(screen.getByRole("list", { name: "Mod load order" })).getAllByRole("listitem")[0]
        .textContent,
    ).toContain("Highest priority");
  });
  test("saving a template asks for a name before importing", async () => {
    vi.mocked(http.post).mockResolvedValueOnce({ data: { preset } });
    mount();
    fireEvent.click(await screen.findByRole("button", { name: "Save as preset" }));
    const dialog = await screen.findByRole("dialog", { name: "Save as preset" });
    const submit = within(dialog).getByRole("button", { name: "Save as preset" });
    expect((submit as HTMLButtonElement).disabled).toBe(true);
    expect(http.post).not.toHaveBeenCalled();
    fireEvent.change(within(dialog).getByLabelText("Preset name"), {
      target: { value: "  Weekend  " },
    });
    fireEvent.click(submit);
    await waitFor(() =>
      expect(http.post).toHaveBeenCalledWith(
        expect.stringContaining("/import"),
        { name: "Weekend", hashes: preset.hashes },
        expect.anything(),
      ),
    );
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
  });
  test("cancelling the template name dialog does not create a preset", async () => {
    mount();
    fireEvent.click(await screen.findByRole("button", { name: "Save as preset" }));
    const dialog = await screen.findByRole("dialog", { name: "Save as preset" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Cancel" }));
    expect(http.post).not.toHaveBeenCalled();
  });
  test("failed saving retains the unsaved order for retry", async () => {
    vi.mocked(http.put).mockRejectedValueOnce(new Error("save failed"));
    mount();
    await screen.findByText("Highest priority");
    fireEvent.click(screen.getByRole("button", { name: "Move Highest priority down" }));
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    await screen.findByRole("alert");
    await waitFor(() =>
      expect((screen.getByRole("button", { name: "Save" }) as HTMLButtonElement).disabled).toBe(
        false,
      ),
    );
    expect(
      within(screen.getByRole("list", { name: "Mod load order" })).getAllByRole("listitem")[0]
        .textContent,
    ).toContain("Lowest priority");
  });

  test("opens on installed files with ordered mods and Workshop links", async () => {
    mount();
    await screen.findByText("Highest priority");
    const list = within(screen.getByRole("list", { name: "Mod load order" }));
    expect(list.getAllByRole("listitem")[0].textContent).toContain("Highest priority");
    expect(list.getAllByRole("listitem")[1].textContent).toContain("Lowest priority");
    expect(list.getByRole("link", { name: "Workshop ↗" }).getAttribute("href")).toContain(
      "1787041596",
    );
    expect(screen.getByText("/.local/share/Euro Truck Simulator 2")).toBeTruthy();
  });
  test("read-only subuser has no mutation controls", async () => {
    mount(["file.read", "file.read-content"]);
    await screen.findByText("Highest priority");
    expect(screen.queryByRole("button", { name: "Upload preset" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Save" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Remove Highest priority" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Activate" })).toBeNull();
    expect(screen.getByRole("button", { name: "Download" })).toBeTruthy();
  });
  test("preset edits are immediate and saving does not activate", async () => {
    mount();
    await screen.findByText("Highest priority");
    fireEvent.click(screen.getByRole("button", { name: /^Convoy/ }));
    fireEvent.click(screen.getByRole("button", { name: "Move Highest priority down" }));
    expect(
      within(screen.getByRole("list", { name: "Mod load order" })).getAllByRole("listitem")[0]
        .textContent,
    ).toContain("Lowest priority");
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    await waitFor(() =>
      expect(http.put).toHaveBeenCalledWith(
        expect.stringContaining("/order"),
        { revision: 1, order: ["_first", "_last"], confirm_experimental_removal: false },
        expect.anything(),
      ),
    );
    expect(http.post).not.toHaveBeenCalled();
  });
  test("removal requires explicit warning confirmation", async () => {
    mount();
    await screen.findByText("Highest priority");
    fireEvent.click(screen.getByRole("button", { name: "Remove Highest priority" }));
    expect(screen.getByText(/Removing mods is experimental/)).toBeTruthy();
    expect(document.querySelectorAll(".smm-mods li")).toHaveLength(2);
    fireEvent.click(screen.getByRole("button", { name: "Remove mod" }));
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    await waitFor(() =>
      expect(http.put).toHaveBeenCalledWith(
        expect.stringContaining("/order"),
        expect.objectContaining({ order: ["_first"], confirm_experimental_removal: true }),
        expect.anything(),
      ),
    );
  });
  test("file.update without file.delete permits reorder but hides removal", async () => {
    mount(["file.read", "file.read-content", "file.create", "file.update"]);
    await screen.findByText("Highest priority");
    expect(screen.getByRole("button", { name: "Move Highest priority down" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Remove Highest priority" })).toBeNull();
  });
  test("separate file selections accumulate until save", async () => {
    vi.mocked(upload).mockResolvedValue({ preset });
    mount();
    fireEvent.click(await screen.findByRole("button", { name: "Upload preset" }));
    fireEvent.change(screen.getByLabelText("Preset name"), { target: { value: "Weekend" } });
    const sii = new File(["SiiNunit"], "server_packages.sii");
    const dat = new File(["binary"], "server_packages.dat");
    fireEvent.change(screen.getByLabelText("Server package files"), { target: { files: [sii] } });
    expect(upload).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText("Server package files"), { target: { files: [dat] } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    await waitFor(() =>
      expect(upload).toHaveBeenCalledWith(expect.any(String), "Weekend", [sii, dat]),
    );
  });
  test("external active pair appears immediately and can be imported", async () => {
    vi.mocked(overview).mockResolvedValue({
      ...data,
      active: { ...data.active, state: "external", preset_id: null },
    });
    mount();
    await screen.findByText("Highest priority");
    expect(screen.getByRole("button", { name: "Save as preset" })).toBeTruthy();
    expect(screen.getByRole("button", { name: "Remove Highest priority" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Edit" })).toBeNull();
  });
  test("downloading does not start competing overview and detail refreshes", async () => {
    vi.mocked(download).mockResolvedValue();
    mount();
    const button = await screen.findByRole("button", { name: "Download" });
    const overviewCalls = vi.mocked(overview).mock.calls.length;
    const detailCalls = vi.mocked(detail).mock.calls.length;
    fireEvent.click(button);
    await waitFor(() => expect(download).toHaveBeenCalled());
    await waitFor(() => expect((button as HTMLButtonElement).disabled).toBe(false));
    expect(overview).toHaveBeenCalledTimes(overviewCalls);
    expect(detail).toHaveBeenCalledTimes(detailCalls);
  });
  test("refresh waits for the overview before starting a detail refresh", async () => {
    mount();
    await screen.findByRole("button", { name: "Download" });
    let finishOverview!: (value: Overview) => void;
    vi.mocked(overview).mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          finishOverview = resolve;
        }),
    );
    const detailCalls = vi.mocked(detail).mock.calls.length;
    fireEvent.click(screen.getByRole("button", { name: "Refresh" }));
    await waitFor(() => expect(finishOverview).toBeDefined());
    expect(detail).toHaveBeenCalledTimes(detailCalls);
    finishOverview(structuredClone(data));
    await waitFor(() => expect(detail).toHaveBeenCalledTimes(detailCalls + 1));
  });
  test("activation changes files and shows restart notice without power request", async () => {
    mount();
    fireEvent.click(await screen.findByRole("button", { name: /^Convoy/ }));
    fireEvent.click(await screen.findByRole("button", { name: "Activate" }));
    await screen.findByText(/Active package updated and backed up/);
    expect(http.post).toHaveBeenCalledTimes(1);
    expect(http.post).toHaveBeenCalledWith(
      expect.stringContaining("/activate"),
      { revision: 1 },
      expect.anything(),
    );
  });
  test("pending operations disable mutation and offer recovery", async () => {
    vi.mocked(overview).mockResolvedValue({
      ...data,
      pending: [{ id: "op", kind: "activate", status: "swapping", created_at: "" }],
    });
    mount();
    const save = await screen.findByRole("button", { name: "Save" });
    expect((save as HTMLButtonElement).disabled).toBe(true);
    expect(
      (screen.getByRole("button", { name: "Move Highest priority down" }) as HTMLButtonElement)
        .disabled,
    ).toBe(true);
    fireEvent.click(screen.getByRole("button", { name: "Recover" }));
    await waitFor(() =>
      expect(http.post).toHaveBeenCalledWith(expect.stringContaining("/operations/op/recover")),
    );
  });
});
