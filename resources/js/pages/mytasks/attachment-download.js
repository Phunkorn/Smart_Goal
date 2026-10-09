/**
 * กล่อง "ดาวน์โหลดไฟล์แนบ" ของ Task Workspace
 *
 * ปัญหาเดิม: การ์ดไฟล์แนบมีแต่ลิงก์เปิดดูไฟล์ทีละไฟล์ ไม่มีปุ่มดาวน์โหลด
 * และเลือกหลายรายการพร้อมกันไม่ได้ ผู้ใช้ที่ต้องเก็บเอกสารทั้งงานจึงต้อง
 * คลิกเปิดทีละไฟล์แล้วสั่งบันทึกจากตัวเปิดไฟล์ของเบราว์เซอร์เอง
 *
 * ทางแก้: โมดัลเดียวที่ทั้ง User และ Admin ใช้ร่วมกัน (markup เดียวใน
 * workspace-interactions.blade.php) ติ๊กเลือกไฟล์ที่ต้องการแล้วกดดาวน์โหลดครั้งเดียว
 *
 * ทำไมไม่บีบเป็น .zip ที่ server: AttachmentPolicy ยอมให้ไฟล์ละ 1 GB และ 20 ไฟล์ต่องาน
 * การบีบฝั่ง server จึงอาจต้องเขียนไฟล์ชั่วคราวระดับหลายสิบกิกะไบต์ต่อหนึ่งคำขอ
 * การให้เบราว์เซอร์ดาวน์โหลดตรงจาก endpoint เดิมจึงสตรีมได้ และใช้จุดตรวจสิทธิ์เดียวกัน
 *
 * ส่วนคำนวณสถานะแยกเป็นฟังก์ชันบริสุทธิ์ทั้งหมด เพื่อให้ทดสอบได้โดยไม่ต้องมี DOM
 */
import {modalStack} from '../../components/modal-stack.js';
import {attachmentStore} from './attachment-store.js';
import {iconFor, toneFor} from './attachment-icons.js';

/* ---------- pure state ---------- */

/**
 * ใช้ลิงก์เปิดไฟล์เป็นคีย์ประจำไฟล์ เพราะ payload ฝั่ง server ไม่ได้ส่ง id มา
 * และ url ของแต่ละไฟล์แนบไม่ซ้ำกันอยู่แล้ว ต่างจากลำดับในอาร์เรย์ที่เลื่อนได้
 * เมื่อมีคนลบหรือแนบไฟล์ขณะกล่องนี้เปิดอยู่
 */
export function fileKey(file) {
    return String(file?.url ?? '');
}

export function toggleKey(keys, key) {
    return keys.includes(key) ? keys.filter((entry) => entry !== key) : [...keys, key];
}

/** ตัดคีย์ของไฟล์ที่ถูกลบทิ้งไปแล้วออก ไม่ให้ตัวนับค้างสูงกว่าจำนวนไฟล์จริง */
export function pruneKeys(files, keys) {
    const existing = files.map(fileKey);

    return keys.filter((key) => existing.includes(key));
}

export function selectedFiles(files, keys) {
    return files.filter((file) => keys.includes(fileKey(file)));
}

export function downloadLabel(count) {
    return count > 0 ? `ดาวน์โหลด ${count} ไฟล์` : 'ดาวน์โหลด';
}

export function selectionLabel(selectedCount, total) {
    return total > 0 ? `เลือกแล้ว ${selectedCount} จาก ${total} ไฟล์` : '';
}

/**
 * สถานะของช่อง "เลือกทั้งหมด"
 *
 * ต้องเป็น indeterminate เมื่อเลือกบางส่วน ไม่ใช่ติ๊กหรือไม่ติ๊กเท่านั้น
 * ไม่งั้นการกดครั้งถัดไปจะเดาไม่ออกว่าจะเลือกเพิ่มหรือล้างทั้งหมด
 */
export function selectAllState(files, keys) {
    const total = files.length;
    const selected = pruneKeys(files, keys).length;

    return {
        checked: total > 0 && selected === total,
        indeterminate: selected > 0 && selected < total,
        disabled: total === 0,
    };
}

/* ---------- DOM controller ---------- */

const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));

/**
 * ระยะห่างระหว่างไฟล์ที่สั่งดาวน์โหลดติดกัน
 *
 * เบราว์เซอร์ทิ้งการนำทางที่ยิงพร้อมกันในเฟรมเดียว ไฟล์ท้าย ๆ จะหายเงียบ ๆ
 * การเว้นจังหวะทำให้ทุกไฟล์ถูกรับไว้ครบ
 */
export const DOWNLOAD_INTERVAL_MS = 350;

export function initAttachmentDownload(doc = globalThis.document) {
    const modal = doc?.querySelector('[data-attachment-download-modal]');
    if (!modal) return null;

    const layers = modalStack(doc);
    const data = attachmentStore(doc);
    const list = modal.querySelector('[data-attachment-download-list]');
    const empty = modal.querySelector('[data-attachment-download-empty]');
    const topic = modal.querySelector('[data-attachment-download-topic]');
    const count = modal.querySelector('[data-attachment-download-count]');
    const toolbar = modal.querySelector('[data-attachment-download-toolbar]');
    const toggleAll = modal.querySelector('[data-attachment-download-all]');
    const start = modal.querySelector('[data-attachment-download-start]');
    const startLabel = modal.querySelector('[data-attachment-download-label]');
    const status = modal.querySelector('[data-attachment-download-status]');

    let taskId = null;
    let keys = [];

    const filesOf = () => data[String(taskId)]?.files || [];

    const setStatus = (message) => {
        if (!status) return;
        status.textContent = message;
        status.hidden = !message;
    };

    /** วาดเฉพาะส่วนที่ขึ้นกับการติ๊กเลือก ไม่สร้างรายการใหม่ทั้งชุดจน focus หลุด */
    const paintSelection = () => {
        const files = filesOf();
        keys = pruneKeys(files, keys);

        const state = selectAllState(files, keys);
        if (toggleAll) {
            toggleAll.checked = state.checked;
            toggleAll.indeterminate = state.indeterminate;
            toggleAll.disabled = state.disabled;
        }
        if (count) count.textContent = selectionLabel(keys.length, files.length);
        if (startLabel) startLabel.textContent = downloadLabel(keys.length);
        if (start) start.disabled = keys.length === 0;

        list?.querySelectorAll('[data-attachment-download-key]').forEach((row) => {
            const checkbox = row.querySelector('input[type="checkbox"]');
            const selected = keys.includes(row.dataset.attachmentDownloadKey);
            if (checkbox) checkbox.checked = selected;
            row.classList.toggle('is-selected', selected);
        });
    };

    const render = () => {
        const files = filesOf();

        if (list) {
            list.innerHTML = files.map((file) => {
                const key = fileKey(file);

                /*
                 * ลิงก์ดาวน์โหลดรายไฟล์อยู่นอก <label> ไม่ใช่ข้างใน
                 * ถ้าอยู่ข้างใน การกดลิงก์จะไปสลับ checkbox ของแถวพ่วงด้วย
                 */
                return `<div class="attachment-download-modal__row" data-attachment-download-key="${escape(key)}">
                    <label>
                        <input type="checkbox" value="${escape(key)}">
                        <i class="bi ${iconFor(file.name)} file-tone-${toneFor(file.name)}" aria-hidden="true"></i>
                        <span>${escape(file.name)}</span>
                    </label>
                    <a href="${escape(file.download_url || file.url)}" download="${escape(file.name)}" title="ดาวน์โหลดไฟล์นี้" aria-label="ดาวน์โหลด ${escape(file.name)}"><i class="bi bi-download" aria-hidden="true"></i></a>
                </div>`;
            }).join('');
        }
        if (empty) empty.hidden = files.length > 0;
        if (toolbar) toolbar.hidden = files.length === 0;

        paintSelection();
    };

    const open = (trigger) => {
        const id = trigger?.dataset.taskId || '';
        if (!id || !data[id]) return;

        taskId = id;
        // เปิดมาแล้วเลือกไว้ครบทุกไฟล์ คนที่ต้องการทั้งงานจึงกดดาวน์โหลดได้ทันทีหนึ่งคลิก
        keys = filesOf().map(fileKey);
        if (topic) topic.textContent = data[id]?.topic || '';
        setStatus('');
        render();
        layers.open(modal, trigger);
    };

    const close = () => {
        layers.close(modal);
        taskId = null;
    };

    /**
     * สั่งดาวน์โหลดจริง
     *
     * ใช้ลิงก์ที่มี ?download=1 จาก server ไม่ใช่แค่ attribute download ฝั่ง client
     * เพราะชื่อไฟล์บนดิสก์ถูก hash ไว้ ชื่อเดิมของผู้ใช้ออกมาจาก Content-Disposition เท่านั้น
     */
    const startDownloads = () => {
        const files = selectedFiles(filesOf(), keys);
        if (!files.length) return;

        files.forEach((file, index) => {
            globalThis.setTimeout(() => {
                const link = doc.createElement('a');
                link.href = file.download_url || file.url;
                link.download = file.name || '';
                link.rel = 'noopener';
                doc.body.appendChild(link);
                link.click();
                link.remove();
            }, index * DOWNLOAD_INTERVAL_MS);
        });

        setStatus(`เริ่มดาวน์โหลด ${files.length} ไฟล์แล้ว ตรวจดูที่รายการดาวน์โหลดของเบราว์เซอร์`);
    };

    doc.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-open-attachment-download]');
        if (trigger) {
            event.preventDefault();
            open(trigger);
            return;
        }

        if (modal.hidden) return;

        // คลิกนอกกล่องปิดได้เฉพาะเมื่อกล่องนี้เป็นชั้นบนสุด แบบเดียวกับโมดัลอื่นในหน้านี้
        if (event.target.closest('[data-close-attachment-download]') || (event.target === modal && layers.isTop(modal))) {
            close();
            return;
        }

        if (event.target.closest('[data-attachment-download-start]')) startDownloads();
    });

    modal.addEventListener('change', (event) => {
        if (event.target === toggleAll) {
            const files = filesOf();
            keys = toggleAll.checked ? files.map(fileKey) : [];
            paintSelection();
            return;
        }

        const row = event.target.closest('[data-attachment-download-key]');
        if (!row) return;

        keys = toggleKey(keys, row.dataset.attachmentDownloadKey);
        paintSelection();
    });

    modal.addEventListener('modalstack:dismiss', close);

    // แนบหรือลบไฟล์จากการ์ดด้านหลังขณะกล่องนี้เปิดอยู่ รายการต้องตามทันที
    doc.addEventListener('mytasks:attachments-changed', (event) => {
        if (modal.hidden || String(event.detail?.id || '') !== String(taskId)) return;
        render();
    });

    return {open, close, modal};
}
