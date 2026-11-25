const createDraftStore = ({ storage, accountId, boardId, now = () => Date.now(), onError = () => {
} }) => {
  const prefix = "smartgoal:board-draft:";
  const key = `${prefix}${accountId}:${boardId}`;
  const maxAge = 7 * 24 * 60 * 60 * 1e3;
  const enabled = Boolean(storage && accountId && boardId);
  return {
    load() {
      if (!enabled) return null;
      try {
        for (let i = storage.length - 1; i >= 0; i--) {
          const item = storage.key(i);
          if (item?.startsWith(prefix) && !item.startsWith(`${prefix}${accountId}:`)) storage.removeItem(item);
        }
        const value = JSON.parse(storage.getItem(key) || "null");
        if (!value) return null;
        if (value.accountId !== accountId || value.boardId !== boardId || now() - value.time > maxAge || !Array.isArray(value.base?.elements) || !Array.isArray(value.local?.elements) || !Number.isSafeInteger(value.version) || value.version < 1) {
          storage.removeItem(key);
          return null;
        }
        return value;
      } catch {
        onError();
        return null;
      }
    },
    save({ base, local, version, conflicts, pendingBatch }) {
      if (!enabled) return false;
      try {
        storage.setItem(key, JSON.stringify({ accountId, boardId, time: now(), base, local, version, conflicts, pendingBatch }));
        return true;
      } catch {
        onError();
        return false;
      }
    },
    clear() {
      if (enabled) {
        try {
          storage.removeItem(key);
        } catch {
          onError();
        }
      }
    }
  };
};
export {
  createDraftStore
};
