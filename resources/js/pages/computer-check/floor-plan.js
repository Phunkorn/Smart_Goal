/*
 * ผังห้อง — การเลือกเครื่อง และการย่อ/ขยาย/เลื่อน
 *
 * ส่วนคำนวณทั้งหมดแยกเป็นฟังก์ชันบริสุทธิ์ไว้ด้านบน เพื่อให้ทดสอบกติกาการซูม
 * และการจำกัดขอบเขตได้โดยไม่ต้องมี DOM หรือ layout จริง (รูปแบบเดียวกับ
 * components/modal-stack.js ของโปรเจกต์)
 *
 * ซูมด้วย CSS transform ไม่ใช่การแก้ viewBox เพราะ transform ถูก composite
 * บน GPU จึงลื่นกว่าบนมือถือ และไม่แตะพิกัดของ workstation ที่มาจากฐานข้อมูล
 */

export const MIN_SCALE = 0.6;
export const MAX_SCALE = 4;
export const SCALE_STEP = 0.3;

/** จำกัดค่าซูมให้อยู่ในช่วงที่ยังใช้งานได้ */
export function clampScale(scale) {
    if (! Number.isFinite(scale)) return 1;

    return Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale));
}

/**
 * จำกัดการเลื่อนไม่ให้ลากผังหลุดออกนอกกรอบจนหาไม่เจอ
 *
 * เมื่อผังเล็กกว่ากรอบ (ซูมออกสุด) จะถูกจัดกึ่งกลางแทนการปล่อยให้ลากไปมุมใดมุมหนึ่ง
 * นี่คือสิ่งที่กัน "เลื่อนแนวนอนแบบควบคุมไม่ได้" ตามข้อกำหนดของฟีเจอร์
 */
export function clampOffset({x, y}, {scale, stageWidth, stageHeight, contentWidth, contentHeight}) {
    const scaledWidth = contentWidth * scale;
    const scaledHeight = contentHeight * scale;

    const axis = (value, scaledSize, stageSize) => {
        if (scaledSize <= stageSize) return (stageSize - scaledSize) / 2;

        return Math.min(0, Math.max(stageSize - scaledSize, value));
    };

    return {
        x: axis(x, scaledWidth, stageWidth),
        y: axis(y, scaledHeight, stageHeight),
    };
}

/**
 * ซูมโดยตรึงจุดหนึ่งบนผังให้อยู่กับที่ (จุดกึ่งกลางกรอบ หรือจุดที่นิ้วหยิก)
 * ถ้าไม่ตรึงจุด ผังจะเลื่อนหนีมือทุกครั้งที่กดปุ่มขยาย
 */
export function zoomAround(state, nextScale, focal) {
    const scale = clampScale(nextScale);
    const ratio = scale / state.scale;

    return {
        scale,
        x: focal.x - (focal.x - state.x) * ratio,
        y: focal.y - (focal.y - state.y) * ratio,
    };
}

/** สลับสมาชิกใน/ออกจากชุดที่เลือกไว้ — ใช้ตอนโหมดเลือกหลายเครื่อง */
export function toggleSelection(selected, id) {
    const next = new Set(selected);

    if (next.has(id)) next.delete(id);
    else next.add(id);

    return next;
}

/* ---------- ตัวควบคุมบน DOM ---------- */

export function initFloorPlan({root, design = {}, onSelect, onMultiChange} = {}) {
    const plan = root?.querySelector('[data-cc-plan]');

    if (! plan) return null;

    const stage = plan.querySelector('[data-cc-plan-stage]');
    const canvas = plan.querySelector('[data-cc-plan-canvas]');
    const multiToggle = plan.querySelector('[data-cc-multi-toggle]');

    if (! stage || ! canvas) return null;

    let view = {scale: 1, x: 0, y: 0};
    let selected = new Set();
    let multi = false;
    // การลากเพื่อเลื่อน — จำระยะไว้เพื่อแยก "แตะเพื่อเลือก" ออกจาก "ลากเพื่อเลื่อน"
    let drag = null;
    const pointers = new Map();
    let pinch = null;

    const metrics = () => ({
        stageWidth: stage.clientWidth,
        stageHeight: stage.clientHeight,
        contentWidth: canvas.offsetWidth || stage.clientWidth,
        contentHeight: canvas.offsetHeight || stage.clientHeight,
    });

    const paint = () => {
        const bounded = clampOffset(view, {scale: view.scale, ...metrics()});
        view = {...view, ...bounded};
        canvas.style.transform = `translate(${view.x}px, ${view.y}px) scale(${view.scale})`;
    };

    const fit = () => {
        view = {scale: 1, x: 0, y: 0};
        paint();
    };

    const zoomBy = (delta, focal) => {
        const centre = focal || {x: stage.clientWidth / 2, y: stage.clientHeight / 2};
        view = zoomAround(view, view.scale + delta, centre);
        paint();
    };

    const stationNodes = () => [...canvas.querySelectorAll('[data-cc-station]')];

    const paintSelection = () => {
        stationNodes().forEach((node) => {
            const isOn = selected.has(node.dataset.stationId);
            node.classList.toggle('is-selected', isOn);
            node.setAttribute('aria-pressed', isOn ? 'true' : 'false');
        });
    };

    const choose = (id) => {
        if (multi) {
            selected = toggleSelection(selected, id);
            paintSelection();
            onMultiChange?.([...selected]);

            return;
        }

        selected = new Set([id]);
        paintSelection();
        onSelect?.(id);
    };

    /* ---------- การเลือกเครื่อง ---------- */

    // event delegation ชุดเดียวบน canvas — เปิดแผงซ้ำ ๆ ไม่ทำให้ listener งอกเพิ่ม
    canvas.addEventListener('click', (event) => {
        const node = event.target.closest('[data-cc-station]');

        if (! node) return;
        // เพิ่งลากผังอยู่ ไม่ใช่การตั้งใจแตะเครื่อง
        if (drag?.moved) return;

        choose(node.dataset.stationId);
    });

    canvas.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const node = event.target.closest('[data-cc-station]');

        if (! node) return;

        event.preventDefault();
        choose(node.dataset.stationId);
    });

    /* ---------- ย่อ/ขยาย ---------- */

    plan.querySelectorAll('[data-cc-zoom]').forEach((button) => {
        button.addEventListener('click', () => {
            const action = button.dataset.ccZoom;

            if (action === 'reset') fit();
            else zoomBy(action === 'in' ? SCALE_STEP : -SCALE_STEP);
        });
    });

    /* ---------- ลากเพื่อเลื่อน และหยิกเพื่อซูม ---------- */

    stage.addEventListener('pointerdown', (event) => {
        pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        stage.setPointerCapture?.(event.pointerId);

        if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            pinch = {distance: Math.hypot(a.x - b.x, a.y - b.y), scale: view.scale};
            drag = null;

            return;
        }

        drag = {startX: event.clientX, startY: event.clientY, originX: view.x, originY: view.y, moved: false};
    });

    stage.addEventListener('pointermove', (event) => {
        if (! pointers.has(event.pointerId)) return;

        pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});

        if (pinch && pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            const distance = Math.hypot(a.x - b.x, a.y - b.y);

            if (pinch.distance > 0) {
                const bounds = stage.getBoundingClientRect();
                const focal = {
                    x: (a.x + b.x) / 2 - bounds.left,
                    y: (a.y + b.y) / 2 - bounds.top,
                };
                view = zoomAround(view, pinch.scale * (distance / pinch.distance), focal);
                paint();
            }

            return;
        }

        if (! drag) return;

        const dx = event.clientX - drag.startX;
        const dy = event.clientY - drag.startY;

        // ขยับไม่ถึง 4px ยังถือว่าเป็นการแตะ ไม่ใช่การลาก — กันนิ้วสั่นบนมือถือ
        if (! drag.moved && Math.hypot(dx, dy) < 4) return;

        drag.moved = true;
        view = {...view, x: drag.originX + dx, y: drag.originY + dy};
        paint();
    });

    const endPointer = (event) => {
        pointers.delete(event.pointerId);

        if (pointers.size < 2) pinch = null;
        // ปล่อยธง moved ไว้ให้ click อ่านก่อน แล้วค่อยล้างในเฟรมถัดไป
        if (pointers.size === 0) requestAnimationFrame(() => { drag = null; });
    };

    stage.addEventListener('pointerup', endPointer);
    stage.addEventListener('pointercancel', endPointer);

    stage.addEventListener('wheel', (event) => {
        if (! event.ctrlKey && Math.abs(event.deltaY) < 1) return;

        event.preventDefault();
        const bounds = stage.getBoundingClientRect();
        zoomBy(event.deltaY > 0 ? -SCALE_STEP : SCALE_STEP, {
            x: event.clientX - bounds.left,
            y: event.clientY - bounds.top,
        });
    }, {passive: false});

    /* ---------- โหมดเลือกหลายเครื่อง ---------- */

    multiToggle?.addEventListener('change', () => {
        multi = multiToggle.checked;
        selected = new Set();
        paintSelection();
        onMultiChange?.(multi ? [] : null);
    });

    globalThis.addEventListener?.('resize', paint);
    paint();

    return {
        isMulti: () => multi,
        selectedIds: () => [...selected],
        clearSelection: () => {
            selected = new Set();
            paintSelection();
        },
        /**
         * ทาสถานะใหม่ลงบนผังจากข้อมูลที่เซิร์ฟเวอร์ส่งกลับ
         *
         * แก้เฉพาะ attribute และข้อความสถานะของ <g> เดิม ไม่สร้าง SVG ใหม่
         * ตำแหน่งซูม/เลื่อน และโฟกัสของผู้ใช้จึงไม่ถูกรีเซ็ตทุกครั้งที่บันทึก
         *
         * ป้ายสถานะมาจาก design island (ComputerCheckDesign) ไฟล์นี้ไม่เก็บ
         * ข้อความไทยชุดที่สองของตัวเอง
         */
        applyStations: (stations) => {
            const byId = new Map((stations || []).map((station) => [String(station.id), station]));

            stationNodes().forEach((node) => {
                const station = byId.get(node.dataset.stationId);

                if (! station) return;

                const meta = design.workstationStatuses?.[station.status] || {};

                node.classList.remove('cc-station--pending', 'cc-station--partial', 'cc-station--normal', 'cc-station--issue');
                node.classList.add(`cc-station--${station.status}`);
                node.dataset.status = station.status;
                node.setAttribute('aria-label', `${station.code} — ${station.status_label}`);

                const title = node.querySelector('title');
                if (title) title.textContent = `${station.code} — ${station.status_label}${station.note ? ` (${station.note})` : ''}`;

                const state = node.querySelector('.cc-station__state');
                if (state) state.textContent = `${meta.mark || ''} ${station.status_label}`.trim();
            });

            paintSelection();
        },
        stationNodes,
        reset: fit,
    };
}
