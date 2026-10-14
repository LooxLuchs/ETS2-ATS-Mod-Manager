import { cpSync, existsSync, mkdirSync, readFileSync, rmSync } from "node:fs";
import { resolve, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { execFileSync } from "node:child_process";
const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const compatibility = JSON.parse(readFileSync(resolve(root, "compatibility.json"), "utf8"));
const revision = compatibility.panel_commit;
const supplied = process.argv[2];
const panel = supplied ? resolve(supplied) : resolve(root, ".cache/panel");
mkdirSync(resolve(root, ".cache"), { recursive: true });
if (!existsSync(resolve(panel, "packages/sdk"))) {
  if (supplied) throw new Error("The supplied directory is not a v2 panel checkout.");
  execFileSync(
    "git",
    [
      "clone",
      "--filter=blob:none",
      "--no-checkout",
      compatibility.panel_repository + ".git",
      panel,
    ],
    { stdio: "inherit" },
  );
  execFileSync("git", ["checkout", revision], { cwd: panel, stdio: "inherit" });
}
const actual = execFileSync("git", ["rev-parse", "HEAD"], { cwd: panel, encoding: "utf8" }).trim();
if (actual !== revision)
  throw new Error(
    `Expected pinned panel ${revision}, got ${actual}. Use the pinned checkout or intentionally update compatibility.json.`,
  );
if (!existsSync(resolve(panel, "node_modules")))
  execFileSync("npm", ["ci"], { cwd: panel, stdio: "inherit" });
execFileSync("npm", ["run", "sdk:testing"], { cwd: panel, stdio: "inherit" });
const target = resolve(root, ".cache/sdk");
rmSync(target, { recursive: true, force: true });
cpSync(resolve(panel, "packages/sdk"), target, { recursive: true });
console.log(`Prepared SDK from panel ${revision}. Next: npm ci`);
