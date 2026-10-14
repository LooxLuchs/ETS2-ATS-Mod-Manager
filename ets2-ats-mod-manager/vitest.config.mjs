import { defineConfig } from "vitest/config";
import {
  pterodactylTestAliases,
  pterodactylTestSetup,
  pterodactylTestDirectory,
} from "@pterodactyl/sdk/vitest";

export default defineConfig({
  server: { fs: { allow: [process.cwd(), pterodactylTestDirectory] } },
  resolve: { alias: pterodactylTestAliases() },
  test: {
    include: ["src/client/**/*.spec.ts", "src/client/**/*.spec.tsx"],
    environment: "jsdom",
    setupFiles: [pterodactylTestSetup],
  },
});
