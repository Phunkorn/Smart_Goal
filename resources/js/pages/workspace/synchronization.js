const clone = (value) => JSON.parse(JSON.stringify(value));
const canonical = (value) => Array.isArray(value) ? value.map(canonical) : value && typeof value === "object" ? Object.fromEntries(Object.keys(value).sort().filter((key) => !(["bold", "italic", "rotation", "letterSpacing"].includes(key) && !value[key]) && !(key === "lineAligns" && !Object.keys(value[key] || {}).length)).map((key) => [key, canonical(value[key])])) : typeof value === "number" ? Math.round(value * 100) / 100 : value;
const equal = (a, b) => JSON.stringify(canonical(a)) === JSON.stringify(canonical(b));
const index = (document) => new Map((document?.elements || []).map((element) => [element.id, element]));
const cleanDocument = (document) => ({ schema: 1, elements: (document?.elements || []).map(({ src, ...element }) => element) });
const operationsBetween = (before, after) => {
  const old = index(cleanDocument(before));
  const current = index(cleanDocument(after));
  const operations = [];
  old.forEach((element, id) => {
    if (!current.has(id)) operations.push({ type: "delete", id });
  });
  current.forEach((element, id) => {
    if (!equal(element, old.get(id))) operations.push({ type: "put", id, element });
  });
  return operations;
};
const applyDelta = (document, delta) => {
  const elements = index(document);
  delta.deleted.forEach((id) => elements.delete(id));
  delta.upserts.forEach((element) => elements.set(element.id, element));
  return { schema: 1, elements: delta.order.map((id) => elements.get(id)).filter(Boolean) };
};
const groupOf = (key) => ["x", "y", "w", "h", "rotation", "points"].includes(key) ? "geometry" : ["text", "lineAligns"].includes(key) ? "text" : key;
const groupValue = (object, group) => Object.fromEntries(Object.entries(object || {}).filter(([key]) => groupOf(key) === group));
const mergeDocuments = (base, local, remote) => {
  const b = index(base), l = index(local), r = index(remote);
  const merged = new Map(r), conflicts = [];
  for (const id of /* @__PURE__ */ new Set([...b.keys(), ...l.keys()])) {
    const before = b.get(id), mine = l.get(id), theirs = r.get(id);
    if (equal(before, mine)) continue;
    if (!before) {
      if (!theirs || equal(cleanDocument({ elements: [mine] }), cleanDocument({ elements: [theirs] }))) merged.set(id, mine);
      else {
        merged.set(id, mine);
        conflicts.push({ id, group: "object", local: mine, remote: theirs });
      }
      continue;
    }
    if (!mine) {
      if (!theirs || equal(before, theirs)) merged.delete(id);
      else {
        merged.delete(id);
        conflicts.push({ id, group: "object", local: null, remote: theirs });
      }
      continue;
    }
    if (!theirs) {
      merged.set(id, mine);
      conflicts.push({ id, group: "object", local: mine, remote: null });
      continue;
    }
    const next = { ...theirs };
    const groups = new Set([...Object.keys(before), ...Object.keys(mine)].filter((k) => k !== "src").map(groupOf));
    groups.forEach((group) => {
      const a = groupValue(before, group), m = groupValue(mine, group), t = groupValue(theirs, group);
      if (equal(a, m)) return;
      Object.keys(next).filter((k) => groupOf(k) === group).forEach((k) => delete next[k]);
      Object.assign(next, m);
      if (!equal(a, t) && !equal(m, t)) conflicts.push({ id, group, local: mine, remote: theirs });
    });
    merged.set(id, next);
  }
  const order = [...r.keys(), ...l.keys()].filter((id, i, all) => all.indexOf(id) === i);
  return { document: { schema: 1, elements: order.map((id) => merged.get(id)).filter(Boolean).sort((a, b2) => (a.z || 0) - (b2.z || 0)) }, conflicts };
};
const withoutConflicts = (local, remote, conflicts) => {
  const result = index(clone(local)), server = index(remote);
  conflicts.forEach(({ id, group }) => {
    const theirs = server.get(id);
    if (group === "object") {
      if (theirs) result.set(id, theirs);
      else result.delete(id);
      return;
    }
    const mine = result.get(id);
    if (!mine || !theirs) return;
    Object.keys(mine).filter((key) => groupOf(key) === group).forEach((key) => delete mine[key]);
    Object.assign(mine, groupValue(theirs, group));
  });
  return { schema: 1, elements: [...result.values()].sort((a, b) => (a.z || 0) - (b.z || 0)) };
};
const applyHistoryDelta = (current, from, to) => {
  const merged = mergeDocuments(from, to, current);
  return withoutConflicts(merged.document, current, merged.conflicts);
};
export {
  applyDelta,
  applyHistoryDelta,
  cleanDocument,
  clone,
  equal,
  mergeDocuments,
  operationsBetween,
  withoutConflicts
};
