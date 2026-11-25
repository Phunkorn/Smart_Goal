const csrfToken = (doc) => doc.querySelector('meta[name="csrf-token"]')?.content || "";
class VersionConflictError extends Error {
  constructor(payload) {
    super(payload?.message || "มีการแก้ไขกระดานพร้อมกัน");
    this.isVersionConflict = true;
    this.payload = payload;
  }
}
const createClient = ({ routes, fetchImpl = globalThis.fetch, doc = globalThis.document, timeoutMs = 15e3 }) => {
  const controllers = /* @__PURE__ */ new Set();
  const request = async (url, options = {}) => {
    const controller = new AbortController();
    controllers.add(controller);
    let timer;
    try {
      const timeout = new Promise((_, reject) => {
        timer = setTimeout(() => {
          controller.abort();
          reject(Object.assign(new Error("การเชื่อมต่อใช้เวลานาน จะลองใหม่"), { retryable: true }));
        }, timeoutMs);
      });
      return await Promise.race([(async () => {
        const response = await fetchImpl(url, { ...options, signal: controller.signal, cache: "no-store", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest", ...options.headers } });
        const payload = await response.json().catch(() => null);
        if (response.status === 409 && payload?.code === "version_conflict") throw new VersionConflictError(payload);
        if (!response.ok) throw Object.assign(new Error(payload?.message || "เชื่อมต่อกระดานไม่สำเร็จ"), { status: response.status, retryable: response.status === 429 || response.status >= 500 });
        if (payload?.ok !== true || !Number.isSafeInteger(payload.version) || payload.version < 1) throw Object.assign(new Error("ข้อมูลตอบกลับไม่ถูกต้อง ยังไม่ได้ยืนยันการบันทึก"), { retryable: true });
        return payload;
      })(), timeout]);
    } finally {
      clearTimeout(timer);
      controllers.delete(controller);
    }
  };
  return {
    async load({ since } = {}) {
      const url = new URL(routes.document, doc.defaultView.location.href);
      if (since) url.searchParams.set("since", String(since));
      const payload = await request(url.toString());
      if (!payload.unchanged && !Array.isArray(payload.document?.elements) && !(Array.isArray(payload.delta?.upserts) && Array.isArray(payload.delta?.deleted) && Array.isArray(payload.delta?.order))) throw new Error("ข้อมูลกระดานไม่ครบ");
      return payload;
    },
    async save({ document, baseVersion, operations, requestId }) {
      const payload = await request(routes.save, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken(doc) }, body: JSON.stringify({ _method: "PUT", base_version: baseVersion, ...operations ? { operations, request_id: requestId } : { document } }) });
      if (operations && (payload.request_id !== requestId || !Array.isArray(payload.document?.elements))) throw Object.assign(new Error("ยังไม่ได้รับการยืนยันชุดแก้ไข"), { retryable: true });
      return payload;
    },
    destroy() {
      controllers.forEach((controller) => controller.abort());
      controllers.clear();
    }
  };
};
export {
  VersionConflictError,
  createClient
};
