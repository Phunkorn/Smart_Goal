/**
 * ไอคอนและโทนสีตามชนิดไฟล์แนบ — แหล่งความจริงเดียวของฝั่งหน้าจอ
 *
 * allow-list ชุดเดียวกับ App\Support\AttachmentPolicy
 * ที่นี่ทำหน้าที่แค่ "บอกชนิดไฟล์ให้ผู้ใช้เห็นเร็ว" การบังคับจริงยังอยู่ที่ server เสมอ
 *
 * เดิมตารางนี้อยู่ใน mytasks-task-modal.js เท่านั้น การ์ดไฟล์แนบจึงมีไอคอนตามชนิดไฟล์
 * ขณะที่กล่องอื่นที่แสดงไฟล์ชุดเดียวกันต้องใช้ไอคอนกระดาษเปล่าเหมือนกันหมด
 * หรือไม่ก็ต้องคัดลอกตารางไปอีกชุดแล้วหลุดจากกันเมื่อ AttachmentPolicy เปลี่ยน
 */
export const FILE_ICONS = {
    jpg: 'bi-file-earmark-image', jpeg: 'bi-file-earmark-image', png: 'bi-file-earmark-image',
    txt: 'bi-file-earmark-text',
    pdf: 'bi-file-earmark-pdf',
    doc: 'bi-file-earmark-word', docx: 'bi-file-earmark-word',
    xls: 'bi-file-earmark-excel', xlsx: 'bi-file-earmark-excel',
    ppt: 'bi-file-earmark-ppt', pptx: 'bi-file-earmark-ppt',
    zip: 'bi-file-earmark-zip',
};

export const extensionOf = (name) => String(name ?? '').split('.').pop().toLowerCase();

export const iconFor = (name) => FILE_ICONS[extensionOf(name)] || 'bi-file-earmark';

export const toneFor = (name) => FILE_ICONS[extensionOf(name)]?.replace('bi-file-earmark-', '') || 'other';

export const PASSWORD_LOCK_LABEL = 'ไฟล์นี้ตั้งรหัสผ่านไว้';

/**
 * ไอคอนกุญแจท้ายชื่อไฟล์ที่ตั้งรหัสผ่าน — ทุกกล่องที่แสดงไฟล์แนบของงานเรียกที่นี่ที่เดียว
 *
 * ค่า password_protected มาจาก TaskAttachmentPresenter ฝั่ง server
 * ข้อความซ่อนไว้ให้โปรแกรมอ่านหน้าจออ่านต่อจากชื่อไฟล์ ส่วน title ให้คนใช้เมาส์ชี้ดู
 */
export const lockBadge = (file) => (file?.password_protected === true
    ? `<span class="attachment-lock" title="${PASSWORD_LOCK_LABEL}"><i class="bi bi-lock-fill" aria-hidden="true"></i><span class="visually-hidden">${PASSWORD_LOCK_LABEL}</span></span>`
    : '');
