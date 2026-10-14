import {
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
  type PointerEvent,
  type RefObject,
} from "react";

/** Pointer sorting keeps the dragged DOM node mounted, including in Chrome and on touch screens. */
export function usePointerSort<T>(options: {
  list: RefObject<HTMLOListElement | null>;
  key: string;
  enabled: boolean;
  rows: { id: string }[];
  before: T;
  restore: (before: T) => void;
  move: (id: string, index: number) => void;
}) {
  const latest = useRef(options);
  useLayoutEffect(() => {
    latest.current = options;
  });
  const [draggedId, setDraggedId] = useState<string>();
  const gesture = useRef<{
    id: string;
    pointerId: number;
    before: T;
    key: string;
    x: number;
    y: number;
  } | null>(null);
  const frame = useRef<number>(0);

  function inside(x: number, y: number) {
    const rect = latest.current.list.current?.getBoundingClientRect();
    return !!rect && x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom;
  }
  function preview() {
    const current = gesture.current;
    const state = latest.current;
    if (!current || !state.enabled || state.key !== current.key || !inside(current.x, current.y))
      return;
    const element = document
      .elementFromPoint(current.x, current.y)
      ?.closest<HTMLLIElement>("li[data-mod-id]");
    if (!element || !state.list.current?.contains(element)) return;
    const index = state.rows.findIndex((row) => row.id === element.dataset.modId);
    const from = state.rows.findIndex((row) => row.id === current.id);
    if (index < 0 || from < 0 || index === from) return;
    const rect = element.getBoundingClientRect();
    if (
      from < index ? current.y < rect.top + rect.height / 2 : current.y > rect.top + rect.height / 2
    )
      return;
    state.move(current.id, index);
  }
  function tick() {
    const current = gesture.current;
    if (!current) return;
    if (inside(current.x, current.y)) {
      if (current.y < 80) window.scrollBy(0, -10);
      else if (current.y > window.innerHeight - 80) window.scrollBy(0, 10);
    }
    preview();
    frame.current = requestAnimationFrame(tick);
  }
  function finish(cancel: boolean) {
    const current = gesture.current;
    if (!current) return;
    cancelAnimationFrame(frame.current);
    gesture.current = null;
    if (cancel || !inside(current.x, current.y)) latest.current.restore(current.before);
    setDraggedId(undefined);
  }
  useEffect(() => {
    function move(event: globalThis.PointerEvent) {
      const current = gesture.current;
      if (!current || event.pointerId !== current.pointerId) return;
      event.preventDefault();
      current.x = event.clientX;
      current.y = event.clientY;
    }
    function up(event: globalThis.PointerEvent) {
      if (!gesture.current || event.pointerId !== gesture.current.pointerId) return;
      gesture.current.x = event.clientX;
      gesture.current.y = event.clientY;
      preview();
      finish(false);
    }
    function cancel() {
      finish(true);
    }
    function key(event: KeyboardEvent) {
      if (event.key === "Escape" && gesture.current) {
        event.preventDefault();
        cancel();
      }
    }
    window.addEventListener("pointermove", move, { passive: false });
    window.addEventListener("pointerup", up);
    window.addEventListener("pointercancel", cancel);
    window.addEventListener("blur", cancel);
    window.addEventListener("keydown", key);
    return () => {
      window.removeEventListener("pointermove", move);
      window.removeEventListener("pointerup", up);
      window.removeEventListener("pointercancel", cancel);
      window.removeEventListener("blur", cancel);
      window.removeEventListener("keydown", key);
      gesture.current = null;
      cancelAnimationFrame(frame.current);
    };
  }, []);
  useEffect(() => {
    if (gesture.current && (!options.enabled || gesture.current.key !== options.key)) finish(true);
  }, [options.enabled, options.key]);

  function start(event: PointerEvent, id: string) {
    const state = latest.current;
    if (!state.enabled || event.button !== 0 || gesture.current) return;
    event.preventDefault();
    gesture.current = {
      id,
      pointerId: event.pointerId,
      before: state.before,
      key: state.key,
      x: event.clientX,
      y: event.clientY,
    };
    setDraggedId(id);
    frame.current = requestAnimationFrame(tick);
  }
  return { draggedId, start };
}
