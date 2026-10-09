import {publishTaskFiles} from './attachment-store.js';

/**
 * การลบไฟล์แนบของงาน — แหล่งความจริงเดียวของทุกมุมมอง
 *
 * เดิมมีเฉพาะการ์ด "ไฟล์แนบ" ใน Task Workspace ที่วาดปุ่มถังขยะ ส่วนกล่องไฟล์แนบ
 * ที่เปิดจากปุ่มคลิปหนีบบนแถวตาราง การ์ดบอร์ด และการ์ด kanban วาดแต่ลิงก์เปิดไฟล์
 * บนจอมือถือผู้ใช้เข้าถึงไฟล์แนบทางปุ่มคลิปหนีบเป็นหลัก จึงไม่เห็นปุ่มลบเลย
 * ทั้งที่สิทธิ์ลบ (delete_url) มาจาก server ชุดเดียวกัน
 *
 * สิทธิ์ยังคงมาจาก delete_url ที่ TaskAttachmentPresenter ใส่มาเท่านั้น
 * ไม่มีการตัดสินสิทธิ์ใน JavaScript
 */

const dialogLayer = {customClass: {container: 'task-workspace-dialog'}};

/** ปุ่มลบของแถวไฟล์ วาดเฉพาะเมื่อ server ให้ delete_url มา */
export function attachmentDeleteButton(file, documentRef = globalThis.document) {
    if (!file?.delete_url) return null;

    const button = documentRef.createElement('button');
    button.type = 'button';
    button.className = 'attachment-delete';
    button.dataset.deleteAttachment = file.delete_url;
    button.setAttribute('aria-label', `ลบไฟล์ ${file.name ?? ''}`.trim());
    const icon = documentRef.createElement('i');
    icon.className = 'bi bi-trash3';
    icon.setAttribute('aria-hidden', 'true');
    button.append(icon);

    return button;
}

export async function confirmAttachmentDelete() {
    if (!globalThis.Swal) return globalThis.confirm?.('ต้องการลบไฟล์นี้ใช่หรือไม่?') === true;

    const result = await globalThis.Swal.fire({
        ...dialogLayer,
        icon: 'warning',
        title: 'ต้องการลบไฟล์นี้หรือไม่?',
        showCancelButton: true,
        confirmButtonText: 'ลบไฟล์',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626',
        reverseButtons: true,
    });

    return result.isConfirmed;
}

/**
 * ลบไฟล์แล้วอัปเดตข้อมูลกลาง คืนรายการไฟล์ใหม่
 *
 * โยน Error เมื่อ server ปฏิเสธ ให้ผู้เรียกเลือกวิธีแจ้งผู้ใช้เอง
 * (การ์ดไฟล์แนบใช้แถบสถานะ กล่องบอร์ดใช้ toast)
 */
export async function deleteTaskAttachment({url, csrf, taskId, files = [], documentRef = globalThis.document}) {
    const response = await fetch(url, {method: 'DELETE', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': csrf}});
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.message || 'ลบไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');

    const remaining = Array.isArray(result.files)
        ? result.files
        : files.filter((file) => file.delete_url !== url);

    publishTaskFiles(taskId, remaining, documentRef);

    return remaining;
}
