import { createSaveScheduler } from "./save-state.js";
import { applyDelta, clone, equal, mergeDocuments, operationsBetween, withoutConflicts } from "./synchronization.js";
import { createDraftStore } from "./draft.js";
import { uniqueRequestId } from "./scene.js";
const initAutosave = ({
  root,
  doc = root?.ownerDocument,
  client,
  design = {},
  capabilities = {},
  initialVersion = 1,
  initialDocument,
  getDocument,
  onReplaceDocument,
  isInteracting = () => false,
  isReceivingBlocked = isInteracting,
  accountId,
  boardId,
  now = () => Date.now(),
  setTimeoutImpl = setTimeout,
  clearTimeoutImpl = clearTimeout
} = {}) => {
  const win = doc.defaultView;
  let server = clone(initialDocument || getDocument()), version = initialVersion;
  let conflicts = [], flight = null, pollFlight = null, stopped = false, pollTimer = null, backoff = 3e3, lastActivity = now(), pendingBatch = null, deferred = null;
  let persistenceError = false;
  let terminalError = null;
  let storage;
  try {
    storage = win.localStorage;
  } catch {
    persistenceError = true;
  }
  const drafts = createDraftStore({ storage, accountId, boardId, now, onError: () => {
    persistenceError = true;
  } });
  const indicator = root.querySelector("[data-workspace-save-state]");
  const recovery = root.querySelector("[data-workspace-recovery]");
  const listeners = [];
  const listen = (target, event, handler) => {
    target?.addEventListener(event, handler);
    listeners.push(() => target?.removeEventListener(event, handler));
  };
  const dirty = () => conflicts.length > 0 || operationsBetween(server, getDocument()).length > 0;
  const persist = () => {
    if (dirty() || pendingBatch) drafts.save({ base: server, local: getDocument(), version, conflicts, pendingBatch });
    else drafts.clear();
  };
  const paint = (state) => {
    if (stopped) return;
    const actual = win.navigator.onLine === false ? "offline" : terminalError ? "blocked" : conflicts.length ? "conflict" : state;
    const meta = design.saveStates?.[actual] || { label: actual, icon: "bi-cloud", tone: "gray" };
    if (indicator) {
      indicator.hidden = false;
      indicator.dataset.state = actual;
      indicator.className = `wsb-save wsb-save--${meta.tone}`;
      const icon = indicator.querySelector("i"), label = indicator.querySelector("[data-workspace-save-label]");
      if (icon) icon.className = `bi ${meta.icon}`;
      if (label) label.textContent = meta.label;
    }
    if (recovery) {
      recovery.hidden = !conflicts.length && !persistenceError && !terminalError;
      const message = recovery.querySelector("[data-workspace-recovery-message]");
      if (message) message.textContent = persistenceError ? "เก็บฉบับสำรองในเครื่องไม่ได้ โปรดดาวน์โหลดงานค้างก่อนออก" : `มี ${conflicts.length} จุดที่แก้ไขพร้อมกัน งานของคุณยังอยู่ในเครื่อง`;
      if (terminalError && message) message.textContent = terminalError;
      recovery.querySelectorAll("[data-workspace-resolve]").forEach((button) => {
        button.disabled = !conflicts.length;
      });
    }
  };
  const merge = (payload, base = server) => {
    if (stopped || !Number.isSafeInteger(payload?.version) || payload.version < version) return;
    if (payload.unchanged) return;
    const remote = payload.document || (payload.delta ? applyDelta(server, payload.delta) : null);
    if (!remote) return;
    const result = mergeDocuments(base, getDocument(), remote);
    const previous = conflicts;
    server = clone(remote);
    version = payload.version;
    const unique = new Map([...previous, ...result.conflicts].map((c) => [`${c.id}:${c.group}`, c]));
    conflicts = [...unique.values()].filter((c) => !equal(withoutConflicts(result.document, server, [c]), result.document));
    onReplaceDocument?.(result.document, { remote: true, previous: base, server: remote });
    persist();
    paint(dirty() ? "dirty" : "saved");
  };
  const save = async () => {
    if (stopped || capabilities.canEdit !== true || isInteracting()) return;
    if (win.navigator.onLine === false) throw Object.assign(new Error("ออฟไลน์"), { retryable: true });
    for (let attempt = 0; attempt < 3; attempt++) {
      const safe = withoutConflicts(clone(getDocument()), server, conflicts);
      const operations = operationsBetween(server, safe);
      if (!operations.length && !pendingBatch) {
        paint(dirty() ? "conflict" : "saved");
        return;
      }
      if (!pendingBatch) pendingBatch = { baseVersion: version, operations, document: safe, created: operations.filter((op) => op.type === "put" && !server.elements.some((e) => e.id === op.id)).map((op) => op.id), requestId: uniqueRequestId() };
      const batch = pendingBatch;
      persist();
      try {
        flight = client.save(batch);
        const payload = await flight;
        flight = null;
        if (stopped) return;
        pendingBatch = null;
        merge({ ...payload, document: payload.document || batch.document }, batch.document);
        if (dirty() && operationsBetween(server, withoutConflicts(getDocument(), server, conflicts)).length) scheduler.markDirty();
        return;
      } catch (error) {
        flight = null;
        if (stopped) return;
        if (error.isVersionConflict) {
          pendingBatch = null;
          merge(error.payload);
          if (batch.uncertain) {
            for (const id of batch.created || []) {
              if (!server.elements.some((e) => e.id === id)) {
                conflicts.push({ id, group: "object", local: getDocument().elements.find((e) => e.id === id), remote: null });
              }
            }
          }
          persist();
          continue;
        }
        if (error.retryable === false) pendingBatch = null;
        else batch.uncertain = true;
        persist();
        throw error;
      }
    }
    throw Object.assign(new Error("กำลังซิงค์การแก้ไขพร้อมกัน"), { retryable: true });
  };
  const scheduler = createSaveScheduler({ save, now, setTimeoutImpl, clearTimeoutImpl, onState: (state, detail) => {
    if (stopped) return;
    if (detail?.error?.retryable === false) terminalError = detail.error.message;
    persist();
    paint(state === "error" && !terminalError ? "reconnecting" : state);
  } });
  const markDirty = () => {
    if (capabilities.canEdit !== true) return;
    lastActivity = now();
    persist();
    if (stopped) return;
    terminalError = null;
    if (dirty()) scheduler.markDirty();
  };
  const schedulePoll = (delay) => {
    clearTimeoutImpl(pollTimer);
    if (stopped || doc.hidden || win.navigator.onLine === false) return;
    pollTimer = setTimeoutImpl(() => {
      pollTimer = null;
      refresh();
    }, delay);
    pollTimer?.unref?.();
  };
  const refresh = async () => {
    if (stopped) return;
    if (flight) {
      try {
        await flight;
      } catch {
      }
      if (stopped) return;
    }
    if (pollFlight) return pollFlight;
    if (isReceivingBlocked()) {
      schedulePoll(3e3);
      return;
    }
    paint("syncing");
    pollFlight = (async () => {
      try {
        const payload = await client.load({ since: version });
        if (stopped) return;
        if (isReceivingBlocked()) deferred = payload;
        else merge(payload);
        backoff = now() - lastActivity > 3e4 ? 1e4 : 3e3;
        if (dirty() && capabilities.canEdit === true && !terminalError && scheduler.state() === "saved") scheduler.markDirty();
        paint(dirty() ? "dirty" : "saved");
      } catch (error) {
        backoff = Math.min(6e4, backoff * 2);
        if (error.retryable === false) terminalError = error.message;
        paint("error");
        if (error.retryable === false) {
          stopped = true;
          scheduler.stop();
          clearTimeoutImpl(pollTimer);
        }
      } finally {
        pollFlight = null;
        schedulePoll(backoff);
      }
    })();
    return pollFlight;
  };
  listen(root.querySelector("[data-workspace-refresh]"), "click", () => refresh());
  listen(root, "pointerdown", () => {
    lastActivity = now();
  });
  listen(doc, "visibilitychange", () => {
    clearTimeoutImpl(pollTimer);
    persist();
    if (doc.hidden) {
      if (dirty()) scheduler.flushNow();
    } else {
      refresh();
    }
  });
  listen(win, "online", () => {
    backoff = 3e3;
    refresh();
    if (dirty()) scheduler.markDirty();
  });
  listen(win, "offline", () => {
    clearTimeoutImpl(pollTimer);
    persist();
    paint("offline");
  });
  listen(win, "pageshow", (event) => {
    if (event.persisted) refresh();
  });
  listen(win, "beforeunload", (event) => {
    if (dirty()) {
      persist();
      event.preventDefault();
      event.returnValue = "";
    }
  });
  const destroy = () => {
    persist();
    stopped = true;
    scheduler.stop();
    clearTimeoutImpl(pollTimer);
    client.destroy?.();
    listeners.splice(0).forEach((remove) => remove());
  };
  listen(win, "pagehide", (event) => {
    persist();
    if (!event.persisted) destroy();
    else clearTimeoutImpl(pollTimer);
  });
  const resolve = (choice) => {
    if (choice === "remote") onReplaceDocument?.(withoutConflicts(getDocument(), server, conflicts), { remote: true });
    else {
      const serverIds = new Set(server.elements.map((e) => e.id));
      const document = clone(getDocument());
      document.elements.forEach((element) => {
        if (!serverIds.has(element.id) && conflicts.some((c) => c.id === element.id && c.remote === null)) element.id = `e-${uniqueRequestId()}`;
      });
      onReplaceDocument?.(document, { remote: true });
    }
    conflicts = [];
    pendingBatch = null;
    scheduler.resume();
    markDirty();
    paint(dirty() ? "dirty" : "saved");
  };
  listen(recovery, "click", (event) => {
    const button = event.target.closest("[data-workspace-resolve]");
    if (button) resolve(button.dataset.workspaceResolve);
    if (event.target.closest("[data-workspace-download]")) {
      const url = win.URL.createObjectURL(new win.Blob([JSON.stringify({ version, base: server, local: getDocument(), conflicts })], { type: "application/json" }));
      const link = doc.createElement("a");
      link.href = url;
      link.download = "idea-board-draft.json";
      link.click();
      win.URL.revokeObjectURL(url);
    }
  });
  const previousDraft = drafts.load();
  const restored = capabilities.canEdit === true ? previousDraft : null;
  if (restored) {
    const result = mergeDocuments(restored.base, restored.local, server);
    conflicts = [...restored.conflicts || [], ...result.conflicts];
    pendingBatch = restored.pendingBatch || null;
    onReplaceDocument?.(result.document, { remote: true });
    markDirty();
    if (pendingBatch) scheduler.markDirty();
  }
  paint(dirty() ? "dirty" : "saved");
  schedulePoll(3e3);
  return {
    scheduler,
    refresh,
    catchUp: refresh,
    currentVersion: () => version,
    markDirty,
    saveNow: () => scheduler.flushNow(),
    beginGesture: () => scheduler.beginGesture(),
    endGesture: () => {
      scheduler.endGesture();
      if (deferred) {
        const payload = deferred;
        deferred = null;
        merge(payload);
        if (dirty()) scheduler.markDirty();
      }
    },
    isDirty: dirty,
    conflicts: () => clone(conflicts),
    resolve,
    destroy
  };
};
export {
  initAutosave
};
