import type { Limits } from "./api";

export default function ModCountNotice({ count, limits }: { count: number; limits?: Limits }) {
  const threshold = limits?.warn_mods ?? 100;
  const maximum = limits?.mods ?? 120;
  if (count < threshold) return null;
  return (
    <p className="smm-alert smm-warning" role="status">
      This package contains {count} mods. The Convoy limit is {threshold} mods; Mod Manager accepts
      up to {maximum}. Packages above the Convoy limit may not load in the game.
    </p>
  );
}
