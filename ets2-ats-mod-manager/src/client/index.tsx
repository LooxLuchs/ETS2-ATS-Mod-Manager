import "./styles.css";
import { definePterodactylExtension } from "@pterodactyl/sdk";
import { useManagerVisibility } from "./presence";
import ConsoleQuickControls from "./ConsoleQuickControls";
export default definePterodactylExtension({
  setup({ screens, slots }) {
    slots.register("server.console.after", ConsoleQuickControls);
    screens.register("admin", () => import("./screens/AdminScreen"));
    screens.register("manager", () => import("./screens/ManagerScreen"), {
      visible: ({ server }) => useManagerVisibility(server?.attributes.identifier),
    });
  },
});
