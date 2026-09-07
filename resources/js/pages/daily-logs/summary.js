/*
 * อัปเดตกล่องสรุปเวลาหลังบันทึก/แก้ไข/ลบ โดยไม่ต้องโหลดหน้าใหม่
 *
 * ตัวเลขทั้งหมดมาจาก WorkLogSummary ฝั่งเซิร์ฟเวอร์ที่ส่งกลับมาใน payload
 * ฝั่งนี้จึงมีหน้าที่แค่ "วางค่าลงช่อง" ไม่คำนวณเลขเอง เพื่อไม่ให้เกิดสูตรที่สอง
 * ที่จะเพี้ยนออกจากหน้าสรุปและรายงานเมื่อกติกาเปลี่ยน
 */
import {durationLabel} from './duration.js';

export function applySummary(summary, {root = document} = {}) {
    if (! summary || typeof summary !== 'object') return;

    const setText = (selector, value) => {
        root.querySelectorAll(selector).forEach((node) => {
            node.textContent = value;
        });
    };

    setText('[data-summary-total]', durationLabel(summary.total_minutes));
    setText('[data-summary-count]', String(summary.total_count ?? 0));
    setText('[data-summary-unlinked]', durationLabel(summary.unlinked_minutes));
}
