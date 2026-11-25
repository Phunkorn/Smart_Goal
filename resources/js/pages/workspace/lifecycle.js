const boardEventOptions = (target, options = {}) => {
  const doc = target?.ownerDocument || target?.document || target;
  const signal = doc?.querySelector?.("[data-workspace-board]")?._workspaceEvents?.signal;
  return { ...typeof options === "boolean" ? { capture: options } : options, ...signal ? { signal } : {} };
};
export {
  boardEventOptions
};
