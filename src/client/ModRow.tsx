import { memo, type PointerEvent } from "react";
import type { Mod } from "./api";

export default memo(function ModRow({
  mod,
  index,
  count,
  canEdit,
  canDelete,
  blocked,
  dragging,
  move,
  remove,
  start,
}: {
  mod: Mod;
  index: number;
  count: number;
  canEdit: boolean;
  canDelete: boolean;
  blocked: boolean;
  dragging: boolean;
  move: (id: string, index: number) => void;
  remove: (id: string) => void;
  start: (event: PointerEvent, id: string) => void;
}) {
  return (
    <li
      data-mod-id={mod.id}
      className={dragging ? "smm-dragging" : undefined}
      draggable={false}
      onDragStart={(e) => e.preventDefault()}
    >
      {canEdit && (
        <span
          className="smm-drag-handle"
          aria-hidden="true"
          onPointerDown={(e) => start(e, mod.id)}
        >
          ⠿
        </span>
      )}
      <span className="smm-position">{index + 1}</span>
      <div className="smm-mod-name">
        <strong>{mod.mod_name || mod.package_name}</strong>
        <small>{mod.package_name}</small>
      </div>
      <div className="smm-tags">
        {mod.optional_mod && <span className="smm-badge">Optional</span>}
        {mod.workshop_url && (
          <a
            href={mod.workshop_url}
            target="_blank"
            rel="noopener noreferrer"
            className="smm-badge smm-link"
          >
            Workshop ↗
          </a>
        )}
      </div>
      {canEdit && (
        <div className="smm-row-actions">
          <button
            disabled={blocked || index === 0}
            aria-label={`Move ${mod.mod_name} up`}
            onClick={() => move(mod.id, index - 1)}
          >
            ↑
          </button>
          <button
            disabled={blocked || index === count - 1}
            aria-label={`Move ${mod.mod_name} down`}
            onClick={() => move(mod.id, index + 1)}
          >
            ↓
          </button>
          {canDelete && (
            <button
              disabled={blocked}
              aria-label={`Remove ${mod.mod_name}`}
              onClick={() => remove(mod.id)}
            >
              ×
            </button>
          )}
        </div>
      )}
    </li>
  );
});
